<?php

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Listings\Application\CreateListing as CreateListingAction;
use Liberu\RealEstate\Listings\Domain\ListingStatus;
use Liberu\RealEstate\Listings\Models\Listing;
use Liberu\RealEstate\ListingsFilament\Resources\ListingResource;
use Liberu\RealEstate\ListingsFilament\Resources\ListingResource\Pages\CreateListing;
use Liberu\RealEstate\ListingsFilament\Resources\ListingResource\Pages\EditListing;
use Liberu\RealEstate\ListingsFilament\Resources\ListingResource\Pages\ListListings;
use Livewire\Livewire;

it('manages the publication lifecycle from the active Filament team', function (): void {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $outside = app(CreateListingAction::class)->handle($other->id, $user->id, ['title' => 'Other home']);
    Gate::before(fn (User $candidate, string $ability): ?bool => $candidate->is($user) && str_ends_with($ability, ':Listing') ? true : null);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);
    Filament::bootCurrentPanel();

    expect(Filament::getCurrentPanel()->getResources())->toContain(ListingResource::class);

    Livewire::test(CreateListing::class)
        ->fillForm(['title' => 'New home', 'price' => 400000])
        ->call('create')
        ->assertHasNoFormErrors();

    $listing = Listing::query()->forTeam($team->id)->sole();
    expect($listing->status)->toBe(ListingStatus::Draft);
    Livewire::test(EditListing::class, ['record' => $outside->id])->assertNotFound();
    Livewire::test(EditListing::class, ['record' => $listing->id])
        ->fillForm(['title' => 'Updated home'])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(ListListings::class)
        ->assertCanSeeTableRecords([$listing])
        ->assertCanNotSeeTableRecords([$outside])
        ->callAction(TestAction::make('section')->table($listing), data: ['section' => 'channel_content', 'value' => 'Updated description'])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('ready')->table($listing))
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('publish')->table($listing))
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('suspend')->table($listing))
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('withdraw')->table($listing))
        ->assertHasNoActionErrors();

    expect($listing->refresh()->title)->toBe('Updated home')
        ->and($listing->status)->toBe(ListingStatus::Withdrawn)
        ->and($listing->channel_content)->toBe(['value' => 'Updated description'])
        ->and($outside->fresh()->status)->toBe(ListingStatus::Draft);
});

it('requires listing panel permissions and hides all listings without a team', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    app(CreateListingAction::class)->handle($team->id, $user->id, ['title' => 'Private home']);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);

    Livewire::test(ListListings::class)->assertForbidden();
    Livewire::test(CreateListing::class)->assertForbidden();

    $user->current_team_id = null;
    Filament::setTenant(null);
    expect(ListingResource::getEloquentQuery()->exists())->toBeFalse();
});
