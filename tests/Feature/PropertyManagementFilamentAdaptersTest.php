<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Panel;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\PropertyManagementFilament\PropertyManagementFilamentPlugin;
use Liberu\RealEstate\PropertyManagementFilament\Resources\InspectionResource;
use Liberu\RealEstate\PropertyManagementFilament\Resources\InspectionResource\Pages\CreateInspection;
use Liberu\RealEstate\PropertyManagementFilament\Resources\InspectionResource\Pages\EditInspection;
use Liberu\RealEstate\PropertyManagementFilament\Resources\InspectionResource\Pages\ListInspections;
use Liberu\RealEstate\PropertyManagementFilament\Resources\MaintenanceRequestResource;
use Liberu\RealEstate\PropertyManagementFilament\Resources\MaintenanceRequestResource\Pages\CreateMaintenanceRequest;
use Liberu\RealEstate\PropertyManagementFilament\Resources\MaintenanceRequestResource\Pages\EditMaintenanceRequest;
use Liberu\RealEstate\PropertyManagementFilament\Resources\MaintenanceRequestResource\Pages\ListMaintenanceRequests;
use Liberu\RealEstate\PropertyManagementFilament\Resources\ManagementRecordResource;
use Liberu\RealEstate\PropertyManagementFilament\Resources\ManagementRecordResource\Pages\CreateManagementRecord;
use Liberu\RealEstate\PropertyManagementFilament\Resources\ManagementRecordResource\Pages\EditManagementRecord;
use Liberu\RealEstate\PropertyManagementFilament\Resources\ManagementRecordResource\Pages\ListManagementRecords;
use Liberu\RealEstate\PropertyManagementFilament\Resources\VendorQuoteResource;
use Liberu\RealEstate\PropertyManagementFilament\Resources\VendorQuoteResource\Pages\CreateVendorQuote;
use Liberu\RealEstate\PropertyManagementFilament\Resources\VendorQuoteResource\Pages\EditVendorQuote;
use Liberu\RealEstate\PropertyManagementFilament\Resources\VendorQuoteResource\Pages\ListVendorQuotes;
use Liberu\RealEstate\PropertyManagementFilament\Resources\WorkOrderResource;
use Liberu\RealEstate\PropertyManagementFilament\Resources\WorkOrderResource\Pages\CreateWorkOrder;
use Liberu\RealEstate\PropertyManagementFilament\Resources\WorkOrderResource\Pages\EditWorkOrder;
use Liberu\RealEstate\PropertyManagementFilament\Resources\WorkOrderResource\Pages\ListWorkOrders;

it('registers resources and dispatches property-management page actions within the active team', function (): void {
    $resources = [
        ManagementRecordResource::class,
        InspectionResource::class,
        MaintenanceRequestResource::class,
        VendorQuoteResource::class,
        WorkOrderResource::class,
    ];
    $panel = Mockery::mock(Panel::class);
    $panel->shouldReceive('resources')->once()->with($resources);
    $plugin = PropertyManagementFilamentPlugin::make();

    expect($plugin->getId())->toBe('real-estate-property-management');
    $plugin->register($panel);
    $plugin->boot($panel);

    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $this->actingAs($user);

    $create = [
        [CreateInspection::class, ['property_id' => 1, 'type' => 'routine', 'scheduled_at' => now()]],
        [CreateMaintenanceRequest::class, ['property_id' => 1, 'title' => 'Repair boiler', 'description' => 'Replace pump.', 'requested_date' => now()->toDateString()]],
        [CreateManagementRecord::class, ['subject' => 'Inspect boiler', 'capability' => 'maintenance']],
        [CreateVendorQuote::class, ['vendor_id' => 1, 'property_id' => 1, 'work_description' => 'Replace pump.', 'quote_amount' => 125, 'quote_date' => now()->toDateString(), 'valid_until' => now()->addWeek()->toDateString()]],
        [CreateWorkOrder::class, ['property_id' => 1, 'title' => 'Repair boiler', 'description' => 'Replace pump.', 'work_type' => 'plumbing']],
    ];
    $records = [];

    foreach ($create as [$pageClass, $attributes]) {
        $page = (new ReflectionClass($pageClass))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($pageClass, 'handleRecordCreation');
        $records[] = $method->invoke($page, $attributes);
    }

    expect($records)->toHaveCount(5);
    foreach ($records as $record) {
        expect((string) $record->team_id)->toBe((string) $team->id);
    }

    $updates = [
        [EditInspection::class, $records[0], ['notes' => 'Boiler inspected.']],
        [EditMaintenanceRequest::class, $records[1], ['description' => 'Pump replaced.']],
        [EditManagementRecord::class, $records[2], ['subject' => 'Boiler inspected']],
        [EditVendorQuote::class, $records[3], ['work_description' => 'Pump replaced.']],
        [EditWorkOrder::class, $records[4], ['description' => 'Pump replaced.']],
    ];

    foreach ($updates as [$pageClass, $record, $attributes]) {
        $page = (new ReflectionClass($pageClass))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($pageClass, 'handleRecordUpdate');
        $updated = $method->invoke($page, $record, $attributes);

        expect($updated->fresh())->not->toBeNull();
    }

    foreach ([
        ListInspections::class,
        ListMaintenanceRequests::class,
        ListManagementRecords::class,
        ListVendorQuotes::class,
        ListWorkOrders::class,
    ] as $pageClass) {
        $page = (new ReflectionClass($pageClass))->newInstanceWithoutConstructor();
        $actions = (new ReflectionMethod($pageClass, 'getHeaderActions'))->invoke($page);

        expect($actions)->toHaveCount(1);
    }
});
