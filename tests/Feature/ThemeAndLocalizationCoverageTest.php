<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\View\ViewFinderInterface;
use Liberu\Foundation\Localization\MyMemory\MyMemoryServiceProvider;
use Liberu\Foundation\Localization\MyMemory\TranslationService;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use Liberu\Foundation\Theme\Cache\ThemeCache;
use Liberu\Foundation\Theme\Discovery\ThemeDiscovery;
use Liberu\Foundation\Theme\Exceptions\InvalidTheme;
use Liberu\Foundation\Theme\Manifests\ThemeManifest;
use Liberu\Foundation\Theme\Services\ThemeManager;
use Liberu\Localization\Contracts\TranslationProvider;
use Liberu\Localization\Contracts\TranslationProviderRegistry;

function makeTempThemeDir(string $baseDir, string $dirName, array $manifestOverrides = [], ?array $composerData = null, bool $createAssets = true): string
{
    $dir = $baseDir.'/'.$dirName;
    File::ensureDirectoryExists($dir.'/resources/css');
    File::ensureDirectoryExists($dir.'/resources/js');
    File::ensureDirectoryExists($dir.'/resources/views/layouts');

    if ($createAssets) {
        File::put($dir.'/resources/css/theme.css', 'body {}');
        File::put($dir.'/resources/js/theme.js', 'console.log(1);');
    }

    $manifest = array_merge([
        'name' => $dirName,
        'display_name' => ucfirst($dirName),
        'version' => '1.0.0',
        'provider' => MyMemoryServiceProvider::class,
        'type' => 'public',
        'parent' => null,
        'optimized_for' => [],
        'tested_with' => [],
        'required_capabilities' => [],
        'optional_capabilities' => ['dark-mode'],
        'supports' => [],
        'assets' => [
            'css' => $createAssets ? ['resources/css/theme.css'] : [],
            'js' => $createAssets ? ['resources/js/theme.js'] : [],
        ],
    ], $manifestOverrides);

    File::put($dir.'/theme.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    if ($composerData !== null) {
        File::put($dir.'/composer.json', json_encode($composerData, JSON_THROW_ON_ERROR));
    } else {
        File::put($dir.'/composer.json', json_encode([
            'name' => 'liberu/'.$dirName,
            'type' => 'liberu-theme',
            'extra' => ['liberu' => ['name' => $manifest['name']]],
        ], JSON_THROW_ON_ERROR));
    }

    return $dir;
}

it('covers MyMemoryServiceProvider and TranslationService completely', function (): void {
    $registered = [];
    $registry = Mockery::mock(TranslationProviderRegistry::class);
    $registry->shouldReceive('register')->once()->andReturnUsing(function (TranslationProvider $provider) use (&$registered): void {
        $registered[$provider->name()] = $provider;
    });

    app()->instance(TranslationProviderRegistry::class, $registry);
    (new MyMemoryServiceProvider(app()))->boot();

    expect($registered)->toHaveKey('mymemory');

    $service = app(TranslationService::class);
    expect($service->name())->toBe('mymemory')
        ->and($service->supportedLanguages())->toHaveKeys(['en', 'es', 'fr', 'de'])
        ->and($service->getSupportedLanguages())->toHaveKey('en')
        ->and($service->isLanguageSupported('fr'))->toBeTrue()
        ->and($service->isLanguageSupported('zz'))->toBeFalse();

    // Same language short-circuit
    expect($service->translate('Hello', 'en', 'en'))->toBe('Hello');

    // Successful HTTP translation + caching
    Cache::flush();
    Http::fake([
        'api.mymemory.translated.net/*' => Http::sequence()
            ->push(['responseData' => ['translatedText' => 'Hola']], 200)
            ->push(['responseData' => ['translatedText' => '']], 200)
            ->pushStatus(500),
    ]);

    expect($service->translate('Hello', 'es', 'en'))->toBe('Hola');
    // Cached hit (string)
    expect($service->translate('Hello', 'es', 'en'))->toBe('Hola');

    // Cached hit (non-string falls back to original)
    $nonStringKey = 'translation:en:fr:'.md5('NonString');
    Cache::put($nonStringKey, ['not-a-string'], 60);
    expect($service->translate('NonString', 'fr', 'en'))->toBe('NonString');

    // Empty translatedText falls back to original
    expect($service->translate('EmptyResponse', 'es', 'en'))->toBe('EmptyResponse');

    // Failed HTTP status falls back to original
    expect($service->translate('ServerError', 'es', 'en'))->toBe('ServerError');

    // Exception path logs error and falls back to original
    Http::swap(new Factory());
    Http::fake([
        'api.mymemory.translated.net/*' => fn () => throw new RuntimeException('Network down'),
    ]);
    Log::shouldReceive('error')->once();
    expect($service->translate('Boom', 'es', 'en'))->toBe('Boom');

    // translateBatch with nested arrays, strings, and non-string values
    Cache::flush();
    Http::swap(new Factory());
    Http::fake([
        'api.mymemory.translated.net/*' => Http::response(['responseData' => ['translatedText' => 'Bonjour']], 200),
    ]);
    $batch = $service->translateBatch([
        'greeting' => 'Hello',
        'nested' => ['inner' => 'Hello'],
        'count' => 42,
    ], 'fr', 'en');

    expect($batch)->toBe([
        'greeting' => 'Bonjour',
        'nested' => ['inner' => 'Bonjour'],
        'count' => 42,
    ]);
});

it('covers ThemeManifest validation and getters', function (): void {
    $tmp = sys_get_temp_dir().'/theme-manifest-'.uniqid();
    File::ensureDirectoryExists($tmp);

    try {
        // Missing required key
        $missingFile = $tmp.'/missing.json';
        File::put($missingFile, json_encode(['name' => 'bad'], JSON_THROW_ON_ERROR));
        expect(fn () => ThemeManifest::fromFile($missingFile))->toThrow(InvalidTheme::class, 'is missing');

        // Invalid name
        $badNameDir = makeTempThemeDir($tmp, 'bad-name', ['name' => 'Invalid_Name']);
        expect(fn () => ThemeManifest::fromFile($badNameDir.'/theme.json'))->toThrow(InvalidTheme::class, 'Theme name is invalid.');

        // Invalid type
        $badTypeDir = makeTempThemeDir($tmp, 'bad-type', ['type' => 'unknown']);
        expect(fn () => ThemeManifest::fromFile($badTypeDir.'/theme.json'))->toThrow(InvalidTheme::class, 'invalid type');

        // Invalid asset path (missing file / traversal)
        $badAssetDir = makeTempThemeDir($tmp, 'bad-asset', ['assets' => ['css' => ['../escape.css'], 'js' => []]], null, false);
        expect(fn () => ThemeManifest::fromFile($badAssetDir.'/theme.json'))->toThrow(InvalidTheme::class, 'invalid css asset');

        // Valid manifest getters
        $goodDir = makeTempThemeDir($tmp, 'good-theme');
        $manifest = ThemeManifest::fromFile($goodDir.'/theme.json');
        expect($manifest->version())->toBe('1.0.0')
            ->and($manifest->type())->toBe('public')
            ->and($manifest->optionalCapabilities())->toBe(['dark-mode']);
    } finally {
        File::deleteDirectory($tmp);
    }
});

it('covers ThemeCache error branches and nested unexpected objects', function (): void {
    $tmp = sys_get_temp_dir().'/theme-cache-'.uniqid();
    File::ensureDirectoryExists($tmp);

    try {
        $cacheFile = $tmp.'/themes.cache';

        // Nested unexpected object
        File::put($cacheFile, serialize(['nested' => [new stdClass()]]));
        $cache = new ThemeCache();
        expect(fn () => $cache->load($cacheFile))->toThrow(InvalidTheme::class, 'invalid');

        // Write failure via mocked Filesystem
        $failingWriteFs = Mockery::mock(Filesystem::class);
        $failingWriteFs->shouldReceive('put')->once()->andReturn(false);
        expect(fn () => (new ThemeCache($failingWriteFs))->write([], $cacheFile))
            ->toThrow(InvalidTheme::class, 'Unable to write theme cache.');

        // Clear failure via mocked Filesystem
        $failingClearFs = Mockery::mock(Filesystem::class);
        $failingClearFs->shouldReceive('isFile')->once()->with($cacheFile)->andReturn(true);
        $failingClearFs->shouldReceive('delete')->once()->with($cacheFile)->andReturn(false);
        expect(fn () => (new ThemeCache($failingClearFs))->clear($cacheFile))
            ->toThrow(InvalidTheme::class, 'Unable to clear theme cache.');
    } finally {
        File::deleteDirectory($tmp);
    }
});

it('covers ThemeDiscovery edge cases and validation errors', function (): void {
    $tmp = sys_get_temp_dir().'/theme-discovery-'.uniqid();
    File::ensureDirectoryExists($tmp);

    try {
        // Empty dir -> no themes installed
        expect(fn () => (new ThemeDiscovery([]))->discover($tmp))
            ->toThrow(InvalidTheme::class, 'No themes are installed.');

        // Subdir without theme.json is skipped (line 45)
        File::ensureDirectoryExists($tmp.'/no-manifest');
        expect(fn () => (new ThemeDiscovery([]))->discover($tmp))
            ->toThrow(InvalidTheme::class, 'No themes are installed.');

        // Directory/name mismatch in tracked tree (line 52)
        $mismatchRoot = $tmp.'/mismatch';
        makeTempThemeDir($mismatchRoot, 'dir-a', ['name' => 'other-name']);
        expect(fn () => (new ThemeDiscovery([]))->discover($mismatchRoot))
            ->toThrow(InvalidTheme::class, 'collision');

        // Duplicate theme name across tracked and installedPaths (line 55)
        $dupTracked = $tmp.'/dup-tracked';
        $dupInstalled = $tmp.'/dup-installed';
        makeTempThemeDir($dupTracked, 'alpha');
        $dupPkg = makeTempThemeDir($dupInstalled, 'alpha-pkg', ['name' => 'alpha']);
        expect(fn () => (new ThemeDiscovery([$dupPkg]))->discover($dupTracked))
            ->toThrow(InvalidTheme::class, 'collision');

        // Missing composer.json (line 59)
        $noComposerRoot = $tmp.'/no-composer';
        $noCompDir = makeTempThemeDir($noComposerRoot, 'beta');
        File::delete($noCompDir.'/composer.json');
        expect(fn () => (new ThemeDiscovery([]))->discover($noComposerRoot))
            ->toThrow(InvalidTheme::class, 'has no composer.json');

        // Inconsistent composer metadata (line 63)
        $badComposerRoot = $tmp.'/bad-composer';
        makeTempThemeDir($badComposerRoot, 'gamma', [], ['type' => 'library', 'extra' => []]);
        expect(fn () => (new ThemeDiscovery([]))->discover($badComposerRoot))
            ->toThrow(InvalidTheme::class, 'Composer metadata is inconsistent');

        // Non-autoloadable provider (line 66)
        $badProviderRoot = $tmp.'/bad-provider';
        makeTempThemeDir($badProviderRoot, 'delta', ['provider' => 'NonExistent\\Provider\\ClassName']);
        expect(fn () => (new ThemeDiscovery([]))->discover($badProviderRoot))
            ->toThrow(InvalidTheme::class, 'is not autoloadable');

        // Valid discovery with explicit installedPaths (line 84)
        $validRoot = $tmp.'/valid';
        $pkgDir = makeTempThemeDir($tmp.'/pkg', 'pkg-theme', ['name' => 'pkg-theme']);
        File::ensureDirectoryExists($validRoot);
        $discovered = (new ThemeDiscovery([$pkgDir]))->discover($validRoot);
        expect($discovered)->toHaveKey('pkg-theme');
    } finally {
        File::deleteDirectory($tmp);
    }
});

it('covers ThemeManager branches and theme_helpers', function (): void {
    $tmp = sys_get_temp_dir().'/theme-manager-'.uniqid();
    File::ensureDirectoryExists($tmp);
    $origCache = config('theme.cache');
    $origCachePath = config('theme.cache_path');
    $origFallback = config('theme.fallback');
    $origDefault = config('theme.default');

    try {
        // Missing fallback theme throws (line 37)
        config(['theme.cache' => false, 'theme.fallback' => 'nonexistent-fallback']);
        expect(fn () => new ThemeManager(base_path('themes')))
            ->toThrow(InvalidTheme::class, 'Safe fallback theme');

        // Cache enabled without cache file throws (lines 47-48)
        $cachePath = $tmp.'/missing.cache';
        config(['theme.cache' => true, 'theme.cache_path' => $cachePath, 'theme.fallback' => 'default']);
        expect(fn () => new ThemeManager(base_path('themes')))
            ->toThrow(InvalidTheme::class, 'no deployment cache exists');

        // Cache enabled WITH valid cache file loads themes (line 50) and clearCache deletes it (lines 264-267)
        $t1 = makeTempThemeDir($tmp.'/cached', 'default', [
            'required_capabilities' => ['some-cap'],
            'assets' => ['css' => [], 'js' => []],
        ], null, false);
        $manifests = ['default' => ThemeManifest::fromFile($t1.'/theme.json')];
        (new ThemeCache())->write($manifests, $cachePath);

        $cachedManager = new ThemeManager($tmp.'/cached');
        expect($cachedManager->getActiveTheme())->toBe('default');

        // Capabilities check (lines 133, 291-305) - twice to hit snapshot cache (line 298)
        $cachedManager->themeIsCompatible('default');
        $cachedManager->themeIsCompatible('default');

        // When ModuleRegistry is not bound (lines 288-289)
        $origRegistry = app()->bound(ModuleRegistry::class) ? app(ModuleRegistry::class) : null;
        app()->forgetInstance(ModuleRegistry::class);
        $refApp = new ReflectionProperty(app(), 'bindings');
        $bindings = $refApp->getValue(app());
        $savedBinding = $bindings[ModuleRegistry::class] ?? null;
        unset($bindings[ModuleRegistry::class]);
        $refApp->setValue(app(), $bindings);
        try {
            $freshCached = new ThemeManager($tmp.'/cached');
            expect($freshCached->themeIsCompatible('default'))->toBeFalse();
        } finally {
            if ($savedBinding !== null) {
                $bindings[ModuleRegistry::class] = $savedBinding;
                $refApp->setValue(app(), $bindings);
            }
            if ($origRegistry !== null) {
                app()->instance(ModuleRegistry::class, $origRegistry);
            }
        }

        // selectForSurface (lines 82-88)
        config(['theme.surfaces.public' => 'default']);
        expect($cachedManager->selectForSurface('public'))->toBe('default');

        // getThemeAssetPath (lines 154-156)
        expect($cachedManager->getThemeAssetPath('css'))->not->toBeNull()
            ->and($cachedManager->getThemeAssetPath('nonexistent-dir'))->toBeNull();

        // firstAsset returns null when theme has no assets (line 230)
        expect($cachedManager->getThemeCss())->toBeNull();

        // assetUrl unsafe path throws (line 212)
        expect(fn () => $cachedManager->assetUrl('../secret.css'))->toThrow(InvalidTheme::class, 'unsafe');

        // hasCustomLayout false branch (line 278)
        expect($cachedManager->hasCustomLayout('nonexistent-layout-xyz'))->toBeFalse();

        // clearCache (lines 264-267)
        $cachedManager->clearCache();
        expect(File::isFile($cachePath))->toBeFalse();

        // Inheritance cycle throws (line 166)
        $cycleCache = $tmp.'/cycle.cache';
        $cycA = makeTempThemeDir($tmp.'/cyc', 'a', ['parent' => 'b'], null, false);
        $cycB = makeTempThemeDir($tmp.'/cyc', 'b', ['parent' => 'a'], null, false);
        (new ThemeCache())->write([
            'a' => ThemeManifest::fromFile($cycA.'/theme.json'),
            'b' => ThemeManifest::fromFile($cycB.'/theme.json'),
        ], $cycleCache);
        config(['theme.cache' => true, 'theme.cache_path' => $cycleCache, 'theme.fallback' => 'a']);
        expect(fn () => new ThemeManager($tmp.'/cyc'))->toThrow(InvalidTheme::class, 'cycle');

        // registerThemePaths early return when View finder is not FileViewFinder (line 180)
        config(['theme.cache' => false, 'theme.fallback' => 'default']);
        $realManager = new ThemeManager(base_path('themes'));
        $origFinder = View::getFinder();
        $customFinder = Mockery::mock(ViewFinderInterface::class);
        View::setFinder($customFinder);
        try {
            $realManager->registerThemePaths();
            expect(true)->toBeTrue();
        } finally {
            View::setFinder($origFinder);
        }

        // viteHasAsset when public/build/manifest.json is absent (line 244)
        $manifestPath = public_path('build/manifest.json');
        $hadManifest = File::isFile($manifestPath);
        $manifestBackup = $hadManifest ? File::get($manifestPath) : null;
        if ($hadManifest) {
            File::delete($manifestPath);
        }
        try {
            expect($realManager->viteHasAsset('resources/css/app.css'))->toBeFalse();
        } finally {
            if ($hadManifest && $manifestBackup !== null) {
                File::ensureDirectoryExists(dirname($manifestPath));
                File::put($manifestPath, $manifestBackup);
            }
        }

        // theme_helpers.php guards and theme_layout()
        require base_path('modules/theme-support/src/Support/theme_helpers.php');
        expect(theme_layout('app'))->toBe('layouts.app');
    } finally {
        config([
            'theme.cache' => $origCache,
            'theme.cache_path' => $origCachePath,
            'theme.fallback' => $origFallback,
            'theme.default' => $origDefault,
        ]);
        File::deleteDirectory($tmp);
    }
});
