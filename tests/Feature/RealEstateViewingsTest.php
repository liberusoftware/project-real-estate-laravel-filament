<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Properties\Application\CreateProperty;
use Liberu\RealEstate\Viewings\Application\CancelViewing;
use Liberu\RealEstate\Viewings\Application\CompleteViewing;
use Liberu\RealEstate\Viewings\Application\ConfirmViewing;
use Liberu\RealEstate\Viewings\Application\CreateViewing;
use Liberu\RealEstate\Viewings\Application\DeleteViewing;
use Liberu\RealEstate\Viewings\Application\MarkViewingNoShow;
use Liberu\RealEstate\Viewings\Application\UpdateViewing;
use Liberu\RealEstate\Viewings\Domain\ViewingStatus;
use Liberu\RealEstate\Viewings\Models\Viewing;
use Liberu\RealEstate\Viewings\Policies\ViewingPolicy;
use Liberu\RealEstate\Viewings\Queries\AvailableViewingSlots;
use Liberu\RealEstate\Viewings\Queries\ViewingCalendarExport;
use Liberu\RealEstate\ViewingsApi\Http\Controllers\ViewingController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));
});

it('routes guest viewing requests to the property team for host review', function (): void {
    $host = User::factory()->create(['current_team_id' => 10]);
    $guest = User::factory()->create(['current_team_id' => 20]);
    $property = app(CreateProperty::class)->handle(10, $host->id, ['address' => '42 Acacia Avenue']);
    $startsAt = now()->next('Monday')->setTime(10, 0);
    $endpoint = '/api/v1/real-estate/viewings';

    $this->actingAs($guest, 'sanctum');
    $viewingId = $this->postJson($endpoint, [
        'subject' => 'First viewing',
        'property_id' => $property->id,
        'starts_at' => $startsAt->toDateTimeString(),
        'guests_count' => 2,
    ])->assertCreated()->assertJsonPath('data.team_id', 10)
        ->assertJsonPath('data.status', 'requested')->assertJsonPath('data.guests_count', 2)->json('data.id');
    $viewingUrl = "$endpoint/$viewingId";

    $this->getJson($viewingUrl)->assertOk()->assertJsonPath('data.subject', 'First viewing');
    $this->getJson($endpoint)->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("$viewingUrl/confirm")->assertNotFound();
    $this->patchJson($viewingUrl, ['subject' => 'Guest edit'])->assertNotFound();

    $this->actingAs($host, 'sanctum');
    $this->getJson($endpoint)->assertOk()->assertJsonPath('data.0.id', $viewingId);
    $slots = $this->getJson("$endpoint/availability?property_id={$property->id}&date={$startsAt->toDateString()}")
        ->assertOk()->json('data');
    expect($slots)->toContain($startsAt->copy()->addHour()->toIso8601String())
        ->not->toContain($startsAt->toIso8601String());
    $this->patchJson($viewingUrl, ['subject' => 'Hosted viewing'])->assertOk()
        ->assertJsonPath('data.subject', 'Hosted viewing');
    $this->postJson("$viewingUrl/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');
    $this->postJson("$viewingUrl/complete", ['feedback' => ['rating' => 5]])
        ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.feedback.rating', 5);
    $this->deleteJson($viewingUrl)->assertNoContent();
    $this->assertSoftDeleted('real_estate_viewings', ['id' => $viewingId]);
});

it('allows guests to cancel their own viewing and reserves the no-show action for hosts', function (): void {
    $host = User::factory()->create(['current_team_id' => 10]);
    $guest = User::factory()->create(['current_team_id' => 20]);
    $create = app(CreateViewing::class);
    $cancelled = $create->handle(10, $guest->id, ['subject' => 'Cancelled', 'starts_at' => now()->addDays(3)]);
    $endpoint = '/api/v1/real-estate/viewings';

    $this->actingAs($host, 'sanctum');
    $noShowId = $this->postJson($endpoint, ['subject' => 'No show', 'starts_at' => now()->addDays(2)->toDateTimeString()])
        ->assertCreated()->assertJsonPath('data.team_id', 10)->json('data.id');
    $this->postJson("$endpoint/$noShowId/confirm")->assertOk();

    $this->actingAs($guest, 'sanctum');
    $this->postJson("$endpoint/{$cancelled->id}/cancel", ['reason' => 'Unavailable'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.access.cancellation_reason', 'Unavailable');
    $this->postJson("$endpoint/$noShowId/no-show")->assertNotFound();

    $this->actingAs($host, 'sanctum');
    $this->postJson("$endpoint/$noShowId/no-show", ['note' => 'Guest did not arrive'])
        ->assertOk()->assertJsonPath('data.no_show', true)->assertJsonPath('data.feedback.no_show_note', 'Guest did not arrive');
});

it('requires authentication at the viewing route and denies a missing controller identity', function (): void {
    $viewing = app(CreateViewing::class)->handle(10, 1, [
        'subject' => 'Private viewing',
        'starts_at' => now()->addDays(2),
    ]);
    $endpoint = "/api/v1/real-estate/viewings/{$viewing->id}";

    $this->getJson($endpoint)->assertUnauthorized();
    expect(fn () => app(ViewingController::class)->show(Request::create($endpoint), $viewing))
        ->toThrow(NotFoundHttpException::class);
});

it('creates a requested viewing with access metadata', function (): void {
    $viewing = app(CreateViewing::class)->handle(1, 5, ['subject' => 'Property viewing', 'starts_at' => now()->addDay()->startOfHour(), 'access' => ['key_holder' => 'agent']]);
    expect($viewing->status->value)->toBe('requested')->and($viewing->access['key_holder'])->toBe('agent');
});

it('compares viewing times consistently when requests use different offsets', function (): void {
    $create = app(CreateViewing::class);
    $startsAt = now()->addDays(2)->utc()->setTime(10, 0);
    $viewing = $create->handle(10, 1, [
        'subject' => 'Original appointment',
        'starts_at' => $startsAt->copy()->setTimezone('+02:00')->toIso8601String(),
        'ends_at' => $startsAt->copy()->addHour()->toIso8601String(),
    ]);

    expect($viewing->starts_at->getTimestamp())->toBe($startsAt->getTimestamp())
        ->and(fn () => $create->handle(10, 2, [
            'subject' => 'Overlapping appointment',
            'starts_at' => $startsAt->copy()->addMinutes(30)->setTimezone('-04:00')->toIso8601String(),
        ]))->toThrow(ValidationException::class, 'That property already has a viewing in the selected time window.');

    $adjacent = $create->handle(10, 2, [
        'subject' => 'Adjacent appointment',
        'starts_at' => $startsAt->copy()->addHour()->toIso8601String(),
    ]);

    expect($adjacent->starts_at->getTimestamp())->toBe($viewing->ends_at->getTimestamp());
});

it('requires a start time and archives a viewing for its team', function (): void {
    expect(fn () => app(CreateViewing::class)->handle(1, 5, ['subject' => 'Viewing']))->toThrow(ValidationException::class);
    $viewing = Viewing::query()->create(['team_id' => 1, 'subject' => 'Viewing', 'status' => 'requested', 'starts_at' => '2026-09-01 10:00']);
    app(DeleteViewing::class)->handle($viewing, 1);
    expect(Viewing::withTrashed()->find($viewing->id)->deleted_at)->not->toBeNull();
});

it('guards viewing availability and supports confirmation, completion, feedback, and no-shows', function (): void {
    $startsAt = now()->addDays(2)->startOfHour();
    $viewing = app(CreateViewing::class)->handle(1, 5, [
        'subject' => 'Viewing workflow',
        'property_id' => 7,
        'starts_at' => $startsAt,
    ]);

    expect(fn () => app(CreateViewing::class)->handle(1, 6, [
        'subject' => 'Overlapping viewing',
        'property_id' => 7,
        'starts_at' => $startsAt->copy()->addMinutes(15),
    ]))->toThrow(ValidationException::class);

    $viewing = app(ConfirmViewing::class)->handle($viewing, 1);
    $viewing = app(CompleteViewing::class)->handle($viewing, 1, ['rating' => 5]);

    expect($viewing->status->value)->toBe('completed')
        ->and($viewing->feedback['rating'])->toBe(5);

    $noShow = app(CreateViewing::class)->handle(1, 5, [
        'subject' => 'No-show workflow',
        'property_id' => 8,
        'starts_at' => $startsAt->copy()->addDay(),
    ]);
    $noShow = app(ConfirmViewing::class)->handle($noShow, 1);
    $noShow = app(MarkViewingNoShow::class)->handle($noShow, 1, 'Party did not attend.');

    expect($noShow->status->value)->toBe('no_show')
        ->and($noShow->no_show)->toBeTrue()
        ->and($noShow->feedback['no_show_note'])->toBe('Party did not attend.');

    expect(fn () => app(CancelViewing::class)->handle($viewing, 1))->toThrow(ValidationException::class);
});

it('returns weekday slots and removes requested or confirmed overlaps', function (): void {
    $date = CarbonImmutable::now()->next(CarbonImmutable::MONDAY)->startOfDay();
    $viewing = app(CreateViewing::class)->handle(1, 5, [
        'subject' => 'Already booked',
        'property_id' => 7,
        'starts_at' => $date->setTime(10, 0),
    ]);

    $slots = app(AvailableViewingSlots::class)->handle(1, 7, $date);

    expect($slots)->toContain($date->setTime(9, 0)->toIso8601String())
        ->not->toContain($viewing->starts_at->toIso8601String())
        ->toContain($date->setTime(11, 0)->toIso8601String());

    expect(app(AvailableViewingSlots::class)->handle(1, 7, $date->next(CarbonImmutable::SATURDAY)->startOfDay()))
        ->toBe([]);
});

it('keeps viewing updates inside the future, valid, non-overlapping schedule', function (): void {
    $startsAt = now()->addDays(3)->startOfHour();
    $viewing = app(CreateViewing::class)->handle(1, 5, [
        'subject' => 'Existing viewing',
        'property_id' => 12,
        'starts_at' => $startsAt,
    ]);
    $other = app(CreateViewing::class)->handle(1, 6, [
        'subject' => 'Other property viewing',
        'property_id' => 13,
        'starts_at' => $startsAt,
    ]);

    expect(fn () => app(UpdateViewing::class)->handle($viewing, 1, [
        'starts_at' => $startsAt->copy()->subMinute(),
        'property_id' => $other->property_id,
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(UpdateViewing::class)->handle($viewing, 1, [
        'starts_at' => now()->subMinute(),
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(UpdateViewing::class)->handle($viewing, 1, [
        'starts_at' => $startsAt,
        'ends_at' => $startsAt,
    ]))->toThrow(ValidationException::class);
});

it('preserves the legacy cancellation and rescheduling notice periods', function (): void {
    $viewing = Viewing::query()->create([
        'team_id' => 1,
        'subject' => 'Short notice viewing',
        'status' => 'requested',
        'starts_at' => now()->addHours(12),
    ]);

    expect($viewing->canBeCancelled())->toBeFalse()->and($viewing->canBeRescheduled())->toBeFalse();
    expect(fn () => app(CancelViewing::class)->handle($viewing, 1))->toThrow(ValidationException::class);
    expect(fn () => app(UpdateViewing::class)->handle($viewing, 1, ['starts_at' => now()->addDays(3)]))->toThrow(ValidationException::class);
});

it('validates end-only edits against the stored start time', function (): void {
    $startsAt = now()->addDays(2)->startOfHour();
    $viewing = app(CreateViewing::class)->handle(1, 5, ['subject' => 'Property viewing', 'starts_at' => $startsAt]);
    $update = app(UpdateViewing::class);

    expect(fn () => $update->handle($viewing, 1, ['ends_at' => $startsAt->toIso8601String()]))
        ->toThrow(ValidationException::class, 'The end time must be after the start time.');

    $endsAt = $startsAt->copy()->addMinutes(90)->setTimezone('+02:00');
    $updated = $update->handle($viewing, 1, ['ends_at' => $endsAt->toIso8601String()]);
    expect($updated->starts_at->equalTo($startsAt))->toBeTrue()
        ->and($updated->ends_at->equalTo($endsAt))->toBeTrue();
});

it('exports a team-scoped viewing calendar file', function (): void {
    $viewing = Viewing::query()->create([
        'team_id' => 10,
        'subject' => 'Garden viewing, phase 2',
        'status' => 'confirmed',
        'starts_at' => '2026-09-01 10:00:00',
        'ends_at' => '2026-09-01 11:00:00',
    ]);

    expect(app(ViewingCalendarExport::class)->handle($viewing))
        ->toContain('BEGIN:VCALENDAR')
        ->toContain('SUMMARY:Garden viewing\\, phase 2')
        ->toContain('DTSTART:20260901T100000Z');

    $user = User::factory()->create(['current_team_id' => 11]);
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/real-estate/viewings/'.$viewing->getKey().'/calendar')->assertNotFound();
});

it('persists cancellation details and preserves access instructions', function (): void {
    $viewing = app(CreateViewing::class)->handle(1, 5, [
        'subject' => 'Property viewing',
        'starts_at' => now()->addDays(3),
        'access' => ['key_holder' => 'agent'],
    ]);
    $cancelViewing = app(CancelViewing::class);

    expect(fn () => $cancelViewing->handle($viewing, 2))
        ->toThrow(NotFoundHttpException::class);

    $cancelled = $cancelViewing->handle($viewing, '1', 'Buyer requested cancellation.');

    expect($cancelled->status)->toBe(ViewingStatus::Cancelled)
        ->and($cancelled->access)->toBe([
            'key_holder' => 'agent',
            'cancellation_reason' => 'Buyer requested cancellation.',
        ]);
});

it('validates viewing subjects, creation times, and status transitions', function (): void {
    $createViewing = app(CreateViewing::class);
    $updateViewing = app(UpdateViewing::class);

    expect(fn () => $createViewing->handle(1, 5, ['subject' => ' ']))
        ->toThrow(ValidationException::class, 'A viewing subject is required.')
        ->and(fn () => $createViewing->handle(1, 5, ['subject' => 'Past viewing', 'starts_at' => now()->subDay()]))
        ->toThrow(ValidationException::class, 'A viewing must be scheduled in the future.');

    $viewing = $createViewing->handle(1, 5, [
        'subject' => 'Property viewing',
        'starts_at' => now()->addDays(3),
    ]);

    expect(fn () => $updateViewing->handle($viewing, 1, ['subject' => ' ']))
        ->toThrow(ValidationException::class, 'A viewing subject is required.')
        ->and(fn () => $updateViewing->handle($viewing, 1, ['status' => 'unknown']))
        ->toThrow(ValidationException::class, 'That viewing status transition is not allowed.')
        ->and(fn () => $updateViewing->handle($viewing, 1, ['status' => ViewingStatus::Completed->value]))
        ->toThrow(ValidationException::class, 'That viewing status transition is not allowed.')
        ->and(fn () => app(CompleteViewing::class)->handle($viewing, 1))
        ->toThrow(ValidationException::class, 'Only confirmed viewings can be completed.')
        ->and(fn () => app(MarkViewingNoShow::class)->handle($viewing, 1))
        ->toThrow(ValidationException::class, 'Only confirmed viewings can be marked as a no-show.');

    $updated = $updateViewing->handle($viewing, 1, [
        'subject' => 'Updated viewing',
        'status' => ViewingStatus::Confirmed->value,
    ]);

    expect($updated->subject)->toBe('Updated viewing')
        ->and($updated->status)->toBe(ViewingStatus::Confirmed)
        ->and(fn () => app(ConfirmViewing::class)->handle($updated, 1))
        ->toThrow(ValidationException::class, 'Only requested viewings can be confirmed.');
});

it('associates a viewing with its owning team', function (): void {
    $team = Team::factory()->create();
    $viewing = app(CreateViewing::class)->handle($team->id, $team->user_id, [
        'subject' => 'Property viewing',
        'starts_at' => now()->addDays(3),
        'guests_count' => 2,
    ]);

    expect($viewing->team->is($team))->toBeTrue()
        ->and($viewing->fresh()->guests_count)->toBe(2);
});

it('checks the corresponding permission for each viewing policy action', function (): void {
    $user = User::factory()->make();
    $viewing = new Viewing();
    Gate::policy(Viewing::class, ViewingPolicy::class);
    $gate = Gate::forUser($user);

    $subjects = [
        'viewAny' => Viewing::class,
        'view' => $viewing,
        'create' => Viewing::class,
        'update' => $viewing,
        'delete' => $viewing,
        'deleteAny' => Viewing::class,
        'restore' => $viewing,
        'restoreAny' => Viewing::class,
        'forceDelete' => $viewing,
        'forceDeleteAny' => Viewing::class,
        'replicate' => $viewing,
        'reorder' => Viewing::class,
    ];

    foreach ($subjects as $action => $subject) {
        expect($gate->allows($action, $subject))->toBeFalse();
        Gate::define(ucfirst($action).':Viewing', fn (User $candidate): bool => $candidate === $user);
        expect($gate->allows($action, $subject))->toBeTrue();
    }
});

it('can roll back and replay the viewing migrations', function (): void {
    $directory = base_path('modules/real-estate-viewings/database/migrations');
    $viewings = require $directory.'/2026_08_23_000009_create_real_estate_viewings_table.php';
    $feedback = require $directory.'/2026_08_30_000022_create_real_estate_viewing_feedback.php';
    $guests = require $directory.'/2026_09_03_000001_add_guests_count_to_viewings.php';

    $guests->down();
    expect(Schema::hasColumn('real_estate_viewings', 'guests_count'))->toBeFalse();
    $feedback->down();
    $viewings->down();
    expect(Schema::hasTable('real_estate_viewings'))->toBeFalse()
        ->and(Schema::hasTable('real_estate_viewing_feedback'))->toBeFalse();

    $viewings->up();
    $feedback->up();
    $guests->up();
    expect(Schema::hasColumn('real_estate_viewings', 'guests_count'))->toBeTrue()
        ->and(Schema::hasTable('real_estate_viewing_feedback'))->toBeTrue();
});
