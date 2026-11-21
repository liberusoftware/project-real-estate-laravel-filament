<?php

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Viewings\Application\CreateViewing as CreateViewingAction;
use Liberu\RealEstate\Viewings\Domain\ViewingStatus;
use Liberu\RealEstate\Viewings\Models\Viewing;
use Liberu\RealEstate\ViewingsFilament\Resources\ViewingResource;
use Liberu\RealEstate\ViewingsFilament\Resources\ViewingResource\Pages\CreateViewing;
use Liberu\RealEstate\ViewingsFilament\Resources\ViewingResource\Pages\EditViewing;
use Liberu\RealEstate\ViewingsFilament\Resources\ViewingResource\Pages\ListViewings;
use Livewire\Livewire;

it('manages the viewing lifecycle in the admin panel for the active team', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $startsAt = now()->addDays(3)->startOfHour();
    $create = app(CreateViewingAction::class);
    $outside = $create->handle($otherTeam->id, $user->id, ['subject' => 'Other viewing', 'starts_at' => $startsAt]);
    $cancelled = $create->handle($team->id, $user->id, ['subject' => 'Cancel this viewing', 'starts_at' => $startsAt->copy()->addDay()]);
    $noShow = $create->handle($team->id, $user->id, ['subject' => 'Missing guest', 'starts_at' => $startsAt->copy()->addDays(2)]);

    Gate::before(fn (User $candidate, string $ability): ?bool => $candidate->is($user) && str_ends_with($ability, ':Viewing') ? true : null);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);
    Filament::bootCurrentPanel();

    expect(Filament::getCurrentPanel()->getResources())->toContain(ViewingResource::class);

    Livewire::test(CreateViewing::class)
        ->fillForm([
            'subject' => 'First viewing',
            'status' => 'requested',
            'starts_at' => $startsAt->toDateTimeString(),
            'access' => '{"key_holder":"agent"}',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $viewing = Viewing::query()->forTeam($team->id)->where('subject', 'First viewing')->sole();
    expect($viewing->access)->toBe(['key_holder' => 'agent'])
        ->and($viewing->created_by)->toEqual($user->id);

    Livewire::test(EditViewing::class, ['record' => $outside->id])->assertNotFound();
    Livewire::test(EditViewing::class, ['record' => $viewing->id])
        ->fillForm(['subject' => 'Updated viewing', 'access' => '{"key_holder":"owner"}'])
        ->call('save')
        ->assertHasNoFormErrors();

    $viewing->refresh();
    expect($viewing->subject)->toBe('Updated viewing')
        ->and($viewing->access)->toBe(['key_holder' => 'owner'])
        ->and($viewing->starts_at->equalTo($startsAt))->toBeTrue();

    Livewire::test(ListViewings::class)
        ->assertCanSeeTableRecords([$viewing, $cancelled, $noShow])
        ->assertCanNotSeeTableRecords([$outside])
        ->callAction(TestAction::make('confirm')->table($viewing))
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('complete')->table($viewing))
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('confirm')->table($noShow))
        ->callAction(TestAction::make('no_show')->table($noShow))
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('cancel')->table($cancelled))
        ->assertHasNoActionErrors();

    expect($viewing->fresh()->status)->toBe(ViewingStatus::Completed)
        ->and($noShow->fresh()->status)->toBe(ViewingStatus::NoShow)
        ->and($cancelled->fresh()->status)->toBe(ViewingStatus::Cancelled)
        ->and($outside->fresh()->status)->toBe(ViewingStatus::Requested);
});

it('requires panel permissions and returns no viewings without a team', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    app(CreateViewingAction::class)->handle($team->id, $user->id, ['subject' => 'Private viewing', 'starts_at' => now()->addDays(3)]);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);

    Livewire::test(ListViewings::class)->assertForbidden();
    Livewire::test(CreateViewing::class)->assertForbidden();

    $user->current_team_id = null;
    Filament::setTenant(null);
    expect(ViewingResource::getEloquentQuery()->exists())->toBeFalse();
});
