<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Offers\Application\CreateOffer;
use Liberu\RealEstate\Offers\Application\DeleteOffer;
use Liberu\RealEstate\Offers\Application\RecordOfferProof;
use Liberu\RealEstate\Offers\Application\TransitionOffer;
use Liberu\RealEstate\Offers\Application\UpdateOffer;
use Liberu\RealEstate\Offers\Domain\OfferStatus;
use Liberu\RealEstate\Offers\Models\Offer;
use Liberu\RealEstate\Offers\Policies\OfferPolicy;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

it('manages and audits a team offer through its authenticated API', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $outside = app(CreateOffer::class)->handle($otherTeam->id, $user->id, ['subject' => 'Outside offer', 'amount' => 100000]);
    $endpoint = '/api/v1/real-estate/offers';
    $outsideUrl = "$endpoint/{$outside->id}";
    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));

    $this->getJson($endpoint)->assertUnauthorized();
    $this->actingAs($user, 'sanctum');
    $id = $this->postJson($endpoint, ['subject' => 'Purchase offer', 'amount' => 375000])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    $offerUrl = "$endpoint/$id";

    $this->getJson("$endpoint?page_size=1")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
    $this->getJson($offerUrl)->assertOk()->assertJsonPath('data.amount', '375000.00');
    $this->getJson($outsideUrl)->assertNotFound();
    $this->patchJson($outsideUrl, ['amount' => 200000])->assertNotFound();
    $this->postJson("$outsideUrl/proof", ['proof' => ['funds_verified' => true]])->assertNotFound();
    $this->postJson("$outsideUrl/transition/submitted")->assertNotFound();
    $this->getJson("$outsideUrl/timeline")->assertNotFound();
    $this->deleteJson($outsideUrl)->assertNotFound();

    $this->patchJson($offerUrl, ['amount' => 380000])->assertOk()->assertJsonPath('data.amount', '380000.00');
    $this->postJson("$offerUrl/proof", ['proof' => ['funds_verified' => true]])
        ->assertOk()->assertJsonPath('data.proof.funds_verified', true);
    $this->postJson("$offerUrl/transition/unknown")->assertNotFound();
    $this->postJson("$offerUrl/transition/submitted")->assertOk()->assertJsonPath('data.status', 'submitted');
    $this->postJson("$offerUrl/transition/accepted", ['note' => 'Seller approved'])
        ->assertOk()->assertJsonPath('data.status', 'accepted');

    $timeline = $this->getJson("$offerUrl/timeline")->assertOk()->json('data');
    expect(array_column($timeline, 'status'))->toBe(['draft', 'draft', 'submitted', 'accepted'])
        ->and(array_unique(array_column($timeline, 'offer_id')))->toBe([$id]);
    $audit = DB::table('activity_log')->where('event', 'offer.amount_changed')->where('subject_id', $id)->sole();
    expect((string) $audit->causer_id)->toBe((string) $user->id);

    $this->deleteJson($offerUrl)->assertNoContent();
    $this->assertSoftDeleted('real_estate_offers', ['id' => $id]);
});

it('searches and updates offers through the active team Livewire list', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    $create = app(CreateOffer::class);
    $first = $create->handle(10, $user->id, ['subject' => 'First purchase', 'amount' => 100000]);
    $second = $create->handle(10, $user->id, ['subject' => 'Second purchase', 'amount' => 110000]);
    $outside = $create->handle(20, $user->id, ['subject' => 'Outside purchase', 'amount' => 120000]);
    $this->actingAs($user);

    Livewire::test('module-real-estate-offers::offer-list')
        ->assertSee('First purchase')
        ->assertSee('Second purchase')
        ->assertDontSee('Outside purchase')
        ->set('search', 'First')
        ->assertDontSee('Second purchase')
        ->call('submitOffer', $first->id)
        ->assertDispatched('offer-updated')
        ->assertSee('First purchase (submitted)')
        ->call('acceptOffer', $first->id)
        ->assertSee('First purchase (accepted)')
        ->set('search', '')
        ->call('submitOffer', $second->id)
        ->call('rejectOffer', $second->id)
        ->assertSee('Second purchase (rejected)')
        ->call('submitOffer', $outside->id)
        ->assertHasErrors('offer');

    expect($first->fresh()->status)->toBe(OfferStatus::Accepted)
        ->and($second->fresh()->status)->toBe(OfferStatus::Rejected)
        ->and($outside->fresh()->status)->toBe(OfferStatus::Draft);
});

it('renders the Livewire offer list and denies transitions without a team', function (): void {
    Livewire::test('module-real-estate-offers::offer-list')
        ->assertSee('No offers found.')
        ->call('submitOffer', 1)
        ->assertForbidden();
});

it('creates a draft offer with terms and qualification', function (): void {
    $offer = app(CreateOffer::class)->handle(1, 5, ['subject' => 'Purchase offer', 'amount' => 375000, 'terms' => ['completion' => '30 days'], 'qualification' => ['funds_verified' => true]]);
    expect($offer->status->value)->toBe('draft')->and($offer->amount)->toBe('375000.00');
});
it('requires a valid amount and archives an offer for its team', function (): void {
    expect(fn () => app(CreateOffer::class)->handle(1, 5, ['subject' => 'Offer', 'amount' => -1]))->toThrow(ValidationException::class);
    $offer = Offer::query()->create(['team_id' => 1, 'subject' => 'Offer', 'amount' => 100, 'status' => 'draft']);
    app(DeleteOffer::class)->handle($offer, 1);
    expect(Offer::withTrashed()->find($offer->id)->deleted_at)->not->toBeNull();
});
it('records guarded decisions, proof, and an auditable offer timeline', function (): void {
    $offer = app(CreateOffer::class)->handle(1, 5, ['subject' => 'Purchase offer', 'amount' => 375000, 'property_id' => 42]);
    app(RecordOfferProof::class)->handle($offer, 1, 5, ['mortgage' => 'agreement-in-principle']);
    app(TransitionOffer::class)->handle($offer, 1, 5, OfferStatus::Submitted);
    $offer = app(TransitionOffer::class)->handle($offer->fresh(), 1, 5, OfferStatus::Accepted, ['note' => 'Approved by seller']);
    expect($offer->status)->toBe(OfferStatus::Accepted)->and($offer->proof['mortgage'])->toBe('agreement-in-principle')->and($offer->events()->count())->toBe(4);
});
it('prevents two accepted offers for the same property and team', function (): void {
    $first = app(CreateOffer::class)->handle(1, 5, ['subject' => 'First', 'amount' => 100, 'property_id' => 9]);
    $second = app(CreateOffer::class)->handle(1, 6, ['subject' => 'Second', 'amount' => 110, 'property_id' => 9]);
    $transition = app(TransitionOffer::class);
    $transition->handle($first, 1, 5, OfferStatus::Submitted);
    $transition->handle($first->fresh(), 1, 5, OfferStatus::Accepted);
    $transition->handle($second, 1, 6, OfferStatus::Submitted);
    expect(fn () => $transition->handle($second->fresh(), 1, 6, OfferStatus::Accepted))->toThrow(ValidationException::class);
});

it('audits amount changes but leaves title edits out of the amount history', function (): void {
    $team = Team::factory()->create();
    $offer = app(CreateOffer::class)->handle($team->id, $team->user_id, [
        'subject' => 'Purchase offer',
        'amount' => 100000,
    ]);
    $update = app(UpdateOffer::class);
    $amountHistory = DB::table('activity_log')->where('event', 'offer.amount_changed')->where('subject_id', $offer->id);

    $edited = $update->handle($offer, $team->id, $team->user_id, ['subject' => 'Revised offer']);
    expect($edited->subject)->toBe('Revised offer')
        ->and($amountHistory->exists())->toBeFalse();

    $edited = $update->handle($edited, $team->id, $team->user_id, ['amount' => 110000]);
    $entry = $amountHistory->sole();

    expect($edited->amount)->toBe('110000.00')
        ->and($edited->team->is($team))->toBeTrue()
        ->and($edited->events()->firstOrFail()->offer->is($edited))->toBeTrue()
        ->and(json_decode($entry->attribute_changes, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'before' => ['amount' => '100000.00'],
            'after' => ['amount' => '110000.00'],
        ])
        ->and((string) $entry->tenant_ref)->toBe((string) $team->id);
});

it('rejects blank subjects, unauthorized edits, and invalid offer transitions', function (): void {
    $create = app(CreateOffer::class);
    expect(fn () => $create->handle(1, 5, ['subject' => ' ', 'amount' => 100]))
        ->toThrow(ValidationException::class, 'An offer subject is required.');

    $offer = $create->handle(1, 5, ['subject' => 'Offer', 'amount' => 100]);
    $update = app(UpdateOffer::class);
    $transition = app(TransitionOffer::class);

    expect(fn () => $update->handle($offer, 1, 5, ['subject' => ' ']))
        ->toThrow(ValidationException::class, 'An offer subject is required.')
        ->and(fn () => $update->handle($offer, 2, 5, ['amount' => 200]))
        ->toThrow(NotFoundHttpException::class)
        ->and(fn () => $transition->handle($offer, 1, 5, OfferStatus::Accepted))
        ->toThrow(ValidationException::class, 'An offer cannot transition from draft to accepted.');

    $withdrawn = $transition->handle($offer, 1, 5, OfferStatus::Withdrawn);

    expect($withdrawn->responded_at)->not->toBeNull()
        ->and(fn () => $transition->handle($withdrawn, 1, 5, OfferStatus::Submitted))
        ->toThrow(ValidationException::class, 'An offer cannot transition from withdrawn to submitted.');
});

it('requires the corresponding permission for each offer policy action', function (): void {
    $user = User::factory()->make();
    $offer = new Offer();
    Gate::policy(Offer::class, OfferPolicy::class);
    $gate = Gate::forUser($user);

    $subjects = [
        'viewAny' => Offer::class,
        'view' => $offer,
        'create' => Offer::class,
        'update' => $offer,
        'delete' => $offer,
        'deleteAny' => Offer::class,
        'restore' => $offer,
        'restoreAny' => Offer::class,
        'forceDelete' => $offer,
        'forceDeleteAny' => Offer::class,
        'replicate' => $offer,
        'reorder' => Offer::class,
    ];

    foreach ($subjects as $action => $subject) {
        expect($gate->allows($action, $subject))->toBeFalse();
        Gate::define(ucfirst($action).':Offer', fn (User $candidate): bool => $candidate === $user);
        expect($gate->allows($action, $subject))->toBeTrue();
    }
});

it('can roll back and replay the offer migrations', function (): void {
    $directory = base_path('modules/real-estate-offers/database/migrations');
    $offers = require $directory.'/2026_08_23_000010_create_real_estate_offers_table.php';
    $workflow = require $directory.'/2026_08_24_000004_add_offer_workflow_fields.php';

    $workflow->down();
    expect(Schema::hasTable('real_estate_offer_events'))->toBeFalse()
        ->and(Schema::hasColumn('real_estate_offers', 'currency'))->toBeFalse();
    $offers->down();
    expect(Schema::hasTable('real_estate_offers'))->toBeFalse();

    $offers->up();
    $workflow->up();
    expect(Schema::hasTable('real_estate_offer_events'))->toBeTrue()
        ->and(Schema::hasColumn('real_estate_offers', 'currency'))->toBeTrue();
});
