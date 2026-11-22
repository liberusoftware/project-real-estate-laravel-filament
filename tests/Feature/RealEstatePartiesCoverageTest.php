<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Parties\Application\CreateContact;
use Liberu\RealEstate\Parties\Application\CreateContactMessage;
use Liberu\RealEstate\Parties\Application\CreateParty;
use Liberu\RealEstate\Parties\Application\CreatePartyRelationship;
use Liberu\RealEstate\Parties\Application\DeletePartyRelationship;
use Liberu\RealEstate\Parties\Application\ManagePartyConsent;
use Liberu\RealEstate\Parties\Application\SubmitPartyReview;
use Liberu\RealEstate\Parties\Application\UpdateParty;
use Liberu\RealEstate\Parties\Models\Contact;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Parties\Models\PartyRelationship;
use Liberu\RealEstate\Parties\Models\PartyReview;
use Liberu\RealEstate\Parties\Policies\PartyPolicy;
use Liberu\RealEstate\PartiesFilament\Resources\PartyResource\Pages\CreateParty as CreatePartyPage;
use Liberu\RealEstate\PartiesFilament\Resources\PartyResource\Pages\EditParty as EditPartyPage;
use Liberu\RealEstate\PartiesLivewire\Components\PartyList;
use Liberu\RealEstate\PartiesLivewire\Components\PartyReviewForm;
use Liberu\RealEstate\Properties\Models\Property;
use Livewire\Livewire;

it('covers real-estate-parties-api controllers and resources', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    Sanctum::actingAs($user);

    // ContactController::show
    $contact = app(CreateContact::class)->handle($team->id, $user->id, [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'type' => 'buyer',
    ]);
    $this->getJson("/api/v1/real-estate/contacts/{$contact->id}")->assertOk();
    expect(Contact::query()->forTeam($team->id)->count())->toBe(1);

    // PartyController full lifecycle: store, index, show, update, consent, relationships, destroyRelationship, destroy
    $partyResponse = $this->postJson('/api/v1/real-estate/parties', [
        'type' => 'landlord',
        'name' => 'Alice Landlord',
        'email' => 'alice@example.com',
        'phone' => '07700900001',
        'metadata' => ['vip' => true],
    ])->assertCreated();
    $partyId = $partyResponse->json('data.id');

    $relatedPartyId = $this->postJson('/api/v1/real-estate/parties', [
        'type' => 'tenant',
        'name' => 'Bob Tenant',
        'email' => 'bob@example.com',
    ])->assertCreated()->json('data.id');

    $this->getJson('/api/v1/real-estate/parties')->assertOk();
    $this->getJson("/api/v1/real-estate/parties/{$partyId}")->assertOk();
    $this->patchJson("/api/v1/real-estate/parties/{$partyId}", [
        'name' => 'Alice Landlord Updated',
    ])->assertOk();

    $this->postJson("/api/v1/real-estate/parties/{$partyId}/consent", [
        'granted' => true,
    ])->assertOk();

    $relId = $this->postJson("/api/v1/real-estate/parties/{$partyId}/relationships", [
        'related_party_id' => $relatedPartyId,
        'relationship' => 'co-owner',
        'metadata' => ['share' => 50],
    ])->assertCreated()->json('data.id');

    $this->getJson("/api/v1/real-estate/parties/{$partyId}/relationships")->assertOk();
    $this->deleteJson("/api/v1/real-estate/parties/{$partyId}/relationships/{$relId}")->assertNoContent();
    $this->deleteJson("/api/v1/real-estate/parties/{$partyId}")->assertNoContent();
});

it('covers real-estate-parties application guards, models, policies, filament pages, and livewire components', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $this->actingAs($user);

    // CreateContactMessage with property_id
    $property = Property::query()->create([
        'team_id' => $team->id,
        'created_by' => $user->id,
        'title' => 'Chelsea Flat',
        'address' => '10 King Street, Chelsea',
        'property_type' => 'flat',
        'status' => 'available',
        'price' => 500000,
    ]);
    $contactMessage = app(CreateContactMessage::class)->handle([
        'name' => 'Sam Buyer',
        'email' => 'sam@example.com',
        'message' => 'Interested in viewing.',
        'property_id' => $property->id,
    ]);
    expect($contactMessage->property_id)->toBe($property->id);

    // CreateParty & UpdateParty validation guards
    $createParty = app(CreateParty::class);
    expect(fn () => $createParty->handle($team->id, $user->id, ['name' => ' ', 'type' => 'buyer']))->toThrow(ValidationException::class);
    expect(fn () => $createParty->handle($team->id, $user->id, ['name' => 'Valid', 'type' => 'invalid']))->toThrow(ValidationException::class);
    $landlord = $createParty->handle($team->id, $user->id, ['name' => 'Primary Landlord', 'type' => 'landlord']);
    $tenant = $createParty->handle($team->id, $user->id, ['name' => 'Primary Tenant', 'type' => 'tenant']);

    $updateParty = app(UpdateParty::class);
    expect(fn () => $updateParty->handle($team->id, $landlord->id, ['name' => ' ']))->toThrow(ValidationException::class);

    // ManagePartyConsent wrong team guard
    expect(fn () => app(ManagePartyConsent::class)->handle($landlord, $otherTeam->id, true))->toThrow(ValidationException::class);

    // CreatePartyRelationship guards + DeletePartyRelationship
    $createRel = app(CreatePartyRelationship::class);
    expect(fn () => $createRel->handle($landlord, $otherTeam->id, ['related_party_id' => $tenant->id, 'relationship' => 'spouse']))->toThrow(ValidationException::class);
    expect(fn () => $createRel->handle($landlord, $team->id, ['related_party_id' => $landlord->id, 'relationship' => 'spouse']))->toThrow(ValidationException::class);
    expect(fn () => $createRel->handle($landlord, $team->id, ['related_party_id' => $tenant->id, 'relationship' => ' ']))->toThrow(ValidationException::class);

    $relationship = $createRel->handle($landlord, $team->id, ['related_party_id' => $tenant->id, 'relationship' => 'agent']);
    expect(PartyRelationship::query()->forTeam($team->id)->count())->toBe(1);
    app(DeletePartyRelationship::class)->handle($relationship, $team->id);

    // SubmitPartyReview duplicate guard + Party::averageReviewRating + PartyReview::party & scopeHighRated
    $submitReview = app(SubmitPartyReview::class);
    $review = $submitReview->handle($team->id, $user->id, $landlord->id, [
        'rating' => 5,
        'comment' => 'Very helpful landlord throughout the tenancy.',
    ]);
    $review->forceFill(['approved' => true, 'moderation_status' => 'approved'])->save();

    expect(fn () => $submitReview->handle($team->id, $user->id, $landlord->id, [
        'rating' => 4,
        'comment' => 'Duplicate review attempt should fail.',
    ]))->toThrow(ValidationException::class);

    expect($landlord->averageReviewRating())->toBe(5.0);
    expect($review->party->id)->toBe($landlord->id);
    expect(PartyReview::query()->highRated()->count())->toBe(1);

    // Filament CreateParty & EditParty pages
    $createPage = (new ReflectionClass(CreatePartyPage::class))->newInstanceWithoutConstructor();
    $filamentParty = (new ReflectionMethod(CreatePartyPage::class, 'handleRecordCreation'))->invoke($createPage, [
        'name' => 'Filament Party',
        'type' => 'buyer',
    ]);
    $editPage = (new ReflectionClass(EditPartyPage::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod(EditPartyPage::class, 'handleRecordUpdate'))->invoke($editPage, $filamentParty, [
        'name' => 'Filament Party Updated',
    ]);

    // Livewire PartyList actions & search
    Livewire::test(PartyList::class)
        ->set('search', 'Primary')
        ->call('setConsent', $landlord->id, true)
        ->call('addRelationship', $landlord->id, ['related_party_id' => $tenant->id, 'relationship' => 'guarantor'])
        ->assertOk();

    $livewireRel = PartyRelationship::query()->forTeam($team->id)->latest('id')->firstOrFail();
    Livewire::test(PartyList::class)
        ->call('removeRelationship', $livewireRel->id)
        ->assertOk();

    // Livewire PartyReviewForm no-team guard
    $reviewComponent = Livewire::test(PartyReviewForm::class, ['partyId' => $tenant->id])
        ->set('rating', 5)
        ->set('comment', 'Responsive and reliable tenant.');

    $user->forceFill(['current_team_id' => null])->save();
    $this->actingAs($user->fresh());
    expect((new PartyList())->render()->name())->toBe('real-estate-parties-livewire::party-list');
    $reviewComponent->call('submitReview')->assertForbidden();

    // Restore team and cover PartyPolicy
    $user->forceFill(['current_team_id' => $team->id])->save();
    $policy = new PartyPolicy();
    expect($policy->viewAny($user))->toBeBool();
    expect($policy->view($user, $landlord))->toBeBool();
    expect($policy->create($user))->toBeBool();
    expect($policy->update($user, $landlord))->toBeBool();
    expect($policy->delete($user, $landlord))->toBeBool();
    expect($policy->deleteAny($user))->toBeBool();
    expect($policy->restore($user, $landlord))->toBeBool();
    expect($policy->forceDelete($user, $landlord))->toBeBool();
    expect($policy->forceDeleteAny($user))->toBeBool();
    expect($policy->restoreAny($user))->toBeBool();
    expect($policy->replicate($user, $landlord))->toBeBool();
    expect($policy->reorder($user))->toBeBool();
});
