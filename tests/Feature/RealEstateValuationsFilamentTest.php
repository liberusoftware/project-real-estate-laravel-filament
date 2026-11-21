<?php

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Valuations\Application\CreateValuation as CreateValuationAction;
use Liberu\RealEstate\Valuations\Domain\ValuationStatus;
use Liberu\RealEstate\Valuations\Models\Valuation;
use Liberu\RealEstate\ValuationsFilament\Resources\ValuationResource;
use Liberu\RealEstate\ValuationsFilament\Resources\ValuationResource\Pages\CreateValuation;
use Liberu\RealEstate\ValuationsFilament\Resources\ValuationResource\Pages\EditValuation;
use Liberu\RealEstate\ValuationsFilament\Resources\ValuationResource\Pages\ListValuations;
use Livewire\Livewire;

it('creates, edits and progresses team valuations in the admin panel', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $otherValuation = app(CreateValuationAction::class)->handle($otherTeam->id, $user->id, ['subject' => 'Other appraisal']);
    Gate::before(fn (User $candidate, string $ability): ?bool => $candidate->is($user) && str_ends_with($ability, ':Valuation') ? true : null);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);
    Filament::bootCurrentPanel();

    expect(Filament::getCurrentPanel()->getResources())->toContain(ValuationResource::class);

    Livewire::test(CreateValuation::class)
        ->fillForm(['subject' => 'Market appraisal', 'status' => 'draft', 'fee_amount' => 250])
        ->call('create')
        ->assertHasNoFormErrors();

    $valuation = Valuation::query()->forTeam($team->id)->sole();
    expect($valuation->created_by)->toEqual($user->id)
        ->and($valuation->fee_amount)->toBe('250.00');

    Livewire::test(EditValuation::class, ['record' => $otherValuation->id])->assertNotFound();
    Livewire::test(EditValuation::class, ['record' => $valuation->id])
        ->fillForm(['subject' => 'Updated appraisal'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($valuation->refresh()->subject)->toBe('Updated appraisal');

    Livewire::test(ListValuations::class)
        ->assertCanSeeTableRecords([$valuation])
        ->assertCanNotSeeTableRecords([$otherValuation])
        ->callAction(TestAction::make('schedule')->table($valuation), data: ['scheduled_at' => now()->addDay()->toDateTimeString()])
        ->assertHasNoActionErrors();
    expect($valuation->refresh()->status)->toBe(ValuationStatus::Scheduled);

    Livewire::test(ListValuations::class)
        ->callAction(TestAction::make('complete')->table($valuation), data: ['valued_amount' => 350000])
        ->assertHasNoActionErrors();
    expect($valuation->refresh()->status)->toBe(ValuationStatus::Completed)
        ->and($valuation->valued_amount)->toBe('350000.00');

    Livewire::test(ListValuations::class)
        ->callAction(TestAction::make('convert')->table($valuation), data: ['type' => 'instruction'])
        ->assertHasNoActionErrors();
    expect($valuation->refresh()->status)->toBe(ValuationStatus::Converted)
        ->and($valuation->conversion)->toBe(['type' => 'instruction']);

    Livewire::test(ListValuations::class)
        ->callAction(TestAction::make('property_estimate')->table($valuation), data: [
            'area_sqft' => 1200, 'bedrooms' => 3, 'bathrooms' => 2, 'year_built' => 2018, 'property_type' => 'detached',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified()
        ->callAction(TestAction::make('mortgage_estimate')->table($valuation), data: [
            'property_price' => 240000, 'loan_amount' => 120000, 'interest_rate' => 0, 'loan_term_years' => 10,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Estimated payment: 1,000.00 per month')
        ->callAction(TestAction::make('rental_yield_estimate')->table($valuation), data: [
            'property_value' => 200000, 'annual_rental_income' => 24000, 'annual_expenses' => 6000,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Estimated net yield: 9.00%');
});

it('requires panel permissions and returns no valuations without a team', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    app(CreateValuationAction::class)->handle($team->id, $user->id, ['subject' => 'Private appraisal']);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);

    Livewire::test(ListValuations::class)->assertForbidden();
    Livewire::test(CreateValuation::class)->assertForbidden();

    $user->current_team_id = null;
    Filament::setTenant(null);
    expect(ValuationResource::getEloquentQuery()->exists())->toBeFalse();
});
