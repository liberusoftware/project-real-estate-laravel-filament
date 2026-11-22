<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Liberu\RealEstate\Properties\Application\CreateProperty;
use Liberu\RealEstate\VrDesign\Application\VrDesignService;
use Liberu\RealEstate\VrDesign\Domain\VrDesignCapabilityDefinition;
use Liberu\RealEstate\VrDesign\Models\VrDesign;
use Liberu\RealEstate\VrDesignLivewire\Components\DesignStudio;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('covers VR design capability definitions, model scopes, and service edge cases', function (): void {
    Storage::fake('public');

    $capabilities = VrDesignCapabilityDefinition::all();
    expect($capabilities)->toHaveKeys([
        'design_styles',
        'furniture_placement',
        'room_layouts',
        'materials_and_lighting',
        'public_templates',
        'thumbnail_uploads',
        'vr_exports',
    ]);

    $user = User::factory()->create(['current_team_id' => 20]);
    $property = app(CreateProperty::class)->handle(20, $user->getKey(), ['address' => '22 Baker Street']);
    $service = app(VrDesignService::class);

    expect(fn () => $service->create(20, $user->getKey(), $property->getKey(), '   ', []))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->create(20, $user->getKey(), $property->getKey(), 'Bad Style', ['room' => 'kitchen'], style: 'nonexistent'))
        ->toThrow(ValidationException::class);

    $design = $service->create(20, $user->getKey(), $property->getKey(), 'Lounge', ['room' => 'living'], 'Initial', 'modern', true);
    $privateDesign = $service->create(20, $user->getKey(), $property->getKey(), 'Private Den', ['room' => 'study'], null, 'minimalist', false);

    expect(fn () => $service->update($design, ['style' => 'nonexistent']))
        ->toThrow(ValidationException::class);

    $updated = $service->update($design, ['name' => 'Updated Lounge', 'style' => 'contemporary', 'is_public' => true]);
    expect($updated->name)->toBe('Updated Lounge')->and($updated->style)->toBe('contemporary');

    expect($service->propertyDesigns(20, $property->getKey(), false))->toHaveCount(2)
        ->and($service->propertyDesigns(20, $property->getKey(), true))->toHaveCount(1);

    expect(fn () => $service->addFurniture($updated, '', 'Sofa', [0, 0, 0]))
        ->toThrow(ValidationException::class);

    $withFurniture = $service->addFurniture($updated, 'seating', 'Armchair', [1, 0, 2]);
    $furnitureId = $withFurniture->furniture_items[0]['id'];
    $afterRemove = $service->removeFurniture($withFurniture, $furnitureId);
    expect($afterRemove->furniture_items)->toBeEmpty();

    $updated->forceFill(['is_template' => true, 'vr_scene_url' => 'vr-designs/scenes/scene.glb'])->save();
    Storage::disk('public')->put('vr-designs/scenes/scene.glb', 'scene-bytes');

    expect($service->templates(20))->toHaveCount(1)
        ->and($service->templates(20, 'contemporary'))->toHaveCount(1)
        ->and($service->templates(20, 'modern'))->toHaveCount(0);

    $firstThumb = $service->uploadThumbnail($updated, UploadedFile::fake()->image('first.jpg'))->thumbnail_path;
    $secondThumb = $service->uploadThumbnail($updated->refresh(), UploadedFile::fake()->image('second.jpg'))->thumbnail_path;
    Storage::disk('public')->assertMissing($firstThumb);
    Storage::disk('public')->assertExists($secondThumb);

    expect($service->delete($updated->refresh()))->toBeTrue();
    Storage::disk('public')->assertMissing($secondThumb);
    Storage::disk('public')->assertMissing('vr-designs/scenes/scene.glb');
    expect($privateDesign->fresh())->not->toBeNull();
});

it('covers all VR design API controller endpoints including validation errors', function (): void {
    Storage::fake('public');
    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));

    $user = User::factory()->create(['current_team_id' => 30]);
    $property = app(CreateProperty::class)->handle(30, $user->getKey(), ['address' => '10 Downing Street']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/real-estate/vr-design/properties/'.$property->getKey().'/designs', [])
        ->assertUnprocessable();

    $created = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/real-estate/vr-design/properties/'.$property->getKey().'/designs', [
            'name' => 'API Design',
            'description' => 'Created via API',
            'style' => 'modern',
            'design_data' => ['room' => 'living'],
            'is_public' => true,
        ])
        ->assertCreated();
    $designId = $created->json('data.id');

    VrDesign::query()->whereKey($designId)->update(['is_template' => true]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/real-estate/vr-design/templates?style=modern')
        ->assertOk()
        ->assertJsonPath('data.count', 1);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/real-estate/vr-design/properties/'.$property->getKey().'/designs?public_only=1')
        ->assertOk()
        ->assertJsonPath('data.count', 1);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/real-estate/vr-design/designs/'.$designId)
        ->assertOk()
        ->assertJsonPath('data.view_count', 1);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/real-estate/vr-design/designs/'.$designId, ['style' => 'invalid-style'])
        ->assertUnprocessable();

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/real-estate/vr-design/designs/'.$designId, ['name' => 'API Design Updated'])
        ->assertOk()
        ->assertJsonPath('data.name', 'API Design Updated');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/real-estate/vr-design/designs/'.$designId.'/furniture', [])
        ->assertUnprocessable();

    $added = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/real-estate/vr-design/designs/'.$designId.'/furniture', [
            'category' => 'seating',
            'type' => 'Sofa',
            'position' => [0, 0, 0],
        ])
        ->assertOk();
    $furnitureId = $added->json('data.furniture_items.0.id');

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/v1/real-estate/vr-design/designs/'.$designId.'/furniture/'.$furnitureId)
        ->assertOk()
        ->assertJsonPath('data.furniture_items', []);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/real-estate/vr-design/designs/'.$designId.'/clone', [])
        ->assertUnprocessable();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/real-estate/vr-design/designs/'.$designId.'/clone', ['name' => 'API Clone'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'API Clone');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/real-estate/vr-design/designs/'.$designId.'/thumbnail', [])
        ->assertUnprocessable();

    $this->actingAs($user, 'sanctum')
        ->post('/api/v1/real-estate/vr-design/designs/'.$designId.'/thumbnail', [
            'thumbnail' => UploadedFile::fake()->image('api-thumb.png'),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/v1/real-estate/vr-design/designs/'.$designId)
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('covers all Livewire DesignStudio actions including update, furniture, thumbnail, clone, and delete', function (): void {
    Storage::fake('public');

    $user = User::factory()->create(['current_team_id' => 40]);
    $property = app(CreateProperty::class)->handle(40, $user->getKey(), ['address' => '50 Studio Lane']);
    Livewire::component('test-vr-design-studio-full', DesignStudio::class);

    $component = Livewire::actingAs($user)->test('test-vr-design-studio-full', ['propertyId' => $property->getKey()])
        ->set('designName', 'Studio Primary')
        ->set('designDescription', 'Main room')
        ->set('designStyle', 'modern')
        ->set('isPublic', true)
        ->call('saveDesign')
        ->assertHasNoErrors()
        ->assertSet('message', 'VR design created successfully.');

    $designId = $component->get('selectedDesignId');
    expect($designId)->not->toBeNull();

    $component
        ->set('designName', 'Studio Primary Renamed')
        ->set('designDescription', 'Updated room')
        ->set('designStyle', 'contemporary')
        ->call('updateDesign')
        ->assertHasNoErrors()
        ->assertSet('message', 'VR design updated successfully.')
        ->set('furnitureCategory', 'seating')
        ->set('furnitureType', 'Chair')
        ->set('furniturePositionX', 1.5)
        ->set('furniturePositionY', 0.0)
        ->set('furniturePositionZ', 2.5)
        ->call('addFurniture')
        ->assertSet('message', 'Furniture added successfully.');

    $furnitureId = VrDesign::query()->findOrFail($designId)->furniture_items[0]['id'];

    $component
        ->call('removeFurniture', $furnitureId)
        ->assertSet('message', 'Furniture removed successfully.')
        ->set('thumbnailUpload', UploadedFile::fake()->image('studio-thumb.jpg'))
        ->call('uploadThumbnail')
        ->assertHasNoErrors()
        ->assertSet('message', 'Thumbnail uploaded successfully.')
        ->call('cloneDesign')
        ->assertSet('message', 'Design cloned successfully.')
        ->call('deleteDesign')
        ->assertSet('selectedDesignId', null)
        ->assertSet('message', 'VR design deleted successfully.');
});
