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
use Liberu\RealEstate\Matching\Application\CalculateMatchScore;
use Liberu\RealEstate\Matching\Application\CreateMatchProfile;
use Liberu\RealEstate\Matching\Application\DeleteMatchProfile;
use Liberu\RealEstate\Matching\Application\RankPropertyRecommendations;
use Liberu\RealEstate\Matching\Application\UpdateMatchProfile;
use Liberu\RealEstate\Matching\Application\UpdateMatchProfileSection;
use Liberu\RealEstate\Matching\Domain\MatchProfileSection;
use Liberu\RealEstate\Matching\Models\MatchProfile;
use Liberu\RealEstate\Matching\Policies\MatchProfilePolicy;
use Liberu\RealEstate\MatchingLivewire\Components\PropertyRecommendations;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);
it('creates a bounded matching profile', function (): void {
    $profile = app(CreateMatchProfile::class)->handle(1, 5, ['subject' => 'First-time buyer', 'score' => 125, 'affordability' => ['max' => 400000]]);
    expect($profile->score)->toBe(100)->and($profile->affordability['max'])->toBe(400000);
});
it('rejects empty subjects and archives a profile for its team', function (): void {
    expect(fn () => app(CreateMatchProfile::class)->handle(1, 5, ['subject' => '']))->toThrow(ValidationException::class);
    $profile = MatchProfile::query()->create(['team_id' => 1, 'subject' => 'Profile']);
    app(DeleteMatchProfile::class)->handle($profile, 1);
    expect(MatchProfile::withTrashed()->find($profile->id)->deleted_at)->not->toBeNull();
});

it('updates each matching concern through an explicit section boundary', function (): void {
    $profile = MatchProfile::query()->create(['team_id' => 1, 'subject' => 'Profile']);

    app(UpdateMatchProfileSection::class)->handle($profile, 1, MatchProfileSection::Requirements, ['min_bedrooms' => 3]);
    app(UpdateMatchProfileSection::class)->handle($profile, 1, MatchProfileSection::Scoring, ['score' => 87]);

    $profile->refresh();
    expect($profile->requirements)->toBe(['min_bedrooms' => 3])->and($profile->score)->toBe(87);
    expect(fn () => app(UpdateMatchProfileSection::class)->handle($profile, 1, MatchProfileSection::Scoring, ['score' => 101]))->toThrow(ValidationException::class);
});

it('reproduces the legacy weighted property match score', function (): void {
    $score = app(CalculateMatchScore::class)->handle(
        ['min_price' => 300000, 'max_price' => 400000, 'min_bedrooms' => 3, 'property_type' => 'house', 'location' => 'SW1', 'required_features' => ['garden']],
        ['price' => 350000, 'bedrooms' => 3, 'property_type' => 'house', 'location' => 'SW1A', 'features' => ['garden'], 'transit_score' => 80],
    );

    expect($score['match_score'])->toBe(90.0)->and($score['price_match'])->toBe(100.0);
});

it('ranks bounded property recommendations and excludes seen properties', function (): void {
    $recommendations = app(RankPropertyRecommendations::class)->handle(
        ['min_price' => 300000, 'max_price' => 400000, 'min_bedrooms' => 3, 'property_type' => 'house'],
        [
            ['id' => 1, 'title' => 'Strong match', 'price' => 350000, 'bedrooms' => 3, 'property_type' => 'house'],
            ['id' => 2, 'title' => 'Weak match', 'price' => 900000, 'bedrooms' => 1, 'property_type' => 'apartment'],
            ['id' => 3, 'title' => 'Seen match', 'price' => 350000, 'bedrooms' => 3, 'property_type' => 'house'],
        ],
        2,
        [3],
    );

    expect($recommendations)->toHaveCount(2)
        ->and($recommendations[0]['id'])->toBe(1)
        ->and($recommendations[0]['recommendation_score'])->toBeGreaterThan($recommendations[1]['recommendation_score'])
        ->and($recommendations[1]['id'])->not->toBe(3)
        ->and($recommendations[0]['match_breakdown'])->toHaveKey('match_score');
});

it('scores partial matches across price, location, size and amenities', function (): void {
    $calculate = app(CalculateMatchScore::class);
    $score = $calculate->handle([
        'max_price' => 200000,
        'location' => 'Central',
        'postal_codes' => ['SW1'],
        'min_bedrooms' => 2,
        'max_area' => 1000,
        'property_type' => 'house',
        'required_features' => ['garden', 'garage'],
        'required_schools' => ['Oak', 'Elm'],
        'min_transit_score' => 80,
    ], [
        'price' => 250000,
        'location' => 'central district',
        'postal_code' => 'SW2',
        'bedrooms' => 3,
        'area_sqft' => 1200,
        'property_type' => 'house',
        'features' => ['garden'],
        'schools' => ['OAK'],
        'transit_score' => 40,
    ]);

    expect($score)->toBe([
        'price_match' => 75.0,
        'location_match' => 50.0,
        'size_match' => 50.0,
        'features_match' => 50.0,
        'type_match' => 100.0,
        'school_match' => 50.0,
        'transport_match' => 50.0,
        'match_score' => 60.0,
    ])->and(fn () => $calculate->handle([], []))
        ->toThrow(ValidationException::class, 'A property price is required for matching.');
});

it('breaks recommendation ties by property ID and validates result limits', function (): void {
    $rank = app(RankPropertyRecommendations::class);
    $properties = [['id' => 3, 'price' => 100000], ['id' => 2, 'price' => 100000], ['id' => 1, 'price' => 100000]];

    expect(array_column($rank->handle([], $properties, 1, ['1']), 'id'))->toBe([2])
        ->and(fn () => $rank->handle([], $properties, 0))->toThrow(ValidationException::class)
        ->and(fn () => $rank->handle([], $properties, 101))->toThrow(ValidationException::class);
});

it('guards matching profile edits by team and validates score changes', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $profile = app(CreateMatchProfile::class)->handle($team->id, $team->user_id, ['subject' => 'Buyer']);
    $update = app(UpdateMatchProfile::class);
    $section = app(UpdateMatchProfileSection::class);

    expect(fn () => $update->handle($profile, $otherTeam->id, ['subject' => 'Outside team']))
        ->toThrow(NotFoundHttpException::class)
        ->and(fn () => $update->handle($profile, $team->id, ['subject' => ' ']))
        ->toThrow(ValidationException::class, 'A matching profile subject is required.')
        ->and(fn () => $section->handle($profile, $otherTeam->id, MatchProfileSection::Scoring, ['score' => 80]))
        ->toThrow(ValidationException::class, 'The matching profile does not belong to this team.')
        ->and(fn () => $section->handle($profile, $team->id, MatchProfileSection::Scoring, ['score' => 'high']))
        ->toThrow(ValidationException::class, 'A numeric score is required.');

    $updated = $update->handle($profile, (string) $team->id, ['subject' => 'Qualified buyer', 'score' => -10]);
    expect($updated->subject)->toBe('Qualified buyer')
        ->and($updated->score)->toBe(0)
        ->and($updated->team->is($team))->toBeTrue()
        ->and(MatchProfile::query()->forTeam($team->id)->sole()->is($updated))->toBeTrue()
        ->and(MatchProfile::query()->forTeam($otherTeam->id)->exists())->toBeFalse();
});

it('uses the corresponding permission for each matching profile policy action', function (): void {
    $policy = new MatchProfilePolicy();
    $profile = new MatchProfile();
    $allowed = User::factory()->make();
    $denied = User::factory()->make();

    foreach ([
        'viewAny' => false, 'view' => true, 'create' => false, 'update' => true,
        'delete' => true, 'deleteAny' => false, 'restore' => true, 'forceDelete' => true,
        'forceDeleteAny' => false, 'restoreAny' => false, 'replicate' => true, 'reorder' => false,
    ] as $action => $requiresProfile) {
        $arguments = $requiresProfile ? [$profile] : [];
        expect($policy->{$action}($allowed, ...$arguments))->toBeFalse();
        Gate::define(ucfirst($action).':MatchProfile', fn (User $user): bool => $user === $allowed);

        expect($policy->{$action}($allowed, ...$arguments))->toBeTrue()
            ->and($policy->{$action}($denied, ...$arguments))->toBeFalse();
    }
});

it('rolls back and replays the matching profile schema', function (): void {
    $migration = require base_path('modules/real-estate-matching/database/migrations/2026_08_23_000008_create_real_estate_match_profiles_table.php');
    $table = 'real_estate_match_profiles';
    expect(Schema::hasTable($table))->toBeTrue();

    $migration->down();
    expect(Schema::hasTable($table))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumns($table, ['team_id', 'requirements', 'score', 'deleted_at']))->toBeTrue();
});

it('serves recommendations through the authenticated API and Livewire adapters', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    $properties = [
        ['id' => 1, 'title' => 'Recommended home', 'price' => 350000, 'bedrooms' => 3, 'property_type' => 'house'],
        ['id' => 2, 'title' => 'Other home', 'price' => 900000, 'bedrooms' => 1, 'property_type' => 'apartment'],
    ];
    $criteria = ['min_price' => 300000, 'max_price' => 400000, 'min_bedrooms' => 3, 'property_type' => 'house'];
    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/real-estate/matching/recommend-properties', compact('criteria', 'properties'))
        ->assertOk()
        ->assertJsonPath('data.recommendations.0.id', 1)
        ->assertJsonPath('data.recommendations.0.recommendation_score', 72.5);

    $this->actingAs($user);
    Livewire::component('test-property-recommendations', PropertyRecommendations::class);

    Livewire::test('test-property-recommendations', ['criteria' => $criteria, 'candidates' => $properties])
        ->assertSee('Recommended properties')
        ->assertSee('Recommended home')
        ->assertSee('Match score');
});

it('manages matching profiles and individual sections through the team API', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    $outside = app(CreateMatchProfile::class)->handle(20, $user->id, ['subject' => 'Other team profile']);
    $endpoint = '/api/v1/real-estate/matching';
    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));

    $this->getJson($endpoint)->assertUnauthorized();
    $this->actingAs($user, 'sanctum');
    $id = $this->postJson($endpoint, ['subject' => 'New buyer', 'score' => 60])
        ->assertCreated()->assertJsonPath('data.score', 60)->json('data.id');
    $url = "$endpoint/$id";

    $this->getJson("$endpoint?page_size=1")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
    $this->getJson("$endpoint/{$outside->id}")->assertNotFound();
    $this->patchJson("$endpoint/{$outside->id}/scoring", ['score' => 80])->assertNotFound();
    $this->getJson($url)->assertOk()->assertJsonPath('data.subject', 'New buyer');
    $this->patchJson($url, ['subject' => 'Qualified buyer'])->assertOk()->assertJsonPath('data.subject', 'Qualified buyer');
    $this->patchJson("$url/requirements", ['value' => ['min_bedrooms' => 3]])
        ->assertOk()->assertJsonPath('data.requirements.min_bedrooms', 3);
    $this->patchJson("$url/scoring", ['score' => 101])->assertUnprocessable()->assertJsonValidationErrors('score');
    $this->patchJson("$url/scoring", ['score' => 85])->assertOk()->assertJsonPath('data.score', 85);
    $this->postJson("$endpoint/calculate-score", ['criteria' => ['max_price' => 100000], 'property' => ['price' => 125000]])
        ->assertOk()->assertJsonPath('data.price_match', 75);
    $this->deleteJson($url)->assertNoContent();
    $this->assertSoftDeleted('real_estate_match_profiles', ['id' => $id]);
});

it('updates matching sections from the active team Livewire list', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    $create = app(CreateMatchProfile::class);
    $profile = $create->handle(10, $user->id, ['subject' => 'First buyer']);
    $create->handle(10, $user->id, ['subject' => 'Second buyer']);
    $outside = $create->handle(20, $user->id, ['subject' => 'Outside buyer']);
    $this->actingAs($user);
    $component = 'module-real-estate-matching::match-profile-list';

    Livewire::test($component)
        ->assertSee('First buyer')
        ->assertSee('Second buyer')
        ->assertDontSee('Outside buyer')
        ->set('search', 'First')
        ->assertDontSee('Second buyer')
        ->call('updateSection', $profile->id, 'scoring', ['score' => 90])
        ->assertSee('First buyer (score 90)')
        ->call('calculateScore', ['max_price' => 100000], ['price' => 100000])
        ->assertReturned(fn (array $score): bool => (float) $score['price_match'] === 100.0);

    Livewire::test($component)->call('updateSection', $outside->id, 'scoring', ['score' => 80])->assertNotFound();
    expect($profile->fresh()->score)->toBe(90)
        ->and($outside->fresh()->score)->toBe(0);
});

it('shows an empty matching list and rejects updates without an active team', function (): void {
    Livewire::test('module-real-estate-matching::match-profile-list')
        ->assertSee('No matching profiles found.')
        ->call('updateSection', 1, 'scoring', ['score' => 90])
        ->assertForbidden();
});

it('loads additional recommendations and clears stale results after a validation error', function (): void {
    $properties = array_map(fn (int $id): array => ['id' => $id, 'title' => "Property $id", 'price' => 100000], range(1, 9));

    Livewire::test('module-real-estate-matching::property-recommendations', [
        'candidates' => $properties, 'excludedIds' => [1],
    ])
        ->assertCount('recommendations', 6)
        ->call('loadMore')
        ->assertCount('recommendations', 8)
        ->set('limit', 0)
        ->call('updateRecommendations')
        ->assertSet('recommendations', [])
        ->assertSet('error', 'Recommendation limits must be between 1 and 100.')
        ->call('loadMore')
        ->assertCount('recommendations', 6)
        ->assertSet('error', null);
});
