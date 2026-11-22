<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\PortalsReporting\Application\CreatePortalReport;
use Liberu\RealEstate\PortalsReporting\Application\RecordPortalMetric;
use Liberu\RealEstate\PortalsReporting\Application\TransitionPortalReport;
use Liberu\RealEstate\PortalsReporting\Application\UpdatePortalReport;
use Liberu\RealEstate\PortalsReporting\Domain\PortalMetric;
use Liberu\RealEstate\PortalsReporting\Domain\PortalReportStatus;
use Liberu\RealEstate\PortalsReporting\Models\PortalReport;
use Liberu\RealEstate\PortalsReporting\Models\SavedReport;
use Liberu\RealEstate\PortalsReporting\Policies\PortalReportPolicy;
use Liberu\RealEstate\PortalsReportingFilament\Resources\PortalReportResource;
use Liberu\RealEstate\PortalsReportingFilament\Resources\PortalReportResource\Pages\CreatePortalReport as CreatePortalReportPage;
use Liberu\RealEstate\PortalsReportingFilament\Resources\PortalReportResource\Pages\EditPortalReport as EditPortalReportPage;

it('covers portals reporting application actions, model scopes, and policy', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);

    $create = app(CreatePortalReport::class);
    expect(fn () => $create->handle($team->id, $user->id, ['portal' => 'rightmove', 'report_type' => '']))
        ->toThrow(ValidationException::class);

    $report = $create->handle($team->id, $user->id, [
        'portal' => 'rightmove',
        'report_type' => 'feed',
    ]);

    // UpdatePortalReport
    $update = app(UpdatePortalReport::class);
    expect(fn () => $update->handle($report, $otherTeam->id, ['portal' => 'zoopla']))
        ->toThrow(ValidationException::class);
    expect(fn () => $update->handle($report, $team->id, ['portal' => '   ']))
        ->toThrow(ValidationException::class);
    $updated = $update->handle($report, $team->id, ['portal' => 'zoopla', 'status' => 'published']);
    expect($updated->portal)->toBe('zoopla');
    expect($updated->status)->toBe(PortalReportStatus::Draft);

    // RecordPortalMetric error paths
    $recordMetric = app(RecordPortalMetric::class);
    expect(fn () => $recordMetric->handle($report, $otherTeam->id, PortalMetric::Conversion, 10))
        ->toThrow(ValidationException::class);
    expect(fn () => $recordMetric->handle($report, $team->id, PortalMetric::Conversion, 'not-a-number'))
        ->toThrow(ValidationException::class);

    // TransitionPortalReport all branches
    $transition = app(TransitionPortalReport::class);
    expect(fn () => $transition->handle($report, $otherTeam->id, PortalReportStatus::Queued))
        ->toThrow(ValidationException::class);
    expect(fn () => $transition->handle($report, $team->id, PortalReportStatus::Published))
        ->toThrow(ValidationException::class);

    $report = $transition->handle($report, $team->id, PortalReportStatus::Queued);
    expect($report->status)->toBe(PortalReportStatus::Queued);

    $report = $transition->handle($report, $team->id, PortalReportStatus::Failed);
    expect($report->status)->toBe(PortalReportStatus::Failed);

    $report = $transition->handle($report, $team->id, PortalReportStatus::Queued);
    $report = $transition->handle($report, $team->id, PortalReportStatus::Published);
    expect($report->status)->toBe(PortalReportStatus::Published);
    expect($report->published_at)->not->toBeNull();

    $report = $transition->handle($report, $team->id, PortalReportStatus::Expired);
    expect($report->status)->toBe(PortalReportStatus::Expired);
    expect(fn () => $transition->handle($report, $team->id, PortalReportStatus::Archived))
        ->toThrow(ValidationException::class);

    // SavedReport::scopeForTeam
    SavedReport::query()->create([
        'team_id' => $team->id,
        'created_by' => $user->id,
        'name' => 'Weekly Feed',
        'type' => 'feed',
        'filters' => [],
        'is_shared' => true,
    ]);
    expect(SavedReport::query()->forTeam($team->id)->count())->toBe(1);

    // PortalReportPolicy all methods
    $policy = new PortalReportPolicy();
    expect($policy->viewAny($user))->toBeBool();
    expect($policy->view($user, $report))->toBeBool();
    expect($policy->create($user))->toBeBool();
    expect($policy->update($user, $report))->toBeBool();
    expect($policy->delete($user, $report))->toBeBool();
    expect($policy->deleteAny($user))->toBeBool();
    expect($policy->restore($user, $report))->toBeBool();
    expect($policy->forceDelete($user, $report))->toBeBool();
    expect($policy->forceDeleteAny($user))->toBeBool();
    expect($policy->restoreAny($user))->toBeBool();
    expect($policy->replicate($user, $report))->toBeBool();
    expect($policy->reorder($user))->toBeBool();
});

it('covers portal report api controller and resource', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);

    $this->actingAs($user, 'sanctum');

    $storeResponse = $this->postJson('/api/v1/real-estate/portals-and-reporting', [
        'portal' => 'rightmove',
        'report_type' => 'feed',
        'payload' => ['foo' => 'bar'],
    ])->assertCreated();

    $id = $storeResponse->json('data.id');
    expect($id)->not->toBeNull();

    $this->getJson('/api/v1/real-estate/portals-and-reporting?page_size=10')
        ->assertOk()
        ->assertJsonPath('data.0.portal', 'rightmove');

    $this->getJson("/api/v1/real-estate/portals-and-reporting/{$id}")
        ->assertOk()
        ->assertJsonPath('data.id', $id);

    $this->patchJson("/api/v1/real-estate/portals-and-reporting/{$id}", [
        'portal' => 'zoopla',
    ])->assertOk()->assertJsonPath('data.portal', 'zoopla');

    $this->postJson("/api/v1/real-estate/portals-and-reporting/{$id}/transition/queued")
        ->assertOk()
        ->assertJsonPath('data.status', 'queued');

    $this->patchJson("/api/v1/real-estate/portals-and-reporting/{$id}/metrics/conversion", [
        'value' => 12.5,
    ])->assertOk()->assertJsonPath('data.metrics.conversion', 12.5);

    $this->deleteJson("/api/v1/real-estate/portals-and-reporting/{$id}")
        ->assertNoContent();
});

it('covers portal report filament pages, delete action, and livewire list', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $this->actingAs($user);

    // CreatePortalReportPage
    $createPage = (new ReflectionClass(CreatePortalReportPage::class))->newInstanceWithoutConstructor();
    $report = (new ReflectionMethod(CreatePortalReportPage::class, 'handleRecordCreation'))
        ->invoke($createPage, ['portal' => 'onthemarket', 'report_type' => 'daily']);
    expect($report)->toBeInstanceOf(PortalReport::class);

    // EditPortalReportPage
    $editPage = (new ReflectionClass(EditPortalReportPage::class))->newInstanceWithoutConstructor();
    $report = (new ReflectionMethod(EditPortalReportPage::class, 'handleRecordUpdate'))
        ->invoke($editPage, $report, ['portal' => 'rightmove']);
    expect($report->portal)->toBe('rightmove');

    // Filament PortalReportResource table DeleteAction closure
    $table = PortalReportResource::table(Table::make(Mockery::mock(HasTable::class)));
    $deleteAction = collect($table->getRecordActions())->first(fn ($action): bool => $action->getName() === 'delete');
    expect($deleteAction)->not->toBeNull();
    ($deleteAction->getActionFunction())($report);
    expect(PortalReport::query()->find($report->id))->toBeNull();
});
