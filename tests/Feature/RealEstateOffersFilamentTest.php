<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Offers\Application\CreateOffer as CreateOfferAction;
use Liberu\RealEstate\Offers\Models\Offer;
use Liberu\RealEstate\OffersFilament\Resources\OfferResource;
use Liberu\RealEstate\OffersFilament\Resources\OfferResource\Pages\CreateOffer;
use Liberu\RealEstate\OffersFilament\Resources\OfferResource\Pages\EditOffer;
use Liberu\RealEstate\OffersFilament\Resources\OfferResource\Pages\ListOffers;
use Livewire\Livewire;

it('manages and audits offers in the admin panel for the active team', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $outside = app(CreateOfferAction::class)->handle($otherTeam->id, $user->id, ['subject' => 'Another offer', 'amount' => 100000]);

    Gate::before(fn (User $candidate, string $ability): ?bool => $candidate->is($user) && str_ends_with($ability, ':Offer') ? true : null);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);
    Filament::bootCurrentPanel();

    expect(Filament::getCurrentPanel()->getResources())->toContain(OfferResource::class);

    Livewire::test(CreateOffer::class)
        ->fillForm(['subject' => 'First offer', 'amount' => 250000, 'terms' => '{"completion":"30 days"}'])
        ->call('create')
        ->assertHasNoFormErrors();

    $offer = Offer::query()->forTeam($team->id)->sole();
    expect($offer->created_by)->toEqual($user->id)
        ->and($offer->amount)->toBe('250000.00')
        ->and($offer->terms)->toBe(['completion' => '30 days']);

    Livewire::test(EditOffer::class, ['record' => $outside->id])->assertNotFound();
    Livewire::test(EditOffer::class, ['record' => $offer->id])
        ->fillForm(['subject' => 'Revised offer', 'amount' => 255000])
        ->call('save')
        ->assertHasNoFormErrors();

    $offer->refresh();
    expect($offer->subject)->toBe('Revised offer')
        ->and($offer->amount)->toBe('255000.00')
        ->and($offer->terms)->toBe(['completion' => '30 days']);

    $audit = DB::table('activity_log')->where('event', 'offer.amount_changed')->where('subject_id', $offer->id)->sole();
    expect((string) $audit->causer_id)->toBe((string) $user->id);

    Livewire::test(ListOffers::class)
        ->assertCanSeeTableRecords([$offer])
        ->assertCanNotSeeTableRecords([$outside]);
});

it('requires panel permissions and returns no offers without a team', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    app(CreateOfferAction::class)->handle($team->id, $user->id, ['subject' => 'Private offer', 'amount' => 100000]);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);

    Livewire::test(ListOffers::class)->assertForbidden();
    Livewire::test(CreateOffer::class)->assertForbidden();

    $user->current_team_id = null;
    Filament::setTenant(null);
    expect(OfferResource::getEloquentQuery()->exists())->toBeFalse();
});
