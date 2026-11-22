<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\MediaAndDocuments\Application\CreateDocumentEnvelope;
use Liberu\RealEstate\MediaAndDocuments\Application\CreateHomeReport;
use Liberu\RealEstate\MediaAndDocuments\Application\CreateMediaDocument;
use Liberu\RealEstate\MediaAndDocuments\Application\GeneratePropertyBrochure;
use Liberu\RealEstate\MediaAndDocuments\Application\ReorderMediaDocument;
use Liberu\RealEstate\MediaAndDocuments\Application\SetMediaDocumentRetention;
use Liberu\RealEstate\MediaAndDocuments\Application\SignDocumentEnvelope;
use Liberu\RealEstate\MediaAndDocuments\Application\UpdateMediaDocument;
use Liberu\RealEstate\MediaAndDocuments\Application\UpdateMediaRights;
use Liberu\RealEstate\MediaAndDocuments\Application\UploadHomeReportFile;
use Liberu\RealEstate\MediaAndDocuments\Domain\DocumentTemplateStatus;
use Liberu\RealEstate\MediaAndDocuments\Models\DocumentEnvelope;
use Liberu\RealEstate\MediaAndDocuments\Models\DocumentTemplate;
use Liberu\RealEstate\MediaAndDocuments\Models\HomeReport;
use Liberu\RealEstate\MediaAndDocuments\Models\MediaDocument;
use Liberu\RealEstate\MediaAndDocuments\Policies\MediaDocumentPolicy;
use Liberu\RealEstate\MediaAndDocumentsApi\Http\Controllers\HomeReportController;
use Liberu\RealEstate\MediaAndDocumentsFilament\Resources\MediaDocumentResource\Pages\CreateMediaDocument as CreateMediaDocumentPage;
use Liberu\RealEstate\MediaAndDocumentsFilament\Resources\MediaDocumentResource\Pages\EditMediaDocument as EditMediaDocumentPage;
use Liberu\RealEstate\MediaAndDocumentsLivewire\Components\MediaDocumentList;
use Liberu\RealEstate\Properties\Models\Property;
use Livewire\Livewire;

it('covers real-estate-media-and-documents-api controllers and resources', function (): void {
    Storage::fake('public');

    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    Sanctum::actingAs($user);

    // MediaDocumentController full lifecycle
    $storeResponse = $this->postJson('/api/v1/real-estate/media-and-documents', [
        'kind' => 'photo',
        'path' => 'properties/front.jpg',
        'title' => 'Front Elevation',
        'rights' => ['public' => true],
        'metadata' => ['width' => 1920],
        'sort_order' => 1,
    ])->assertCreated();
    $docId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/real-estate/media-and-documents')->assertOk();
    $this->getJson("/api/v1/real-estate/media-and-documents/{$docId}")->assertOk();
    $this->patchJson("/api/v1/real-estate/media-and-documents/{$docId}", [
        'title' => 'Front Elevation Updated',
    ])->assertOk();
    $this->patchJson("/api/v1/real-estate/media-and-documents/{$docId}/rights", [
        'rights' => ['public' => false, 'portal' => true],
    ])->assertOk();
    $this->patchJson("/api/v1/real-estate/media-and-documents/{$docId}/reorder", [
        'sort_order' => 3,
    ])->assertOk();
    $this->patchJson("/api/v1/real-estate/media-and-documents/{$docId}/retention", [
        'retention_until' => now()->addYear()->toDateString(),
    ])->assertOk();

    $this->postJson('/api/v1/real-estate/media-and-documents/brochure', [
        'property' => [
            'id' => 1,
            'title' => 'Kensington Townhouse',
            'price' => 1250000,
            'description' => 'Period home',
            'features' => ['Garden'],
            'images' => ['https://example.com/1.jpg'],
        ],
        'options' => ['template' => 'luxury'],
    ])->assertOk();

    $this->deleteJson("/api/v1/real-estate/media-and-documents/{$docId}")->assertNoContent();

    // HomeReportController::upload (direct invocation since route is not registered)
    $property = Property::query()->create([
        'team_id' => $team->id,
        'created_by' => $user->id,
        'title' => 'Edinburgh Flat',
        'address' => '22 Princes Street, Edinburgh',
        'property_type' => 'flat',
        'status' => 'available',
        'price' => 320000,
    ]);
    $homeReport = app(CreateHomeReport::class)->handle($property, $user->id, [
        'energy_band' => 'B',
        'property_condition' => '1',
    ]);

    $pdf = UploadedFile::fake()->create('home-report.pdf', 10, 'application/pdf');
    $uploadRequest = Request::create('/upload', 'POST', [], [], ['file' => $pdf]);
    $uploadRequest->setUserResolver(fn () => $user);

    $uploadResponse = (new HomeReportController())->upload(
        $uploadRequest,
        $homeReport,
        app(UploadHomeReportFile::class),
    );
    expect($uploadResponse->getStatusCode())->toBeIn([200, 201]);
});

it('covers real-estate-media-and-documents application guards, models, policies, filament pages, and livewire components', function (): void {
    Storage::fake('public');

    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $secondUser = User::factory()->create(['current_team_id' => $team->id]);
    $this->actingAs($user);

    // MediaDocument application guards
    $createMedia = app(CreateMediaDocument::class);
    expect(fn () => $createMedia->handle($team->id, $user->id, ['kind' => 'invalid', 'path' => 'a.jpg']))->toThrow(ValidationException::class);
    $document = $createMedia->handle($team->id, $user->id, ['kind' => 'photo', 'path' => 'photos/living.jpg', 'title' => 'Living Room']);

    expect(fn () => app(UpdateMediaDocument::class)->handle($document, $team->id, ['path' => ' ']))->toThrow(ValidationException::class);
    expect(fn () => app(UpdateMediaRights::class)->handle($document, $otherTeam->id, ['public' => true]))->toThrow(ValidationException::class);

    $reorder = app(ReorderMediaDocument::class);
    expect(fn () => $reorder->handle($document, $otherTeam->id, 1))->toThrow(ValidationException::class);
    expect(fn () => $reorder->handle($document, $team->id, -1))->toThrow(ValidationException::class);

    $retention = app(SetMediaDocumentRetention::class);
    expect(fn () => $retention->handle($document, $otherTeam->id, null))->toThrow(ValidationException::class);
    expect(fn () => $retention->handle($document, $team->id, 'not-a-valid-date'))->toThrow(ValidationException::class);

    expect(fn () => app(GeneratePropertyBrochure::class)->handle(['title' => 'Missing ID', 'price' => 100]))->toThrow(ValidationException::class);

    // MediaDocument::publicUrl() empty path branch
    expect((new MediaDocument(['path' => '']))->publicUrl())->toBeNull();

    // HomeReport guards: invalid condition & non-PDF upload
    $property = Property::query()->create([
        'team_id' => $team->id,
        'created_by' => $user->id,
        'title' => 'Glasgow Terrace',
        'address' => '45 Sauchiehall Street, Glasgow',
        'property_type' => 'house',
        'status' => 'available',
        'price' => 275000,
    ]);
    expect(fn () => app(CreateHomeReport::class)->handle($property, $user->id, ['property_condition' => '9']))
        ->toThrow(InvalidArgumentException::class);

    $report = HomeReport::query()->create([
        'team_id' => $team->id,
        'created_by' => $user->id,
        'property_id' => $property->id,
        'property_condition' => '2',
    ]);
    $txtFile = UploadedFile::fake()->create('notes.txt', 5, 'text/plain');
    expect(fn () => app(UploadHomeReportFile::class)->handle($report, $team->id, $txtFile))
        ->toThrow(InvalidArgumentException::class);

    // DocumentTemplate, DocumentEnvelope, DocumentParticipant, DocumentEnvelopeSignature guards & methods
    $template = DocumentTemplate::query()->create([
        'team_id' => $team->id,
        'created_by' => $user->id,
        'name' => 'Tenancy Agreement',
        'type' => 'tenancy',
        'document_type' => 'tenancy',
        'status' => DocumentTemplateStatus::Published,
        'content' => 'Agreement for {{ tenant_name }}',
    ]);

    expect(fn () => $template->validateValues([]))->toThrow(ValidationException::class);

    $createEnvelope = app(CreateDocumentEnvelope::class);
    expect(fn () => $createEnvelope->handle($team->id, $user->id, $template, ['tenant_name' => 'Alice'], []))
        ->toThrow(ValidationException::class);

    $envelope = $createEnvelope->handle($team->id, $user->id, $template, ['tenant_name' => 'Alice'], [
        ['user_id' => $user->id, 'name' => 'First Signer', 'role' => 'tenant', 'signing_order' => 1],
        ['user_id' => $secondUser->id, 'name' => 'Second Signer', 'role' => 'landlord', 'signing_order' => 2],
    ]);

    expect($template->envelopes()->count())->toBe(1);
    expect(DocumentEnvelope::query()->forTeam($team->id)->count())->toBe(1);
    expect($envelope->isComplete())->toBeFalse();

    [$firstParticipant, $secondParticipant] = $envelope->participants()->get()->all();
    expect($firstParticipant->envelope->id)->toBe($envelope->id);

    $signEnvelope = app(SignDocumentEnvelope::class);

    // Wrong actor
    expect(fn () => $signEnvelope->handle($envelope, $firstParticipant, $team->id, $secondUser->id, 'sig-data', 'drawn'))
        ->toThrow(ValidationException::class);

    // Out-of-order signer
    expect(fn () => $signEnvelope->handle($envelope, $secondParticipant, $team->id, $secondUser->id, 'sig-data', 'drawn'))
        ->toThrow(ValidationException::class);

    // Empty signature / invalid method
    expect(fn () => $signEnvelope->handle($envelope, $firstParticipant, $team->id, $user->id, '   ', 'drawn'))
        ->toThrow(ValidationException::class);

    // First participant signs
    $signature = $signEnvelope->handle($envelope, $firstParticipant, $team->id, $user->id, 'base64-sig-1', 'drawn');
    expect($signature->envelope->id)->toBe($envelope->id);
    expect($signature->isValid())->toBeTrue();

    // Already signed participant
    expect(fn () => $signEnvelope->handle($envelope->fresh(), $firstParticipant->fresh(), $team->id, $user->id, 'base64-sig-again', 'drawn'))
        ->toThrow(ValidationException::class);

    // Filament CreateMediaDocument & EditMediaDocument pages
    $createPage = (new ReflectionClass(CreateMediaDocumentPage::class))->newInstanceWithoutConstructor();
    $filamentDoc = (new ReflectionMethod(CreateMediaDocumentPage::class, 'handleRecordCreation'))->invoke($createPage, [
        'kind' => 'brochure',
        'path' => 'brochures/main.pdf',
        'title' => 'Main Brochure',
    ]);
    $editPage = (new ReflectionClass(EditMediaDocumentPage::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod(EditMediaDocumentPage::class, 'handleRecordUpdate'))->invoke($editPage, $filamentDoc, [
        'title' => 'Main Brochure Updated',
    ]);

    // Livewire MediaDocumentList actions & search
    Livewire::test(MediaDocumentList::class)
        ->set('search', 'Living')
        ->call('generateBrochure', ['id' => 1, 'title' => 'Flat', 'price' => 250000], [])
        ->call('updateRights', $document->id, ['public' => true])
        ->call('reorder', $document->id, 2)
        ->call('setRetention', $document->id, now()->addMonth()->toDateString())
        ->assertOk();

    // MediaDocumentPolicy
    $policy = new MediaDocumentPolicy();
    expect($policy->viewAny($user))->toBeBool();
    expect($policy->view($user, $document))->toBeBool();
    expect($policy->create($user))->toBeBool();
    expect($policy->update($user, $document))->toBeBool();
    expect($policy->delete($user, $document))->toBeBool();
    expect($policy->deleteAny($user))->toBeBool();
    expect($policy->restore($user, $document))->toBeBool();
    expect($policy->forceDelete($user, $document))->toBeBool();
    expect($policy->forceDeleteAny($user))->toBeBool();
    expect($policy->restoreAny($user))->toBeBool();
    expect($policy->replicate($user, $document))->toBeBool();
    expect($policy->reorder($user))->toBeBool();
});
