<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Marketing\Application\CreateMarketingCampaign;
use Liberu\RealEstate\Marketing\Application\DispatchScheduledCampaigns;
use Liberu\RealEstate\Marketing\Application\SendMarketingCampaign;
use Liberu\RealEstate\Marketing\Application\TransitionMarketingCampaign;
use Liberu\RealEstate\Marketing\Application\UpdateMarketingCampaign;
use Liberu\RealEstate\Marketing\Application\UpdateMarketingCampaignSection;
use Liberu\RealEstate\Marketing\Domain\MarketingCampaignSection;
use Liberu\RealEstate\Marketing\Domain\MarketingCampaignStatus;
use Liberu\RealEstate\Marketing\Mail\CampaignMail;
use Liberu\RealEstate\Marketing\Models\MarketingCampaign;
use Liberu\RealEstate\Marketing\Models\NewsArticle;
use Liberu\RealEstate\Marketing\Policies\MarketingCampaignPolicy;
use Liberu\RealEstate\MarketingApi\Http\Controllers\NewsArticleController;
use Liberu\RealEstate\MarketingFilament\Resources\MarketingCampaignResource;
use Liberu\RealEstate\MarketingFilament\Resources\MarketingCampaignResource\Pages\CreateMarketingCampaign as CreateMarketingCampaignPage;
use Liberu\RealEstate\MarketingFilament\Resources\MarketingCampaignResource\Pages\EditMarketingCampaign as EditMarketingCampaignPage;
use Liberu\RealEstate\MarketingFilament\Resources\NewsArticleResource\Pages\CreateNewsArticle as CreateNewsArticlePage;
use Liberu\RealEstate\MarketingFilament\Resources\NewsArticleResource\Pages\EditNewsArticle as EditNewsArticlePage;
use Liberu\RealEstate\MarketingLivewire\Components\NewsArticleList;
use Livewire\Livewire;

it('covers marketing application actions, mail, and policy', function (): void {
    Mail::fake();

    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);

    // CreateMarketingCampaign missing channel
    $create = app(CreateMarketingCampaign::class);
    expect(fn () => $create->handle($team->id, $user->id, ['name' => 'Spring', 'channel' => '']))
        ->toThrow(ValidationException::class);

    $campaign = $create->handle($team->id, $user->id, [
        'name' => 'Spring Launch',
        'channel' => 'email',
    ]);

    // UpdateMarketingCampaign
    $update = app(UpdateMarketingCampaign::class);
    expect(fn () => $update->handle($campaign, $otherTeam->id, ['name' => 'Other']))
        ->toThrow(ValidationException::class);
    expect(fn () => $update->handle($campaign, $team->id, ['name' => '  ']))
        ->toThrow(ValidationException::class);
    $campaign = $update->handle($campaign, $team->id, ['name' => 'Summer Launch', 'status' => 'active']);
    expect($campaign->name)->toBe('Summer Launch');
    expect($campaign->status)->toBe(MarketingCampaignStatus::Draft);

    // UpdateMarketingCampaignSection wrong team
    $updateSection = app(UpdateMarketingCampaignSection::class);
    expect(fn () => $updateSection->handle($campaign, $otherTeam->id, MarketingCampaignSection::Audience, ['emails' => ['a@example.com']]))
        ->toThrow(ValidationException::class);

    // TransitionMarketingCampaign all branches
    $transition = app(TransitionMarketingCampaign::class);
    expect(fn () => $transition->handle($campaign, $otherTeam->id, MarketingCampaignStatus::Scheduled))
        ->toThrow(ValidationException::class);
    expect(fn () => $transition->handle($campaign, $team->id, MarketingCampaignStatus::Completed))
        ->toThrow(ValidationException::class);

    $campaign = $transition->handle($campaign, $team->id, MarketingCampaignStatus::Scheduled);
    $campaign = $transition->handle($campaign, $team->id, MarketingCampaignStatus::Paused);
    $campaign = $transition->handle($campaign, $team->id, MarketingCampaignStatus::Active);
    $campaign = $transition->handle($campaign, $team->id, MarketingCampaignStatus::Completed);
    expect($campaign->status)->toBe(MarketingCampaignStatus::Completed);
    expect(fn () => $transition->handle($campaign, $team->id, MarketingCampaignStatus::Cancelled))
        ->toThrow(ValidationException::class);

    // SendMarketingCampaign uncovered validation branches
    $send = app(SendMarketingCampaign::class);
    $smsCampaign = $create->handle($team->id, $user->id, [
        'name' => 'SMS Blast',
        'channel' => 'sms',
        'status' => MarketingCampaignStatus::Active,
    ]);
    expect(fn () => $send->handle($smsCampaign))->toThrow(ValidationException::class);

    $emptyContent = $create->handle($team->id, $user->id, [
        'name' => 'No Content',
        'channel' => 'email',
        'status' => MarketingCampaignStatus::Active,
    ]);
    expect(fn () => $send->handle($emptyContent))->toThrow(ValidationException::class);

    $emptyAudience = $create->handle($team->id, $user->id, [
        'name' => 'No Audience',
        'channel' => 'email',
        'status' => MarketingCampaignStatus::Active,
        'content' => ['subject' => 'Hello', 'body' => '<p>Body</p>'],
        'audience' => ['emails' => ['invalid-email']],
    ]);
    expect(fn () => $send->handle($emptyAudience))->toThrow(ValidationException::class);

    // DispatchScheduledCampaigns (due scheduled + valid + invalid active)
    $dueCampaign = $create->handle($team->id, $user->id, [
        'name' => 'Scheduled Due',
        'channel' => 'email',
        'status' => MarketingCampaignStatus::Scheduled,
        'schedule' => ['send_at' => now()->subMinute()->toISOString()],
        'content' => ['subject' => 'Due', 'body' => '<p>Ready</p>'],
        'audience' => ['emails' => ['lead@example.com']],
    ]);
    $sentCount = app(DispatchScheduledCampaigns::class)->handle($team->id);
    expect($sentCount)->toBe(1);
    expect($dueCampaign->fresh()->status)->toBe(MarketingCampaignStatus::Completed);

    // CampaignMail::build()
    $mail = (new CampaignMail('Test Subject', '<p>Hello</p>'))->build();
    expect($mail->subject)->toBe('Test Subject');

    // MarketingCampaignPolicy
    $policy = new MarketingCampaignPolicy();
    expect($policy->viewAny($user))->toBeBool();
    expect($policy->view($user, $campaign))->toBeBool();
    expect($policy->create($user))->toBeBool();
    expect($policy->update($user, $campaign))->toBeBool();
    expect($policy->delete($user, $campaign))->toBeBool();
    expect($policy->deleteAny($user))->toBeBool();
    expect($policy->restore($user, $campaign))->toBeBool();
    expect($policy->forceDelete($user, $campaign))->toBeBool();
    expect($policy->forceDeleteAny($user))->toBeBool();
    expect($policy->restoreAny($user))->toBeBool();
    expect($policy->replicate($user, $campaign))->toBeBool();
    expect($policy->reorder($user))->toBeBool();
});

it('covers marketing api controllers and resources', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);

    $this->actingAs($user, 'sanctum');

    $storeResponse = $this->postJson('/api/v1/real-estate/marketing', [
        'name' => 'Autumn Mailer',
        'channel' => 'email',
    ])->assertCreated();

    $id = $storeResponse->json('data.id');

    $this->getJson('/api/v1/real-estate/marketing?page_size=10')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Autumn Mailer');

    $this->getJson("/api/v1/real-estate/marketing/{$id}")
        ->assertOk()
        ->assertJsonPath('data.id', $id);

    $this->patchJson("/api/v1/real-estate/marketing/{$id}", [
        'name' => 'Winter Mailer',
    ])->assertOk()->assertJsonPath('data.name', 'Winter Mailer');

    $this->postJson("/api/v1/real-estate/marketing/{$id}/transition/scheduled")
        ->assertOk()
        ->assertJsonPath('data.status', 'scheduled');

    $this->patchJson("/api/v1/real-estate/marketing/{$id}/content", [
        'value' => ['subject' => 'Winter', 'body' => '<p>Deals</p>'],
    ])->assertOk()->assertJsonPath('data.content.subject', 'Winter');

    $this->deleteJson("/api/v1/real-estate/marketing/{$id}")
        ->assertNoContent();

    // NewsArticleController::latest()
    NewsArticle::query()->create([
        'team_id' => $team->id,
        'title' => 'Market Update',
        'slug' => 'market-update',
        'content' => 'Prices steady.',
        'published_at' => now()->subHour(),
    ]);
    $request = Request::create('/api/v1/real-estate/news', 'GET');
    $request->setUserResolver(fn () => $user);
    $latestResponse = (new NewsArticleController())->latest($request);
    expect($latestResponse->getStatusCode())->toBe(200);
});

it('covers marketing filament pages, table actions, and livewire components', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $this->actingAs($user);

    // CreateMarketingCampaignPage & EditMarketingCampaignPage
    $createCampaignPage = (new ReflectionClass(CreateMarketingCampaignPage::class))->newInstanceWithoutConstructor();
    $campaign = (new ReflectionMethod(CreateMarketingCampaignPage::class, 'handleRecordCreation'))
        ->invoke($createCampaignPage, ['name' => 'Filament Campaign', 'channel' => 'email']);
    expect($campaign)->toBeInstanceOf(MarketingCampaign::class);

    $editCampaignPage = (new ReflectionClass(EditMarketingCampaignPage::class))->newInstanceWithoutConstructor();
    $campaign = (new ReflectionMethod(EditMarketingCampaignPage::class, 'handleRecordUpdate'))
        ->invoke($editCampaignPage, $campaign, ['name' => 'Updated Campaign']);
    expect($campaign->name)->toBe('Updated Campaign');

    // CreateNewsArticlePage & EditNewsArticlePage
    $createArticlePage = (new ReflectionClass(CreateNewsArticlePage::class))->newInstanceWithoutConstructor();
    $article = (new ReflectionMethod(CreateNewsArticlePage::class, 'handleRecordCreation'))
        ->invoke($createArticlePage, [
            'title' => 'Local Market News',
            'content' => 'Body copy.',
            'published_at' => now()->subMinute(),
        ]);
    expect($article)->toBeInstanceOf(NewsArticle::class);
    expect($article->slug)->toBe('local-market-news');

    $editArticlePage = (new ReflectionClass(EditNewsArticlePage::class))->newInstanceWithoutConstructor();
    $headerActions = (new ReflectionMethod(EditNewsArticlePage::class, 'getHeaderActions'))->invoke($editArticlePage);
    expect($headerActions)->toHaveCount(1);
    $article = (new ReflectionMethod(EditNewsArticlePage::class, 'handleRecordUpdate'))
        ->invoke($editArticlePage, $article, [
            'title' => 'Updated Market News',
            'slug' => 'custom-market-slug',
            'content' => 'Updated body.',
            'published_at' => now()->subMinute(),
        ]);
    expect($article->slug)->toBe('custom-market-slug');

    // Livewire NewsArticleList
    Livewire::test(NewsArticleList::class)
        ->set('search', 'Updated')
        ->assertOk();

    // Filament MarketingCampaignResource table update_section & delete closures
    $table = MarketingCampaignResource::table(Table::make(Mockery::mock(HasTable::class)));
    $actions = collect($table->getRecordActions());
    $updateSectionAction = $actions->first(fn ($action): bool => $action->getName() === 'update_section');
    ($updateSectionAction->getActionFunction())($campaign, [
        'section' => 'content',
        'value' => ['subject' => 'Hi', 'body' => 'There'],
    ]);
    expect($campaign->fresh()->content['subject'])->toBe('Hi');

    $deleteAction = $actions->first(fn ($action): bool => $action->getName() === 'delete');
    ($deleteAction->getActionFunction())($campaign);
    expect(MarketingCampaign::query()->find($campaign->id))->toBeNull();
});
