<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Instructions\Application\CreateInstruction;
use Liberu\RealEstate\Instructions\Application\DeleteInstruction;
use Liberu\RealEstate\Instructions\Application\TransitionInstruction;
use Liberu\RealEstate\Instructions\Application\UpdateInstruction;
use Liberu\RealEstate\Instructions\Domain\InstructionStatus;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\Instructions\Policies\InstructionPolicy;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);
it('creates a draft instruction with checks and terms', function (): void {
    $instruction = app(CreateInstruction::class)->handle(1, 5, ['subject' => 'Sole agency agreement', 'ownership_check' => ['verified' => true], 'terms' => ['commission' => '1.5']]);
    expect($instruction->status->value)->toBe('draft')->and($instruction->terms['commission'])->toBe('1.5');
});
it('rejects empty subjects and archives an instruction for its team', function (): void {
    expect(fn () => app(CreateInstruction::class)->handle(1, 5, ['subject' => '']))->toThrow(ValidationException::class);
    $instruction = Instruction::query()->create(['team_id' => 1, 'subject' => 'Instruction', 'status' => 'draft']);
    app(DeleteInstruction::class)->handle($instruction, 1);
    expect(Instruction::withTrashed()->find($instruction->id)->deleted_at)->not->toBeNull();
});

it('requires explicit lifecycle transitions for approval and withdrawal', function (): void {
    $instruction = app(CreateInstruction::class)->handle(1, 5, [
        'subject' => 'Agreement',
        'ownership_check' => ['verified' => true],
        'terms' => ['commission' => '1.5'],
        'disclosures' => ['material_facts' => true],
    ]);

    $transition = app(TransitionInstruction::class);
    $instruction = $transition->handle($instruction, 1, 5, InstructionStatus::PendingApproval);
    $instruction = $transition->handle($instruction, 1, 5, InstructionStatus::Approved);
    $instruction = $transition->handle($instruction, 1, 5, InstructionStatus::Withdrawn);

    expect($instruction->status)->toBe(InstructionStatus::Withdrawn)
        ->and($instruction->approved_at)->not->toBeNull()
        ->and($instruction->withdrawn_at)->not->toBeNull()
        ->and(fn () => $transition->handle($instruction, 1, 5, InstructionStatus::Approved))
        ->toThrow(ValidationException::class);
});

it('keeps instruction edits scoped to the team and separate from lifecycle changes', function (): void {
    $team = Team::factory()->create();
    $outside = Team::factory()->create();
    $instruction = app(CreateInstruction::class)->handle($team->id, $team->user_id, ['subject' => 'Agreement']);
    $update = app(UpdateInstruction::class);

    expect(fn () => $update->handle($instruction, $outside->id, ['subject' => 'Outside edit']))
        ->toThrow(NotFoundHttpException::class)
        ->and(fn () => $update->handle($instruction, $team->id, ['subject' => ' ']))
        ->toThrow(ValidationException::class, 'An instruction subject is required.');

    foreach (['status' => 'approved', 'approved_at' => now(), 'withdrawn_at' => now()] as $field => $value) {
        expect(fn () => $update->handle($instruction, $team->id, [$field => $value]))
            ->toThrow(ValidationException::class, 'Instruction lifecycle changes must use the transition action.');
    }

    $instruction = $update->handle($instruction, (string) $team->id, ['subject' => 'Updated agreement']);

    expect($instruction->subject)->toBe('Updated agreement')
        ->and($instruction->status)->toBe(InstructionStatus::Draft)
        ->and($instruction->team->is($team))->toBeTrue()
        ->and(Instruction::query()->forTeam($outside->id)->exists())->toBeFalse();
});

it('exposes the instruction lifecycle through the authenticated team API', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    $outside = app(CreateInstruction::class)->handle(20, $user->id, ['subject' => 'Outside instruction']);
    $endpoint = '/api/v1/real-estate/instructions';
    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));

    $this->getJson($endpoint)->assertUnauthorized();
    $this->actingAs($user, 'sanctum');
    $id = $this->postJson($endpoint, ['subject' => 'Agency agreement'])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    $url = "$endpoint/$id";

    $this->getJson("$endpoint?page_size=1")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
    $this->getJson("$endpoint/{$outside->id}")->assertNotFound();
    $this->postJson("$endpoint/{$outside->id}/transition/pending_approval")->assertNotFound();
    $this->getJson($url)->assertOk()->assertJsonPath('data.subject', 'Agency agreement');
    $this->patchJson($url, ['subject' => 'Updated agreement'])
        ->assertOk()->assertJsonPath('data.subject', 'Updated agreement');
    $this->postJson("$url/transition/unknown")->assertNotFound();
    $this->postJson("$url/transition/approved")->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson("$url/transition/pending_approval")->assertOk()->assertJsonPath('data.status', 'pending_approval');
    $this->postJson("$url/transition/approved", [
        'ownership_check' => ['verified' => true],
        'terms' => ['commission' => '1.5'],
        'disclosures' => ['material_facts' => true],
    ])->assertOk()->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.ownership_check.verified', true)
        ->assertJsonPath('data.terms.commission', '1.5')
        ->assertJsonPath('data.disclosures.material_facts', true);
    $this->postJson("$url/transition/withdrawn")->assertOk()->assertJsonPath('data.status', 'withdrawn');
    $this->deleteJson($url)->assertNoContent();
    $this->assertSoftDeleted('real_estate_instructions', ['id' => $id]);
});

it('uses the corresponding permission for each instruction policy action', function (): void {
    $policy = new InstructionPolicy();
    $instruction = new Instruction();
    $allowed = User::factory()->make();
    $denied = User::factory()->make();

    foreach ([
        'viewAny' => false, 'view' => true, 'create' => false, 'update' => true,
        'delete' => true, 'deleteAny' => false, 'restore' => true, 'forceDelete' => true,
        'forceDeleteAny' => false, 'restoreAny' => false, 'replicate' => true, 'reorder' => false,
    ] as $action => $requiresInstruction) {
        $arguments = $requiresInstruction ? [$instruction] : [];
        expect($policy->{$action}($allowed, ...$arguments))->toBeFalse();
        Gate::define(ucfirst($action).':Instruction', fn (User $user): bool => $user === $allowed);

        expect($policy->{$action}($allowed, ...$arguments))->toBeTrue()
            ->and($policy->{$action}($denied, ...$arguments))->toBeFalse();
    }
});

it('searches and transitions instructions through the active team Livewire list', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    $create = app(CreateInstruction::class);
    $first = $create->handle(10, $user->id, ['subject' => 'First agreement']);
    $second = $create->handle(10, $user->id, ['subject' => 'Second agreement']);
    $outside = $create->handle(20, $user->id, ['subject' => 'Outside agreement']);
    $this->actingAs($user);

    Livewire::test('module-real-estate-instructions::instruction-list')
        ->assertSee('First agreement')
        ->assertSee('Second agreement')
        ->assertDontSee('Outside agreement')
        ->set('search', 'First')
        ->assertDontSee('Second agreement')
        ->call('submitInstruction', $outside->id)
        ->assertSet('error', fn (?string $error): bool => filled($error))
        ->call('submitInstruction', $first->id)
        ->assertSet('error', null)
        ->call('approveInstruction', $first->id)
        ->assertSee('First agreement (approved)')
        ->call('withdrawInstruction', $first->id)
        ->assertSee('First agreement (withdrawn)')
        ->set('search', '')
        ->call('submitInstruction', $second->id)
        ->call('rejectInstruction', $second->id)
        ->assertSee('Second agreement (rejected)');

    expect($first->fresh()->status)->toBe(InstructionStatus::Withdrawn)
        ->and($second->fresh()->status)->toBe(InstructionStatus::Rejected)
        ->and($outside->fresh()->status)->toBe(InstructionStatus::Draft);
});

it('renders an empty instruction list and denies transitions without a team', function (): void {
    Livewire::test('module-real-estate-instructions::instruction-list')
        ->assertSee('No instructions found.')
        ->call('submitInstruction', 1)
        ->assertForbidden();
});

it('rolls back and replays the instruction schema', function (): void {
    $migration = require base_path('modules/real-estate-instructions/database/migrations/2026_08_23_000006_create_real_estate_instructions_table.php');
    $table = 'real_estate_instructions';
    expect(Schema::hasTable($table))->toBeTrue();

    $migration->down();
    expect(Schema::hasTable($table))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumns($table, ['team_id', 'status', 'approved_at', 'withdrawn_at']))->toBeTrue();
});
