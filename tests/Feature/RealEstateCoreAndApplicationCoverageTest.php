<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JoelButcher\Socialstream\Providers;
use Laravel\Sanctum\Sanctum;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Liberu\RealEstate\Application\Database\Factories\UserFactory;
use Liberu\RealEstate\Application\Database\Seeders\UserSeeder;
use Liberu\RealEstate\Application\Filament\App\Pages\AccountSetupWizard;
use Liberu\RealEstate\Application\Filament\Widgets\AdminOverviewWidget;
use Liberu\RealEstate\Application\Http\Middleware\ApplyTeamIntegrationSettings;
use Liberu\RealEstate\Core\Application\CreateAgency;
use Liberu\RealEstate\Core\Application\CreateBranch;
use Liberu\RealEstate\Core\Application\CreateCalendarEntry;
use Liberu\RealEstate\Core\Application\CreateTerritory;
use Liberu\RealEstate\Core\Application\DefineStatus;
use Liberu\RealEstate\Core\Application\NextNumber;
use Liberu\RealEstate\Core\Application\RecordAuditEntry;
use Liberu\RealEstate\Core\Application\RecordCommunication;
use Liberu\RealEstate\Core\Application\SetTerminology;
use Liberu\RealEstate\Core\Application\UpdateAgency;
use Liberu\RealEstate\Core\Application\UpdateBranch;
use Liberu\RealEstate\Core\Application\UpdateTerritory;
use Liberu\RealEstate\Core\Models\AuditEntry;
use Liberu\RealEstate\Core\Models\Branch;
use Liberu\RealEstate\Core\Models\CalendarEntry;
use Liberu\RealEstate\Core\Models\Communication;
use Liberu\RealEstate\Core\Models\Terminology;
use Liberu\RealEstate\Core\Policies\AgencyPolicy;
use Liberu\RealEstate\Core\Policies\BranchPolicy;
use Liberu\RealEstate\Core\Policies\StatusDefinitionPolicy;
use Liberu\RealEstate\Core\Policies\TerritoryPolicy;
use Liberu\RealEstate\CoreApi\Http\Controllers\CoreConfigurationController;
use Liberu\RealEstate\CoreFilament\Resources\AgencyResource\Pages\CreateAgency as CreateAgencyPage;
use Liberu\RealEstate\CoreFilament\Resources\AgencyResource\Pages\EditAgency as EditAgencyPage;
use Liberu\RealEstate\CoreFilament\Resources\BranchResource\Pages\CreateBranch as CreateBranchPage;
use Liberu\RealEstate\CoreFilament\Resources\BranchResource\Pages\EditBranch as EditBranchPage;
use Liberu\RealEstate\CoreFilament\Resources\StatusDefinitionResource\Pages\CreateStatusDefinition as CreateStatusDefinitionPage;
use Liberu\RealEstate\CoreFilament\Resources\StatusDefinitionResource\Pages\EditStatusDefinition as EditStatusDefinitionPage;
use Liberu\RealEstate\CoreFilament\Resources\TerritoryResource\Pages\CreateTerritory as CreateTerritoryPage;
use Liberu\RealEstate\CoreFilament\Resources\TerritoryResource\Pages\EditTerritory as EditTerritoryPage;
use Liberu\RealEstate\CoreLivewire\Components\AgencyList;
use Liberu\RealEstate\CoreLivewire\Components\BranchList;
use Liberu\RealEstate\CoreLivewire\Components\CoreConfigurationList;
use Liberu\RealEstate\CoreLivewire\Components\TerritoryList;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('covers real-estate-application factory, seeder, widget, middleware, and account setup wizard edge cases', function (): void {
    // UserFactory: Jetstream disabled branch & non-User parent guard & withConnectedAccount
    $originalFeatures = config('jetstream.features');
    $originalProviders = config('socialstream.providers');

    try {
        config(['jetstream.features' => []]);
        expect(UserFactory::new()->withPersonalTeam())->toBeInstanceOf(UserFactory::class);

        config(['jetstream.features' => $originalFeatures]);
        $personalTeamFactory = UserFactory::new()->withPersonalTeam();
        $hasProperty = new ReflectionProperty($personalTeamFactory, 'has');
        $teamRelationship = $hasProperty->getValue($personalTeamFactory)->first();
        $relFactoryProp = new ReflectionProperty($teamRelationship, 'factory');
        $teamSubFactory = $relFactoryProp->getValue($teamRelationship);
        $statesProp = new ReflectionProperty($teamSubFactory, 'states');
        $teamStateClosure = $statesProp->getValue($teamSubFactory)->last();
        expect(fn () => $teamStateClosure([], null))->toThrow(LogicException::class);

        config(['socialstream.providers' => []]);
        expect(UserFactory::new()->withConnectedAccount('google'))->toBeInstanceOf(UserFactory::class);

        config(['socialstream.providers' => [Providers::google()]]);
        $connectedFactory = UserFactory::new()->withConnectedAccount(Providers::google());
        $connectedRel = $hasProperty->getValue($connectedFactory)->first();
        $connectedSubFactory = $relFactoryProp->getValue($connectedRel);
        $connectedStateClosure = $statesProp->getValue($connectedSubFactory)->last();
        expect(fn () => $connectedStateClosure([], null))->toThrow(LogicException::class);

        $sampleUser = User::factory()->create();
        $connectedAttrs = $connectedStateClosure([], $sampleUser);
        expect($connectedAttrs)->toBe([
            'provider' => Providers::google(),
            'user_id' => $sampleUser->getKey(),
        ]);
    } finally {
        config(['jetstream.features' => $originalFeatures, 'socialstream.providers' => $originalProviders]);
    }

    // UserSeeder: existing admin password update + local environment generated password branch
    $team = Team::factory()->create();
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

    $originalSeedPassword = config('app.seed_admin_password');
    $originalEnv = app()['env'];

    try {
        User::query()->where('email', 'admin@example.com')->delete();
        config(['app.seed_admin_password' => '']);
        app()['env'] = 'local';
        (new UserSeeder())->run();

        app()['env'] = $originalEnv;
        config(['app.seed_admin_password' => 'UpdatedAdminPass123!']);
        (new UserSeeder())->run();
    } finally {
        config(['app.seed_admin_password' => $originalSeedPassword]);
        app()['env'] = $originalEnv;
    }

    // AdminOverviewWidget::getHeading
    $widget = new AdminOverviewWidget();
    $heading = (new ReflectionMethod(AdminOverviewWidget::class, 'getHeading'))->invoke($widget);
    expect($heading)->toBeString();

    // ApplyTeamIntegrationSettings with guest user (line 21)
    $response = (new ApplyTeamIntegrationSettings())->handle(
        Request::create('/test-guest'),
        fn () => response('ok'),
    );
    expect($response->getContent())->toBe('ok');

    // AccountSetupWizard: no team, non-owner, and archived branch code validation
    $noTeamUser = User::factory()->create(['current_team_id' => null]);
    $this->actingAs($noTeamUser);
    (new AccountSetupWizard())->save();

    $owner = User::factory()->create();
    $ownedTeam = Team::factory()->create(['user_id' => $owner->id, 'personal_team' => true]);
    $owner->forceFill(['current_team_id' => $ownedTeam->id])->save();
    $ownedTeam->users()->attach($owner->id);

    $member = User::factory()->create(['current_team_id' => $ownedTeam->id]);
    $ownedTeam->users()->attach($member->id);
    $this->actingAs($member);
    (new AccountSetupWizard())->save();

    $archivedBranch = Branch::query()->create([
        'team_id' => $ownedTeam->id,
        'name' => 'Archived Office',
        'code' => 'ARCH01',
    ]);
    $archivedBranch->delete();

    $this->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Livewire::test(AccountSetupWizard::class)
        ->set('data.team_name', 'Updated Workspace')
        ->set('data.portal_timeout', 30)
        ->set('data.branches', [
            ['name' => 'Duplicate Archived', 'code' => 'ARCH01', 'email' => null, 'phone' => null],
        ])
        ->call('save')
        ->assertHasErrors(['data.branches']);
});

it('covers real-estate-core-api controllers and resources', function (): void {
    // PublicTerritoryController when no teams exist
    Team::query()->delete();
    $this->getJson('/api/v1/public/territories')
        ->assertOk()
        ->assertExactJson(['data' => []]);

    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    Sanctum::actingAs($user);

    // AgencyController CRUD
    $agencyCreate = $this->postJson('/api/v1/real-estate/agencies', [
        'name' => 'Mayfair Agency',
        'code' => 'MAY01',
        'active' => true,
        'metadata' => ['region' => 'London'],
    ])->assertCreated();
    $agencyId = $agencyCreate->json('data.id');

    $this->getJson('/api/v1/real-estate/agencies')->assertOk();
    $this->getJson("/api/v1/real-estate/agencies/{$agencyId}")->assertOk();
    $this->patchJson("/api/v1/real-estate/agencies/{$agencyId}", [
        'name' => 'Mayfair Prime Agency',
        'code' => 'MAY02',
    ])->assertOk();
    $this->deleteJson("/api/v1/real-estate/agencies/{$agencyId}")->assertNoContent();

    // BranchController CRUD
    $branchCreate = $this->postJson('/api/v1/real-estate/branches', [
        'name' => 'Soho Branch',
        'code' => 'SOHO1',
        'address' => null,
        'phone' => '02070000001',
        'email' => 'soho@example.com',
        'metadata' => ['desk' => 'sales'],
    ])->assertCreated();
    $branchId = $branchCreate->json('data.id');

    $this->getJson('/api/v1/real-estate/branches')->assertOk();
    $this->getJson("/api/v1/real-estate/branches/{$branchId}")->assertOk();
    $this->patchJson("/api/v1/real-estate/branches/{$branchId}", [
        'name' => 'Soho Central',
        'code' => 'SOHO2',
    ])->assertOk();
    $this->deleteJson("/api/v1/real-estate/branches/{$branchId}")->assertNoContent();

    // TerritoryController CRUD + PublicTerritoryController with data
    $territoryCreate = $this->postJson('/api/v1/real-estate/territories', [
        'name' => 'West End',
        'code' => 'WE01',
        'boundary' => ['type' => 'Polygon'],
        'metadata' => ['tier' => 'prime'],
    ])->assertCreated();
    $territoryId = $territoryCreate->json('data.id');

    $this->getJson('/api/v1/public/territories')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/real-estate/territories')->assertOk();
    $this->getJson("/api/v1/real-estate/territories/{$territoryId}")->assertOk();
    $this->patchJson("/api/v1/real-estate/territories/{$territoryId}", [
        'name' => 'West End Prime',
        'code' => 'WE02',
    ])->assertOk();
    $this->deleteJson("/api/v1/real-estate/territories/{$territoryId}")->assertNoContent();

    // CalendarEntryController destroy & CommunicationController destroy
    $calendarEntry = CalendarEntry::query()->create([
        'team_id' => $team->id,
        'created_by' => $user->id,
        'organiser_id' => $user->id,
        'type' => 'meeting',
        'title' => 'Team Sync',
        'starts_at' => now()->addHour(),
    ]);
    $this->deleteJson("/api/v1/real-estate/calendar-entries/{$calendarEntry->id}")->assertNoContent();

    $communication = Communication::query()->create([
        'team_id' => $team->id,
        'created_by' => $user->id,
        'channel' => 'email',
        'subject' => 'Follow up',
        'occurred_at' => now(),
    ]);
    $this->deleteJson("/api/v1/real-estate/communications/{$communication->id}")->assertNoContent();

    // CoreConfigurationController: terminology, statuses, audit, list + default 404 branch
    $this->putJson('/api/v1/real-estate/core/terminology/property.label', [
        'value' => 'Residence',
        'locale' => 'en',
    ])->assertSuccessful();

    $this->postJson('/api/v1/real-estate/core/statuses', [
        'entity' => 'property',
        'key' => 'under_offer',
        'label' => 'Under Offer',
        'active' => true,
    ])->assertSuccessful();

    $this->postJson('/api/v1/real-estate/core/audit', [
        'event' => 'property.published',
        'subject_type' => 'property',
        'subject_id' => '100',
        'metadata' => ['source' => 'api'],
    ])->assertCreated();

    $this->getJson('/api/v1/real-estate/core/terminology')->assertOk();
    $this->getJson('/api/v1/real-estate/core/statuses')->assertOk();
    $this->getJson('/api/v1/real-estate/core/audit')->assertOk();

    $badKindRequest = Request::create('/api/v1/real-estate/core/invalid', 'GET');
    $badKindRequest->setUserResolver(fn () => $user);
    expect(fn () => (new CoreConfigurationController())->list($badKindRequest, 'invalid'))
        ->toThrow(HttpException::class);

    // NumberingController
    $this->postJson('/api/v1/real-estate/numbering/invoice/next', [
        'prefix' => 'INV-',
        'padding' => 5,
    ])->assertOk()->assertJsonPath('data.number', 'INV-00001');
});

it('covers real-estate-core application guards, models, policies, filament pages, and livewire components', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $this->actingAs($user);

    // Application validation guards
    $createAgency = app(CreateAgency::class);
    expect(fn () => $createAgency->handle($team->id, ['name' => ' ', 'code' => 'AG01']))->toThrow(ValidationException::class);
    expect(fn () => $createAgency->handle($team->id, ['name' => 'Valid', 'code' => '!']))->toThrow(ValidationException::class);
    $agency = $createAgency->handle($team->id, ['name' => 'Core Agency', 'code' => 'AG01']);

    $updateAgency = app(UpdateAgency::class);
    expect(fn () => $updateAgency->handle($team->id, $agency->id, ['name' => ' ']))->toThrow(ValidationException::class);
    expect(fn () => $updateAgency->handle($team->id, $agency->id, ['code' => '!']))->toThrow(ValidationException::class);
    $updateAgency->handle($team->id, $agency->id, ['code' => 'ag02']);

    $createBranch = app(CreateBranch::class);
    expect(fn () => $createBranch->handle($team->id, ['name' => ' ', 'code' => 'BR01']))->toThrow(ValidationException::class);
    expect(fn () => $createBranch->handle($team->id, ['name' => 'Valid', 'code' => '!']))->toThrow(ValidationException::class);
    $branch = $createBranch->handle($team->id, ['name' => 'Core Branch', 'code' => 'BR01']);

    $updateBranch = app(UpdateBranch::class);
    expect(fn () => $updateBranch->handle($team->id, $branch->id, ['name' => ' ']))->toThrow(ValidationException::class);
    expect(fn () => $updateBranch->handle($team->id, $branch->id, ['code' => '!']))->toThrow(ValidationException::class);

    $createTerritory = app(CreateTerritory::class);
    expect(fn () => $createTerritory->handle($team->id, ['name' => ' ', 'code' => 'TR01']))->toThrow(ValidationException::class);
    expect(fn () => $createTerritory->handle($team->id, ['name' => 'Valid', 'code' => '!']))->toThrow(ValidationException::class);
    $territory = $createTerritory->handle($team->id, ['name' => 'North Zone', 'code' => 'TR01']);

    $updateTerritory = app(UpdateTerritory::class);
    expect(fn () => $updateTerritory->handle($team->id, $territory->id, ['name' => ' ']))->toThrow(ValidationException::class);
    expect(fn () => $updateTerritory->handle($team->id, $territory->id, ['code' => '!']))->toThrow(ValidationException::class);
    $updateTerritory->handle($team->id, $territory->id, ['name' => ' North Zone Updated ', 'code' => 'tr02']);

    $createCalendar = app(CreateCalendarEntry::class);
    expect(fn () => $createCalendar->handle($team->id, $user->id, ['type' => 'meeting', 'title' => '', 'starts_at' => now()]))->toThrow(ValidationException::class);
    expect(fn () => $createCalendar->handle($team->id, $user->id, ['type' => 'invalid', 'title' => 'Call', 'starts_at' => now()]))->toThrow(ValidationException::class);
    expect(fn () => $createCalendar->handle($team->id, $user->id, ['type' => 'meeting', 'title' => 'Call', 'starts_at' => now()->addHour(), 'reminder_at' => now()->addHours(2)]))->toThrow(ValidationException::class);

    $defineStatus = app(DefineStatus::class);
    expect(fn () => $defineStatus->handle($team->id, '', 'open', 'Open'))->toThrow(ValidationException::class);
    $status = $defineStatus->handle($team->id, 'deal', 'open', 'Open');

    $nextNumber = app(NextNumber::class);
    expect(fn () => $nextNumber->handle($team->id, 'bad key!'))->toThrow(ValidationException::class);
    expect(fn () => $nextNumber->handle($team->id, 'valid', null, 0))->toThrow(ValidationException::class);
    expect(fn () => $nextNumber->handle($team->id, 'valid', str_repeat('X', 31), 4))->toThrow(ValidationException::class);

    $recordComm = app(RecordCommunication::class);
    expect(fn () => $recordComm->handle($team->id, $user->id, ['channel' => 'email']))->toThrow(ValidationException::class);

    $setTerminology = app(SetTerminology::class);
    expect(fn () => $setTerminology->handle($team->id, 'bad key!', 'Home'))->toThrow(ValidationException::class);
    expect(fn () => $setTerminology->handle($team->id, 'home.label', '  '))->toThrow(ValidationException::class);
    expect(fn () => $setTerminology->handle($team->id, 'home.label', 'Home', 'invalid-locale-123'))->toThrow(ValidationException::class);
    $setTerminology->handle($team->id, 'home.label', 'Residence');

    app(RecordAuditEntry::class)->handle($team->id, $user->id, 'agency.created');
    expect(AuditEntry::query()->forTeam($team->id)->count())->toBe(1);
    expect(Terminology::query()->forTeam($team->id)->count())->toBe(1);

    // Core Filament Create/Edit pages
    $filamentCreates = [
        [CreateAgencyPage::class, ['name' => 'Filament Agency', 'code' => 'FAG1']],
        [CreateBranchPage::class, ['name' => 'Filament Branch', 'code' => 'FBR1']],
        [CreateStatusDefinitionPage::class, ['entity' => 'lead', 'key' => 'new', 'label' => 'New', 'active' => true]],
        [CreateTerritoryPage::class, ['name' => 'Filament Territory', 'code' => 'FTR1']],
    ];
    $createdRecords = [];
    foreach ($filamentCreates as [$pageClass, $attributes]) {
        $page = (new ReflectionClass($pageClass))->newInstanceWithoutConstructor();
        $createdRecords[] = (new ReflectionMethod($pageClass, 'handleRecordCreation'))->invoke($page, $attributes);
    }

    $filamentUpdates = [
        [EditAgencyPage::class, $createdRecords[0], ['name' => 'Filament Agency Updated']],
        [EditBranchPage::class, $createdRecords[1], ['name' => 'Filament Branch Updated']],
        [EditStatusDefinitionPage::class, $createdRecords[2], ['entity' => 'lead', 'key' => 'new', 'label' => 'Fresh', 'active' => false]],
        [EditTerritoryPage::class, $createdRecords[3], ['name' => 'Filament Territory Updated']],
    ];
    foreach ($filamentUpdates as [$pageClass, $record, $attributes]) {
        $page = (new ReflectionClass($pageClass))->newInstanceWithoutConstructor();
        (new ReflectionMethod($pageClass, 'handleRecordUpdate'))->invoke($page, $record, $attributes);
    }

    // Core Livewire components (with team + search, and guest branch for BranchList)
    Livewire::test(AgencyList::class)->set('search', 'Core')->assertOk();
    Livewire::test(BranchList::class)->set('search', 'Core')->assertOk();
    $configList = new CoreConfigurationList();
    $configList->search = 'home';
    expect($configList->render()->name())->toBe('real-estate-core-livewire::core-configuration-list');
    Livewire::test(TerritoryList::class)->set('search', 'North')->assertOk();

    auth()->logout();
    expect((new BranchList())->render()->name())->toBe('real-estate-core-livewire::branch-list');
    $this->actingAs($user);

    // Core Policies
    $policies = [
        [new AgencyPolicy(), $agency],
        [new BranchPolicy(), $branch],
        [new StatusDefinitionPolicy(), $status],
        [new TerritoryPolicy(), $territory],
    ];
    foreach ($policies as [$policy, $model]) {
        expect($policy->viewAny($user))->toBeBool();
        expect($policy->view($user, $model))->toBeBool();
        expect($policy->create($user))->toBeBool();
        expect($policy->update($user, $model))->toBeBool();
        expect($policy->delete($user, $model))->toBeBool();
        expect($policy->deleteAny($user))->toBeBool();
        expect($policy->restore($user, $model))->toBeBool();
        expect($policy->forceDelete($user, $model))->toBeBool();
        expect($policy->forceDeleteAny($user))->toBeBool();
        expect($policy->restoreAny($user))->toBeBool();
        expect($policy->replicate($user, $model))->toBeBool();
        expect($policy->reorder($user))->toBeBool();
    }
});
