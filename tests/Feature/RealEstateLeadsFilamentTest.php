<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Leads\Application\CreateLead as CreateLeadAction;
use Liberu\RealEstate\Leads\Domain\LeadStatus;
use Liberu\RealEstate\Leads\Models\Lead;
use Liberu\RealEstate\LeadsFilament\Resources\LeadResource;
use Liberu\RealEstate\LeadsFilament\Resources\LeadResource\Pages\CreateLead;
use Liberu\RealEstate\LeadsFilament\Resources\LeadResource\Pages\EditLead;
use Liberu\RealEstate\LeadsFilament\Resources\LeadResource\Pages\ListLeads;
use Livewire\Livewire;

it('manages and filters the active team lead pipeline in the admin panel', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $assignee = User::factory()->create();
    $outsideAssignee = User::factory()->create();
    $team->users()->attach($assignee);
    $otherTeam->users()->attach($outsideAssignee);
    $create = app(CreateLeadAction::class);
    $outside = $create->handle($otherTeam->id, ['name' => 'Other lead', 'email' => 'other@example.test']);
    $unqualified = $create->handle($team->id, ['name' => 'New lead', 'email' => 'new@example.test']);

    Gate::before(fn (User $candidate, string $ability): ?bool => $candidate->is($user) && str_ends_with($ability, ':Lead') ? true : null);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);
    Filament::bootCurrentPanel();

    expect(Filament::getCurrentPanel()->getResources())->toContain(LeadResource::class);

    $form = [
        'name' => 'Jane Buyer',
        'email' => 'jane@example.test',
        'source' => 'manual',
        'status' => 'new',
    ];

    Livewire::test(CreateLead::class)
        ->fillForm([...$form, 'assigned_to' => $outsideAssignee->id])
        ->call('create')
        ->assertHasFormErrors(['assigned_to']);

    Livewire::test(CreateLead::class)
        ->fillForm([...$form, 'assigned_to' => $assignee->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $lead = Lead::query()->forTeam($team->id)->where('email', $form['email'])->sole();
    expect($lead->assignee->is($assignee))->toBeTrue();

    Livewire::test(EditLead::class, ['record' => $outside->id])->assertNotFound();
    Livewire::test(EditLead::class, ['record' => $lead->id])
        ->fillForm(['status' => 'qualified', 'notes' => 'Financing confirmed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($lead->refresh()->status)->toBe(LeadStatus::Qualified)
        ->and($lead->notes)->toBe('Financing confirmed');

    Livewire::test(ListLeads::class)
        ->assertCanSeeTableRecords([$lead, $unqualified])
        ->assertCanNotSeeTableRecords([$outside])
        ->filterTable('status', 'qualified')
        ->assertCanSeeTableRecords([$lead])
        ->assertCanNotSeeTableRecords([$unqualified]);
});

it('requires lead panel permissions and leaves the collection empty without a team', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    app(CreateLeadAction::class)->handle($team->id, ['name' => 'Private lead', 'email' => 'private@example.test']);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);

    Livewire::test(ListLeads::class)->assertForbidden();
    Livewire::test(CreateLead::class)->assertForbidden();

    $user->current_team_id = null;
    Filament::setTenant(null);
    expect(LeadResource::getEloquentQuery()->exists())->toBeFalse();
});
