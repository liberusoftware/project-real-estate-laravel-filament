<?php

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Matching\Application\CreateMatchProfile as CreateMatchProfileAction;
use Liberu\RealEstate\Matching\Models\MatchProfile;
use Liberu\RealEstate\MatchingFilament\Resources\MatchProfileResource;
use Liberu\RealEstate\MatchingFilament\Resources\MatchProfileResource\Pages\CreateMatchProfile;
use Liberu\RealEstate\MatchingFilament\Resources\MatchProfileResource\Pages\EditMatchProfile;
use Liberu\RealEstate\MatchingFilament\Resources\MatchProfileResource\Pages\ListMatchProfiles;
use Livewire\Livewire;

it('manages matching criteria and runs scoring and recommendations in the admin panel', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $outside = app(CreateMatchProfileAction::class)->handle($otherTeam->id, $user->id, ['subject' => 'Other buyer']);
    Gate::before(fn (User $candidate, string $ability): ?bool => $candidate->is($user) && str_ends_with($ability, ':MatchProfile') ? true : null);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);
    Filament::bootCurrentPanel();

    expect(Filament::getCurrentPanel()->getResources())->toContain(MatchProfileResource::class);

    Livewire::test(CreateMatchProfile::class)
        ->fillForm(['subject' => 'New buyer', 'score' => 20, 'requirements' => '{"min_bedrooms":3}'])
        ->call('create')
        ->assertHasNoFormErrors();

    $profile = MatchProfile::query()->forTeam($team->id)->sole();
    expect($profile->requirements)->toBe(['min_bedrooms' => 3]);

    Livewire::test(EditMatchProfile::class, ['record' => $outside->id])->assertNotFound();
    Livewire::test(EditMatchProfile::class, ['record' => $profile->id])
        ->fillForm(['subject' => 'Qualified buyer'])
        ->call('save')
        ->assertHasNoFormErrors();

    $profile->refresh();
    expect($profile->subject)->toBe('Qualified buyer')
        ->and($profile->requirements)->toBe(['min_bedrooms' => 3]);

    Livewire::test(ListMatchProfiles::class)
        ->assertCanSeeTableRecords([$profile])
        ->assertCanNotSeeTableRecords([$outside])
        ->callAction(TestAction::make('calculate_score')->table($profile), data: [
            'criteria' => '{"max_price":100000}', 'property' => '{"price":100000}',
        ])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('recommend_properties')->table($profile), data: [
            'criteria' => '{"max_price":100000}', 'properties' => '[{"id":1,"price":100000}]', 'limit' => 1,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('1 properties recommended');

    expect($profile->fresh()->score)->toBe(60);
});

it('denies matching panel access without permissions and lists nothing without a team', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    app(CreateMatchProfileAction::class)->handle($team->id, $user->id, ['subject' => 'Private buyer']);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);

    Livewire::test(ListMatchProfiles::class)->assertForbidden();
    Livewire::test(CreateMatchProfile::class)->assertForbidden();

    $user->current_team_id = null;
    Filament::setTenant(null);
    expect(MatchProfileResource::getEloquentQuery()->exists())->toBeFalse();
});
