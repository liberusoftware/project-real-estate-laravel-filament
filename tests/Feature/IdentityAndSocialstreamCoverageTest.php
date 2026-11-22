<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use JoelButcher\Socialstream\ConnectedAccount;
use JoelButcher\Socialstream\Contracts\AuthenticatesOAuthCallback;
use JoelButcher\Socialstream\Contracts\SocialstreamResponse;
use JoelButcher\Socialstream\Events\NewOAuthRegistration;
use JoelButcher\Socialstream\RefreshedCredentials;
use JoelButcher\Socialstream\Socialstream;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Liberu\Foundation\Identity\Jobs\SyncNewUserToCrm;
use Liberu\Foundation\Identity\Listeners\EmitAuthenticationEvent;
use Liberu\Foundation\Identity\Socialstream\Actions\CreateConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Actions\CreateUserFromProvider;
use Liberu\Foundation\Identity\Socialstream\Actions\GenerateRedirectForProvider;
use Liberu\Foundation\Identity\Socialstream\Actions\ResolveSocialiteUser;
use Liberu\Foundation\Identity\Socialstream\Actions\SetUserPassword;
use Liberu\Foundation\Identity\Socialstream\Actions\UpdateConnectedAccount;
use Liberu\Foundation\Identity\Socialstream\Actions\VerifyTelegramAuthPayload;
use Liberu\Foundation\Identity\Socialstream\Http\Controllers\TelegramAuthController;
use Liberu\Foundation\Identity\Socialstream\Http\Responses\SpaAwareOAuthResponse;
use Liberu\Foundation\Identity\Socialstream\Listeners\SyncNewOAuthUserToCrm;
use Liberu\Foundation\IdentityCoreApi\Http\Controllers\AuthTokenController;
use Liberu\Foundation\IdentityFilament\Resources\UserResource\Pages\EditUser;
use Liberu\Foundation\Organizations\Models\Team;

it('covers SyncNewUserToCrm job and EmitAuthenticationEvent listener', function (): void {
    $user = User::factory()->create();

    $job = new SyncNewUserToCrm($user->id, 'email', ['locale' => 'en'], ['ip' => '127.0.0.1']);
    expect($job->backoff())->toBe([10, 30, 60, 300, 900]);

    // No-op when CRM config is missing
    config(['services.crm.base_url' => null, 'services.crm.token' => null]);
    Http::fake();
    $job->handle();
    Http::assertNothingSent();

    // No-op when user does not exist
    config(['services.crm.base_url' => 'https://crm.example.test', 'services.crm.token' => 'secret-tok']);
    (new SyncNewUserToCrm(999999, 'email'))->handle();
    Http::assertNothingSent();

    // Posts lead payload when configured and user exists
    Http::fake([
        'crm.example.test/api/v1/crm/lead-capture' => Http::response(['ok' => true], 200),
    ]);
    $job->handle();
    Http::assertSentCount(1);

    // failed() logs error
    Log::shouldReceive('error')->once();
    $job->failed(new RuntimeException('CRM unreachable'));

    // EmitAuthenticationEvent ignores unrelated events (line 18)
    Event::fake();
    (new EmitAuthenticationEvent())->handle(new Registered($user));
    Event::assertNothingDispatched();
});

it('covers AuthTokenController register, store, show, destroy, 2FA rejection, and invalid credentials', function (): void {
    Queue::fake();

    if (! Schema::hasColumn('users', 'signup_intent')) {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('signup_intent')->nullable();
        });
    }

    $owner = User::factory()->create();
    $team = Team::forceCreate([
        'user_id' => $owner->id,
        'name' => 'Public Storefront',
        'personal_team' => true,
    ]);
    config(['identity.public_team_id' => $team->id]);

    // Register with valid signup_intent and session
    $regResp = $this->withSession(['_token' => 'tok'])
        ->postJson(route('identity.auth-token.register'), [
            'name' => 'Alice Buyer',
            'email' => 'alice.buyer@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'signup_intent' => 'buyer',
        ]);

    $regResp->assertCreated()
        ->assertJsonStructure(['plainTextToken', 'user' => ['id', 'name', 'email', 'roles']]);
    Queue::assertPushed(SyncNewUserToCrm::class);

    // Register with unknown signup_intent (hits else branch line 57)
    $regUnknown = $this->postJson(route('identity.auth-token.register'), [
        'name' => 'Bob Unknown',
        'email' => 'bob.unknown@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
        'signup_intent' => 'not-an-intent',
    ]);
    $regUnknown->assertCreated();

    // Login invalid credentials (lines 133-136)
    $this->postJson(route('identity.auth-token.store'), [
        'email' => 'alice.buyer@example.com',
        'password' => 'wrong-password',
    ])->assertUnprocessable();

    // Login 2FA-enabled user rejected (lines 139-143)
    $twoFaUser = User::factory()->create([
        'password' => Hash::make('StrongPass123!'),
        'two_factor_confirmed_at' => now(),
    ]);
    $this->postJson(route('identity.auth-token.store'), [
        'email' => $twoFaUser->email,
        'password' => 'StrongPass123!',
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'two_factor_required');

    // Login valid user with session (lines 146-161)
    $loginResp = $this->withSession(['_token' => 'tok'])
        ->postJson(route('identity.auth-token.store'), [
            'email' => 'alice.buyer@example.com',
            'password' => 'StrongPass123!',
        ]);
    $loginResp->assertOk()
        ->assertJsonStructure(['plainTextToken', 'user' => ['id', 'name', 'email', 'roles']]);

    $bearer = (string) $loginResp->json('plainTextToken');
    expect(PersonalAccessToken::findToken($bearer))->not->toBeNull();

    // Show user via bearer token (hits roleNames with current_team_id)
    $this->withHeader('Authorization', 'Bearer '.$bearer)
        ->getJson(route('identity.auth-token.show'))
        ->assertOk()
        ->assertJsonPath('user.email', 'alice.buyer@example.com');

    // Destroy token + invalidate session (lines 213-230)
    $this->withSession(['_token' => 'tok'])
        ->withHeader('Authorization', 'Bearer '.$bearer)
        ->deleteJson(route('identity.auth-token.destroy'))
        ->assertOk()
        ->assertJsonPath('message', 'Logged out.');

    expect(PersonalAccessToken::findToken($bearer))->toBeNull();

    // Also invoke register(), store(), destroy() directly with a session-attached Request (lines 80-81, 147-148, 225-227)
    $controller = app(AuthTokenController::class);

    $regReq = Request::create('/api/v1/auth/register', 'POST', [
        'name' => 'Session User',
        'email' => 'session.user@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
        'signup_intent' => 'buyer',
    ]);
    $regReq->setLaravelSession(session()->driver());
    $sessionRegResp = $controller->register($regReq, app(CreatesNewUsers::class));
    expect($sessionRegResp->getStatusCode())->toBe(201);

    $loginReq = Request::create('/api/v1/auth/token', 'POST', [
        'email' => 'session.user@example.com',
        'password' => 'StrongPass123!',
    ]);
    $loginReq->setLaravelSession(session()->driver());
    $sessionLoginResp = $controller->store($loginReq);
    expect($sessionLoginResp->getStatusCode())->toBe(200);
    $sessToken = (string) $sessionLoginResp->getData(true)['plainTextToken'];

    $sessionUser = User::where('email', 'session.user@example.com')->firstOrFail();
    $destroyReq = Request::create('/api/v1/auth/token', 'DELETE', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$sessToken]);
    $destroyReq->setLaravelSession(session()->driver());
    $destroyReq->setUserResolver(fn () => $sessionUser);
    $sessionDestroyResp = $controller->destroy($destroyReq);
    expect($sessionDestroyResp->getStatusCode())->toBe(200);
});

it('covers EditUser Filament page header actions', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $page = new EditUser();
    $ref = new ReflectionMethod(EditUser::class, 'getHeaderActions');
    $actions = $ref->invoke($page);

    expect($actions)->toHaveCount(1);
});

it('covers identity-socialstream actions, routes, controller, response, and listener', function (): void {
    // Load routes/web.php explicitly so PCOV records lines 13-15
    require base_path('modules/identity-socialstream/routes/web.php');
    app('router')->getRoutes()->refreshNameLookups();
    expect(Route::has('oauth.telegram.callback'))->toBeTrue();

    $user = User::factory()->create();
    $providerUser = (new SocialiteUser())->map([
        'id' => 'prov-123',
        'nickname' => 'jdoe',
        'name' => 'Jane Doe',
        'email' => 'jane.oauth@example.com',
        'avatar' => null,
    ])->setToken('tok-1')->setRefreshToken('ref-1')->setExpiresIn(3600);

    // CreateConnectedAccount: non-Model throws (lines 19-20), Model succeeds (lines 23-35)
    $creator = new CreateConnectedAccount();
    expect(fn () => $creator->create(new stdClass(), 'google', $providerUser))
        ->toThrow(InvalidArgumentException::class);

    $connected = $creator->create($user, 'Google', $providerUser);
    expect($connected)->toBeInstanceOf(ConnectedAccount::class)
        ->and($connected->provider)->toBe('google');

    // CreateUserFromProvider (lines 25-63) including provider-avatars branch (line 44)
    $tmpAvatar = sys_get_temp_dir().'/avatar-test-'.uniqid().'.png';
    file_put_contents($tmpAvatar, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    $origSocialFeatures = config('socialstream.features', []);
    config(['socialstream.features' => array_merge((array) $origSocialFeatures, ['provider-avatars'])]);
    try {
        $providerUserNew = (new SocialiteUser())->map([
            'id' => 'prov-456',
            'nickname' => 'newbie',
            'name' => 'New OAuth',
            'email' => 'new.oauth@example.com',
            'avatar' => $tmpAvatar,
        ])->setToken('tok-2');
        $createdUser = (new CreateUserFromProvider($creator))->create('google', $providerUserNew);
        expect($createdUser->email)->toBe('new.oauth@example.com')
            ->and($createdUser->ownedTeams()->count())->toBe(1);
    } finally {
        config(['socialstream.features' => $origSocialFeatures]);
        @unlink($tmpAvatar);
    }

    // GenerateRedirectForProvider (line 16)
    $driverMock = Mockery::mock(Provider::class);
    $driverMock->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('/oauth-provider'));
    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($driverMock);
    $redirect = (new GenerateRedirectForProvider())->generate('google');
    expect($redirect->getTargetUrl())->toBe('/oauth-provider');

    // ResolveSocialiteUser with missing email generation (lines 18-31)
    $noEmailUser = (new SocialiteUser())->map([
        'id' => 'no-email-99',
        'name' => 'No Email',
        'email' => null,
    ]);
    $driverMock2 = Mockery::mock(Provider::class);
    $driverMock2->shouldReceive('user')->once()->andReturn($noEmailUser);
    Socialite::shouldReceive('driver')->with('github')->once()->andReturn($driverMock2);
    config(['socialstream.features' => array_merge((array) config('socialstream.features', []), ['generate-missing-emails'])]);
    $resolved = (new ResolveSocialiteUser())->resolve('github');
    expect($resolved->getEmail())->toContain('no-email-99@github');

    // SetUserPassword non-Model throws (line 22)
    expect(fn () => (new SetUserPassword())->set(new stdClass(), ['password' => 'Secret123!', 'password_confirmation' => 'Secret123!']))
        ->toThrow(InvalidArgumentException::class);

    // UpdateConnectedAccount::updateRefreshToken (lines 41-52)
    $refreshed = new RefreshedCredentials(
        token: 'new-tok',
        tokenSecret: 'new-sec',
        refreshToken: 'new-ref',
        expiry: now()->addHour(),
    );
    $refResolvers = new ReflectionProperty(Socialstream::class, 'refreshTokenResolvers');
    $origResolvers = $refResolvers->getValue();
    $refResolvers->setValue(null, array_merge($origResolvers, ['google' => fn () => $refreshed]));
    try {
        Gate::define('update', fn () => true);
        $updated = (new UpdateConnectedAccount())->updateRefreshToken($connected);
        expect($updated->token)->toBe('new-tok');
    } finally {
        $refResolvers->setValue(null, $origResolvers);
    }

    // VerifyTelegramAuthPayload failure branches (lines 33, 44, 49)
    $verifier = new VerifyTelegramAuthPayload('test-bot-token');
    expect($verifier->verify(Request::create('/oauth/telegram/verify', 'GET', [])))->toBeNull();
    expect($verifier->verify(Request::create('/oauth/telegram/verify', 'GET', [
        'id' => '123',
        'auth_date' => (string) now()->timestamp,
        'hash' => 'deadbeef',
    ])))->toBeNull();

    // Valid signature but stale auth_date (line 49)
    $staleData = ['auth_date' => (string) (now()->timestamp - 100000), 'id' => '123'];
    ksort($staleData);
    $check = collect($staleData)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
    $staleHash = hash_hmac('sha256', $check, hash('sha256', 'test-bot-token', true));
    expect($verifier->verify(Request::create('/oauth/telegram/verify', 'GET', $staleData + ['hash' => $staleHash])))->toBeNull();

    // TelegramAuthController::callback (lines 26-59)
    config(['services.telegram.bot_token' => 'test-bot-token']);
    $freshData = [
        'auth_date' => (string) now()->timestamp,
        'first_name' => 'Pavel',
        'id' => '777888',
        'last_name' => 'Durov',
        'username' => 'durov',
    ];
    ksort($freshData);
    $freshCheck = collect($freshData)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
    $freshHash = hash_hmac('sha256', $freshCheck, hash('sha256', 'test-bot-token', true));

    $authMock = Mockery::mock(AuthenticatesOAuthCallback::class);
    $authMock->shouldReceive('authenticate')
        ->once()
        ->with('telegram', Mockery::type(SocialiteUser::class))
        ->andReturn(new class() implements SocialstreamResponse
        {
            public function toResponse($request)
            {
                return new RedirectResponse('/dashboard');
            }
        });
    app()->instance(AuthenticatesOAuthCallback::class, $authMock);

    $tgResp = $this->get('/oauth/telegram/verify?'.http_build_query($freshData + ['hash' => $freshHash, 'spa' => '1']));
    $tgResp->assertRedirect('/dashboard');

    // SpaAwareOAuthResponse: default fallback (lines 30-31) and SPA redirect (lines 34-44)
    $defaultResp = new class() implements Responsable
    {
        public function toResponse($request)
        {
            return new RedirectResponse('/default-home');
        }
    };
    $spaWrapper = new SpaAwareOAuthResponse($defaultResp);
    $req = Request::create('/oauth/callback', 'GET');
    $req->setUserResolver(fn () => $user);

    session()->forget('socialstream.spa_redirect');
    expect($spaWrapper->toResponse($req)->getTargetUrl())->toBe('/default-home');

    session(['socialstream.spa_redirect' => true]);
    $spaRedirect = $spaWrapper->toResponse($req);
    expect($spaRedirect)->toBeInstanceOf(RedirectResponse::class)
        ->and($spaRedirect->getTargetUrl())->toContain('#token=');

    // SyncNewOAuthUserToCrm listener (lines 21-36)
    Queue::fake();
    $oauthEvent = new NewOAuthRegistration($user, 'google', $providerUser);
    (new SyncNewOAuthUserToCrm())->handle($oauthEvent);
    Queue::assertPushed(SyncNewUserToCrm::class);
});
