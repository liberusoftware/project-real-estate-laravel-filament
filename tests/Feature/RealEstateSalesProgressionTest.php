<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\SalesProgression\Application\CreateSalesProgression;
use Liberu\RealEstate\SalesProgression\Application\DeleteSalesProgression;
use Liberu\RealEstate\SalesProgression\Application\TransitionSalesProgression;
use Liberu\RealEstate\SalesProgression\Application\UpdateSalesProgression;
use Liberu\RealEstate\SalesProgression\Application\UpdateSalesProgressionSection;
use Liberu\RealEstate\SalesProgression\Domain\SalesProgressionSection;
use Liberu\RealEstate\SalesProgression\Domain\SalesProgressionStatus;
use Liberu\RealEstate\SalesProgression\Models\SalesProgression;
use Liberu\RealEstate\SalesProgression\Policies\SalesProgressionPolicy;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates a team-scoped progression with milestones and chain metadata', function (): void {
    $progression = app(CreateSalesProgression::class)->handle(31, 7, [
        'subject' => '12 Example Street sale',
        'property_id' => 12,
        'offer_id' => 8,
        'milestones' => ['memorandum_issued' => true],
        'chain' => ['upstream' => ['subject' => '14 Example Street']],
    ]);

    expect($progression)
        ->toBeInstanceOf(SalesProgression::class)
        ->team_id->toBe(31)
        ->status->value->toBe('in_progress')
        ->milestones->toMatchArray(['memorandum_issued' => true]);
});

it('requires a subject and archives only a progression in its team', function (): void {
    expect(fn (): SalesProgression => app(CreateSalesProgression::class)->handle(31, 7, []))
        ->toThrow(ValidationException::class);

    $progression = app(CreateSalesProgression::class)->handle(31, 7, ['subject' => 'Sale']);

    expect(fn () => app(DeleteSalesProgression::class)->handle($progression, 32))
        ->toThrow(ValidationException::class);

    app(DeleteSalesProgression::class)->handle($progression, 31);

    expect($progression->fresh()->trashed())->toBeTrue();
});

it('updates chain and completion sections independently', function (): void {
    $progression = app(CreateSalesProgression::class)->handle(31, 7, ['subject' => 'Sale']);

    app(UpdateSalesProgressionSection::class)->handle($progression, 31, SalesProgressionSection::Chain, ['upstream' => ['subject' => 'Upstream sale']]);

    expect($progression->refresh()->chain['upstream']['subject'])->toBe('Upstream sale');
});

it('guards progression updates and lifecycle transitions by team', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $progression = app(CreateSalesProgression::class)->handle($team->id, $team->user_id, ['subject' => 'Sale']);
    $update = app(UpdateSalesProgression::class);
    $section = app(UpdateSalesProgressionSection::class);
    $transition = app(TransitionSalesProgression::class);

    expect(fn () => $update->handle($progression, $otherTeam->id, ['subject' => 'Other team']))
        ->toThrow(ValidationException::class, 'The progression does not belong to this team.')
        ->and(fn () => $section->handle($progression, $otherTeam->id, SalesProgressionSection::Chain, ['upstream' => []]))
        ->toThrow(ValidationException::class, 'The progression does not belong to this team.')
        ->and(fn () => $transition->handle($progression, $otherTeam->id, SalesProgressionStatus::OnHold))
        ->toThrow(ValidationException::class, 'The progression does not belong to this team.')
        ->and(fn () => $update->handle($progression, $team->id, ['subject' => ' ']))
        ->toThrow(ValidationException::class, 'A progression subject is required.');

    $progression = $update->handle($progression, (string) $team->id, ['subject' => 'Revised sale', 'status' => 'completed']);
    expect($progression->subject)->toBe('Revised sale')
        ->and($progression->status)->toBe(SalesProgressionStatus::InProgress)
        ->and($progression->team->is($team))->toBeTrue()
        ->and(SalesProgression::query()->forTeam($otherTeam->id)->exists())->toBeFalse();

    $progression = $transition->handle($progression, $team->id, SalesProgressionStatus::OnHold);
    $progression = $transition->handle($progression, $team->id, SalesProgressionStatus::InProgress);
    $progression = $transition->handle($progression, $team->id, SalesProgressionStatus::FallenThrough);

    expect($progression->status)->toBe(SalesProgressionStatus::FallenThrough)
        ->and(fn () => $transition->handle($progression, $team->id, SalesProgressionStatus::InProgress))
        ->toThrow(ValidationException::class, 'Cannot transition a fallen_through progression to in_progress.');
});

it('manages and completes sales progressions through the authenticated team API', function (): void {
    $user = User::factory()->create(['current_team_id' => 31]);
    $outside = app(CreateSalesProgression::class)->handle(32, $user->id, ['subject' => 'Other sale']);
    $endpoint = '/api/v1/real-estate/sales-progression';
    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));

    $this->getJson($endpoint)->assertUnauthorized();
    $this->actingAs($user, 'sanctum');
    $id = $this->postJson($endpoint, ['subject' => 'New sale'])
        ->assertCreated()->assertJsonPath('data.status', 'in_progress')->json('data.id');
    $url = "$endpoint/$id";

    $this->getJson("$endpoint?page_size=1")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
    $this->getJson("$endpoint/{$outside->id}")->assertNotFound();
    $this->postJson("$endpoint/{$outside->id}/transition/on_hold")->assertNotFound();
    $this->getJson($url)->assertOk()->assertJsonPath('data.subject', 'New sale');
    $this->patchJson($url, ['subject' => 'Updated sale'])->assertOk()->assertJsonPath('data.subject', 'Updated sale');
    $this->patchJson("$url/milestones", ['value' => ['memorandum_issued' => true]])
        ->assertOk()->assertJsonPath('data.milestones.memorandum_issued', true);
    $this->postJson("$url/transition/unknown")->assertNotFound();
    $this->postJson("$url/transition/completed")->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson("$url/transition/exchanged")->assertOk()->assertJsonPath('data.status', 'exchanged');
    $this->postJson("$url/transition/completed")->assertOk()->assertJsonPath('data.status', 'completed');

    $progression = SalesProgression::query()->findOrFail($id);
    expect($progression->exchanged_at)->not->toBeNull()
        ->and($progression->completed_at)->not->toBeNull()
        ->and($outside->fresh()->status)->toBe(SalesProgressionStatus::InProgress);

    $this->deleteJson($url)->assertNoContent();
    $this->assertSoftDeleted('real_estate_sales_progressions', ['id' => $id]);
});

it('checks each sales progression policy action against its corresponding permission', function (): void {
    $policy = new SalesProgressionPolicy();
    $progression = new SalesProgression();
    $allowed = User::factory()->make();
    $denied = User::factory()->make();

    foreach ([
        'viewAny' => false, 'view' => true, 'create' => false, 'update' => true,
        'delete' => true, 'deleteAny' => false, 'restore' => true, 'forceDelete' => true,
        'forceDeleteAny' => false, 'restoreAny' => false, 'replicate' => true, 'reorder' => false,
    ] as $action => $requiresProgression) {
        $arguments = $requiresProgression ? [$progression] : [];
        expect($policy->{$action}($allowed, ...$arguments))->toBeFalse();
        Gate::define(ucfirst($action).':SalesProgression', fn (User $user): bool => $user === $allowed);

        expect($policy->{$action}($allowed, ...$arguments))->toBeTrue()
            ->and($policy->{$action}($denied, ...$arguments))->toBeFalse();
    }
});

it('filters and updates sales progressions in the active team Livewire list', function (): void {
    $user = User::factory()->create(['current_team_id' => 31]);
    $create = app(CreateSalesProgression::class);
    $progression = $create->handle(31, $user->id, ['subject' => 'First sale']);
    $create->handle(31, $user->id, ['subject' => 'Second sale']);
    $outside = $create->handle(32, $user->id, ['subject' => 'Outside sale']);
    $component = 'module-real-estate-sales-progression::sales-progression-list';

    Livewire::test($component)->assertForbidden();
    $this->actingAs($user);
    Livewire::test($component)
        ->assertSee('First sale')
        ->assertSee('Second sale')
        ->assertDontSee('Outside sale')
        ->set('search', 'First')
        ->assertDontSee('Second sale')
        ->call('updateSection', $progression->id, 'milestones', ['memorandum_issued' => true])
        ->call('transitionProgression', $progression->id, 'exchanged')
        ->assertSee('First sale — exchanged');

    Livewire::test($component)->call('updateSection', $outside->id, 'milestones', ['memorandum_issued' => true])->assertNotFound();
    Livewire::test($component)->call('transitionProgression', $progression->id, 'unknown')->assertNotFound();

    expect($progression->fresh()->milestones)->toBe(['memorandum_issued' => true])
        ->and($outside->fresh()->milestones)->toBe([]);
});

it('rolls back and replays the sales progression schema', function (): void {
    $migration = require base_path('modules/real-estate-sales-progression/database/migrations/2026_08_23_000011_create_real_estate_sales_progressions_table.php');
    $table = 'real_estate_sales_progressions';
    expect(Schema::hasTable($table))->toBeTrue();

    $migration->down();
    expect(Schema::hasTable($table))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumns($table, ['team_id', 'status', 'exchanged_at', 'completed_at']))->toBeTrue();
});
