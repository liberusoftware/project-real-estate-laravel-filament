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
use Liberu\RealEstate\Listings\Application\CreateListing;
use Liberu\RealEstate\Listings\Application\DeleteListing;
use Liberu\RealEstate\Listings\Application\TransitionListing;
use Liberu\RealEstate\Listings\Application\UpdateListing;
use Liberu\RealEstate\Listings\Application\UpdateListingSection;
use Liberu\RealEstate\Listings\Domain\ListingSection;
use Liberu\RealEstate\Listings\Domain\ListingStatus;
use Liberu\RealEstate\Listings\Models\Listing;
use Liberu\RealEstate\Listings\Policies\ListingPolicy;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);
it('creates a draft listing with price and channel content', function (): void {
    $listing = app(CreateListing::class)->handle(1, 5, ['title' => 'Market listing', 'price' => 425000, 'channel_content' => ['description' => 'A home']]);
    expect($listing->status->value)->toBe('draft')->and($listing->price)->toBe('425000.00');
});
it('rejects empty titles and archives a listing for its team', function (): void {
    expect(fn () => app(CreateListing::class)->handle(1, 5, ['title' => '']))->toThrow(ValidationException::class);
    $listing = Listing::query()->create(['team_id' => 1, 'title' => 'Listing', 'status' => 'draft']);
    app(DeleteListing::class)->handle($listing, 1);
    expect(Listing::withTrashed()->find($listing->id)->deleted_at)->not->toBeNull();
});

it('requires explicit publication lifecycle transitions', function (): void {
    $listing = app(CreateListing::class)->handle(1, 5, ['title' => 'Family home']);
    $transition = app(TransitionListing::class);

    $listing = $transition->handle($listing, 1, ListingStatus::Ready);
    $listing = $transition->handle($listing, 1, ListingStatus::Published);
    $listing = $transition->handle($listing, 1, ListingStatus::Suspended);
    $listing = $transition->handle($listing, 1, ListingStatus::Withdrawn);

    expect($listing->status)->toBe(ListingStatus::Withdrawn)
        ->and($listing->published_at)->not->toBeNull()
        ->and(fn () => $transition->handle($listing, 1, ListingStatus::Published))
        ->toThrow(ValidationException::class);
});

it('updates channel and reconciliation sections independently', function (): void {
    $listing = app(CreateListing::class)->handle(1, 5, ['title' => 'Family home']);

    app(UpdateListingSection::class)->handle($listing, 1, ListingSection::ChannelContent, ['description' => 'A bright home']);

    expect($listing->refresh()->channel_content['description'])->toBe('A bright home');
});

it('manages publication through the authenticated listing API', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    $outside = app(CreateListing::class)->handle(20, $user->id, ['title' => 'Outside home']);
    $endpoint = '/api/v1/real-estate/listings';
    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));

    $this->getJson($endpoint)->assertUnauthorized();
    $this->actingAs($user, 'sanctum');
    $id = $this->postJson($endpoint, ['title' => 'New home', 'price' => 400000])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    $url = "$endpoint/$id";

    $this->getJson("$endpoint?page_size=1")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
    $this->getJson("$endpoint/{$outside->id}")->assertNotFound();
    $this->postJson("$endpoint/{$outside->id}/transition/ready")->assertNotFound();
    $this->getJson($url)->assertOk()->assertJsonPath('data.price', '400000.00');
    $this->patchJson($url, ['title' => 'Updated home'])->assertOk()->assertJsonPath('data.title', 'Updated home');
    $this->patchJson("$url/channel_content", ['value' => ['description' => 'A bright home']])
        ->assertOk()->assertJsonPath('data.channel_content.description', 'A bright home');
    $this->postJson("$url/transition/unknown")->assertNotFound();
    $this->postJson("$url/transition/published")->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson("$url/transition/ready")->assertOk()->assertJsonPath('data.status', 'ready');
    $this->postJson("$url/transition/published", [
        'channel_content' => ['description' => 'Ready for buyers'],
        'publication_rules' => ['approved' => true],
        'portal_feeds' => ['enabled' => true],
        'reconciliation' => ['verified' => true],
    ])->assertOk()->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.channel_content.description', 'Ready for buyers')
        ->assertJsonPath('data.publication_rules.approved', true)
        ->assertJsonPath('data.portal_feeds.enabled', true)
        ->assertJsonPath('data.reconciliation.verified', true);
    $this->deleteJson($url)->assertNoContent();
    $this->assertSoftDeleted('real_estate_listings', ['id' => $id]);
});

it('searches and progresses only active team listings through Livewire', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    $create = app(CreateListing::class);
    $listing = $create->handle(10, $user->id, ['title' => 'First home']);
    $create->handle(10, $user->id, ['title' => 'Second home']);
    $outside = $create->handle(20, $user->id, ['title' => 'Outside home']);
    $component = 'module-real-estate-listings::listing-list';

    Livewire::test($component)->assertSee('No listings found.')->call('markReady', $listing->id)->assertForbidden();
    $this->actingAs($user);
    Livewire::test($component)
        ->assertSee('First home')
        ->assertSee('Second home')
        ->assertDontSee('Outside home')
        ->set('search', 'First')
        ->assertDontSee('Second home')
        ->call('markReady', $outside->id)
        ->assertSet('error', fn (?string $error): bool => filled($error))
        ->call('updateSection', $listing->id, 'reconciliation', ['verified' => true])
        ->call('markReady', $listing->id)
        ->assertSet('error', null)
        ->call('publish', $listing->id)
        ->call('suspend', $listing->id)
        ->call('withdraw', $listing->id)
        ->assertSee('First home (withdrawn)');

    expect($listing->fresh()->reconciliation)->toBe(['verified' => true])
        ->and($outside->fresh()->status)->toBe(ListingStatus::Draft);
});

it('rejects invalid listing edits and requires a title before publication', function (): void {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $listing = app(CreateListing::class)->handle($team->id, $team->user_id, ['title' => 'Original home']);
    $update = app(UpdateListing::class);
    $transition = app(TransitionListing::class);

    expect(fn () => $update->handle($listing, $other->id, ['title' => 'Outside edit']))
        ->toThrow(NotFoundHttpException::class)
        ->and(fn () => $update->handle($listing, $team->id, ['title' => ' ']))
        ->toThrow(ValidationException::class, 'A listing title is required.')
        ->and(fn () => $update->handle($listing, $team->id, ['status' => 'published']))
        ->toThrow(ValidationException::class, 'Listing lifecycle changes must use the transition action.')
        ->and(fn () => app(UpdateListingSection::class)->handle($listing, $other->id, ListingSection::ChannelContent, ['description' => 'Outside edit']))
        ->toThrow(ValidationException::class, 'The listing does not belong to this team.')
        ->and($listing->refresh()->team->is($team))->toBeTrue();

    $listing = $transition->handle($listing, $team->id, ListingStatus::Ready);
    $listing->update(['title' => '']);
    expect(fn () => $transition->handle($listing, $team->id, ListingStatus::Published))
        ->toThrow(ValidationException::class, 'A listing title is required before publication.')
        ->and($listing->refresh()->status)->toBe(ListingStatus::Ready);
});

it('uses the corresponding permission for each listing policy action', function (): void {
    $policy = new ListingPolicy();
    $listing = new Listing();
    $allowed = User::factory()->make();
    $denied = User::factory()->make();

    foreach ([
        'viewAny' => false, 'view' => true, 'create' => false, 'update' => true,
        'delete' => true, 'deleteAny' => false, 'restore' => true, 'forceDelete' => true,
        'forceDeleteAny' => false, 'restoreAny' => false, 'replicate' => true, 'reorder' => false,
    ] as $action => $requiresListing) {
        $arguments = $requiresListing ? [$listing] : [];
        expect($policy->{$action}($allowed, ...$arguments))->toBeFalse();
        Gate::define(ucfirst($action).':Listing', fn (User $user): bool => $user === $allowed);

        expect($policy->{$action}($allowed, ...$arguments))->toBeTrue()
            ->and($policy->{$action}($denied, ...$arguments))->toBeFalse();
    }
});

it('rolls back and replays the listing table', function (): void {
    $migration = require base_path('modules/real-estate-listings/database/migrations/2026_08_23_000007_create_real_estate_listings_table.php');
    $table = 'real_estate_listings';
    expect(Schema::hasTable($table))->toBeTrue();

    $migration->down();
    expect(Schema::hasTable($table))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumns($table, ['team_id', 'status', 'channel_content', 'published_at']))->toBeTrue();
});
