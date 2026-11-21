<?php

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\SalesProgression\Application\CreateSalesProgression as CreateSalesProgressionAction;
use Liberu\RealEstate\SalesProgression\Domain\SalesProgressionStatus;
use Liberu\RealEstate\SalesProgression\Models\SalesProgression;
use Liberu\RealEstate\SalesProgressionFilament\Resources\SalesProgressionResource;
use Liberu\RealEstate\SalesProgressionFilament\Resources\SalesProgressionResource\Pages\CreateSalesProgression;
use Liberu\RealEstate\SalesProgressionFilament\Resources\SalesProgressionResource\Pages\EditSalesProgression;
use Liberu\RealEstate\SalesProgressionFilament\Resources\SalesProgressionResource\Pages\ListSalesProgressions;
use Livewire\Livewire;

it('manages team sales and their milestones through the admin panel', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $outside = app(CreateSalesProgressionAction::class)->handle($otherTeam->id, $user->id, ['subject' => 'Other sale']);
    Gate::before(fn (User $candidate, string $ability): ?bool => $candidate->is($user) && str_ends_with($ability, ':SalesProgression') ? true : null);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);
    Filament::bootCurrentPanel();

    expect(Filament::getCurrentPanel()->getResources())->toContain(SalesProgressionResource::class);

    Livewire::test(CreateSalesProgression::class)
        ->fillForm(['subject' => 'New sale', 'status' => 'in_progress'])
        ->call('create')
        ->assertHasNoFormErrors();
    $progression = SalesProgression::query()->forTeam($team->id)->sole();

    Livewire::test(EditSalesProgression::class, ['record' => $outside->id])->assertNotFound();
    Livewire::test(EditSalesProgression::class, ['record' => $progression->id])
        ->fillForm(['subject' => 'Updated sale'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($progression->refresh()->subject)->toBe('Updated sale');

    $list = Livewire::test(ListSalesProgressions::class)
        ->assertCanSeeTableRecords([$progression])
        ->assertCanNotSeeTableRecords([$outside])
        ->callAction(TestAction::make('section')->table($progression), data: [
            'section' => 'milestones', 'value' => '{"memorandum_issued":true}',
        ])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('exchange')->table($progression))
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('complete')->table($progression))
        ->assertHasNoActionErrors();

    expect($progression->refresh()->status)->toBe(SalesProgressionStatus::Completed)
        ->and($progression->milestones)->toBe(['memorandum_issued' => true])
        ->and($progression->completed_at)->not->toBeNull();

    $list->callAction(TestAction::make('delete')->table($progression))->assertHasNoActionErrors();
    $this->assertSoftDeleted($progression);
});

it('requires admin panel permissions and hides progressions without a team', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    app(CreateSalesProgressionAction::class)->handle($team->id, $user->id, ['subject' => 'Private sale']);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);

    Livewire::test(ListSalesProgressions::class)->assertForbidden();
    Livewire::test(CreateSalesProgression::class)->assertForbidden();

    $user->current_team_id = null;
    Filament::setTenant(null);
    expect(SalesProgressionResource::getEloquentQuery()->exists())->toBeFalse();
});
