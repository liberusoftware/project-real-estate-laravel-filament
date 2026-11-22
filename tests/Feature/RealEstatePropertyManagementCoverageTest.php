<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\PropertyManagement\Application\CreateInspection;
use Liberu\RealEstate\PropertyManagement\Application\CreateMaintenanceRequest;
use Liberu\RealEstate\PropertyManagement\Application\CreateManagementRecord;
use Liberu\RealEstate\PropertyManagement\Application\CreateVendorQuote;
use Liberu\RealEstate\PropertyManagement\Application\CreateWorkOrder;
use Liberu\RealEstate\PropertyManagement\Application\DecideVendorQuote;
use Liberu\RealEstate\PropertyManagement\Application\RecordManagementFailure;
use Liberu\RealEstate\PropertyManagement\Application\RecordWorkOrderUpdate;
use Liberu\RealEstate\PropertyManagement\Application\TransitionManagementRecord;
use Liberu\RealEstate\PropertyManagement\Application\UpdateInspection;
use Liberu\RealEstate\PropertyManagement\Application\UpdateMaintenanceRequest;
use Liberu\RealEstate\PropertyManagement\Application\UpdateManagementDetails;
use Liberu\RealEstate\PropertyManagement\Application\UpdateManagementRecord;
use Liberu\RealEstate\PropertyManagement\Domain\ManagementStatus;
use Liberu\RealEstate\PropertyManagement\Models\WorkOrder;
use Liberu\RealEstate\PropertyManagement\Policies\ManagementRecordPolicy;
use Liberu\RealEstate\PropertyManagement\Queries\ListManagementRecords;
use Liberu\RealEstate\PropertyManagementLivewire\Components\MaintenanceRequestForm;
use Liberu\RealEstate\PropertyManagementLivewire\Components\ManagementRecordList;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('exercises all property management API endpoints', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $otherUser = User::factory()->create(['current_team_id' => $otherTeam->id]);
    $teamlessUser = User::factory()->create(['current_team_id' => null]);

    Sanctum::actingAs($teamlessUser);
    $this->getJson('/api/v1/real-estate/property-management')->assertForbidden();
    $this->getJson('/api/v1/real-estate/inspections')->assertForbidden();
    $this->getJson('/api/v1/real-estate/maintenance')->assertForbidden();
    $this->getJson('/api/v1/real-estate/contractor-quotes')->assertForbidden();
    $this->getJson('/api/v1/real-estate/work-orders')->assertForbidden();

    Sanctum::actingAs($user);

    // Management records endpoints
    $recordRes = $this->postJson('/api/v1/real-estate/property-management', [
        'subject' => 'Annual Gas Safety Check',
        'capability' => 'maintenance',
        'property_id' => 11,
        'party_id' => 22,
        'details' => ['description' => 'Check boiler', 'priority' => 'normal'],
    ])->assertCreated();
    $recordId = $recordRes->json('data.id');

    $this->getJson('/api/v1/real-estate/property-management')->assertOk()->assertJsonPath('data.0.id', $recordId);
    $this->getJson("/api/v1/real-estate/property-management/{$recordId}")->assertOk()->assertJsonPath('data.subject', 'Annual Gas Safety Check');
    $this->patchJson("/api/v1/real-estate/property-management/{$recordId}", ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->patchJson("/api/v1/real-estate/property-management/{$recordId}/details", ['details' => ['description' => 'Replaced valve', 'priority' => 'high']])->assertOk();
    $this->postJson("/api/v1/real-estate/property-management/{$recordId}/failure", ['reason' => 'Tenant unavailable'])->assertOk();

    // Inspections endpoints
    $inspRes = $this->postJson('/api/v1/real-estate/inspections', [
        'property_id' => 11,
        'type' => 'routine',
        'scheduled_at' => now()->addDay()->toISOString(),
        'notes' => 'Quarterly check',
    ])->assertCreated();
    $inspId = $inspRes->json('data.id');

    $this->getJson('/api/v1/real-estate/inspections')->assertOk()->assertJsonPath('data.0.id', $inspId);
    $this->getJson("/api/v1/real-estate/inspections/{$inspId}")->assertOk();
    $this->patchJson("/api/v1/real-estate/inspections/{$inspId}", ['status' => 'in_progress', 'notes' => 'Started'])->assertOk();

    // Maintenance requests endpoints
    $maintRes = $this->postJson('/api/v1/real-estate/maintenance', [
        'property_id' => 11,
        'title' => 'Broken radiator',
        'description' => 'Bedroom radiator is cold',
        'requested_date' => now()->toDateString(),
        'priority' => 'high',
    ])->assertCreated();
    $maintId = $maintRes->json('data.id');

    $this->getJson('/api/v1/real-estate/maintenance')->assertOk()->assertJsonPath('data.0.id', $maintId);
    $this->getJson("/api/v1/real-estate/maintenance/{$maintId}")->assertOk();
    $this->patchJson("/api/v1/real-estate/maintenance/{$maintId}", ['status' => 'in_progress'])->assertOk();

    // Contractor quotes endpoints
    $quoteRes = $this->postJson('/api/v1/real-estate/contractor-quotes', [
        'vendor_id' => 5,
        'property_id' => 11,
        'work_description' => 'Replace radiator valve',
        'quote_amount' => 250,
        'labor_cost' => 150,
        'materials_cost' => 80,
        'additional_costs' => 20,
        'quote_date' => now()->toDateString(),
        'valid_until' => now()->addDays(14)->toDateString(),
    ])->assertCreated();
    $quoteId = $quoteRes->json('data.id');

    $this->getJson('/api/v1/real-estate/contractor-quotes')->assertOk()->assertJsonPath('data.0.id', $quoteId);
    $this->getJson("/api/v1/real-estate/contractor-quotes/{$quoteId}")->assertOk();
    $this->patchJson("/api/v1/real-estate/contractor-quotes/{$quoteId}", ['notes' => 'Includes warranty'])->assertOk();
    $this->postJson("/api/v1/real-estate/contractor-quotes/{$quoteId}/decision", ['decision' => 'accepted'])->assertOk();

    // Work orders endpoints
    $orderRes = $this->postJson('/api/v1/real-estate/work-orders', [
        'property_id' => 11,
        'title' => 'Radiator repair',
        'description' => 'Replace TRV valve',
        'work_type' => 'plumbing',
        'scheduled_date' => now()->subDay()->toISOString(),
    ])->assertCreated();
    $orderId = $orderRes->json('data.id');

    $this->getJson('/api/v1/real-estate/work-orders')->assertOk()->assertJsonPath('data.0.id', $orderId);
    $this->getJson("/api/v1/real-estate/work-orders/{$orderId}")->assertOk();
    $this->patchJson("/api/v1/real-estate/work-orders/{$orderId}", ['priority' => 2])->assertOk();
    $this->postJson("/api/v1/real-estate/work-orders/{$orderId}/updates", [
        'update_type' => 'status_change',
        'status_change' => 'in_progress',
        'description' => 'Engineer on site',
        'progress_percentage' => 50,
    ])->assertCreated();
    $this->getJson("/api/v1/real-estate/work-orders/{$orderId}/updates")->assertOk()->assertJsonCount(1, 'data');

    // Cross-team isolation
    Sanctum::actingAs($otherUser);
    $this->getJson("/api/v1/real-estate/property-management/{$recordId}")->assertNotFound();
    $this->getJson("/api/v1/real-estate/inspections/{$inspId}")->assertNotFound();
    $this->getJson("/api/v1/real-estate/maintenance/{$maintId}")->assertNotFound();
    $this->getJson("/api/v1/real-estate/contractor-quotes/{$quoteId}")->assertNotFound();
    $this->getJson("/api/v1/real-estate/work-orders/{$orderId}")->assertNotFound();
});

it('exercises property management livewire components', function (): void {
    expect((new ManagementRecordList())->render()->getData()['records'])->toBeEmpty();

    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    app(CreateManagementRecord::class)->handle($team->id, $user->id, ['subject' => 'Boiler Service', 'capability' => 'maintenance']);

    $this->actingAs($user);

    Livewire::test(ManagementRecordList::class)
        ->set('search', 'Boiler')
        ->assertOk();

    Livewire::test(MaintenanceRequestForm::class)
        ->set('property_id', 12)
        ->set('title', 'Leaking tap')
        ->set('description', 'Kitchen tap drips continuously')
        ->call('submit')
        ->assertDispatched('maintenanceRequestSubmitted');
});

it('covers property management application validation paths, models, policies, and queries', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $otherUser = User::factory()->create(['current_team_id' => $otherTeam->id]);

    // CreateManagementRecord validation
    $createRecord = app(CreateManagementRecord::class);
    expect(fn () => $createRecord->handle($team->id, $user->id, ['subject' => '  ', 'capability' => 'maintenance']))->toThrow(ValidationException::class);
    expect(fn () => $createRecord->handle($team->id, $user->id, ['subject' => 'Valid', 'capability' => 'invalid']))->toThrow(ValidationException::class);
    $record = $createRecord->handle($team->id, $user->id, ['subject' => 'Roof Check', 'capability' => 'maintenance']);

    // Model, Policy, Query
    expect($record->team->id)->toBe($team->id);
    $policy = new ManagementRecordPolicy();
    expect($policy->view($user, $record))->toBeTrue()
        ->and($policy->update($user, $record))->toBeTrue()
        ->and($policy->delete($user, $record))->toBeTrue()
        ->and($policy->view($otherUser, $record))->toBeFalse();
    expect(app(ListManagementRecords::class)->handle($team->id, 'maintenance', 10)->total())->toBe(1);

    // UpdateManagementRecord validation & transition
    $updateRecord = app(UpdateManagementRecord::class);
    expect(fn () => $updateRecord->handle($record, $team->id, $user->id, ['subject' => '']))->toThrow(ValidationException::class);
    expect(fn () => $updateRecord->handle($record, $team->id, $user->id, ['capability' => 'bad']))->toThrow(ValidationException::class);
    expect(fn () => $updateRecord->handle($record, $team->id, $user->id, ['status' => 'bad']))->toThrow(ValidationException::class);
    $record = $updateRecord->handle($record, $team->id, $user->id, ['subject' => 'Roof Check Updated', 'status' => 'in_progress']);

    // UpdateManagementDetails validation & wrong team
    $updateDetails = app(UpdateManagementDetails::class);
    expect(fn () => $updateDetails->handle($record, $otherTeam->id, $user->id, ['description' => 'Ok', 'priority' => 'low']))->toThrow(HttpException::class);
    expect(fn () => $updateDetails->handle($record, $team->id, $user->id, []))->toThrow(ValidationException::class);

    // RecordManagementFailure validation & wrong team
    $recordFailure = app(RecordManagementFailure::class);
    expect(fn () => $recordFailure->handle($record, $otherTeam->id, $user->id, 'Reason'))->toThrow(HttpException::class);
    expect(fn () => $recordFailure->handle($record, $team->id, $user->id, '   '))->toThrow(ValidationException::class);

    // TransitionManagementRecord validation & wrong team
    $transitionRecord = app(TransitionManagementRecord::class);
    expect(fn () => $transitionRecord->handle($record, $otherTeam->id, $user->id, ManagementStatus::Completed))->toThrow(HttpException::class);
    $record = $transitionRecord->handle($record, $team->id, $user->id, ManagementStatus::Completed);
    expect(fn () => $transitionRecord->handle($record, $team->id, $user->id, ManagementStatus::Draft))->toThrow(ValidationException::class);

    // CreateInspection & UpdateInspection validation
    $createInspection = app(CreateInspection::class);
    expect(fn () => $createInspection->handle($team->id, $user->id, ['type' => 'routine', 'scheduled_at' => now()]))->toThrow(ValidationException::class);
    expect(fn () => $createInspection->handle($team->id, $user->id, ['property_id' => 1, 'type' => 'invalid', 'scheduled_at' => now()]))->toThrow(ValidationException::class);
    expect(fn () => $createInspection->handle($team->id, $user->id, ['property_id' => 1, 'type' => 'routine']))->toThrow(ValidationException::class);
    $inspection = $createInspection->handle($team->id, $user->id, ['property_id' => 1, 'type' => 'routine', 'scheduled_at' => now()]);

    $updateInspection = app(UpdateInspection::class);
    expect(fn () => $updateInspection->handle($inspection, $team->id, ['status' => 'invalid']))->toThrow(ValidationException::class);
    $inspection = $updateInspection->handle($inspection, $team->id, ['status' => 'completed']);
    expect(fn () => $updateInspection->handle($inspection, $team->id, ['status' => 'scheduled']))->toThrow(ValidationException::class);

    // CreateMaintenanceRequest & UpdateMaintenanceRequest validation
    $createMaint = app(CreateMaintenanceRequest::class);
    expect(fn () => $createMaint->handle($team->id, $user->id, ['property_id' => 1]))->toThrow(ValidationException::class);
    expect(fn () => $createMaint->handle($team->id, $user->id, ['property_id' => 1, 'title' => 'T', 'description' => 'D', 'requested_date' => now()->toDateString(), 'priority' => 'invalid']))->toThrow(ValidationException::class);
    $maint = $createMaint->handle($team->id, $user->id, ['property_id' => 1, 'title' => 'T', 'description' => 'D', 'requested_date' => now()->toDateString()]);

    $updateMaint = app(UpdateMaintenanceRequest::class);
    expect(fn () => $updateMaint->handle($maint, $team->id, ['status' => 'invalid']))->toThrow(ValidationException::class);
    $maint = $updateMaint->handle($maint, $team->id, ['status' => 'completed']);
    expect(fn () => $updateMaint->handle($maint, $team->id, ['status' => 'pending']))->toThrow(ValidationException::class);

    // CreateVendorQuote, DecideVendorQuote, VendorQuote model
    $createQuote = app(CreateVendorQuote::class);
    expect(fn () => $createQuote->handle($team->id, $user->id, ['vendor_id' => 1]))->toThrow(ValidationException::class);
    expect(fn () => $createQuote->handle($team->id, $user->id, ['vendor_id' => 1, 'property_id' => 1, 'work_description' => 'Work', 'quote_amount' => 100, 'quote_date' => '2026-05-10', 'valid_until' => '2026-05-01']))->toThrow(ValidationException::class);
    $quote = $createQuote->handle($team->id, $user->id, [
        'vendor_id' => 1,
        'property_id' => 1,
        'work_description' => 'Work',
        'quote_amount' => 100,
        'labor_cost' => 60,
        'materials_cost' => 30,
        'additional_costs' => 10,
        'quote_date' => now()->toDateString(),
        'valid_until' => now()->addDays(10)->toDateString(),
    ]);
    expect($quote->isValid())->toBeTrue()
        ->and($quote->totalCost())->toBe(100.0);

    $decideQuote = app(DecideVendorQuote::class);
    expect(fn () => $decideQuote->handle($quote, $team->id, $user->id, 'invalid'))->toThrow(ValidationException::class);
    $quote = $decideQuote->handle($quote, $team->id, $user->id, 'rejected', 'Too expensive');
    expect(fn () => $decideQuote->handle($quote, $team->id, $user->id, 'accepted'))->toThrow(ValidationException::class);

    // CreateWorkOrder, RecordWorkOrderUpdate, WorkOrder model
    $createOrder = app(CreateWorkOrder::class);
    expect(fn () => $createOrder->handle($team->id, $user->id, ['property_id' => 1]))->toThrow(ValidationException::class);
    $order = $createOrder->handle($team->id, $user->id, [
        'property_id' => 1,
        'title' => 'Fix door',
        'description' => 'Replace hinge',
        'work_type' => 'carpentry',
        'scheduled_date' => now()->subDay(),
    ]);
    expect(WorkOrder::query()->forTeam($team->id)->count())->toBe(1)
        ->and($order->isOverdue())->toBeTrue();

    $recordUpdate = app(RecordWorkOrderUpdate::class);
    expect(fn () => $recordUpdate->handle($order, $team->id, $user->id, ['description' => '']))->toThrow(ValidationException::class);
    expect(fn () => $recordUpdate->handle($order, $team->id, $user->id, ['description' => 'Note', 'status_change' => 'invalid']))->toThrow(ValidationException::class);
    $recordUpdate->handle($order, $team->id, $user->id, ['update_type' => 'completion', 'status_change' => 'completed', 'description' => 'Done']);
    expect($order->fresh()->isOverdue())->toBeFalse();
});
