<?php

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Instructions\Application\CreateInstruction as CreateInstructionAction;
use Liberu\RealEstate\Instructions\Domain\InstructionStatus;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\InstructionsFilament\Resources\InstructionResource;
use Liberu\RealEstate\InstructionsFilament\Resources\InstructionResource\Pages\CreateInstruction;
use Liberu\RealEstate\InstructionsFilament\Resources\InstructionResource\Pages\EditInstruction;
use Liberu\RealEstate\InstructionsFilament\Resources\InstructionResource\Pages\ListInstructions;
use Livewire\Livewire;

it('edits instructions and runs approval actions within the active Filament team', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $create = app(CreateInstructionAction::class);
    $outside = $create->handle($otherTeam->id, $user->id, ['subject' => 'Other agreement']);
    $rejected = $create->handle($team->id, $user->id, ['subject' => 'Rejected agreement']);

    Gate::before(fn (User $candidate, string $ability): ?bool => $candidate->is($user) && str_ends_with($ability, ':Instruction') ? true : null);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);
    Filament::bootCurrentPanel();

    expect(Filament::getCurrentPanel()->getResources())->toContain(InstructionResource::class);

    Livewire::test(CreateInstruction::class)
        ->fillForm(['subject' => 'New agreement'])
        ->call('create')
        ->assertHasNoFormErrors();

    $instruction = Instruction::query()->forTeam($team->id)->where('subject', 'New agreement')->sole();
    expect($instruction->status)->toBe(InstructionStatus::Draft)
        ->and($instruction->created_by)->toEqual($user->id);

    Livewire::test(EditInstruction::class, ['record' => $outside->id])->assertNotFound();
    Livewire::test(EditInstruction::class, ['record' => $instruction->id])
        ->fillForm(['subject' => 'Updated agreement'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($instruction->refresh()->subject)->toBe('Updated agreement');

    Livewire::test(ListInstructions::class)
        ->assertCanSeeTableRecords([$instruction, $rejected])
        ->assertCanNotSeeTableRecords([$outside])
        ->callAction(TestAction::make('submit')->table($instruction))
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('approve')->table($instruction))
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('withdraw')->table($instruction))
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('submit')->table($rejected))
        ->callAction(TestAction::make('reject')->table($rejected))
        ->assertHasNoActionErrors();

    expect($instruction->fresh()->status)->toBe(InstructionStatus::Withdrawn)
        ->and($rejected->fresh()->status)->toBe(InstructionStatus::Rejected)
        ->and($outside->fresh()->status)->toBe(InstructionStatus::Draft);
});

it('requires instruction panel permissions and scopes out records without a team', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    app(CreateInstructionAction::class)->handle($team->id, $user->id, ['subject' => 'Private agreement']);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);

    Livewire::test(ListInstructions::class)->assertForbidden();
    Livewire::test(CreateInstruction::class)->assertForbidden();

    $user->current_team_id = null;
    Filament::setTenant(null);
    expect(InstructionResource::getEloquentQuery()->exists())->toBeFalse();
});
