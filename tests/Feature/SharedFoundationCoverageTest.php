<?php

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Panel;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Liberu\Foundation\AnalyticsGoogleFilament\AnalyticsGoogleFilamentPlugin;
use Liberu\Foundation\AnalyticsGoogleFilament\AnalyticsGoogleFilamentServiceProvider;
use Liberu\Foundation\AnalyticsGoogleFilament\Pages\Overview as GoogleOverview;
use Liberu\Foundation\AnalyticsMetaFilament\AnalyticsMetaFilamentPlugin;
use Liberu\Foundation\AnalyticsMetaFilament\AnalyticsMetaFilamentServiceProvider;
use Liberu\Foundation\AnalyticsMetaFilament\Pages\Overview as MetaOverview;
use Liberu\Foundation\ApplicationCore\Http\Middleware\SecurityHeaders;
use Liberu\Foundation\Audit\Contracts\AuditRecorder;
use Liberu\Foundation\Audit\Models\ActivityLogEntry;
use Liberu\Foundation\Audit\Support\AuditContext;
use Liberu\Foundation\Audit\Support\ModelAuditListener;
use Liberu\Foundation\AuditFilament\Resources\ActivityLogResource;
use Liberu\Foundation\AuditFilament\Resources\ActivityLogResource\Pages\ViewActivityLog;
use Liberu\Foundation\Currency\Services\OpenExchangeRateApiProvider;
use Liberu\Foundation\Currency\ValueObjects\Currency;
use Liberu\Foundation\FilamentClusters\Clusters\UsersCluster;
use Liberu\Foundation\JetstreamBridge\Actions\Fortify\UpdateUserProfileInformation;
use Liberu\Foundation\JetstreamBridge\Actions\Jetstream\DeleteUser;
use Liberu\Foundation\Organizations\Actions\InviteMember;
use Liberu\Foundation\Organizations\Actions\TransferOwnership;
use Liberu\Foundation\Organizations\Models\Organization;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamInvitation;
use Liberu\Foundation\Organizations\Policies\TeamPolicy;
use Liberu\Foundation\OrganizationsFilament\Resources\TeamResource\Pages\EditTeam;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Liberu\Foundation\RolesPermissions\Services\AnyTeamRoleLookup;
use Liberu\Foundation\Search\Registry\SearcherRegistry;
use Liberu\Foundation\Sessions\Queries\SessionReader;
use Liberu\Foundation\SessionsDevicesFilament\Pages\AccountSecurity;
use Liberu\Foundation\UsersRoles\Database\Factories\UserFactory;
use Liberu\Foundation\WebhooksApi\Http\Controllers\StatusController;

it('covers analytics-google-filament, analytics-meta-filament, filament-clusters-contracts, and SecurityHeaders', function (): void {
    // Google Analytics Filament
    (new AnalyticsGoogleFilamentServiceProvider(app()))->boot();
    $googlePlugin = AnalyticsGoogleFilamentPlugin::make();
    expect($googlePlugin->getId())->toBe('analytics-google-filament');
    $panel = Panel::make()->id('test-analytics-g');
    $googlePlugin->register($panel);
    $googlePlugin->boot($panel);
    expect(GoogleOverview::getNavigationLabel())->toBeString()
        ->and((new GoogleOverview())->getTitle())->toBeString();

    // Meta Analytics Filament
    (new AnalyticsMetaFilamentServiceProvider(app()))->boot();
    $metaPlugin = AnalyticsMetaFilamentPlugin::make();
    expect($metaPlugin->getId())->toBe('analytics-meta-filament');
    $panel2 = Panel::make()->id('test-analytics-m');
    $metaPlugin->register($panel2);
    $metaPlugin->boot($panel2);
    expect(MetaOverview::getNavigationLabel())->toBeString()
        ->and((new MetaOverview())->getTitle())->toBeString();

    // UsersCluster
    expect(UsersCluster::getNavigationLabel())->toBeString();

    // SecurityHeaders over HTTPS (line 32)
    $httpsReq = Request::create('https://example.test/secure', 'GET');
    $resp = (new SecurityHeaders())->handle($httpsReq, fn () => new Response('ok'));
    expect($resp->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000');
});

it('covers audit and audit-filament resources, pages, model, and ModelAuditListener', function (): void {
    $entry = new ActivityLogEntry();
    expect($entry->subject())->not->toBeNull();

    // ActivityLogResource labels, canCreate, infolist, and ViewActivityLog
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(ActivityLogResource::getModelLabel())->toBeString()
        ->and(ActivityLogResource::canCreate())->toBeFalse();

    $viewPage = new ViewActivityLog();
    $sampleEntry = new ActivityLogEntry([
        'attribute_changes' => ['name' => ['old' => 'A', 'new' => 'B']],
        'record_hash' => 'abcdef1234567890',
        'previous_hash' => '0000000000000000',
        'subject_type' => User::class,
        'subject_id' => 1,
    ]);
    $viewPage->record = $sampleEntry;
    $schema = ActivityLogResource::infolist(Schema::make($viewPage)->record($sampleEntry));
    expect($schema->getComponents())->toHaveCount(3);
    // Render the infolist to evaluate formatStateUsing closures (line 117)
    foreach ($schema->getFlatComponents(withHidden: true) as $component) {
        if ($component instanceof TextEntry) {
            $component->formatState($component->getState());
        }
    }

    $refHeader = new ReflectionMethod(ViewActivityLog::class, 'getHeaderActions');
    expect($refHeader->invoke($viewPage))->toBe([]);

    // ModelAuditListener (lines 20-50)
    $genealogyModel = new class(['id' => 7, 'team_id' => 3, 'name' => 'Root']) extends Model
    {
        protected $table = 'genealogy_people';

        protected $guarded = [];
    };

    $listener = new ModelAuditListener();

    // Unknown event name -> early return (lines 24-25)
    $listener->handle('eloquent.saving: GenealogyPerson', [$genealogyModel]);

    // Unbound AuditRecorder -> early return (lines 27-28)
    $origRecorder = app()->bound(AuditRecorder::class) ? app(AuditRecorder::class) : null;
    $refBindings = new ReflectionProperty(app(), 'bindings');
    $bindings = $refBindings->getValue(app());
    $savedBinding = $bindings[AuditRecorder::class] ?? null;
    unset($bindings[AuditRecorder::class]);
    $refBindings->setValue(app(), $bindings);
    app()->forgetInstance(AuditRecorder::class);
    try {
        $listener->handle('eloquent.created: GenealogyPerson', [$genealogyModel]);
    } finally {
        if ($savedBinding !== null) {
            $bindings[AuditRecorder::class] = $savedBinding;
            $refBindings->setValue(app(), $bindings);
        }
        if ($origRecorder !== null) {
            app()->instance(AuditRecorder::class, $origRecorder);
        }
    }

    // Bound AuditRecorder records created, updated, deleted (lines 31-50)
    $recorded = [];
    $fakeRecorder = new class($recorded) implements AuditRecorder
    {
        public function __construct(public array &$recorded) {}

        public function record(string $event, string $subjectType, int|string|null $subjectId, array $before, array $after, AuditContext $context): void
        {
            $this->recorded[] = [$event, $subjectType, $subjectId, $before, $after];
        }
    };
    app()->instance(AuditRecorder::class, $fakeRecorder);
    $user = User::factory()->create();
    $this->actingAs($user);

    $listener->handle('eloquent.created: GenealogyPerson', [$genealogyModel]);
    $listener->handle('eloquent.updated: GenealogyPerson', [$genealogyModel]);
    $listener->handle('eloquent.deleted: GenealogyPerson', [$genealogyModel]);

    expect($recorded)->toHaveCount(3)
        ->and($recorded[0][0])->toBe('genealogy_created')
        ->and($recorded[1][0])->toBe('genealogy_updated')
        ->and($recorded[2][0])->toBe('genealogy_deleted');
});

it('covers OpenExchangeRateApiProvider fallback URL branch', function (): void {
    Cache::flush();
    config([
        'currency.exchange_rates_enabled' => true,
        'currency.exchange_rate_api_url' => 'https://primary.rates.test/{base}',
        'currency.exchange_rate_api_fallback_url' => 'https://fallback.rates.test/{base}',
        'currency.stale_after_seconds' => 60,
    ]);

    Http::fake([
        'primary.rates.test/*' => Http::response(['result' => 'error'], 500),
        'fallback.rates.test/*' => Http::response([
            'result' => 'success',
            'rates' => ['EUR' => 0.91],
            'time_last_update_unix' => now()->timestamp,
        ], 200),
    ]);

    $rate = (new OpenExchangeRateApiProvider())->rate(
        new Currency('USD', 2, '$'),
        new Currency('EUR', 2, '€'),
        new DateTimeImmutable(),
    );

    expect($rate)->not->toBeNull()
        ->and($rate->rate)->toBe('0.91');
});

it('covers jetstream-bridge UpdateUserProfileInformation, DeleteUser, and FortifyServiceProvider rate limiters', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'old.verified@example.com',
        'email_verified_at' => now(),
    ]);

    // UpdateUserProfileInformation with changed email on MustVerifyEmail user (lines 43, 61-67)
    $verifiableUser = new class() extends Illuminate\Foundation\Auth\User implements MustVerifyEmail
    {
        protected $table = 'users';

        public function hasVerifiedEmail(): bool
        {
            return $this->email_verified_at !== null;
        }

        public function markEmailAsVerified(): bool
        {
            return true;
        }

        public function sendEmailVerificationNotification(): void {}

        public function getEmailForVerification(): string
        {
            return (string) $this->email;
        }
    };
    $verifiableUser = $verifiableUser->newQuery()->findOrFail($user->id);

    app(UpdateUserProfileInformation::class)->update($verifiableUser, [
        'name' => 'Updated Name',
        'email' => 'new.verified@example.com',
    ]);
    expect($user->fresh()->email)->toBe('new.verified@example.com')
        ->and($user->fresh()->email_verified_at)->toBeNull();

    // FortifyServiceProvider rate limiters (lines 49-63)
    $req = Request::create('/login', 'POST', ['email' => 'test@example.com', 'credential' => ['id' => 'cred-1']]);
    $req->setLaravelSession(session()->driver());

    foreach (['login', 'two-factor', 'passkeys'] as $limiterName) {
        $limiter = RateLimiter::limiter($limiterName);
        expect($limiter)->not->toBeNull();
        expect($limiter($req))->not->toBeNull();
    }

    // DeleteUser: non-User throws, valid User deletes teams, tokens, connectedAccounts, and user (lines 27-62)
    $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Owned Team', 'personal_team' => true]);
    $user->createToken('test');

    $deletesTeams = Mockery::mock(DeletesTeams::class);
    $deletesTeams->shouldReceive('delete')->once()->with(Mockery::on(fn ($t) => $t->id === $team->id));

    $deleter = new DeleteUser($deletesTeams);
    expect(fn () => $deleter->delete(new stdClass()))->toThrow(InvalidArgumentException::class);

    $deleter->delete($user);
    expect(User::find($user->id))->toBeNull();
});

it('covers organizations-teams, organizations-teams-filament, roles-permissions, users-roles, search, sessions-devices-filament, and webhooks-api', function (): void {
    // InviteMember invalid role (line 18)
    expect(fn () => (new InviteMember())->handle(1, 'invitee@example.com', 'not-a-valid-role-xyz', 1))
        ->toThrow(InvalidArgumentException::class);

    // TransferOwnership ineligible successor (line 17)
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Alpha Team', 'personal_team' => false]);
    expect(fn () => (new TransferOwnership())->handle($team, $owner->id, $outsider->id, true))
        ->toThrow(RuntimeException::class, 'active member');

    // Organization & TeamInvitation models (casts + relations)
    $org = new Organization();
    expect($org->getCasts())->toHaveKey('archived_at', 'datetime')
        ->and($org->teams())->not->toBeNull();

    $inv = new TeamInvitation();
    expect($inv->getCasts())->toHaveKey('expires_at', 'datetime')
        ->and($inv->team())->not->toBeNull();

    // TeamPolicy viewAny and create (lines 18, 34)
    $policy = new TeamPolicy();
    expect($policy->viewAny($owner))->toBeTrue()
        ->and($policy->create($owner))->toBeTrue();

    // EditTeam header actions
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $editTeam = new EditTeam();
    $refEditTeam = new ReflectionMethod(EditTeam::class, 'getHeaderActions');
    expect($refEditTeam->invoke($editTeam))->toHaveCount(1);

    // Role::team() relation
    $role = new Role();
    expect($role->team())->not->toBeNull();

    // HasUserRoles::authorizationIdentifier & authorizationType + AnyTeamRoleLookup
    expect($owner->authorizationIdentifier())->toBe($owner->getKey())
        ->and($owner->authorizationType())->toBe($owner->getMorphClass());

    $lookup = new AnyTeamRoleLookup();
    expect($lookup->hasRoleInAnyTeam($owner, []))->toBeFalse()
        ->and($lookup->hasRoleInAnyTeam($owner, 'super_admin'))->toBeFalse();

    // UserFactory::unverified() (lines 41-43)
    $unverified = UserFactory::new()->unverified()->make();
    expect($unverified->email_verified_at)->toBeNull();

    // SearcherRegistry duplicate registration throws (line 31)
    $searchers = new SearcherRegistry();
    $fn = fn (array $params): LengthAwarePaginator => new LengthAwarePaginator([], 0, 10);
    $searchers->register('properties', $fn);
    expect(fn () => $searchers->register('properties', $fn))->toThrow(InvalidArgumentException::class);

    // AccountSecurity::revoke (lines 28-29)
    $this->actingAs($owner);
    $secPage = new AccountSecurity();
    $secPage->revoke('sess-123', app(SessionReader::class));
    expect($secPage->sessions)->toBeInstanceOf(Collection::class);

    // WebhooksApi StatusController (line 14)
    $statusResp = (new StatusController())(Request::create('/api/v1/webhooks/status', 'GET', [], [], [], ['HTTP_X_REQUEST_ID' => 'req-42']));
    expect($statusResp->getStatusCode())->toBe(200)
        ->and($statusResp->getData(true)['meta']['request_id'])->toBe('req-42');
});
