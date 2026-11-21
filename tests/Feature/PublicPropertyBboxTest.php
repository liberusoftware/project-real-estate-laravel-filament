<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Properties\Application\CreateProperty;
use Liberu\RealEstate\Properties\Domain\PropertyStatus;
use Liberu\RealEstate\Properties\Models\Property;

uses(RefreshDatabase::class);

it('filters the public property list to a map viewport bounding box', function (): void {
    $team = Team::factory()->create();

    $inside = app(CreateProperty::class)->handle($team->id, $team->user_id, [
        'address' => 'Inside the viewport', 'latitude' => 38.56, 'longitude' => 68.78,
    ]);
    $inside->forceFill(['status' => PropertyStatus::Published])->save();

    $outside = app(CreateProperty::class)->handle($team->id, $team->user_id, [
        'address' => 'Outside the viewport', 'latitude' => 40.0, 'longitude' => 70.0,
    ]);
    $outside->forceFill(['status' => PropertyStatus::Published])->save();

    $response = $this->getJson('/api/v1/public/properties?bbox=68.7,38.5,68.9,38.6');

    $response->assertSuccessful();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($inside->id)->not->toContain($outside->id);
});

it('rejects invalid map bounds', function (string $bounds): void {
    Team::factory()->create();

    $this->getJson('/api/v1/public/properties?bbox='.$bounds)->assertUnprocessable();
})->with([
    'malformed' => 'not-a-bbox',
    'invalid latitude' => '-10,-91,10,10',
    'invalid longitude' => '-181,-10,10,10',
    'reversed latitude' => '-10,20,10,10',
]);

it('scopes properties to a rectangle without loading unrelated rows', function (): void {
    $team = Team::factory()->create();
    $near = app(CreateProperty::class)->handle($team->id, $team->user_id, [
        'address' => 'Near', 'latitude' => 38.56, 'longitude' => 68.78,
    ]);
    $far = app(CreateProperty::class)->handle($team->id, $team->user_id, [
        'address' => 'Far', 'latitude' => -10.0, 'longitude' => -10.0,
    ]);

    $ids = Property::query()->withinBounds(38.5, 68.7, 38.6, 68.9)->pluck('id');

    expect($ids)->toContain($near->id)->not->toContain($far->id);
});

it('applies the public catalog filters and supported sort orders', function (): void {
    $team = Team::factory()->create();

    $affordable = app(CreateProperty::class)->handle($team->id, $team->user_id, [
        'address' => 'Affordable family home', 'price' => 200000, 'bedrooms' => 3,
        'area_sqft' => 1200, 'property_type' => 'house',
    ]);
    $affordable->forceFill(['status' => PropertyStatus::Available])->save();

    $premium = app(CreateProperty::class)->handle($team->id, $team->user_id, [
        'address' => 'Premium apartment', 'price' => 500000, 'bedrooms' => 5,
        'area_sqft' => 900, 'property_type' => 'apartment',
    ]);
    $premium->forceFill(['status' => PropertyStatus::Published])->save();

    $draft = app(CreateProperty::class)->handle($team->id, $team->user_id, [
        'address' => 'Hidden draft', 'price' => 100000, 'bedrooms' => 2,
    ]);

    $this->getJson('/api/v1/public/properties?type=house&deal_type=sale&min_price=150000&max_price=250000&rooms=3,invalid&page_size=1')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $affordable->id);

    $this->getJson('/api/v1/public/properties?sort=price_asc')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $affordable->id);

    $this->getJson('/api/v1/public/properties?sort=price_desc&rooms=5')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $premium->id);

    $this->getJson('/api/v1/public/properties?sort=area_desc')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $affordable->id)
        ->assertJsonMissing(['id' => $draft->id]);
});

it('returns an empty catalog without a team and hides private properties from the detail endpoint', function (): void {
    $this->getJson('/api/v1/public/properties')->assertSuccessful()->assertJsonCount(0, 'data');

    $team = Team::factory()->create();
    $property = app(CreateProperty::class)->handle($team->id, $team->user_id, ['address' => 'Private draft']);

    $this->getJson('/api/v1/public/properties/'.$property->id)->assertNotFound();

    $property->forceFill(['status' => PropertyStatus::Published])->save();
    $this->getJson('/api/v1/public/properties/'.$property->id)->assertSuccessful();

    expect($property->fresh()->views_count)->toBe(1);
});
