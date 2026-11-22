<?php

use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\GenericUser;
use Liberu\Foundation\IdentityFilament\Resources\UserResource;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource;
use Liberu\RealEstate\PropertyManagement\Models\Inspection;
use Liberu\RealEstate\PropertyManagementFilament\Resources\InspectionResource;
use Liberu\RealEstate\PropertyManagementFilament\Resources\InspectionResource\Pages\CreateInspection;
use Liberu\RealEstate\PropertyManagementFilament\Resources\InspectionResource\Pages\EditInspection;
use Liberu\RealEstate\PropertyManagementFilament\Resources\MaintenanceRequestResource;
use Liberu\RealEstate\PropertyManagementFilament\Resources\MaintenanceRequestResource\Pages\CreateMaintenanceRequest;
use Liberu\RealEstate\PropertyManagementFilament\Resources\MaintenanceRequestResource\Pages\EditMaintenanceRequest;
use Liberu\RealEstate\PropertyManagementFilament\Resources\ManagementRecordResource;
use Liberu\RealEstate\PropertyManagementFilament\Resources\ManagementRecordResource\Pages\CreateManagementRecord;
use Liberu\RealEstate\PropertyManagementFilament\Resources\ManagementRecordResource\Pages\EditManagementRecord;
use Liberu\RealEstate\PropertyManagementFilament\Resources\VendorQuoteResource;
use Liberu\RealEstate\PropertyManagementFilament\Resources\VendorQuoteResource\Pages\CreateVendorQuote;
use Liberu\RealEstate\PropertyManagementFilament\Resources\VendorQuoteResource\Pages\EditVendorQuote;
use Liberu\RealEstate\PropertyManagementFilament\Resources\WorkOrderResource;
use Liberu\RealEstate\PropertyManagementFilament\Resources\WorkOrderResource\Pages\CreateWorkOrder;
use Liberu\RealEstate\PropertyManagementFilament\Resources\WorkOrderResource\Pages\EditWorkOrder;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('builds every module resource form and page map', function () {
    foreach ([
        UserResource::class,
        TeamResource::class,
        InspectionResource::class,
        MaintenanceRequestResource::class,
        ManagementRecordResource::class,
        VendorQuoteResource::class,
        WorkOrderResource::class,
    ] as $resource) {
        $schema = $resource::form(Schema::make());
        $table = $resource::table(Table::make(Mockery::mock(HasTable::class)));

        expect($resource::getModelLabel())->toBeString()
            ->and($resource::getPluralModelLabel())->toBeString()
            ->and($schema->getComponents())->not->toBeEmpty()
            ->and($table->getColumns())->not->toBeEmpty()
            ->and($resource::getPages())->toHaveKeys(['index', 'create', 'edit']);
    }
});

it('scopes property management resources to the active team and hides them without one', function () {
    $resources = [
        InspectionResource::class,
        MaintenanceRequestResource::class,
        ManagementRecordResource::class,
        VendorQuoteResource::class,
        WorkOrderResource::class,
    ];

    foreach ($resources as $resource) {
        expect($resource::getEloquentQuery()->toSql())->toContain('1=0');
    }

    $this->actingAs(new GenericUser(['id' => 1, 'current_team_id' => 42]));

    foreach ($resources as $resource) {
        expect($resource::getEloquentQuery()->toSql())->toContain('team_id');
    }
});

it('rejects property management writes without an authenticated tenant', function () {
    $createPages = [
        CreateInspection::class,
        CreateMaintenanceRequest::class,
        CreateManagementRecord::class,
        CreateVendorQuote::class,
        CreateWorkOrder::class,
    ];
    $editPages = [
        EditInspection::class,
        EditMaintenanceRequest::class,
        EditManagementRecord::class,
        EditVendorQuote::class,
        EditWorkOrder::class,
    ];

    foreach ($createPages as $pageClass) {
        $page = (new ReflectionClass($pageClass))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($pageClass, 'handleRecordCreation');

        expect(fn () => $method->invoke($page, []))->toThrow(HttpException::class);
    }

    $this->actingAs(new GenericUser(['id' => 1, 'current_team_id' => 7]));

    foreach ($editPages as $pageClass) {
        $page = (new ReflectionClass($pageClass))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($pageClass, 'handleRecordUpdate');
        $exception = null;

        try {
            $method->invoke($page, new Inspection(['team_id' => 42]), []);
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        expect($exception)->toBeInstanceOf(HttpException::class)
            ->and($exception->getStatusCode())->toBe(403);
    }
});
