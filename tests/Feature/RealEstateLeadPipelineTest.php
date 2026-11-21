<?php

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Leads\Application\AssignLead;
use Liberu\RealEstate\Leads\Application\CreateLead;
use Liberu\RealEstate\Leads\Application\TransitionLead;
use Liberu\RealEstate\Leads\Application\UpdateLead;
use Liberu\RealEstate\Leads\Domain\Events\LeadStatusChanged;
use Liberu\RealEstate\Leads\Domain\LeadSource;
use Liberu\RealEstate\Leads\Domain\LeadStatus;
use Liberu\RealEstate\Leads\Models\Lead;
use Liberu\RealEstate\Leads\Policies\LeadPolicy;
use Liberu\RealEstate\Offers\Domain\Events\OfferStatusChanged;
use Liberu\RealEstate\Offers\Domain\OfferStatus;
use Liberu\RealEstate\Offers\Models\Offer;
use Liberu\RealEstate\Parties\Application\CreateParty;
use Liberu\RealEstate\Parties\Domain\Events\ContactMessageReceived;
use Liberu\RealEstate\Parties\Models\ContactMessage;
use Liberu\RealEstate\Properties\Application\CreateProperty;
use Liberu\RealEstate\Viewings\Domain\Events\ViewingCreated;
use Liberu\RealEstate\Viewings\Models\Viewing;

it('creates a lead and publishes a status change when the lead advances', function () {
    Event::fake([LeadStatusChanged::class]);
    $lead = app(CreateLead::class)->handle(12, [
        'name' => '  Jane Buyer  ',
        'email' => '  jane@example.test  ',
    ]);

    expect($lead->name)->toBe('Jane Buyer')
        ->and($lead->email)->toBe('jane@example.test')
        ->and($lead->source)->toBe(LeadSource::Manual)
        ->and($lead->status)->toBe(LeadStatus::New);

    $lead = app(UpdateLead::class)->handle($lead, '12', [
        'status' => LeadStatus::Qualified->value,
        'notes' => 'Financing confirmed.',
    ]);

    expect($lead->status)->toBe(LeadStatus::Qualified)
        ->and($lead->notes)->toBe('Financing confirmed.')
        ->and(Lead::query()->forTeam(12)->sole()->is($lead))->toBeTrue()
        ->and(Lead::query()->forTeam(13)->exists())->toBeFalse();

    Event::assertDispatched(LeadStatusChanged::class, fn (LeadStatusChanged $event): bool => $event->lead->is($lead)
        && $event->from === LeadStatus::New
        && $event->to === LeadStatus::Qualified);
});

it('rejects a status change for another team or from a closed lead', function () {
    Event::fake([LeadStatusChanged::class]);
    $lead = Lead::query()->create([
        'team_id' => 12,
        'name' => 'Jane Buyer',
        'email' => 'jane@example.test',
        'status' => LeadStatus::Won,
    ]);
    $transition = app(TransitionLead::class);

    expect(fn () => $transition->handle($lead, 13, LeadStatus::Offer))
        ->toThrow(ValidationException::class, 'The lead does not belong to this team.')
        ->and(fn () => $transition->handle($lead, 12, LeadStatus::Offer))
        ->toThrow(ValidationException::class, 'A won lead cannot be moved to another status.')
        ->and($lead->fresh()->status)->toBe(LeadStatus::Won);

    Event::assertNotDispatched(LeadStatusChanged::class);
});

it('rejects invalid lead details and permits edits without advancing the pipeline', function () {
    $createLead = app(CreateLead::class);

    expect(fn () => $createLead->handle(12, ['name' => ' ']))
        ->toThrow(ValidationException::class, 'A name is required.')
        ->and(fn () => $createLead->handle(12, ['name' => 'Jane', 'email' => 'invalid']))
        ->toThrow(ValidationException::class, 'A valid email is required.');

    $lead = $createLead->handle(12, ['name' => 'Jane', 'email' => 'jane@example.test']);
    $updateLead = app(UpdateLead::class);
    Event::fake([LeadStatusChanged::class]);

    expect(fn () => $updateLead->handle($lead, 13, ['name' => 'Other']))
        ->toThrow(ValidationException::class, 'The lead does not belong to this team.')
        ->and(fn () => $updateLead->handle($lead, 12, ['name' => ' ']))
        ->toThrow(ValidationException::class, 'A name is required.')
        ->and(fn () => $updateLead->handle($lead, 12, ['email' => 'invalid']))
        ->toThrow(ValidationException::class, 'A valid email is required.')
        ->and(fn () => $updateLead->handle($lead, 12, ['status' => 'invalid']))
        ->toThrow(ValidationException::class, 'Select a valid lead status.');

    $lead = $updateLead->handle($lead, 12, ['name' => 'Jane Buyer', 'team_id' => 13]);

    expect($lead->name)->toBe('Jane Buyer')
        ->and((int) $lead->team_id)->toBe(12)
        ->and($lead->status)->toBe(LeadStatus::New);
    Event::assertNotDispatched(LeadStatusChanged::class);
});

it('assigns and unassigns leads within their own team', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $team->users()->attach($member);
    $lead = app(CreateLead::class)->handle($team->getKey(), [
        'name' => 'Jane Buyer',
        'email' => 'jane@example.test',
    ]);
    $assignLead = app(AssignLead::class);

    expect(fn () => $assignLead->handle($lead, $team->getKey() + 1, $member->getKey()))
        ->toThrow(ValidationException::class, 'The lead does not belong to this team.')
        ->and(fn () => $assignLead->handle($lead, $team->getKey(), $outsider->getKey()))
        ->toThrow(ValidationException::class);

    $lead = $assignLead->handle($lead, $team->getKey(), $member->getKey());
    expect($lead->assignee->is($member))->toBeTrue();

    $lead = $assignLead->handle($lead, $team->getKey(), null);
    expect($lead->assigned_to)->toBeNull()
        ->and($lead->assignee)->toBeNull();
});

it('limits lead property and assignee references to the owning team', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $member = User::factory()->create();
    $otherMember = User::factory()->create();
    $team->users()->attach($member);
    $otherTeam->users()->attach($otherMember);
    $property = app(CreateProperty::class)->handle($team->id, $team->user_id, ['address' => '42 Acacia Avenue']);
    $otherProperty = app(CreateProperty::class)->handle($otherTeam->id, $otherTeam->user_id, ['address' => '43 Acacia Avenue']);
    $createLead = app(CreateLead::class);
    $details = ['name' => 'Jane Buyer', 'email' => 'jane@example.test'];

    expect(fn () => $createLead->handle($team->id, [...$details, 'property_id' => $otherProperty->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $createLead->handle($team->id, [...$details, 'assigned_to' => $otherMember->id]))
        ->toThrow(ValidationException::class)
        ->and(Lead::query()->exists())->toBeFalse();

    $lead = $createLead->handle($team->id, [
        ...$details,
        'property_id' => $property->id,
        'assigned_to' => $member->id,
    ]);
    $updateLead = app(UpdateLead::class);

    expect(fn () => $updateLead->handle($lead, $team->id, ['property_id' => $otherProperty->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $updateLead->handle($lead, $team->id, ['assigned_to' => $otherMember->id]))
        ->toThrow(ValidationException::class);

    $lead->refresh();

    expect($lead->property_id)->toEqual($property->id)
        ->and($lead->assignee->is($member))->toBeTrue();

    $lead = $updateLead->handle($lead, $team->id, ['property_id' => null, 'assigned_to' => null]);

    expect($lead->property_id)->toBeNull()
        ->and($lead->assigned_to)->toBeNull();
});

it('captures an unassigned enquiry when a storefront team exists', function () {
    $message = ContactMessage::query()->create([
        'name' => 'Jane Buyer',
        'email' => 'jane@example.test',
        'phone' => '01234567890',
        'message' => 'I would like to arrange a viewing.',
    ]);

    Event::dispatch(new ContactMessageReceived($message));
    expect(Lead::query()->exists())->toBeFalse();

    $team = Team::factory()->create();
    Event::dispatch(new ContactMessageReceived($message));
    $lead = Lead::query()->forTeam($team->getKey())->sole();

    expect($lead->source)->toBe(LeadSource::ContactForm)
        ->and($lead->source_contact_message_id)->toEqual($message->getKey())
        ->and($lead->email)->toBe($message->email)
        ->and($lead->phone)->toBe($message->phone)
        ->and($lead->notes)->toBe($message->message);
});

it('advances the matching lead when viewings and offers are recorded', function () {
    $party = app(CreateParty::class)->handle(12, 1, [
        'type' => 'buyer',
        'name' => 'Jane Buyer',
        'email' => 'jane@example.test',
    ]);
    $leadDetails = [
        'property_id' => 42,
        'name' => 'Jane Buyer',
        'email' => $party->email,
        'status' => LeadStatus::Qualified,
    ];
    $lead = Lead::query()->create(['team_id' => 12, ...$leadDetails]);
    $otherTeamLead = Lead::query()->create(['team_id' => 13, ...$leadDetails]);
    $eventDetails = ['team_id' => 12, 'property_id' => 42, 'party_id' => $party->getKey()];
    $anonymousViewing = new Viewing(['team_id' => 12, 'property_id' => 42]);
    $viewing = new Viewing($eventDetails);
    $offer = new Offer($eventDetails);

    Event::dispatch(new ViewingCreated(new Viewing(['team_id' => 12])));
    Event::dispatch(new OfferStatusChanged(new Offer(['team_id' => 12]), OfferStatus::Draft, OfferStatus::Submitted));
    Event::dispatch(new OfferStatusChanged(new Offer(['team_id' => 12, 'property_id' => 42]), OfferStatus::Draft, OfferStatus::Submitted));
    Event::dispatch(new ViewingCreated($anonymousViewing));
    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);

    Event::dispatch(new ViewingCreated($viewing));
    expect($lead->fresh()->status)->toBe(LeadStatus::Viewing);

    Event::dispatch(new OfferStatusChanged($offer, OfferStatus::Draft, OfferStatus::Submitted));
    Event::dispatch(new ViewingCreated($viewing));
    Event::fake([LeadStatusChanged::class]);
    Event::dispatch(new OfferStatusChanged($offer, OfferStatus::Submitted, OfferStatus::Countered));
    Event::dispatch(new OfferStatusChanged($offer, OfferStatus::Submitted, OfferStatus::Rejected));
    Event::assertNotDispatched(LeadStatusChanged::class);
    expect($lead->fresh()->status)->toBe(LeadStatus::Offer);

    Event::dispatch(new OfferStatusChanged($offer, OfferStatus::Submitted, OfferStatus::Accepted));
    expect($lead->fresh()->status)->toBe(LeadStatus::Won)
        ->and($otherTeamLead->fresh()->status)->toBe(LeadStatus::Qualified);
});

it('routes a property enquiry to the team that owns the property', function () {
    Team::factory()->create(['created_at' => now()->subDay()]);
    $team = Team::factory()->create();
    $property = app(CreateProperty::class)->handle($team->id, $team->user_id, [
        'address' => '42 Acacia Avenue',
    ]);
    $message = ContactMessage::query()->create([
        'name' => 'Jane Buyer',
        'email' => 'jane@example.test',
        'message' => 'Is this property still available?',
        'property_id' => $property->id,
    ]);

    Event::dispatch(new ContactMessageReceived($message));
    $lead = Lead::query()->sole();

    expect($lead->team_id)->toEqual($team->id)
        ->and($lead->property_id)->toEqual($property->id);
});

it('requires the matching permission for each lead policy action', function (string $action, string $ability, bool $requiresLead) {
    $user = User::factory()->make();
    $policy = new LeadPolicy();
    $arguments = $requiresLead ? [$user, new Lead()] : [$user];

    expect($policy->{$action}(...$arguments))->toBeFalse();

    Gate::define($ability, fn (User $candidate): bool => $candidate === $user);

    expect($policy->{$action}(...$arguments))->toBeTrue();
})->with([
    'list' => ['viewAny', 'ViewAny:Lead', false],
    'view' => ['view', 'View:Lead', true],
    'create' => ['create', 'Create:Lead', false],
    'update' => ['update', 'Update:Lead', true],
    'delete' => ['delete', 'Delete:Lead', true],
]);

it('can roll back and replay the lead table migration', function () {
    $migration = require base_path('modules/real-estate-leads/database/migrations/2026_09_10_000001_create_real_estate_leads.php');

    expect(Schema::hasTable('real_estate_leads'))->toBeTrue();
    $migration->down();
    expect(Schema::hasTable('real_estate_leads'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('real_estate_leads'))->toBeTrue();
});
