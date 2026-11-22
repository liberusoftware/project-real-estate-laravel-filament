<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Core\Models\Territory;
use Liberu\RealEstate\Properties\Application\CheckPriceAlerts;
use Liberu\RealEstate\Properties\Application\CreatePriceAlert;
use Liberu\RealEstate\Properties\Application\CreateProperty;
use Liberu\RealEstate\Properties\Application\EstimatePropertyTax;
use Liberu\RealEstate\Properties\Application\FetchWalkabilityScores;
use Liberu\RealEstate\Properties\Application\GeneratePropertyDescription;
use Liberu\RealEstate\Properties\Application\RecordPropertyKey;
use Liberu\RealEstate\Properties\Application\SavePropertySearch;
use Liberu\RealEstate\Properties\Application\SubmitNeighborhoodReview;
use Liberu\RealEstate\Properties\Application\SubmitPropertyReview;
use Liberu\RealEstate\Properties\Application\UpdatePriceAlert;
use Liberu\RealEstate\Properties\Application\UpdateProperty;
use Liberu\RealEstate\Properties\Application\UpsertPropertyUnit;
use Liberu\RealEstate\Properties\Domain\DealType;
use Liberu\RealEstate\Properties\Domain\Events\PriceAlertTriggered;
use Liberu\RealEstate\Properties\Domain\Events\SavedSearchAlertTriggered;
use Liberu\RealEstate\Properties\Domain\PropertyGalleryItem;
use Liberu\RealEstate\Properties\Listeners\SendPriceAlertMail;
use Liberu\RealEstate\Properties\Listeners\SendSavedSearchAlertMail;
use Liberu\RealEstate\Properties\Mail\PriceAlertMail;
use Liberu\RealEstate\Properties\Mail\PropertyShareMail;
use Liberu\RealEstate\Properties\Mail\SavedSearchAlertMail;
use Liberu\RealEstate\Properties\Models\City;
use Liberu\RealEstate\Properties\Models\CommunityEvent;
use Liberu\RealEstate\Properties\Models\District;
use Liberu\RealEstate\Properties\Models\Neighborhood;
use Liberu\RealEstate\Properties\Models\NeighborhoodReview;
use Liberu\RealEstate\Properties\Models\Property;
use Liberu\RealEstate\Properties\Models\PropertyCategory;
use Liberu\RealEstate\Properties\Models\PropertyFavorite;
use Liberu\RealEstate\Properties\Models\PropertyHistory;
use Liberu\RealEstate\Properties\Models\PropertyKey;
use Liberu\RealEstate\Properties\Models\PropertyPriceAlert;
use Liberu\RealEstate\Properties\Models\PropertyReview;
use Liberu\RealEstate\Properties\Models\PropertySavedSearch;
use Liberu\RealEstate\Properties\Models\PropertyTemplate;
use Liberu\RealEstate\Properties\Models\PropertyUnit;
use Liberu\RealEstate\Properties\Models\Region;
use Liberu\RealEstate\Properties\Policies\PropertyCategoryPolicy;
use Liberu\RealEstate\Properties\Policies\PropertyPolicy;
use Liberu\RealEstate\Properties\Policies\PropertyTemplatePolicy;
use Liberu\RealEstate\PropertiesApi\Http\Resources\PublicPropertyResource;
use Liberu\RealEstate\PropertiesFilament\Resources\CityResource;
use Liberu\RealEstate\PropertiesFilament\Resources\CityResource\Pages\EditCity;
use Liberu\RealEstate\PropertiesFilament\Resources\DistrictResource;
use Liberu\RealEstate\PropertiesFilament\Resources\DistrictResource\Pages\EditDistrict;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertyCategoryResource\Pages\CreatePropertyCategory;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertyCategoryResource\Pages\EditPropertyCategory;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertyResource;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertyResource\Pages\CreateProperty as CreatePropertyPage;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertyResource\Pages\EditProperty as EditPropertyPage;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertySavedSearchResource\Pages\CreatePropertySavedSearch;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertySavedSearchResource\Pages\EditPropertySavedSearch;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertyTemplateResource\Pages\CreatePropertyTemplate;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertyTemplateResource\Pages\EditPropertyTemplate;
use Liberu\RealEstate\PropertiesFilament\Resources\RegionResource;
use Liberu\RealEstate\PropertiesFilament\Resources\RegionResource\Pages\EditRegion;
use Liberu\RealEstate\PropertiesFilament\Widgets\PropertiesByStatusChart;
use Liberu\RealEstate\PropertiesFilament\Widgets\PropertiesByTerritoryChart;
use Liberu\RealEstate\PropertiesFilament\Widgets\RealEstateOverview;
use Liberu\RealEstate\PropertiesLivewire\Components\AdvancedPropertySearch;
use Liberu\RealEstate\PropertiesLivewire\Components\NeighborhoodReviewForm;
use Liberu\RealEstate\PropertiesLivewire\Components\PriceAlertManager;
use Liberu\RealEstate\PropertiesLivewire\Components\PropertyComparison;
use Liberu\RealEstate\PropertiesLivewire\Components\PropertyDetail;
use Liberu\RealEstate\PropertiesLivewire\Components\PropertyList;
use Liberu\RealEstate\PropertiesLivewire\Components\PropertyMap;
use Liberu\RealEstate\PropertiesLivewire\Components\PropertyReviewForm;
use Liberu\RealEstate\PropertiesLivewire\Components\PropertySubmissionForm;
use Liberu\RealEstate\PropertiesLivewire\Components\WishlistManager;
use Livewire\Livewire;

it('covers properties application actions, domain, listeners, mail, models, and policies', function (): void {
    Mail::fake();

    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);

    $createProperty = app(CreateProperty::class);
    expect(fn () => $createProperty->handle($team->id, $user->id, ['address' => '   ']))
        ->toThrow(ValidationException::class);
    expect(fn () => $createProperty->handle($team->id, $user->id, ['address' => '1 Main St', 'territory_id' => 999999]))
        ->toThrow(ValidationException::class);

    $property = $createProperty->handle($team->id, $user->id, [
        'address' => '10 Downing Street',
        'title' => 'Townhouse',
        'price' => 500000,
        'currency' => 'GBP',
        'bedrooms' => 3,
        'bathrooms' => 2,
        'area_sqft' => 1500,
        'energy_score' => 82,
        'deal_type' => DealType::Rent,
    ]);

    // UpdateProperty error branches
    $updateProperty = app(UpdateProperty::class);
    expect(fn () => $updateProperty->handle($team->id, $user->id, $property->id, ['address' => '  ']))
        ->toThrow(ValidationException::class);
    expect(fn () => $updateProperty->handle($team->id, $user->id, $property->id, ['status' => 'available']))
        ->toThrow(ValidationException::class);
    expect(fn () => $updateProperty->handle($team->id, $user->id, $property->id, ['branch_id' => 999999]))
        ->toThrow(ValidationException::class);
    expect(fn () => $updateProperty->handle($team->id, $user->id, $property->id, ['property_category_id' => 999999]))
        ->toThrow(ValidationException::class);
    expect(fn () => $updateProperty->handle($team->id, $user->id, $property->id, ['property_template_id' => 999999]))
        ->toThrow(ValidationException::class);
    expect(fn () => $updateProperty->handle($team->id, $user->id, $property->id, ['territory_id' => 999999]))
        ->toThrow(ValidationException::class);

    // UpsertPropertyUnit & RecordPropertyKey error branches
    $upsertUnit = app(UpsertPropertyUnit::class);
    expect(fn () => $upsertUnit->handle($property, $otherTeam->id, ['label' => 'Flat 1']))
        ->toThrow(ValidationException::class);
    expect(fn () => $upsertUnit->handle($property, $team->id, ['label' => '  ']))
        ->toThrow(ValidationException::class);
    $unit = $upsertUnit->handle($property, $team->id, ['label' => 'Flat 1']);
    expect(PropertyUnit::query()->forTeam($team->id)->whereKey($unit->id)->exists())->toBeTrue();

    $recordKey = app(RecordPropertyKey::class);
    expect(fn () => $recordKey->handle($property, $otherTeam->id, ['key_reference' => 'K1']))
        ->toThrow(ValidationException::class);
    expect(fn () => $recordKey->handle($property, $team->id, ['key_reference' => '  ']))
        ->toThrow(ValidationException::class);
    expect(fn () => $recordKey->handle($property, $team->id, ['key_reference' => 'K1', 'quantity' => 0]))
        ->toThrow(ValidationException::class);
    $key = $recordKey->handle($property, $team->id, ['key_reference' => 'K1', 'quantity' => 2]);
    expect(PropertyKey::query()->forTeam($team->id)->whereKey($key->id)->exists())->toBeTrue();

    // CreatePriceAlert, UpdatePriceAlert, CheckPriceAlerts
    $noPriceProperty = $createProperty->handle($team->id, $user->id, ['address' => 'No Price Rd']);
    $createAlert = app(CreatePriceAlert::class);
    expect(fn () => $createAlert->handle($team->id, $user->id, $property->id, ['alert_percentage' => 5, 'alert_frequency' => 'invalid']))
        ->toThrow(ValidationException::class);
    expect(fn () => $createAlert->handle($team->id, $user->id, $noPriceProperty->id, ['alert_percentage' => 5, 'alert_frequency' => 'daily']))
        ->toThrow(ValidationException::class);
    $alert = $createAlert->handle($team->id, $user->id, $property->id, ['alert_percentage' => 5, 'alert_frequency' => 'daily']);
    expect($alert->user()->first()->id)->toBe($user->id);

    $updateAlert = app(UpdatePriceAlert::class);
    expect(fn () => $updateAlert->handle($team->id, $user->id, $alert->id, ['alert_percentage' => 0]))
        ->toThrow(ValidationException::class);
    expect(fn () => $updateAlert->handle($team->id, $user->id, $alert->id, ['alert_frequency' => 'yearly']))
        ->toThrow(ValidationException::class);

    // CheckPriceAlerts with initial_price <= 0
    PropertyPriceAlert::query()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'property_id' => $property->id,
        'initial_price' => 0,
        'alert_percentage' => 5,
        'alert_frequency' => 'daily',
        'is_active' => true,
    ]);
    expect(app(CheckPriceAlerts::class)->handle($team->id))->toBe(0);

    // SavePropertySearch error branch
    expect(fn () => app(SavePropertySearch::class)->handle($team->id, $user->id, '', []))
        ->toThrow(ValidationException::class);

    // SubmitPropertyReview error branches
    $submitReview = app(SubmitPropertyReview::class);
    expect(fn () => $submitReview->handle($team->id, $user->id, $property->id, [
        'rating' => 0, 'title' => 'No', 'comment' => 'short',
    ]))->toThrow(ValidationException::class);

    Schema::partialMock()->shouldReceive('hasTable')->with('real_estate_viewings')->once()->andReturn(false);
    expect(fn () => $submitReview->handle($team->id, $user->id, $property->id, [
        'rating' => 5, 'title' => 'Great Home', 'comment' => 'Really loved the property tour.',
    ]))->toThrow(ValidationException::class);

    // EstimatePropertyTax: US, generic, UK > 1M, first_time_buyer, additional_property
    $tax = app(EstimatePropertyTax::class);
    expect($tax->handle(500000, 'US')['country'])->toBe('United States');
    expect($tax->handle(500000, 'FR', ['country_name' => 'France'])['country'])->toBe('France');
    expect($tax->handle(1500000, 'UK', ['buyer_type' => 'first_time_buyer'])['buyer_type'])->toBe('first_time_buyer');
    expect($tax->handle(1500000, 'UK', ['buyer_type' => 'additional_property'])['buyer_type'])->toBe('additional_property');

    // FetchWalkabilityScores: failed response, exception, and Good Transit mock
    config(['services.walkscore.api_key' => 'test-key']);
    Http::fake(['*' => Http::response([], 500)]);
    $failedScores = app(FetchWalkabilityScores::class)->handle('10 Downing St', 0.15, 0.0);
    expect($failedScores['transit_description'])->toBe('Good Transit');

    Http::swap(new Factory());
    Http::fake(fn () => throw new RuntimeException('network error'));
    $exScores = app(FetchWalkabilityScores::class)->handle('10 Downing St', 0.15, 0.0);
    expect($exScores['transit_score'])->toBe(55);
    config(['services.walkscore.api_key' => '']);

    // GeneratePropertyDescription: professional, casual & luxury in fallback mode, casual & luxury in OpenAI mode
    $genDesc = app(GeneratePropertyDescription::class);
    config(['services.openai.api_key' => '']);
    expect($genDesc->handle(['property_type' => 'house'], 'professional'))->toContain('well-presented');
    expect($genDesc->handle(['property_type' => 'house'], 'casual'))->toContain('comfortable');
    expect($genDesc->handle(['property_type' => 'house'], 'luxury'))->toContain('exceptional');

    config(['services.openai.api_key' => 'sk-test']);
    Http::swap(new Factory());
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'AI copy']]]], 200)]);
    expect($genDesc->handle(['property_type' => 'house'], 'casual'))->toBe('AI copy');
    expect($genDesc->handle(['property_type' => 'house'], 'luxury'))->toBe('AI copy');
    config(['services.openai.api_key' => '']);

    // PropertyGalleryItem
    $galleryItem = new PropertyGalleryItem('https://example.com/plan.png', 'floor plan');
    expect($galleryItem->alt())->toBe('Floor plan');
    expect($galleryItem->isPlan())->toBeTrue();

    // Listeners & Mail
    $sendPriceMail = new SendPriceAlertMail();
    $sendPriceMail->handle(new PriceAlertTriggered($alert, $property, -10.5));
    Mail::assertQueued(PriceAlertMail::class);

    $orphanAlert = new PropertyPriceAlert(['team_id' => $team->id, 'user_id' => 999999]);
    $sendPriceMail->handle(new PriceAlertTriggered($orphanAlert, $property, -5.0));

    $builtPriceMail = (new PriceAlertMail($alert, $property, -10.5))->build();
    expect($builtPriceMail->subject)->toContain('dropped');

    $savedSearch = PropertySavedSearch::query()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'name' => 'Central Flats',
        'criteria' => ['search' => 'Downing'],
    ]);
    $orphanSearch = new PropertySavedSearch(['team_id' => $team->id, 'user_id' => 999999, 'name' => 'None', 'criteria' => []]);
    (new SendSavedSearchAlertMail())->handle(new SavedSearchAlertTriggered($orphanSearch, new EloquentCollection([$property])));

    $builtSavedMail = (new SavedSearchAlertMail($savedSearch, new EloquentCollection([$property])))->build();
    expect($builtSavedMail->subject)->toContain('1 new match');

    $builtShareMail = (new PropertyShareMail([
        'recipient_email' => 'friend@example.com',
        'recipient_name' => 'Friend',
        'sender_email' => 'me@example.com',
        'sender_name' => 'Me',
        'subject' => 'Check this out',
        'body' => '<p>Nice place</p>',
    ]))->build();
    expect($builtShareMail->subject)->toBe('Check this out');

    // Models: Geo, CommunityEvent, Neighborhood, NeighborhoodReview, Property, PropertyCategory, PropertyFavorite, PropertyHistory, PropertyReview, PropertyTemplate
    $region = Region::query()->create(['name' => 'London', 'slug' => 'london']);
    $city = City::query()->create(['region_id' => $region->id, 'name' => 'Westminster', 'slug' => 'westminster']);
    $district = District::query()->create(['city_id' => $city->id, 'name' => 'Soho', 'slug' => 'soho']);
    expect($region->properties()->count())->toBe(0);
    expect($city->properties()->count())->toBe(0);
    expect($district->properties()->count())->toBe(0);

    $event = CommunityEvent::query()->create([
        'team_id' => $team->id,
        'property_id' => $property->id,
        'title' => 'Open Day',
        'event_date' => now()->addDay(),
        'is_public' => true,
        'latitude' => 51.5,
        'longitude' => -0.12,
    ]);
    expect($event->property->id)->toBe($property->id);
    expect(fn () => CommunityEvent::query()->nearby(999, 0, 10)->get())
        ->toThrow(InvalidArgumentException::class);

    $neighborhood = Neighborhood::query()->create([
        'team_id' => $team->id,
        'created_by' => $user->id,
        'name' => 'Mayfair',
    ]);
    expect($neighborhood->properties()->count())->toBe(0);
    $nReview = NeighborhoodReview::query()->create([
        'team_id' => $team->id,
        'neighborhood_id' => $neighborhood->id,
        'user_id' => $user->id,
        'rating' => 5,
        'title' => 'Lovely',
        'comment' => 'Great neighborhood to live in.',
        'approved' => true,
        'moderation_status' => 'approved',
    ]);
    expect($nReview->neighborhood->id)->toBe($neighborhood->id);
    expect($neighborhood->averageReviewRating())->toBe(5.0);
    expect($neighborhood->approvedReviewCount())->toBe(1);
    expect(NeighborhoodReview::query()->highRated()->count())->toBe(1);

    // Property uncovered methods/branches
    expect($property->priceAlerts()->count())->toBe(2);
    expect($property->reviews()->count())->toBe(0);
    expect($property->averageReviewRating())->toBe(0.0);
    expect($property->approvedReviewCount())->toBe(0);
    expect($property->getNearbyCommunityEvents())->toHaveCount(0);
    expect(fn () => Property::query()->nearby(999, 0, 10)->get())->toThrow(InvalidArgumentException::class);
    expect($noPriceProperty->pricePerSquareFootForHumans())->toBeNull();
    expect($property->pricePerSquareMeterLabel())->toBe('£/m²');
    expect($property->pricePerSquareMeterForHumans())->toContain('pcm');
    expect($property->disclosureFacts()['energy']['value'])->toBe('82');
    expect($noPriceProperty->similarProperties())->toHaveCount(0);
    expect($property->canBePublished())->toBeTrue();

    $category = PropertyCategory::query()->create(['team_id' => $team->id, 'name' => 'Luxury', 'slug' => 'luxury']);
    expect($category->properties()->count())->toBe(0);

    $template = PropertyTemplate::query()->create(['team_id' => $team->id, 'name' => 'Default', 'content' => 'Body']);
    expect($template->properties()->count())->toBe(0);

    $favorite = PropertyFavorite::query()->create(['team_id' => $team->id, 'user_id' => $user->id, 'property_id' => $property->id]);
    expect($favorite->property->id)->toBe($property->id);

    $pReview = PropertyReview::query()->create([
        'team_id' => $team->id,
        'property_id' => $property->id,
        'user_id' => $user->id,
        'rating' => 5,
        'title' => 'Superb',
        'comment' => 'Really wonderful property.',
        'approved' => true,
        'moderation_status' => 'approved',
    ]);
    expect($pReview->property->id)->toBe($property->id);
    expect(PropertyReview::query()->forTeam($team->id)->approved()->highRated()->count())->toBe(1);

    // PropertyHistory uncovered scopes, methods, relations
    $statusHistory = PropertyHistory::query()->create([
        'team_id' => $team->id,
        'property_id' => $property->id,
        'actor_id' => $user->id,
        'event' => 'status_changed',
        'changes' => ['from' => 'draft', 'to' => 'sold'],
    ]);
    $createdHistory = PropertyHistory::query()->create([
        'team_id' => $team->id,
        'property_id' => $property->id,
        'actor_id' => $user->id,
        'event' => 'custom_event',
        'changes' => [],
    ]);
    expect(PropertyHistory::query()->priceChanges()->count())->toBeInt();
    expect(PropertyHistory::query()->sales()->count())->toBeGreaterThanOrEqual(1);
    expect($createdHistory->getPriceChangePercentage())->toBeNull();
    expect($statusHistory->getFormattedDescription())->toContain('Status changed from');
    expect($createdHistory->getFormattedDescription())->toBe('Custom event');
    expect($statusHistory->property->id)->toBe($property->id);
    expect($statusHistory->team->id)->toBe($team->id);

    // Policies
    $propPolicy = new PropertyPolicy();
    $catPolicy = new PropertyCategoryPolicy();
    $tplPolicy = new PropertyTemplatePolicy();
    foreach (['view', 'create', 'update', 'delete', 'deleteAny', 'restore', 'forceDelete', 'forceDeleteAny', 'restoreAny', 'replicate', 'reorder'] as $method) {
        $propArgs = in_array($method, ['create', 'deleteAny', 'forceDeleteAny', 'restoreAny', 'reorder'], true) ? [$user] : [$user, $property];
        $catArgs = in_array($method, ['create', 'deleteAny', 'forceDeleteAny', 'restoreAny', 'reorder'], true) ? [$user] : [$user, $category];
        $tplArgs = in_array($method, ['create', 'deleteAny', 'forceDeleteAny', 'restoreAny', 'reorder'], true) ? [$user] : [$user, $template];
        expect($propPolicy->{$method}(...$propArgs))->toBeBool();
        expect($catPolicy->{$method}(...$catArgs))->toBeBool();
        expect($tplPolicy->{$method}(...$tplArgs))->toBeBool();
    }
});

it('covers properties api controllers and resources', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $this->actingAs($user, 'sanctum');

    $property = app(CreateProperty::class)->handle($team->id, $user->id, [
        'address' => '221B Baker Street',
        'title' => 'Baker Flat',
        'price' => 300000,
        'currency' => 'USD',
        'bedrooms' => 2,
        'bathrooms' => 1,
        'property_type' => 'apartment',
    ]);

    // CommunityEventController::show
    $event = CommunityEvent::query()->create([
        'team_id' => $team->id,
        'property_id' => $property->id,
        'title' => 'Street Fair',
        'event_date' => now()->addDays(2),
        'is_public' => true,
    ]);
    $this->getJson("/api/v1/real-estate/community-events/{$event->id}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Street Fair');

    // PropertyCategoryController CRUD + duplicate slug 422
    $catRes = $this->postJson('/api/v1/real-estate/property-categories', ['name' => 'Detached'])->assertCreated();
    $catId = $catRes->json('data.id');
    $cat2Res = $this->postJson('/api/v1/real-estate/property-categories', ['name' => 'Semi'])->assertCreated();
    $cat2Id = $cat2Res->json('data.id');
    $this->getJson('/api/v1/real-estate/property-categories')->assertOk();
    $this->patchJson("/api/v1/real-estate/property-categories/{$catId}", ['name' => 'Detached Home'])->assertOk();
    $this->patchJson("/api/v1/real-estate/property-categories/{$cat2Id}", ['slug' => 'detached-home'])->assertStatus(422);
    $this->deleteJson("/api/v1/real-estate/property-categories/{$catId}")->assertNoContent();

    // PropertyTemplateController CRUD
    $tplRes = $this->postJson('/api/v1/real-estate/property-templates', ['name' => 'Brochure', 'content' => 'Hello'])->assertCreated();
    $tplId = $tplRes->json('data.id');
    $this->getJson('/api/v1/real-estate/property-templates')->assertOk();
    $this->patchJson("/api/v1/real-estate/property-templates/{$tplId}", ['name' => 'Brochure v2'])->assertOk();
    $this->deleteJson("/api/v1/real-estate/property-templates/{$tplId}")->assertNoContent();

    // PropertyController: show, favorite, similar, transition, unit, key, destroy
    $this->getJson("/api/v1/real-estate/properties/{$property->id}")->assertOk();
    $this->postJson("/api/v1/real-estate/properties/{$property->id}/favorite")->assertOk()->assertJsonPath('favorited', true);
    $this->getJson("/api/v1/real-estate/properties/{$property->id}/similar?limit=5")->assertOk();
    $this->postJson("/api/v1/real-estate/properties/{$property->id}/transition/available")->assertOk();
    $this->putJson("/api/v1/real-estate/properties/{$property->id}/units", ['label' => 'Unit A'])->assertCreated();
    $this->postJson("/api/v1/real-estate/properties/{$property->id}/keys", ['key_reference' => 'KEY-A', 'quantity' => 1])->assertCreated();

    // PublicPropertyResource::priceUsd + ResolvesPropertyGallery when media table absent
    Schema::partialMock()->shouldReceive('hasTable')->with('real_estate_media_documents')->once()->andReturn(false);
    $publicArray = (new PublicPropertyResource($property->fresh()))->toArray(Request::create('/'));
    expect($publicArray['price_usd'])->toBe(300000.0);
    expect($publicArray['gallery'])->toBe([]);

    $this->deleteJson("/api/v1/real-estate/properties/{$property->id}")->assertNoContent();
});

it('covers properties filament resources, pages, and widgets', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $this->actingAs($user);

    $region = Region::query()->create(['name' => 'North', 'slug' => 'north']);
    $city = City::query()->create(['region_id' => $region->id, 'name' => 'Leeds', 'slug' => 'leeds']);
    $district = District::query()->create(['city_id' => $city->id, 'name' => 'Center', 'slug' => 'center']);

    // canDelete on geo resources
    expect(RegionResource::canDelete($region))->toBeBool();
    expect(CityResource::canDelete($city))->toBeBool();
    expect(DistrictResource::canDelete($district))->toBeBool();

    // EditRegion, EditCity, EditDistrict
    $editRegion = (new ReflectionClass(EditRegion::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod(EditRegion::class, 'handleRecordUpdate'))->invoke($editRegion, $region, ['name' => 'North East']);
    expect($region->fresh()->slug)->toBe('north-east');

    $editCity = (new ReflectionClass(EditCity::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod(EditCity::class, 'handleRecordUpdate'))->invoke($editCity, $city, ['region_id' => $region->id, 'name' => 'York']);
    expect($city->fresh()->slug)->toBe('york');

    $editDistrict = (new ReflectionClass(EditDistrict::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod(EditDistrict::class, 'handleRecordUpdate'))->invoke($editDistrict, $district, ['city_id' => $city->id, 'name' => 'Old Town']);
    expect($district->fresh()->slug)->toBe('old-town');

    // CreatePropertyCategory & EditPropertyCategory
    $createCat = (new ReflectionClass(CreatePropertyCategory::class))->newInstanceWithoutConstructor();
    $cat = (new ReflectionMethod(CreatePropertyCategory::class, 'handleRecordCreation'))->invoke($createCat, ['name' => 'Cottages']);
    $editCat = (new ReflectionClass(EditPropertyCategory::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod(EditPropertyCategory::class, 'handleRecordUpdate'))->invoke($editCat, $cat, ['name' => 'Rural Cottages']);
    expect($cat->fresh()->slug)->toBe('rural-cottages');

    // CreatePropertyTemplate & EditPropertyTemplate
    $createTpl = (new ReflectionClass(CreatePropertyTemplate::class))->newInstanceWithoutConstructor();
    $tpl = (new ReflectionMethod(CreatePropertyTemplate::class, 'handleRecordCreation'))->invoke($createTpl, ['name' => 'T1', 'content' => 'C1']);
    $editTpl = (new ReflectionClass(EditPropertyTemplate::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod(EditPropertyTemplate::class, 'handleRecordUpdate'))->invoke($editTpl, $tpl, ['name' => 'T2', 'content' => 'C2']);
    expect($tpl->fresh()->name)->toBe('T2');

    // CreatePropertySavedSearch & EditPropertySavedSearch
    $createSearch = (new ReflectionClass(CreatePropertySavedSearch::class))->newInstanceWithoutConstructor();
    $saved = (new ReflectionMethod(CreatePropertySavedSearch::class, 'handleRecordCreation'))->invoke($createSearch, ['name' => 'S1', 'criteria' => ['search' => 'a']]);
    $editSearch = (new ReflectionClass(EditPropertySavedSearch::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod(EditPropertySavedSearch::class, 'handleRecordUpdate'))->invoke($editSearch, $saved, ['name' => 'S2', 'criteria' => ['search' => 'b']]);
    expect($saved->fresh()->name)->toBe('S2');

    // CreatePropertyPage & EditPropertyPage
    $territory = Territory::query()->create(['team_id' => $team->id, 'name' => 'Zone A', 'code' => 'ZA']);
    $createProp = (new ReflectionClass(CreatePropertyPage::class))->newInstanceWithoutConstructor();
    $property = (new ReflectionMethod(CreatePropertyPage::class, 'handleRecordCreation'))->invoke($createProp, [
        'address' => '5 Filament Way',
        'price' => 400000,
        'bedrooms' => 3,
        'bathrooms' => 2,
        'territory_id' => $territory->id,
    ]);
    $editProp = (new ReflectionClass(EditPropertyPage::class))->newInstanceWithoutConstructor();
    $property = (new ReflectionMethod(EditPropertyPage::class, 'handleRecordUpdate'))->invoke($editProp, $property, ['title' => 'Edited Way']);
    expect($property->title)->toBe('Edited Way');

    // PropertyResource table closures: similar, tax_estimate, and favorites_only when no team
    $table = PropertyResource::table(Table::make(Mockery::mock(HasTable::class)));
    $actions = collect($table->getRecordActions());
    ($actions->first(fn ($a): bool => $a->getName() === 'similar')->getActionFunction())($property);
    ($actions->first(fn ($a): bool => $a->getName() === 'tax_estimate')->getActionFunction())($property, ['country' => 'GB', 'buyer_type' => 'home_mover']);

    Auth::logout();
    $favFilter = collect($table->getFilters())->first(fn ($f): bool => $f->getName() === 'favorites_only');
    $favQuery = Property::query();
    $favFilter->apply($favQuery, ['isActive' => true]);
    expect($favQuery->toSql())->toContain('1 = 0');

    // Widgets: PropertiesByStatusChart, PropertiesByTerritoryChart, RealEstateOverview
    $statusChart = new PropertiesByStatusChart();
    $territoryChart = new PropertiesByTerritoryChart();
    $overview = new RealEstateOverview();

    Filament::setTenant(null);
    expect((new ReflectionMethod(PropertiesByStatusChart::class, 'getData'))->invoke($statusChart)['datasets'])->toBe([]);
    expect((new ReflectionMethod(PropertiesByTerritoryChart::class, 'getData'))->invoke($territoryChart)['datasets'])->toBe([]);
    expect((new ReflectionMethod(RealEstateOverview::class, 'getStats'))->invoke($overview))->toBe([]);

    Filament::setTenant($team, isQuiet: true);
    $statusData = (new ReflectionMethod(PropertiesByStatusChart::class, 'getData'))->invoke($statusChart);
    expect($statusData['datasets'][0]['data'])->toBe([1]);
    expect((new ReflectionMethod(PropertiesByStatusChart::class, 'getType'))->invoke($statusChart))->toBe('doughnut');

    $territoryData = (new ReflectionMethod(PropertiesByTerritoryChart::class, 'getData'))->invoke($territoryChart);
    expect($territoryData['datasets'][0]['data'])->toBe([1]);
    expect((new ReflectionMethod(PropertiesByTerritoryChart::class, 'getType'))->invoke($territoryChart))->toBe('bar');
    Filament::setTenant(null);
});

it('covers properties livewire components', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $property = app(CreateProperty::class)->handle($team->id, $user->id, [
        'address' => '100 Livewire Ln',
        'title' => 'Livewire House',
        'price' => 350000,
        'currency' => 'GBP',
        'bedrooms' => 3,
        'bathrooms' => 2,
        'latitude' => 51.51,
        'longitude' => -0.11,
        'property_type' => 'house',
        'live_tour_available' => true,
    ]);
    $neighborhood = Neighborhood::query()->create(['team_id' => $team->id, 'created_by' => $user->id, 'name' => 'Chelsea']);

    // AdvancedPropertySearch: guest render + updatedSearch + applyFilters
    Livewire::test(AdvancedPropertySearch::class)->assertOk();
    $this->actingAs($user);
    Livewire::test(AdvancedPropertySearch::class)
        ->set('search', 'Livewire')
        ->call('applyFilters')
        ->assertHasNoErrors();

    // PropertyComparison: duplicate addProperty (line 46) & short searchTerm (lines 70, 72)
    Livewire::test(PropertyComparison::class, ['propertyIds' => [$property->id]])
        ->call('addProperty', $property->id)
        ->set('searchTerm', 'ab')
        ->assertSet('searchResults', []);

    // PropertyDetail: toggleFavorite, toggleVirtualTour, guest openScheduleLiveTourModal, missing media table
    Livewire::test(PropertyDetail::class, ['propertyId' => $property->id])
        ->call('toggleFavorite')
        ->assertSet('isFavorited', true)
        ->call('toggleVirtualTour')
        ->assertSet('showVirtualTour', true);

    // PropertyList: showSimilar, toggleFavorite, saveUnit, recordKey, transitions, appliedFilters, clearFilter, clearFilters, resultCount, guest render
    Livewire::test(PropertyList::class)
        ->set('search', 'Livewire')
        ->set('propertyType', 'house')
        ->set('minPrice', 100000)
        ->set('selectedAmenities', ['wifi'])
        ->set('needsSyncingOnly', true)
        ->set('featuredOnly', true)
        ->call('showSimilar', $property->id)
        ->call('toggleFavorite', $property->id)
        ->call('saveUnit', $property->id, ['label' => 'Suite 1'])
        ->call('recordKey', $property->id, ['key_reference' => 'LW-1', 'quantity' => 1])
        ->call('publish', $property->id)
        ->call('markUnderOffer', $property->id)
        ->call('markSold', $property->id)
        ->call('withdraw', $property->id)
        ->call('clearFilter', 'non_existent')
        ->set('search', '')
        ->call('clearFilters')
        ->assertOk();

    $listComponent = new PropertyList();
    expect($listComponent->resultCount())->toBeInt();

    // PropertyMap: defaults() & currency prefixes
    app()->forgetInstance(PropertyMap::class.':defaults');
    $map = new PropertyMap();
    expect($map->properties)->not->toBeEmpty();
    $points = PropertyMap::points([
        ['id' => 1, 'title' => 'A', 'price' => 1, 'currency' => 'EUR'],
        ['id' => 2, 'title' => 'B', 'price' => 1, 'currency' => 'USD'],
        ['id' => 3, 'title' => 'C', 'price' => 1, 'currency' => 'CAD'],
        ['id' => 4, 'title' => 'D', 'price' => 1, 'currency' => 'AUD'],
    ]);
    expect($points->pluck('currency')->all())->toBe(['€', '$', 'CA$', 'A$']);

    // PropertySubmissionForm: updateDescription + submit without team
    Livewire::test(PropertySubmissionForm::class)
        ->call('updateDescription', 'Custom text')
        ->assertSet('description', 'Custom text');

    // WishlistManager: updatingSearch & updatedSortBy
    Livewire::test(WishlistManager::class)
        ->set('search', 'Livewire')
        ->set('sortBy', 'price')
        ->assertSet('removed', null);

    // Guest / no-team paths
    Auth::logout();
    Livewire::test(PropertyList::class)->assertOk();

    $detailGuest = new PropertyDetail();
    $detailGuest->propertyId = $property->id;
    expect($detailGuest->openScheduleLiveTourModal())->not->toBeNull();

    Schema::partialMock()->shouldReceive('hasTable')->with('real_estate_media_documents')->twice()->andReturn(false);
    expect((new ReflectionMethod(PropertyDetail::class, 'mediaItems'))->invoke($detailGuest, $property))->toBe([]);
    expect((new ReflectionMethod(PropertyDetail::class, 'videoUrl'))->invoke($detailGuest, $property))->toBeNull();

    Livewire::test(PropertySubmissionForm::class)
        ->set('title', 'No Team Home')
        ->set('description', 'A great home.')
        ->set('location', 'London')
        ->set('price', 200000)
        ->set('bedrooms', 2)
        ->set('bathrooms', 1)
        ->set('area_sqft', 900)
        ->set('year_built', 2000)
        ->set('property_type', 'house')
        ->call('submit')
        ->assertHasErrors(['submission']);

    // Direct validation checks for teamless submitReview / createAlert
    $this->actingAs($user);
    $nTest = Livewire::test(NeighborhoodReviewForm::class, ['neighborhoodId' => $neighborhood->id])
        ->set('rating', 5)
        ->set('title', 'Nice Place')
        ->set('comment', 'A very nice neighborhood indeed.');
    $pTest = Livewire::test(PropertyReviewForm::class, ['propertyId' => $property->id])
        ->set('rating', 5)
        ->set('title', 'Nice House')
        ->set('comment', 'A very nice property indeed.');
    $aTest = Livewire::test(PriceAlertManager::class, ['propertyId' => $property->id])
        ->set('alertPercentage', 5)
        ->set('alertFrequency', 'daily');

    Auth::logout();
    expect(fn () => $nTest->instance()->submitReview(app(SubmitNeighborhoodReview::class)))
        ->toThrow(ValidationException::class);
    expect(fn () => $pTest->instance()->submitReview(app(SubmitPropertyReview::class)))
        ->toThrow(ValidationException::class);
    expect(fn () => $aTest->instance()->createAlert(app(CreatePriceAlert::class)))
        ->toThrow(ValidationException::class);
});
