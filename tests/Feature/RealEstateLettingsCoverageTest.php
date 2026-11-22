<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Lettings\Application\CreateLeaseAgreement;
use Liberu\RealEstate\Lettings\Application\CreateLetting;
use Liberu\RealEstate\Lettings\Application\CreateRentalApplication;
use Liberu\RealEstate\Lettings\Application\CreateRentalCharge;
use Liberu\RealEstate\Lettings\Application\DecideRentalApplication;
use Liberu\RealEstate\Lettings\Application\RecordLettingFailure;
use Liberu\RealEstate\Lettings\Application\RenewLeaseAgreement;
use Liberu\RealEstate\Lettings\Application\ServeLeaseNotice;
use Liberu\RealEstate\Lettings\Application\TransitionLetting;
use Liberu\RealEstate\Lettings\Application\UpdateLeaseAgreement;
use Liberu\RealEstate\Lettings\Application\UpdateLetting;
use Liberu\RealEstate\Lettings\Application\UpdateLettingDetails;
use Liberu\RealEstate\Lettings\Application\UpdateRentalApplicationScreening;
use Liberu\RealEstate\Lettings\Application\UpdateRentalCharge;
use Liberu\RealEstate\Lettings\Domain\LettingStatus;
use Liberu\RealEstate\Lettings\Models\Letting;
use Liberu\RealEstate\Lettings\Models\RentalApplication;
use Liberu\RealEstate\Lettings\Models\RentalCharge;
use Liberu\RealEstate\Lettings\Policies\LettingPolicy;
use Liberu\RealEstate\Lettings\Queries\ListLettings;
use Liberu\RealEstate\LettingsFilament\LettingsFilamentPlugin;
use Liberu\RealEstate\LettingsFilament\Resources\LeaseAgreementResource;
use Liberu\RealEstate\LettingsFilament\Resources\LeaseAgreementResource\Pages\CreateLeaseAgreement as CreateLeaseAgreementPage;
use Liberu\RealEstate\LettingsFilament\Resources\LeaseAgreementResource\Pages\EditLeaseAgreement as EditLeaseAgreementPage;
use Liberu\RealEstate\LettingsFilament\Resources\LeaseAgreementResource\Pages\ListLeaseAgreements;
use Liberu\RealEstate\LettingsFilament\Resources\LettingResource;
use Liberu\RealEstate\LettingsFilament\Resources\LettingResource\Pages\CreateLetting as CreateLettingPage;
use Liberu\RealEstate\LettingsFilament\Resources\LettingResource\Pages\EditLetting as EditLettingPage;
use Liberu\RealEstate\LettingsFilament\Resources\LettingResource\Pages\ListLettings as ListLettingsPage;
use Liberu\RealEstate\LettingsFilament\Resources\RentalApplicationResource;
use Liberu\RealEstate\LettingsFilament\Resources\RentalApplicationResource\Pages\CreateRentalApplication as CreateRentalApplicationPage;
use Liberu\RealEstate\LettingsFilament\Resources\RentalApplicationResource\Pages\EditRentalApplication as EditRentalApplicationPage;
use Liberu\RealEstate\LettingsFilament\Resources\RentalApplicationResource\Pages\ListRentalApplications;
use Liberu\RealEstate\LettingsLivewire\Components\LettingList;
use Liberu\RealEstate\LettingsLivewire\Components\RentalApplicationForm;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('exercises all lettings API endpoints for lettings, tenancy agreements, rental applications, and rental charges', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $otherUser = User::factory()->create(['current_team_id' => $otherTeam->id]);
    $teamlessUser = User::factory()->create(['current_team_id' => null]);

    Sanctum::actingAs($teamlessUser);
    $this->getJson('/api/v1/real-estate/lettings')->assertForbidden();
    $this->getJson('/api/v1/real-estate/tenancy-agreements')->assertForbidden();
    $this->getJson('/api/v1/real-estate/rental-applications')->assertForbidden();
    $this->getJson('/api/v1/real-estate/rental-charges')->assertForbidden();

    Sanctum::actingAs($user);

    // Lettings endpoints
    $lettingRes = $this->postJson('/api/v1/real-estate/lettings', [
        'subject' => 'Flat 4B Application',
        'capability' => 'applications',
        'property_id' => 101,
        'party_id' => 202,
        'details' => ['applicant_id' => 5],
    ])->assertCreated();
    $lettingId = $lettingRes->json('data.id');

    $this->getJson('/api/v1/real-estate/lettings')->assertOk()->assertJsonPath('data.0.id', $lettingId);
    $this->getJson("/api/v1/real-estate/lettings/{$lettingId}")->assertOk()->assertJsonPath('data.subject', 'Flat 4B Application');
    $this->patchJson("/api/v1/real-estate/lettings/{$lettingId}", ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->patchJson("/api/v1/real-estate/lettings/{$lettingId}/details", ['details' => ['applicant_id' => 9]])->assertOk()->assertJsonPath('data.details.applicant_id', 9);
    $this->postJson("/api/v1/real-estate/lettings/{$lettingId}/failure", ['reason' => 'Reference check failed'])->assertOk();

    // Tenancy agreement endpoints
    $leaseRes = $this->postJson('/api/v1/real-estate/tenancy-agreements', [
        'property_id' => 101,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'monthly_rent' => 1400,
        'security_deposit' => 1400,
        'status' => 'active',
        'landlord_signed' => true,
        'tenant_signed' => true,
    ])->assertCreated();
    $leaseId = $leaseRes->json('data.id');

    $this->getJson('/api/v1/real-estate/tenancy-agreements')->assertOk()->assertJsonPath('data.0.id', $leaseId);
    $this->getJson("/api/v1/real-estate/tenancy-agreements/{$leaseId}")->assertOk();
    $this->patchJson("/api/v1/real-estate/tenancy-agreements/{$leaseId}", ['monthly_rent' => 1450])->assertOk();
    $this->postJson("/api/v1/real-estate/tenancy-agreements/{$leaseId}/notice", [
        'notice_type' => 'landlord',
        'notice_served_at' => '2026-10-01',
        'notice_expires_at' => '2026-12-01',
        'end_reason' => 'End of term',
    ])->assertOk();
    $this->postJson("/api/v1/real-estate/tenancy-agreements/{$leaseId}/renew", [
        'start_date' => '2027-01-01',
        'end_date' => '2027-12-31',
        'monthly_rent' => 1500,
        'security_deposit' => 1500,
    ])->assertCreated();

    // Rental application endpoints
    $appRes = $this->postJson('/api/v1/real-estate/rental-applications', [
        'property_id' => 101,
        'status' => 'submitted',
        'employment_status' => 'employed',
        'annual_income' => 60000,
    ])->assertCreated();
    $appId = $appRes->json('data.id');

    $this->getJson('/api/v1/real-estate/rental-applications')->assertOk()->assertJsonPath('data.0.id', $appId);
    $this->getJson("/api/v1/real-estate/rental-applications/{$appId}")->assertOk();
    $this->patchJson("/api/v1/real-estate/rental-applications/{$appId}", ['employment_status' => 'self_employed'])->assertOk();
    $this->patchJson("/api/v1/real-estate/rental-applications/{$appId}/screening", [
        'background_check_status' => 'passed',
        'credit_report_status' => 'good',
        'rental_history_status' => 'good',
        'affordability_status' => 'passed',
        'right_to_rent_status' => 'verified',
    ])->assertOk()->assertJsonPath('screening_complete', true)->assertJsonPath('screening_passed', true);
    $this->postJson("/api/v1/real-estate/rental-applications/{$appId}/decision", [
        'decision' => 'approved',
        'notes' => 'All checks passed',
    ])->assertOk();

    // Rental charge endpoints
    $chargeRes = $this->postJson('/api/v1/real-estate/rental-charges', [
        'property_id' => 101,
        'amount' => 1400,
        'charge_date' => '2026-02-01',
        'description' => 'February Rent',
        'status' => 'pending',
    ])->assertCreated();
    $chargeId = $chargeRes->json('data.id');

    $this->getJson('/api/v1/real-estate/rental-charges')->assertOk()->assertJsonPath('data.0.id', $chargeId);
    $this->getJson("/api/v1/real-estate/rental-charges/{$chargeId}")->assertOk();
    $this->patchJson("/api/v1/real-estate/rental-charges/{$chargeId}", ['status' => 'paid'])->assertOk();
    $this->deleteJson("/api/v1/real-estate/rental-charges/{$chargeId}")->assertOk()->assertJson(['deleted' => true]);

    // Cross-team isolation
    Sanctum::actingAs($otherUser);
    $this->getJson("/api/v1/real-estate/lettings/{$lettingId}")->assertNotFound();
    $this->getJson("/api/v1/real-estate/tenancy-agreements/{$leaseId}")->assertNotFound();
    $this->getJson("/api/v1/real-estate/rental-applications/{$appId}")->assertNotFound();
});

it('exercises lettings filament plugin, resources, and page actions', function (): void {
    $resources = [
        LettingResource::class,
        LeaseAgreementResource::class,
        RentalApplicationResource::class,
    ];

    $panel = Mockery::mock(Panel::class);
    $panel->shouldReceive('resources')->once()->with($resources);
    $plugin = LettingsFilamentPlugin::make();

    expect($plugin->getId())->toBe('real-estate-lettings');
    $plugin->register($panel);
    $plugin->boot($panel);

    expect(LeaseAgreementResource::isScopedToTenant())->toBeFalse()
        ->and(RentalApplicationResource::isScopedToTenant())->toBeFalse();

    foreach ($resources as $resource) {
        $schema = $resource::form(Schema::make());
        $table = $resource::table(Table::make(Mockery::mock(HasTable::class)));

        expect($resource::getModelLabel())->toBeString()
            ->and($resource::getPluralModelLabel())->toBeString()
            ->and($schema->getComponents())->not->toBeEmpty()
            ->and($table->getColumns())->not->toBeEmpty()
            ->and($resource::getPages())->toHaveKeys(['index', 'create', 'edit'])
            ->and(str_replace(' ', '', $resource::getEloquentQuery()->toSql()))->toContain('1=0');
    }

    // Reject creation without authenticated user
    foreach ([CreateLettingPage::class, CreateLeaseAgreementPage::class, CreateRentalApplicationPage::class] as $pageClass) {
        $page = (new ReflectionClass($pageClass))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($pageClass, 'handleRecordCreation');
        expect(fn () => $method->invoke($page, []))->toThrow(HttpException::class);
    }

    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $this->actingAs($user);

    foreach ($resources as $resource) {
        expect($resource::getEloquentQuery()->toSql())->toContain('team_id');
    }

    $created = [];
    foreach ([
        [CreateLettingPage::class, ['subject' => 'Filament Letting', 'capability' => 'applications']],
        [CreateLeaseAgreementPage::class, ['property_id' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'monthly_rent' => 1200]],
        [CreateRentalApplicationPage::class, ['property_id' => 1, 'status' => 'submitted']],
    ] as [$pageClass, $attributes]) {
        $page = (new ReflectionClass($pageClass))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($pageClass, 'handleRecordCreation');
        $created[] = $method->invoke($page, $attributes);
    }

    foreach ([
        [EditLettingPage::class, $created[0], ['subject' => 'Updated Filament Letting', 'status' => 'in_progress']],
        [EditLeaseAgreementPage::class, $created[1], ['monthly_rent' => 1250]],
        [EditRentalApplicationPage::class, $created[2], ['employment_status' => 'employed']],
    ] as [$pageClass, $record, $attributes]) {
        $page = (new ReflectionClass($pageClass))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($pageClass, 'handleRecordUpdate');
        $updated = $method->invoke($page, $record, $attributes);
        expect($updated->fresh())->not->toBeNull();

        $foreignRecord = new Letting(['team_id' => $otherTeam->id]);
        expect(fn () => $method->invoke($page, $foreignRecord, []))->toThrow(HttpException::class);
    }

    foreach ([ListLettingsPage::class, ListLeaseAgreements::class, ListRentalApplications::class] as $pageClass) {
        $page = (new ReflectionClass($pageClass))->newInstanceWithoutConstructor();
        $actions = (new ReflectionMethod($pageClass, 'getHeaderActions'))->invoke($page);
        expect($actions)->toHaveCount(1);
    }
});

it('exercises lettings livewire components', function (): void {
    expect((new LettingList())->render()->getData()['lettings'])->toBeEmpty();

    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    app(CreateLetting::class)->handle($team->id, $user->id, ['subject' => 'Oak Street Flat', 'capability' => 'applications']);

    $this->actingAs($user);

    Livewire::test(LettingList::class)
        ->set('search', 'Oak')
        ->assertOk();

    Livewire::test(RentalApplicationForm::class, ['property' => 55])
        ->assertSet('property_id', 55)
        ->set('employment_status', 'employed')
        ->set('annual_income', '48000')
        ->set('desired_move_in_date', now()->addDays(14)->toDateString())
        ->set('lease_end_date', now()->addYear()->toDateString())
        ->call('submit')
        ->assertDispatched('rentalApplicationSubmitted');
});

it('covers lettings application validation paths, models, policies, and queries', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $otherUser = User::factory()->create(['current_team_id' => $otherTeam->id]);

    // CreateLetting validation
    $createLetting = app(CreateLetting::class);
    expect(fn () => $createLetting->handle($team->id, $user->id, ['subject' => '   ', 'capability' => 'applications']))->toThrow(ValidationException::class);
    expect(fn () => $createLetting->handle($team->id, $user->id, ['subject' => 'Valid', 'capability' => 'nope']))->toThrow(ValidationException::class);
    $letting = $createLetting->handle($team->id, $user->id, ['subject' => 'Policy Letting', 'capability' => 'applications']);

    // Letting model, policy, query
    expect($letting->team->id)->toBe($team->id);
    $policy = new LettingPolicy();
    expect($policy->view($user, $letting))->toBeTrue()
        ->and($policy->update($user, $letting))->toBeTrue()
        ->and($policy->delete($user, $letting))->toBeTrue()
        ->and($policy->view($otherUser, $letting))->toBeFalse();
    expect(app(ListLettings::class)->handle($team->id, 'applications', 10)->total())->toBe(1);

    // UpdateLetting validation & status transition
    $updateLetting = app(UpdateLetting::class);
    expect(fn () => $updateLetting->handle($letting, $team->id, $user->id, ['subject' => '']))->toThrow(ValidationException::class);
    expect(fn () => $updateLetting->handle($letting, $team->id, $user->id, ['capability' => 'bad']))->toThrow(ValidationException::class);
    expect(fn () => $updateLetting->handle($letting, $team->id, $user->id, ['status' => 'bad']))->toThrow(ValidationException::class);
    $letting = $updateLetting->handle($letting, $team->id, $user->id, ['subject' => 'Renamed']);

    // UpdateLettingDetails validation & wrong team
    $updateDetails = app(UpdateLettingDetails::class);
    expect(fn () => $updateDetails->handle($letting, $otherTeam->id, $user->id, ['applicant_id' => 1]))->toThrow(HttpException::class);
    expect(fn () => $updateDetails->handle($letting, $team->id, $user->id, []))->toThrow(ValidationException::class);

    // RecordLettingFailure validation & wrong team
    $recordFailure = app(RecordLettingFailure::class);
    expect(fn () => $recordFailure->handle($letting, $otherTeam->id, $user->id, 'Reason'))->toThrow(HttpException::class);
    expect(fn () => $recordFailure->handle($letting, $team->id, $user->id, '   '))->toThrow(ValidationException::class);

    // TransitionLetting validation & wrong team
    $transitionLetting = app(TransitionLetting::class);
    expect(fn () => $transitionLetting->handle($letting, $otherTeam->id, $user->id, LettingStatus::InProgress))->toThrow(HttpException::class);
    $letting = $transitionLetting->handle($letting, $team->id, $user->id, LettingStatus::Completed);
    expect(fn () => $transitionLetting->handle($letting, $team->id, $user->id, LettingStatus::Draft))->toThrow(ValidationException::class);

    // CreateLeaseAgreement validation
    $createLease = app(CreateLeaseAgreement::class);
    expect(fn () => $createLease->handle($team->id, ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'monthly_rent' => 1000]))->toThrow(ValidationException::class);
    expect(fn () => $createLease->handle($team->id, ['property_id' => 1, 'start_date' => '2026-12-31', 'end_date' => '2026-01-01', 'monthly_rent' => 1000]))->toThrow(ValidationException::class);
    expect(fn () => $createLease->handle($team->id, ['property_id' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'monthly_rent' => 1000, 'status' => 'invalid']))->toThrow(ValidationException::class);

    $lease = $createLease->handle($team->id, ['property_id' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'monthly_rent' => 1000, 'landlord_signed' => true, 'tenant_signed' => true]);
    expect($lease->isFullySigned())->toBeTrue()
        ->and($lease->canDeploySmartContract())->toBeTrue();

    // UpdateLeaseAgreement validation
    $updateLease = app(UpdateLeaseAgreement::class);
    expect(fn () => $updateLease->handle($lease, $otherTeam->id, ['monthly_rent' => 1100]))->toThrow(ValidationException::class);
    expect(fn () => $updateLease->handle($lease, $team->id, ['start_date' => '2026-12-31', 'end_date' => '2026-01-01']))->toThrow(ValidationException::class);
    expect(fn () => $updateLease->handle($lease, $team->id, ['status' => 'invalid']))->toThrow(ValidationException::class);

    // ServeLeaseNotice validation
    $serveNotice = app(ServeLeaseNotice::class);
    expect(fn () => $serveNotice->handle($lease, $team->id, ['notice_served_at' => '2026-10-01', 'notice_expires_at' => '2026-12-01']))->toThrow(ValidationException::class);
    $lease = $updateLease->handle($lease, $team->id, ['status' => 'active']);
    expect(fn () => $serveNotice->handle($lease, $team->id, ['notice_served_at' => '2026-12-01', 'notice_expires_at' => '2026-10-01']))->toThrow(ValidationException::class);

    // RenewLeaseAgreement validation
    $renewLease = app(RenewLeaseAgreement::class);
    expect(fn () => $renewLease->handle($lease, $team->id, ['start_date' => '2026-06-01', 'end_date' => '2027-06-01', 'monthly_rent' => 1100]))->toThrow(ValidationException::class);
    expect(fn () => $renewLease->handle($lease, $team->id, ['start_date' => '2027-01-01', 'end_date' => '2027-01-01', 'monthly_rent' => 1100]))->toThrow(ValidationException::class);

    // CreateRentalApplication, UpdateRentalApplicationScreening, DecideRentalApplication, RentalApplication model
    $createApp = app(CreateRentalApplication::class);
    expect(fn () => $createApp->handle($team->id, $user->id, []))->toThrow(ValidationException::class);
    expect(fn () => $createApp->handle($team->id, $user->id, ['property_id' => 1, 'status' => 'approved']))->toThrow(ValidationException::class);

    $application = $createApp->handle($team->id, $user->id, ['property_id' => 1, 'status' => 'submitted']);
    expect(RentalApplication::query()->forTeam($team->id)->pending()->count())->toBe(1);

    $decideApp = app(DecideRentalApplication::class);
    expect(fn () => $decideApp->handle($application, $team->id, $user->id, 'invalid'))->toThrow(ValidationException::class);
    $application = $decideApp->handle($application, $team->id, $user->id, 'rejected', 'Incomplete');
    expect(fn () => $decideApp->handle($application, $team->id, $user->id, 'approved'))->toThrow(ValidationException::class);
    expect(fn () => app(UpdateRentalApplicationScreening::class)->handle($application, $team->id, ['background_check_status' => 'passed']))->toThrow(ValidationException::class);

    // CreateRentalCharge & UpdateRentalCharge validation
    $createCharge = app(CreateRentalCharge::class);
    expect(fn () => $createCharge->handle($team->id, $user->id, ['property_id' => 1]))->toThrow(ValidationException::class);
    expect(fn () => $createCharge->handle($team->id, $user->id, ['property_id' => 1, 'amount' => -50, 'charge_date' => '2026-01-01', 'description' => 'Rent']))->toThrow(ValidationException::class);
    expect(fn () => $createCharge->handle($team->id, $user->id, ['property_id' => 1, 'amount' => 100, 'charge_date' => '2026-01-01', 'description' => 'Rent', 'status' => 'invalid']))->toThrow(ValidationException::class);

    $charge = $createCharge->handle($team->id, $user->id, ['property_id' => 1, 'amount' => 100, 'charge_date' => '2026-01-01', 'description' => 'Rent']);
    expect(RentalCharge::query()->forTeam($team->id)->count())->toBe(1);

    $updateCharge = app(UpdateRentalCharge::class);
    expect(fn () => $updateCharge->handle($charge, $team->id, ['amount' => -10]))->toThrow(ValidationException::class);
    expect(fn () => $updateCharge->handle($charge, $team->id, ['status' => 'invalid']))->toThrow(ValidationException::class);
});
