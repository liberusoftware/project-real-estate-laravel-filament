<?php

declare(strict_types=1);
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Properties\Application\CreateProperty;
use Liberu\RealEstate\Valuations\Application\CalculateComparables;
use Liberu\RealEstate\Valuations\Application\CalculateHomeValuation;
use Liberu\RealEstate\Valuations\Application\CalculateMortgage;
use Liberu\RealEstate\Valuations\Application\CalculateRentalYield;
use Liberu\RealEstate\Valuations\Application\CompleteValuation;
use Liberu\RealEstate\Valuations\Application\ConvertValuation;
use Liberu\RealEstate\Valuations\Application\CreateValuation;
use Liberu\RealEstate\Valuations\Application\DeleteValuation;
use Liberu\RealEstate\Valuations\Application\GenerateNeuralPropertyValuation;
use Liberu\RealEstate\Valuations\Application\GeneratePropertyValuation;
use Liberu\RealEstate\Valuations\Application\ScheduleValuation;
use Liberu\RealEstate\Valuations\Application\UpdateValuation;
use Liberu\RealEstate\Valuations\Domain\ValuationStatus;
use Liberu\RealEstate\Valuations\Models\Valuation;
use Liberu\RealEstate\Valuations\Policies\ValuationPolicy;
use Liberu\RealEstate\ValuationsLivewire\Components\MortgageCalculator;
use Liberu\RealEstate\ValuationsLivewire\Components\PropertyValuationEstimator;
use Liberu\RealEstate\ValuationsLivewire\Components\RentalYieldCalculator;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);
it('creates a draft valuation with pricing metadata', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $valuation = app(CreateValuation::class)->handle($team->id, $team->user_id, [
        'subject' => '  Market appraisal  ',
        'valued_amount' => 350000,
        'fee_amount' => 250,
        'comparable_data' => '{"count":2}',
        'recommendation' => 'invalid JSON',
    ]);

    expect($valuation->subject)->toBe('Market appraisal')
        ->and($valuation->status)->toBe(ValuationStatus::Draft)
        ->and($valuation->valued_amount)->toBe('350000.00')
        ->and($valuation->fee_amount)->toBe('250.00')
        ->and($valuation->comparable_data)->toBe(['count' => 2])
        ->and($valuation->recommendation)->toBe([])
        ->and($valuation->team->is($team))->toBeTrue()
        ->and(Valuation::query()->forTeam($team->id)->sole()->is($valuation))->toBeTrue()
        ->and(Valuation::query()->forTeam($otherTeam->id)->exists())->toBeFalse();
});
it('rejects empty subjects and archives a valuation for its team', function (): void {
    expect(fn () => app(CreateValuation::class)->handle(1, 5, ['subject' => '']))->toThrow(ValidationException::class);
    $valuation = Valuation::query()->create(['team_id' => 1, 'subject' => 'Valuation', 'status' => 'draft']);
    app(DeleteValuation::class)->handle($valuation, 1);
    expect(Valuation::withTrashed()->find($valuation->id)->deleted_at)->not->toBeNull();
});

it('supports comparable pricing, appraisal completion, follow-up, and conversion', function (): void {
    $valuation = app(CreateValuation::class)->handle(1, 5, ['subject' => 'Full appraisal']);
    $valuation = app(CalculateComparables::class)->handle($valuation, 1, [
        ['reference' => 'A', 'amount' => 300000],
        ['reference' => 'B', 'amount' => 400000],
        ['reference' => 'C', 'amount' => 'unknown'],
    ]);
    $valuation = app(ScheduleValuation::class)->handle($valuation, 1, now()->addDay()->toDateTimeString());
    $valuation = app(CompleteValuation::class)->handle($valuation, 1, [
        'valued_amount' => 350000,
        'recommendation' => ['price_band' => ['min' => 340000, 'max' => 360000]],
        'follow_up_at' => now()->addWeek()->toDateTimeString(),
    ]);
    $valuation = app(ConvertValuation::class)->handle($valuation, 1, ['type' => 'instruction', 'id' => 42]);

    expect($valuation->status->value)->toBe('converted')
        ->and($valuation->comparable_data['average_amount'])->toBe(350000)
        ->and($valuation->comparable_data['count'])->toBe(2)
        ->and($valuation->recommendation['price_band']['min'])->toBe(340000)
        ->and($valuation->conversion['type'])->toBe('instruction')
        ->and($valuation->follow_up_at)->not->toBeNull()
        ->and($valuation->canTransitionTo(ValuationStatus::Cancelled))->toBeFalse();
});

it('rejects invalid appraisal transitions and pricing without changing the appraisal', function (): void {
    $valuation = app(CreateValuation::class)->handle(1, 5, ['subject' => 'Full appraisal']);
    $schedule = app(ScheduleValuation::class);
    $complete = app(CompleteValuation::class);
    $convert = app(ConvertValuation::class);
    $calculateComparables = app(CalculateComparables::class);
    $scheduledAt = now()->addDay()->toDateTimeString();

    expect(fn () => $schedule->handle($valuation, 2, $scheduledAt))
        ->toThrow(NotFoundHttpException::class)
        ->and(fn () => $complete->handle($valuation, 1, ['valued_amount' => 350000]))
        ->toThrow(ValidationException::class, 'Only scheduled valuations can be completed.')
        ->and(fn () => $convert->handle($valuation, 1, ['type' => 'instruction']))
        ->toThrow(ValidationException::class, 'Only completed valuations can be converted.')
        ->and(fn () => $calculateComparables->handle($valuation, 1, []))
        ->toThrow(ValidationException::class, 'At least one comparable is required.')
        ->and(fn () => $calculateComparables->handle($valuation, 1, [['amount' => -1], ['amount' => 'unknown']]))
        ->toThrow(ValidationException::class, 'Comparables must contain non-negative amounts.')
        ->and($valuation->refresh()->status)->toBe(ValuationStatus::Draft)
        ->and($valuation->comparable_data)->toBe([]);

    $valuation = $schedule->handle($valuation, '1', $scheduledAt);

    expect(fn () => $schedule->handle($valuation, 1, $scheduledAt))
        ->toThrow(ValidationException::class, 'Only draft valuations can be scheduled.')
        ->and(fn () => $complete->handle($valuation, 1, ['valued_amount' => -1]))
        ->toThrow(ValidationException::class, 'A non-negative valuation amount is required.')
        ->and($valuation->refresh()->status)->toBe(ValuationStatus::Scheduled)
        ->and($valuation->completed_at)->toBeNull();

    $valuation = $complete->handle($valuation, 1, ['valued_amount' => 350000]);

    expect(fn () => $convert->handle($valuation, 1, ['type' => ' ']))
        ->toThrow(ValidationException::class, 'A conversion type is required.')
        ->and($valuation->refresh()->status)->toBe(ValuationStatus::Completed)
        ->and($valuation->conversion)->toBeNull();
});

it('updates appraisal metadata from JSON and validates edits before saving', function (): void {
    $valuation = app(CreateValuation::class)->handle(1, 5, ['subject' => 'Full appraisal']);
    $update = app(UpdateValuation::class);

    expect(fn () => $update->handle($valuation, 2, ['subject' => 'Other appraisal']))
        ->toThrow(NotFoundHttpException::class)
        ->and(fn () => $update->handle($valuation, 1, ['subject' => ' ']))
        ->toThrow(ValidationException::class, 'A valuation subject is required.')
        ->and(fn () => $update->handle($valuation, 1, ['status' => 'unknown']))
        ->toThrow(ValidationException::class, 'That valuation status transition is not allowed.')
        ->and(fn () => $update->handle($valuation, 1, ['status' => ValuationStatus::Completed->value]))
        ->toThrow(ValidationException::class, 'That valuation status transition is not allowed.')
        ->and($valuation->refresh()->subject)->toBe('Full appraisal')
        ->and($valuation->status)->toBe(ValuationStatus::Draft);

    $valuation = $update->handle($valuation, 1, [
        'subject' => 'Updated appraisal',
        'status' => ValuationStatus::Scheduled->value,
        'comparable_data' => '{"count":2}',
        'recommendation' => 'invalid JSON',
    ]);

    expect($valuation->subject)->toBe('Updated appraisal')
        ->and($valuation->status)->toBe(ValuationStatus::Scheduled)
        ->and($valuation->comparable_data)->toBe(['count' => 2])
        ->and($valuation->recommendation)->toBe([]);
});

it('checks the corresponding shield permission for each valuation policy action', function (): void {
    $policy = new ValuationPolicy();
    $valuation = new Valuation();
    $allowed = User::factory()->make();
    $denied = User::factory()->make();

    foreach ([
        'viewAny' => ['ViewAny:Valuation', false],
        'view' => ['View:Valuation', true],
        'create' => ['Create:Valuation', false],
        'update' => ['Update:Valuation', true],
        'delete' => ['Delete:Valuation', true],
        'deleteAny' => ['DeleteAny:Valuation', false],
        'restore' => ['Restore:Valuation', true],
        'forceDelete' => ['ForceDelete:Valuation', true],
        'forceDeleteAny' => ['ForceDeleteAny:Valuation', false],
        'restoreAny' => ['RestoreAny:Valuation', false],
        'replicate' => ['Replicate:Valuation', true],
        'reorder' => ['Reorder:Valuation', false],
    ] as $action => [$permission, $requiresValuation]) {
        $arguments = $requiresValuation ? [$valuation] : [];

        expect($policy->{$action}($allowed, ...$arguments))->toBeFalse();
        Gate::define($permission, fn (User $user): bool => $user === $allowed);

        expect($policy->{$action}($allowed, ...$arguments))->toBeTrue()
            ->and($policy->{$action}($denied, ...$arguments))->toBeFalse();
    }
});

it('rolls back and replays the valuation migrations in order', function (): void {
    $migrations = base_path('modules/real-estate-valuations/database/migrations');
    $createTable = require $migrations.'/2026_08_23_000005_create_real_estate_valuations_table.php';
    $addWorkflowFields = require $migrations.'/2026_08_24_000003_add_valuation_workflow_fields.php';
    $table = 'real_estate_valuations';
    $workflowFields = ['currency', 'follow_up_at', 'conversion'];

    expect(Schema::hasColumns($table, $workflowFields))->toBeTrue();

    $addWorkflowFields->down();

    expect(Schema::hasTable($table))->toBeTrue()
        ->and(array_intersect($workflowFields, Schema::getColumnListing($table)))->toBe([]);

    $createTable->down();
    expect(Schema::hasTable($table))->toBeFalse();

    $createTable->up();
    $addWorkflowFields->up();
    expect(Schema::hasColumns($table, $workflowFields))->toBeTrue();
});

it('carries the legacy home valuation calculator into the valuation boundary', function (): void {
    $calculate = app(CalculateHomeValuation::class);
    $result = $calculate->handle(1200, 3, 2, 2018, 'semi-detached', 'good', 'prime', 3000);

    expect($result['estimated_value'])->toBeGreaterThan($result['min_value'])
        ->and($result['max_value'])->toBeGreaterThan($result['estimated_value'])
        ->and($result['confidence_level'])->toBeBetween(70, 95)
        ->and($result['breakdown']['room_bonus'])->toBe(23000.0);

    $unknownFeatures = $calculate->handle(100, 1, 1, (int) date('Y') - 60, 'other', 'unknown', 'unrated');
    expect($unknownFeatures['estimated_value'])->toBe(270000.0)
        ->and($unknownFeatures['confidence_level'])->toBe(85)
        ->and($unknownFeatures['breakdown']['age_adjustment'])->toBe(0.9);

    expect(fn () => $calculate->handle(0, 1, 1, 2018, 'apartment', 'good', 'prime'))
        ->toThrow(ValidationException::class, 'Property size, rooms, year, and base price must be valid positive values.');
});

it('carries the legacy explainable property valuation report into the valuation boundary', function (): void {
    $result = app(GeneratePropertyValuation::class)->handle([
        'address' => 'Valuation Street',
        'area_sqft' => 1200,
        'bedrooms' => 3,
        'bathrooms' => 2,
        'year_built' => 2018,
        'property_type' => 'detached',
        'is_featured' => true,
        'status' => 'available',
    ], 4, 20);

    expect($result['estimated'])->toBeTrue()
        ->and($result['estimated_value'])->toBeGreaterThan(0)
        ->and($result['price_range']['min'])->toBeLessThan($result['estimated_value'])
        ->and($result['price_range']['max'])->toBeGreaterThan($result['estimated_value'])
        ->and($result['confidence_level'])->toBeBetween(70, 100)
        ->and($result['comparables_count'])->toBe(4)
        ->and($result['feature_importance'])->not->toBeEmpty()
        ->and($result['disclaimer'])->toContain('not a professional appraisal');
});

it('provides the legacy neural valuation contract through the modular engine', function (): void {
    $property = app(CreateProperty::class)->handle(10, 5, [
        'address' => 'Neural Valuation Street',
        'area_sqft' => 1500,
        'bedrooms' => 3,
        'bathrooms' => 2,
        'year_built' => 2010,
        'property_type' => 'detached',
        'status' => 'available',
    ]);

    $result = app(GenerateNeuralPropertyValuation::class)->handle($property, 4, 20);

    expect($result['method'])->toBe('neural_network')
        ->and($result['model_version'])->toBe('1.0.0')
        ->and($result['estimated_value'])->toBeGreaterThan(0)
        ->and($result['price_range']['min'])->toBeLessThan($result['estimated_value'])
        ->and($result['price_range']['max'])->toBeGreaterThan($result['estimated_value'])
        ->and($result['feature_importance'])->not->toBeEmpty()
        ->and($result['prediction_factors'])->toBeArray();
});

it('explains valuation adjustments for an older property and counts elapsed listing time', function (): void {
    $this->freezeTime();
    $generate = app(GeneratePropertyValuation::class);
    $property = [
        'area_sqft' => 2400,
        'bedrooms' => 0,
        'year_built' => now()->year - 110,
        'property_type' => 'detached',
        'is_featured' => true,
    ];
    $listed = $generate->handle([...$property, 'list_date' => now()->subDays(120)]);
    $futureListing = $generate->handle([...$property, 'list_date' => now()->addDays(120)]);

    expect($listed['confidence_level'])->toBe(45)
        ->and($listed['prediction_factors'])->toContain(
            'Large property size adds significant value',
            'Age of property reduces value',
            'Detached property type adds premium',
            'Featured property status indicates higher quality',
            'Extended time on market may indicate overpricing',
        )
        ->and($listed['estimated_value'])->toBeLessThan($futureListing['estimated_value'])
        ->and($futureListing['prediction_factors'])->not->toContain('Extended time on market may indicate overpricing');

    $compactNewBuild = $generate->handle([...$property, 'area_sqft' => 250, 'year_built' => now()->year]);
    expect($compactNewBuild['confidence_level'])->toBe(50)
        ->and($compactNewBuild['prediction_factors'])->toContain('New construction premium applied');

    expect(fn () => $generate->handle([...$property, 'area_sqft' => 0]))
        ->toThrow(ValidationException::class, 'Property area, rooms, year, and valuation counts must be valid.');
});

it('serves property valuation reports through the API and Livewire adapters', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));
    $property = [
        'area_sqft' => 1200,
        'bedrooms' => 3,
        'bathrooms' => 2,
        'year_built' => 2018,
        'property_type' => 'detached',
        'address' => 'Valuation Street',
    ];

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/real-estate/valuations/calculate-property', ['property' => $property])
        ->assertOk()
        ->assertJsonPath('data.estimated', true)
        ->assertJsonPath('data.method', 'explainable_heuristic');

    $this->actingAs($user);
    Livewire::component('test-property-valuation-estimator', PropertyValuationEstimator::class);

    Livewire::test('test-property-valuation-estimator', ['property' => $property])
        ->call('generateValuation')
        ->assertSet('valuation.estimated', true)
        ->assertSet('valuation.method', 'explainable_heuristic')
        ->set('property.area_sqft', 0)
        ->call('generateValuation')
        ->assertSet('valuation', null)
        ->assertSet('error', 'Property area, rooms, year, and valuation counts must be valid.')
        ->call('resetValuation')
        ->assertSet('error', null);
});

it('runs the appraisal workflow through the authenticated team API', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    $otherTeamValuation = app(CreateValuation::class)->handle(20, $user->id, ['subject' => 'Other team appraisal']);
    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));
    $endpoint = '/api/v1/real-estate/valuations';
    $this->actingAs($user, 'sanctum');

    $valuationId = $this->postJson($endpoint, ['subject' => 'Market appraisal', 'fee_amount' => 250])
        ->assertCreated()
        ->assertJsonPath('data.team_id', 10)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.fee_amount', '250.00')
        ->json('data.id');
    $valuationUrl = "$endpoint/$valuationId";

    $this->getJson("$endpoint?page_size=1")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $valuationId)
        ->assertJsonPath('meta.total', 1);
    $this->getJson("$endpoint/{$otherTeamValuation->id}")->assertNotFound();
    $this->getJson($valuationUrl)->assertOk()->assertJsonPath('data.subject', 'Market appraisal');
    $this->patchJson($valuationUrl, ['subject' => 'Updated appraisal'])
        ->assertOk()->assertJsonPath('data.subject', 'Updated appraisal');

    $this->postJson("$valuationUrl/comparables", ['comparables' => [['amount' => 300000], ['amount' => 400000]]])
        ->assertOk()->assertJsonPath('data.comparable_data.average_amount', 350000);
    $this->postJson("$valuationUrl/schedule", ['scheduled_at' => now()->subDay()->toDateTimeString()])
        ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
    $this->postJson("$valuationUrl/schedule", ['scheduled_at' => now()->addDay()->toDateTimeString()])
        ->assertOk()->assertJsonPath('data.status', 'scheduled');
    $this->postJson("$valuationUrl/complete", ['valued_amount' => 350000])
        ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.valued_amount', '350000.00');
    $this->postJson("$valuationUrl/convert", ['type' => 'instruction', 'id' => 42])
        ->assertOk()->assertJsonPath('data.status', 'converted')->assertJsonPath('data.conversion.id', 42);

    $this->deleteJson($valuationUrl)->assertNoContent();
    $this->getJson($valuationUrl)->assertNotFound();
    $this->assertSoftDeleted('real_estate_valuations', ['id' => $valuationId]);
});

it('runs Livewire appraisal actions only within the active team', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    $create = app(CreateValuation::class);
    $valuation = $create->handle(10, $user->id, ['subject' => 'Market appraisal']);
    $create->handle(10, $user->id, ['subject' => 'Rental appraisal']);
    $otherTeamValuation = $create->handle(20, $user->id, ['subject' => 'Other team appraisal']);
    $scheduledAt = now()->addDay()->toDateTimeString();

    $this->actingAs($user);
    Livewire::test('module-real-estate-valuations::valuation-list')
        ->assertSee('Market appraisal')
        ->assertSee('Rental appraisal')
        ->assertDontSee('Other team appraisal')
        ->set('search', 'Market')
        ->assertDontSee('Rental appraisal')
        ->set('scheduledAt', $scheduledAt)
        ->call('scheduleValuation', $otherTeamValuation->id)
        ->assertSet('error', fn (?string $error): bool => filled($error))
        ->call('scheduleValuation', $valuation->id)
        ->assertSet('error', null)
        ->assertSee('Market appraisal (scheduled)')
        ->set('valuedAmount', 350000)
        ->call('completeValuation', $valuation->id)
        ->assertSee('Market appraisal (completed)')
        ->set('conversionType', 'instruction')
        ->call('convertValuation', $valuation->id)
        ->assertSee('Market appraisal (converted)');

    expect($valuation->fresh()->conversion)->toBe(['type' => 'instruction'])
        ->and($otherTeamValuation->fresh()->status)->toBe(ValuationStatus::Draft);
});

it('shows an empty Livewire valuation list without an active team', function (): void {
    Livewire::test('module-real-estate-valuations::valuation-list')
        ->assertSee('No valuations found.')
        ->call('scheduleValuation', 1)
        ->assertSet('error', 'A team context is required.');
});

it('serves the home valuation calculation through its API resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/real-estate/valuations/calculate-home', [
            'property_size' => 1200,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'year_built' => 2018,
            'property_type' => 'semi-detached',
            'condition' => 'good',
            'location' => 'prime',
        ])
        ->assertOk()
        ->assertJsonPath('data.property_size', 1200)
        ->assertJsonPath('data.breakdown.room_bonus', 23000);
});

it('serves neural property valuations for properties in the current team', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));
    $property = app(CreateProperty::class)->handle(10, $user->getKey(), [
        'address' => 'Neural API Street',
        'area_sqft' => 1200,
        'bedrooms' => 3,
        'bathrooms' => 2,
        'year_built' => 2018,
        'property_type' => 'detached',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/real-estate/valuations/calculate-neural-property', ['property_id' => $property->getKey(), 'training_samples' => 20])
        ->assertOk()
        ->assertJsonPath('data.method', 'neural_network')
        ->assertJsonPath('data.model_version', '1.0.0');
});

it('calculates amortization safely, including zero-interest loans', function (): void {
    $calculate = app(CalculateMortgage::class);
    $interestFree = $calculate->handle(240000, 120000, 0, 10);

    expect($interestFree['estimated'])->toBeTrue()
        ->and($interestFree['monthly_payment'])->toBe(1000.0)
        ->and($interestFree['total_interest'])->toBe(0.0)
        ->and($interestFree['loan_to_value'])->toBe(50.0)
        ->and($interestFree['amortization_schedule'])->toHaveCount(120)
        ->and($interestFree['amortization_schedule'][119]['balance'])->toBe(0.0)
        ->and($interestFree['disclaimer'])->toContain('actual offers');

    expect(fn () => $calculate->handle(240000, 250000, 5, 25))->toThrow(ValidationException::class);
});

it('serves mortgage estimates through the authenticated API and Livewire adapters', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/real-estate/valuations/calculate-mortgage', [
            'property_price' => 240000,
            'loan_amount' => 120000,
            'interest_rate' => 0,
            'loan_term_years' => 10,
        ])
        ->assertOk()
        ->assertJsonPath('data.estimated', true)
        ->assertJsonPath('data.monthly_payment', 1000);

    $this->actingAs($user);
    Livewire::component('test-mortgage-calculator', MortgageCalculator::class);

    Livewire::test('test-mortgage-calculator', ['propertyPrice' => 240000, 'loanAmount' => 120000, 'interestRate' => 0, 'loanTermYears' => 10])
        ->call('calculateMortgage')
        ->assertSee('Mortgage estimate')
        ->assertSet('result.monthly_payment', 1000)
        ->set('loanAmount', 250000)
        ->call('calculateMortgage')
        ->assertSet('result', null)
        ->assertSet('error', 'Property price, loan amount, interest rate, and term must be valid.')
        ->call('resetCalculation')
        ->assertSet('error', null);
});

it('calculates estimate-only rental yields', function (): void {
    $result = app(CalculateRentalYield::class)->handle(200000, 24000, 6000);

    expect($result['estimated'])->toBeTrue()
        ->and($result['gross_yield'])->toBe(12.0)
        ->and($result['net_yield'])->toBe(9.0)
        ->and($result['expense_ratio'])->toBe(3.0)
        ->and($result['net_annual_income'])->toBe(18000.0)
        ->and($result['disclaimer'])->toContain('not fully modelled');

    expect(fn () => app(CalculateRentalYield::class)->handle(0, 24000))->toThrow(ValidationException::class);
});

it('serves rental yield estimates through the authenticated API and Livewire adapters', function (): void {
    $user = User::factory()->create(['current_team_id' => 10]);
    RateLimiter::for('api', static fn (): Limit => Limit::perMinute(1000));

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/real-estate/valuations/calculate-rental-yield', [
            'property_value' => 200000,
            'annual_rental_income' => 24000,
            'annual_expenses' => 6000,
        ])
        ->assertOk()
        ->assertJsonPath('data.estimated', true)
        ->assertJsonPath('data.net_yield', 9);

    $this->actingAs($user);
    Livewire::component('test-rental-yield-calculator', RentalYieldCalculator::class);

    Livewire::test('test-rental-yield-calculator', ['propertyValue' => 200000, 'annualRentalIncome' => 24000, 'annualExpenses' => 6000])
        ->call('calculateRentalYield')
        ->assertSee('Rental yield estimate')
        ->assertSet('result.net_yield', 9)
        ->set('propertyValue', 0)
        ->call('calculateRentalYield')
        ->assertSet('result', null)
        ->assertSet('error', 'Property value, rental income, and expenses must be valid non-negative amounts.')
        ->call('resetCalculation')
        ->assertSet('error', null);
});
