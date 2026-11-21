<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Core\Application\CreateBranch;
use Liberu\RealEstate\Properties\Application\CreateProperty;
use Liberu\RealEstate\Properties\Application\TogglePropertyFavorite;
use Liberu\RealEstate\Properties\Domain\PropertyStatus;

it('filters the authenticated property index by tenant and requested listing attributes', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $branch = app(CreateBranch::class)->handle($team->id, ['name' => 'Central Office', 'code' => 'CENTRAL']);

    $matching = app(CreateProperty::class)->handle($team->id, $user->id, [
        'address' => '1 High Street',
        'title' => 'Featured family home',
        'price' => 450000,
        'bedrooms' => 3,
        'bathrooms' => 2,
        'area_sqft' => 1400,
        'year_built' => 2001,
        'property_type' => 'house',
        'country' => 'GB',
        'postal_code' => 'SW1A 1AA',
        'energy_rating' => 'B',
        'energy_score' => 82,
        'walkability_score' => 75,
        'transit_score' => 70,
        'bike_score' => 65,
        'is_featured' => true,
        'features' => ['garden', 'fireplace'],
        'branch_id' => $branch->id,
        'last_synced_at' => now()->subDay(),
        'latitude' => 51.5,
        'longitude' => -0.12,
    ]);
    app(TogglePropertyFavorite::class)->handle($team->id, $user->id, $matching->id);

    app(CreateProperty::class)->handle($team->id, $user->id, [
        'address' => '2 Low Street', 'title' => 'Small flat', 'price' => 250000,
        'bedrooms' => 1, 'bathrooms' => 1, 'area_sqft' => 600, 'year_built' => 1990,
        'property_type' => 'apartment', 'country' => 'GB', 'energy_rating' => 'D',
        'energy_score' => 55, 'last_synced_at' => now()->addDay(),
    ]);
    app(CreateProperty::class)->handle($otherTeam->id, $user->id, [
        'address' => 'Private High Street', 'title' => 'Other tenant home', 'price' => 450000,
        'bedrooms' => 3, 'bathrooms' => 2, 'area_sqft' => 1400, 'year_built' => 2001,
        'property_type' => 'house', 'country' => 'GB', 'energy_rating' => 'B',
        'energy_score' => 90, 'is_featured' => true,
    ]);

    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/real-estate/properties?'.http_build_query([
            'search' => 'family',
            'postal_code' => 'SW1A',
            'needs_syncing' => true,
            'amenities' => ['garden'],
            'latitude' => 51.5,
            'longitude' => -0.12,
            'radius' => 10,
            'branch_id' => $branch->id,
            'min_price' => 400000,
            'max_price' => 500000,
            'min_bedrooms' => 3,
            'max_bedrooms' => 4,
            'min_bathrooms' => 2,
            'max_bathrooms' => 3,
            'min_area' => 1000,
            'max_area' => 2000,
            'min_year_built' => 2000,
            'max_year_built' => 2010,
            'property_type' => 'house',
            'country' => 'gb',
            'energy_rating' => 'b',
            'status' => PropertyStatus::Draft->value,
            'featured' => true,
            'favorites_only' => true,
            'min_energy_score' => 80,
            'min_walkability_score' => 70,
            'min_transit_score' => 65,
            'min_bike_score' => 60,
            'sort_by' => 'price',
            'sort_direction' => 'asc',
            'page_size' => 1,
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $matching->id);
});

it('requires a current team to view the authenticated property index', function (): void {
    $user = User::factory()->create(['current_team_id' => null]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/real-estate/properties')
        ->assertForbidden();
});

it('creates and updates a property through the validated API contract', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $payload = [
        'address' => '1 High Street', 'deal_type' => 'sale', 'title' => 'A family home',
        'description' => 'A bright home near the park.', 'description_generated_at' => now()->toDateTimeString(),
        'internal_notes' => 'Call after 5pm.', 'price' => 450000, 'currency' => 'GBP',
        'bedrooms' => 3, 'bathrooms' => 2, 'reception_rooms' => 2, 'parking' => ['driveway'],
        'gardens' => ['rear'], 'area_sqft' => 1400, 'year_built' => 2001,
        'structured_address' => ['city' => 'London'], 'latitude' => 51.5, 'longitude' => -0.12,
        'postal_code' => 'SW1A 1AA', 'country' => 'GB', 'tenure' => 'freehold',
        'lease_years_remaining' => 99, 'service_charge' => 1200, 'ground_rent' => 250,
        'energy_rating' => 'B', 'council_tax_band' => 'D', 'energy_score' => 82,
        'walkability_score' => 75, 'walkability_description' => 'Walkable to shops.',
        'transit_score' => 70, 'transit_description' => 'Near a rail station.',
        'bike_score' => 65, 'bike_description' => 'Protected cycle route nearby.',
        'walkability_updated_at' => now()->toDateTimeString(), 'epc' => ['rating' => 'B'],
        'virtual_tour_url' => 'https://example.test/tour', 'virtual_tour_provider' => 'Example',
        'model_3d_url' => 'https://example.test/model.glb', 'floor_plan_data' => ['rooms' => 5],
        'floor_plan_image' => 'https://example.test/plan.png', 'list_date' => '2026-01-01',
        'sold_date' => '2026-02-01', 'last_synced_at' => now()->toDateTimeString(),
        'is_featured' => true, 'has_generator' => true, 'has_wifi' => true, 'has_parking' => true,
        'mountain_view' => 'North ridge', 'altitude' => 300, 'water_source' => 'well',
        'max_guests' => 6, 'live_tour_available' => true, 'ar_tour_enabled' => true,
        'ar_tour_settings' => ['auto_rotate' => false], 'ar_placement_guide' => 'Place in the garden.',
        'ar_model_scale' => 1.5, 'holographic_tour_url' => 'https://example.test/hologram',
        'holographic_provider' => 'Example holograms', 'holographic_metadata' => ['format' => 'glb'],
        'holographic_enabled' => true, 'energy_rating_date' => '2026-01-01', 'insurance_policy_id' => 42,
        'insurance_coverage_amount' => 500000, 'insurance_premium' => 850,
        'insurance_expiry_date' => '2027-01-01', 'jupix_id' => 'JX-42',
        'property_type' => 'house', 'characteristics' => ['period'],
        'utilities' => ['electricity'], 'features' => ['garden'],
    ];

    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));
    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/real-estate/properties', $payload)
        ->assertCreated()
        ->assertJsonPath('data.address', '1 High Street');

    $propertyId = $response->json('data.id');
    $this->putJson('/api/v1/real-estate/properties/'.$propertyId, [
        ...$payload,
        'address' => '2 High Street',
        'title' => 'An updated family home',
        'price' => 475000,
    ])->assertOk()
        ->assertJsonPath('data.address', '2 High Street')
        ->assertJsonPath('data.title', 'An updated family home');
});
