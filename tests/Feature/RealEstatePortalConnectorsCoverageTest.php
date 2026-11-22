<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\OnTheMarket\Application\CreateOnTheMarketSync;
use Liberu\RealEstate\OnTheMarket\Application\DeleteOnTheMarketSync;
use Liberu\RealEstate\OnTheMarket\Application\SyncOnTheMarketListing;
use Liberu\RealEstate\OnTheMarket\Application\UpdateOnTheMarketSync;
use Liberu\RealEstate\OnTheMarket\Models\OnTheMarketSync;
use Liberu\RealEstate\OnTheMarket\Transport\OnTheMarketClient;
use Liberu\RealEstate\OnTheMarketFilament\Resources\OnTheMarketSyncResource;
use Liberu\RealEstate\OnTheMarketFilament\Resources\OnTheMarketSyncResource\Pages\CreateOnTheMarketSync as CreateOnTheMarketSyncPage;
use Liberu\RealEstate\OnTheMarketFilament\Resources\OnTheMarketSyncResource\Pages\EditOnTheMarketSync as EditOnTheMarketSyncPage;
use Liberu\RealEstate\OnTheMarketLivewire\Components\OnTheMarketSyncList;
use Liberu\RealEstate\Rightmove\Application\CreateRightmoveSync;
use Liberu\RealEstate\Rightmove\Application\DeleteRightmoveSync;
use Liberu\RealEstate\Rightmove\Application\SyncRightmoveListing;
use Liberu\RealEstate\Rightmove\Application\UpdateRightmoveSync;
use Liberu\RealEstate\Rightmove\Models\RightmoveSync;
use Liberu\RealEstate\Rightmove\Transport\RightmoveClient;
use Liberu\RealEstate\RightmoveFilament\Resources\RightmoveSyncResource;
use Liberu\RealEstate\RightmoveFilament\Resources\RightmoveSyncResource\Pages\CreateRightmoveSync as CreateRightmoveSyncPage;
use Liberu\RealEstate\RightmoveFilament\Resources\RightmoveSyncResource\Pages\EditRightmoveSync as EditRightmoveSyncPage;
use Liberu\RealEstate\RightmoveLivewire\Components\RightmoveSyncList;
use Liberu\RealEstate\Zoopla\Application\CreateZooplaSync;
use Liberu\RealEstate\Zoopla\Application\DeleteZooplaSync;
use Liberu\RealEstate\Zoopla\Application\SyncZooplaListing;
use Liberu\RealEstate\Zoopla\Application\UpdateZooplaSync;
use Liberu\RealEstate\Zoopla\Models\ZooplaSync;
use Liberu\RealEstate\Zoopla\Transport\ZooplaClient;
use Liberu\RealEstate\ZooplaFilament\Resources\ZooplaSyncResource;
use Liberu\RealEstate\ZooplaFilament\Resources\ZooplaSyncResource\Pages\CreateZooplaSync as CreateZooplaSyncPage;
use Liberu\RealEstate\ZooplaFilament\Resources\ZooplaSyncResource\Pages\EditZooplaSync as EditZooplaSyncPage;
use Liberu\RealEstate\ZooplaLivewire\Components\ZooplaSyncList;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('covers core application actions, validation, tenant guards, and sync failure handling for all portal connectors', function (): void {
    $providers = [
        [
            'create' => CreateRightmoveSync::class,
            'update' => UpdateRightmoveSync::class,
            'delete' => DeleteRightmoveSync::class,
            'sync' => SyncRightmoveListing::class,
            'model' => RightmoveSync::class,
            'config' => ['rightmove.client_id' => '', 'rightmove.client_secret' => ''],
        ],
        [
            'create' => CreateZooplaSync::class,
            'update' => UpdateZooplaSync::class,
            'delete' => DeleteZooplaSync::class,
            'sync' => SyncZooplaListing::class,
            'model' => ZooplaSync::class,
            'config' => ['zoopla.certificate' => '', 'zoopla.key' => ''],
        ],
        [
            'create' => CreateOnTheMarketSync::class,
            'update' => UpdateOnTheMarketSync::class,
            'delete' => DeleteOnTheMarketSync::class,
            'sync' => SyncOnTheMarketListing::class,
            'model' => OnTheMarketSync::class,
            'config' => ['onthemarket.certificate' => '', 'onthemarket.key' => ''],
        ],
    ];

    foreach ($providers as $provider) {
        config($provider['config']);

        expect(fn () => app($provider['create'])->handle(10, 1, []))
            ->toThrow(ValidationException::class);

        $record = app($provider['create'])->handle(10, 1, ['listing_id' => 101, 'external_id' => 'ext-1']);

        expect($provider['model']::query()->forTeam(10)->count())->toBe(1);

        expect(fn () => app($provider['update'])->handle($record, 99, ['external_id' => 'blocked']))
            ->toThrow(ValidationException::class);

        $updated = app($provider['update'])->handle($record, 10, ['external_id' => 'ext-updated']);
        expect($updated->external_id)->toBe('ext-updated');

        expect(fn () => app($provider['sync'])->handle($updated, 'ref-1', ['price' => 500], []))
            ->toThrow(RuntimeException::class);

        $updated->refresh();
        expect($updated->status->value)->toBe('failed')
            ->and($updated->error)->not->toBeNull();

        expect(fn () => app($provider['delete'])->handle($updated, 99))
            ->toThrow(ValidationException::class);

        app($provider['delete'])->handle($updated, 10);
        expect($updated->fresh()->trashed())->toBeTrue();
    }
});

it('covers Rightmove, Zoopla, and OnTheMarket transport clients including error branches and secondary endpoints', function (): void {
    config([
        'rightmove.client_id' => '',
        'rightmove.client_secret' => '',
        'zoopla.certificate' => '',
        'zoopla.key' => '',
        'zoopla.key_password' => null,
        'onthemarket.certificate' => '',
        'onthemarket.key' => '',
        'onthemarket.key_password' => null,
    ]);

    $rightmove = app(RightmoveClient::class);
    $zoopla = app(ZooplaClient::class);
    $onTheMarket = app(OnTheMarketClient::class);

    expect(fn () => $rightmove->branchPropertyList([]))->toThrow(RuntimeException::class);
    expect(fn () => $zoopla->branchPropertyList([]))->toThrow(RuntimeException::class);
    expect(fn () => $onTheMarket->branchPropertyList([]))->toThrow(RuntimeException::class);

    Http::fake([
        '*oauth/token' => Http::sequence()
            ->push(['access_token' => ''])
            ->push(['access_token' => 'rm-token'])
            ->push(['access_token' => 'rm-token']),
        '*' => Http::response(['ok' => true]),
    ]);

    expect(fn () => $rightmove->branchPropertyList(['client_id' => 'cid', 'client_secret' => 'csec']))
        ->toThrow(RuntimeException::class);

    $rmCreds = ['client_id' => 'cid', 'client_secret' => 'csec'];
    expect($rightmove->removeProperty('rm-ref-1', $rmCreds))->toBe(['ok' => true])
        ->and($rightmove->branchPropertyList($rmCreds))->toBe(['ok' => true]);

    $certCreds = ['certificate' => 'cert.pem', 'key' => 'key.pem', 'key_password' => 'secret-pass'];
    expect($zoopla->removeProperty('zp-ref-1', $certCreds))->toBe(['ok' => true])
        ->and($zoopla->branchPropertyList($certCreds))->toBe(['ok' => true])
        ->and($onTheMarket->removeProperty('otm-ref-1', $certCreds))->toBe(['ok' => true])
        ->and($onTheMarket->branchPropertyList($certCreds))->toBe(['ok' => true]);
});

it('covers API CRUD and sync endpoints for Rightmove, Zoopla, and OnTheMarket', function (): void {
    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));
    Http::fake([
        '*oauth/token' => Http::response(['access_token' => 'api-token']),
        '*' => Http::response(['accepted' => true]),
    ]);
    config([
        'rightmove.client_id' => 'rm-client',
        'rightmove.client_secret' => 'rm-secret',
        'zoopla.certificate' => 'zp-cert.pem',
        'zoopla.key' => 'zp-key.pem',
        'zoopla.key_password' => 'zp-pass',
        'onthemarket.certificate' => 'otm-cert.pem',
        'onthemarket.key' => 'otm-key.pem',
        'onthemarket.key_password' => 'otm-pass',
    ]);

    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $otherUser = User::factory()->create(['current_team_id' => $otherTeam->id]);
    $teamlessUser = User::factory()->create(['current_team_id' => null]);

    $endpoints = [
        '/api/v1/real-estate/integrations/rightmove/syncs',
        '/api/v1/real-estate/integrations/zoopla/syncs',
        '/api/v1/real-estate/integrations/onthemarket/syncs',
    ];

    foreach ($endpoints as $baseUrl) {
        $this->actingAs($teamlessUser, 'sanctum')->getJson($baseUrl)->assertForbidden();
        $this->actingAs($teamlessUser, 'sanctum')->postJson($baseUrl, ['listing_id' => 1])->assertForbidden();

        $created = $this->actingAs($user, 'sanctum')
            ->postJson($baseUrl, ['listing_id' => 50, 'external_id' => 'ext-50'])
            ->assertCreated();
        $id = $created->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson($baseUrl.'?page_size=10')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->actingAs($user, 'sanctum')->getJson($baseUrl.'/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->actingAs($otherUser, 'sanctum')->getJson($baseUrl.'/'.$id)->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->postJson($baseUrl.'/'.$id.'/sync', ['reference' => 'ref-50', 'payload' => ['price' => 250000]])
            ->assertOk()
            ->assertJsonPath('data.status', 'synced');
        $this->actingAs($otherUser, 'sanctum')
            ->postJson($baseUrl.'/'.$id.'/sync', ['reference' => 'ref-50', 'payload' => ['price' => 250000]])
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->patchJson($baseUrl.'/'.$id, ['external_id' => 'ext-50-updated'])
            ->assertOk()
            ->assertJsonPath('data.id', $id);
        $this->actingAs($otherUser, 'sanctum')
            ->patchJson($baseUrl.'/'.$id, ['external_id' => 'blocked'])
            ->assertNotFound();

        $this->actingAs($otherUser, 'sanctum')->deleteJson($baseUrl.'/'.$id)->assertNotFound();
        $this->actingAs($user, 'sanctum')->deleteJson($baseUrl.'/'.$id)->assertNoContent();
    }
});

it('covers Filament resources, table delete actions, and create/edit pages for all portal connectors', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $otherUser = User::factory()->create(['current_team_id' => $otherTeam->id]);

    $resources = [
        [
            'resource' => RightmoveSyncResource::class,
            'create' => CreateRightmoveSyncPage::class,
            'edit' => EditRightmoveSyncPage::class,
        ],
        [
            'resource' => ZooplaSyncResource::class,
            'create' => CreateZooplaSyncPage::class,
            'edit' => EditZooplaSyncPage::class,
        ],
        [
            'resource' => OnTheMarketSyncResource::class,
            'create' => CreateOnTheMarketSyncPage::class,
            'edit' => EditOnTheMarketSyncPage::class,
        ],
    ];

    foreach ($resources as $entry) {
        $resourceClass = $entry['resource'];
        $createClass = $entry['create'];
        $editClass = $entry['edit'];

        auth()->logout();
        expect($resourceClass::getModelLabel())->toBeString()
            ->and($resourceClass::getPluralModelLabel())->toBeString()
            ->and($resourceClass::isScopedToTenant())->toBeFalse()
            ->and($resourceClass::getPages())->toHaveKeys(['index', 'create', 'edit'])
            ->and($resourceClass::getEloquentQuery()->toSql())->toContain('1 = 0');

        $schema = $resourceClass::form(Schema::make());
        $table = $resourceClass::table(Table::make(Mockery::mock(HasTable::class)));
        expect($schema->getComponents())->not->toBeEmpty()
            ->and($table->getColumns())->not->toBeEmpty();

        $createPage = (new ReflectionClass($createClass))->newInstanceWithoutConstructor();
        $createMethod = new ReflectionMethod($createClass, 'handleRecordCreation');
        expect(fn () => $createMethod->invoke($createPage, ['listing_id' => 1]))->toThrow(HttpException::class);

        $this->actingAs($user);
        expect($resourceClass::getEloquentQuery()->toSql())->toContain('team_id');

        $record = $createMethod->invoke($createPage, ['listing_id' => 77, 'external_id' => 'fil-77']);
        expect((string) $record->team_id)->toBe((string) $team->id);

        $editPage = (new ReflectionClass($editClass))->newInstanceWithoutConstructor();
        $editMethod = new ReflectionMethod($editClass, 'handleRecordUpdate');
        $updated = $editMethod->invoke($editPage, $record, ['external_id' => 'fil-77-edited']);
        expect($updated->external_id)->toBe('fil-77-edited');

        $this->actingAs($otherUser);
        expect(fn () => $editMethod->invoke($editPage, $record, ['external_id' => 'blocked']))
            ->toThrow(HttpException::class);

        $this->actingAs($user);
        $deleteAction = collect($table->getRecordActions())
            ->first(static fn ($action): bool => $action instanceof DeleteAction);
        expect($deleteAction)->not->toBeNull();
        $actionClosure = $deleteAction->getActionFunction();
        $actionClosure($record);
        expect($record->fresh()->trashed())->toBeTrue();
    }
});

it('covers Livewire sync list components for Rightmove, Zoopla, and OnTheMarket', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);

    app(CreateRightmoveSync::class)->handle($team->id, $user->id, ['listing_id' => 1, 'external_id' => 'rm-match']);
    app(CreateZooplaSync::class)->handle($team->id, $user->id, ['listing_id' => 2, 'external_id' => 'zp-match']);
    app(CreateOnTheMarketSync::class)->handle($team->id, $user->id, ['listing_id' => 3, 'external_id' => 'otm-match']);

    Livewire::component('test-rightmove-sync-list', RightmoveSyncList::class);
    Livewire::component('test-zoopla-sync-list', ZooplaSyncList::class);
    Livewire::component('test-onthemarket-sync-list', OnTheMarketSyncList::class);

    Livewire::actingAs($user)->test('test-rightmove-sync-list')
        ->set('search', 'rm-match')
        ->assertOk();

    Livewire::actingAs($user)->test('test-zoopla-sync-list')
        ->set('search', 'zp-match')
        ->assertOk();

    Livewire::actingAs($user)->test('test-onthemarket-sync-list')
        ->set('search', 'otm-match')
        ->assertOk();
});
