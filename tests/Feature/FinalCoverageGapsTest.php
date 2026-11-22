<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use JoelButcher\Socialstream\Contracts\CreatesConnectedAccounts;
use JoelButcher\Socialstream\Features as SocialstreamFeatures;
use Laravel\Jetstream\Features as JetstreamFeatures;
use Laravel\Socialite\Contracts\User as ProviderUserContract;
use Liberu\Foundation\Identity\Socialstream\Actions\CreateUserFromProvider;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Lettings\Application\DecideRentalApplication;
use Liberu\RealEstate\Lettings\Domain\RentalApplicationStatus;
use Liberu\RealEstate\Lettings\Models\RentalApplication;
use Liberu\RealEstate\Marketing\Domain\MarketingCampaignStatus;
use Liberu\RealEstate\Marketing\Models\MarketingCampaign;
use Liberu\RealEstate\MarketingLivewire\Components\MarketingCampaignList;
use Liberu\RealEstate\PortalsReporting\Domain\PortalMetric;
use Liberu\RealEstate\PortalsReporting\Domain\PortalReportStatus;
use Liberu\RealEstate\PortalsReporting\Models\PortalReport;
use Liberu\RealEstate\PortalsReportingLivewire\Components\PortalReportList;
use Liberu\RealEstate\PropertyManagement\Application\DecideVendorQuote;
use Liberu\RealEstate\PropertyManagement\Domain\VendorQuoteStatus;
use Liberu\RealEstate\PropertyManagement\Models\VendorQuote;
use Livewire\Livewire;

it('enforces screening before rental application approval and rejection reason for vendor quotes', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);

    $application = RentalApplication::query()->create([
        'team_id' => $team->id,
        'property_id' => 1,
        'applicant_name' => 'Jane Applicant',
        'applicant_email' => 'jane@example.test',
        'status' => RentalApplicationStatus::Submitted->value,
        'screening_status' => 'pending',
    ]);

    expect(fn () => app(DecideRentalApplication::class)->handle(
        $application,
        $team->id,
        $user->id,
        RentalApplicationStatus::Approved->value,
    ))->toThrow(ValidationException::class);

    $quote = VendorQuote::query()->create([
        'team_id' => $team->id,
        'vendor_id' => 1,
        'property_id' => 1,
        'work_description' => 'Replace roof tiles',
        'quote_amount' => 500,
        'quote_date' => now()->toDateString(),
        'valid_until' => now()->addWeek()->toDateString(),
        'status' => VendorQuoteStatus::Pending->value,
    ]);

    expect(fn () => app(DecideVendorQuote::class)->handle(
        $quote,
        $team->id,
        $user->id,
        VendorQuoteStatus::Rejected->value,
        '',
    ))->toThrow(ValidationException::class);
});

it('renders and mutates marketing campaigns and portal reports via Livewire components', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $this->actingAs($user);

    $campaign = MarketingCampaign::query()->create([
        'team_id' => $team->id,
        'name' => 'Spring Launch',
        'channel' => 'email',
        'status' => MarketingCampaignStatus::Draft->value,
    ]);

    Livewire::test(MarketingCampaignList::class)
        ->set('search', 'Spring')
        ->assertSee('Spring Launch')
        ->call('updateSection', $campaign->id, 'audience', ['segment' => 'buyers'])
        ->call('transitionCampaign', $campaign->id, MarketingCampaignStatus::Scheduled->value);

    expect($campaign->fresh()->status)->toBe(MarketingCampaignStatus::Scheduled);

    $report = PortalReport::query()->create([
        'team_id' => $team->id,
        'portal' => 'rightmove',
        'report_type' => 'monthly_leads',
        'status' => PortalReportStatus::Draft->value,
    ]);

    Livewire::test(PortalReportList::class)
        ->set('search', 'rightmove')
        ->assertSee('rightmove')
        ->call('recordMetric', $report->id, PortalMetric::Conversion->value, 150)
        ->call('transitionReport', $report->id, PortalReportStatus::Queued->value);

    expect($report->fresh()->status)->toBe(PortalReportStatus::Queued);
});

it('sets provider avatar when provider avatars feature is enabled on CreateUserFromProvider', function (): void {
    Storage::fake('public');
    Http::fake([
        'https://example.test/avatar.png' => Http::response('fake-png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $previousSocialstreamFeatures = config('socialstream.features', []);
    $previousJetstreamFeatures = config('jetstream.features', []);

    Config::set('socialstream.features', array_values(array_unique([
        ...$previousSocialstreamFeatures,
        SocialstreamFeatures::providerAvatars(),
    ])));
    Config::set('jetstream.features', array_values(array_unique([
        ...$previousJetstreamFeatures,
        JetstreamFeatures::profilePhotos(),
    ])));

    try {
        $providerUser = Mockery::mock(ProviderUserContract::class);
        $providerUser->shouldReceive('getName')->andReturn('Avatar User');
        $providerUser->shouldReceive('getEmail')->andReturn('avatar-user@example.test');
        $providerUser->shouldReceive('getAvatar')->andReturn('https://example.test/avatar.png');

        $createsConnected = Mockery::mock(CreatesConnectedAccounts::class);
        $createsConnected->shouldReceive('create')->once();

        $action = new CreateUserFromProvider($createsConnected);
        $created = $action->create('github', $providerUser);

        expect($created->email)->toBe('avatar-user@example.test')
            ->and($created->profile_photo_path)->not->toBeNull();
    } finally {
        Config::set('socialstream.features', $previousSocialstreamFeatures);
        Config::set('jetstream.features', $previousJetstreamFeatures);
    }
});

it('exercises mysql and pgsql driver branches in the pulse migration', function (): void {
    $migration = require base_path('modules/observability/database/migrations/2026_06_29_124052_create_pulse_tables.php');
    $sqliteConnection = DB::connection('sqlite');

    Schema::shouldReceive('create')->times(6)->andReturnUsing(function (string $table, Closure $callback) use ($sqliteConnection): void {
        $blueprint = new Blueprint($sqliteConnection, $table);
        $callback($blueprint);
    });

    foreach (['mysql', 'pgsql'] as $driver) {
        $connectionName = 'pulse_fake_'.$driver;
        $connection = new class($sqliteConnection->getPdo(), '', '', ['driver' => $driver]) extends Connection
        {
            public function getDriverName(): string
            {
                return (string) $this->getConfig('driver');
            }
        };

        $previousConn = config('pulse.storage.database.connection');
        Config::set("database.connections.{$connectionName}", ['driver' => $connectionName]);
        Config::set('pulse.storage.database.connection', $connectionName);
        DB::extend($connectionName, fn () => $connection);

        try {
            $migration->up();
        } finally {
            Config::set('pulse.storage.database.connection', $previousConn);
            DB::purge($connectionName);
        }
    }

    Schema::clearResolvedInstances();

    expect(true)->toBeTrue();
});
