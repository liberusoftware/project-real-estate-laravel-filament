<?php

use App\Models\User;
use Illuminate\Support\Facades\Session;
use Liberu\Foundation\Theme\Services\ThemeManager;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['app.supported_locales' => ['en' => 'English', 'fr' => 'Français', 'invalid' => ['not a label']]]);
    app()->setLocale('en');
    Session::flush();
});

it('renders the registered language switcher and persists a guest preference', function (): void {
    Livewire::test('language-switcher')
        ->assertSet('currentLocale', 'en')
        ->assertSet('availableLocales', ['en' => 'English', 'fr' => 'Français'])
        ->assertSee('Français')
        ->call('switchLanguage', 'fr')
        ->assertSet('currentLocale', 'fr')
        ->assertNoRedirect()->assertJs('window.location.reload()');

    expect(Session::get('locale'))->toBe('fr');
});

it('persists the authenticated user language alongside the session preference', function (): void {
    $user = User::factory()->create(['locale' => 'en']);
    Livewire::actingAs($user)->test('language-switcher')
        ->call('switchLanguage', 'fr')->assertNoRedirect()->assertJs('window.location.reload()');

    expect($user->fresh()->locale)->toBe('fr')->and(Session::get('locale'))->toBe('fr');
});

it('ignores unsupported locales without changing stored preferences or redirecting', function (): void {
    Session::put('locale', 'en');
    Livewire::test('language-switcher')->call('switchLanguage', 'invalid')
        ->assertSet('currentLocale', 'en')->assertNoRedirect();

    expect(Session::get('locale'))->toBe('en');
});

it('preserves the panel locale subset and rejects injected locales outside the application list', function (): void {
    $component = Livewire::test('language-switcher', ['locales' => ['en' => 'English']])
        ->assertSet('availableLocales', ['en' => 'English'])
        ->call('switchLanguage', 'fr')->assertNoRedirect()->assertNoJs()
        ->set('availableLocales', ['xx' => 'Injected locale'])
        ->call('switchLanguage', 'xx')->assertNoRedirect()->assertNoJs();

    $component->assertSet('currentLocale', 'en');
    expect(Session::has('locale'))->toBeFalse();
});

it('renders the registered theme switcher and persists valid selections with a notification', function (): void {
    $manager = app(ThemeManager::class);
    Livewire::test('theme-switcher')
        ->assertSet('currentTheme', $manager->getActiveTheme())
        ->assertSet('availableThemes', $manager->getThemes())
        ->call('switchTheme', 'dark')->assertSet('currentTheme', 'dark')
        ->assertDispatched('theme-changed', theme: 'dark')->assertRedirect('/');

    expect(Session::get('theme_preference'))->toBe('dark')
        ->and(Session::get('message'))->toBe('Theme changed successfully.');
});

it('ignores unknown themes without persistence notifications or redirects', function (): void {
    $active = app(ThemeManager::class)->getActiveTheme();
    Livewire::test('theme-switcher')->call('switchTheme', 'unknown-host-theme')
        ->assertSet('currentTheme', $active)->assertNotDispatched('theme-changed')->assertNoRedirect();

    expect(Session::has('theme_preference'))->toBeFalse()->and(Session::has('message'))->toBeFalse();
});

it('returns switcher requests only to the application URL boundary', function (string $alias, string $method, string $selection, ?string $suffix, bool $allowed): void {
    $base = url('/');
    $headers = $suffix === null ? [] : ['Referer' => $base.$suffix];
    $component = Livewire::test($alias);
    $this->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($component->snapshot, JSON_THROW_ON_ERROR),
            'updates' => [],
            'calls' => [['path' => '', 'method' => $method, 'params' => [$selection]]],
        ]],
    ], ['X-Livewire' => 'true', ...$headers])
        ->assertSuccessful()
        ->assertJsonPath('components.0.effects.redirect', $allowed ? $base.$suffix : '/');
})->with([
    'theme adapter' => ['theme-switcher', 'switchTheme', 'dark'],
])->with([
    'missing referer' => [null, false],
    'application root' => ['', true],
    'application path' => ['/account?tab=preferences', true],
    'application query' => ['?tab=preferences', true],
    'lookalike hostname' => ['.attacker.example/account', false],
    'userinfo hostname' => ['@attacker.example/account', false],
]);
