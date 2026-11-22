<?php

use Composer\InstalledVersions;
use Filament\Facades\Filament;
use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\ModuleManager\Cache\RegistryCache;
use Liberu\Foundation\ModuleManager\Exceptions\DependencyResolutionFailed;
use Liberu\Foundation\ModuleManager\Exceptions\InvalidManifest;
use Liberu\Foundation\ModuleManager\Exceptions\ModuleActivationRefused;
use Liberu\Foundation\ModuleManager\Exceptions\RequiredModuleFailed;
use Liberu\Foundation\ModuleManager\Manifest;
use Liberu\Foundation\ModuleManager\ModuleActivations;
use Liberu\Foundation\ModuleManager\ModuleDiscovery;
use Liberu\Foundation\ModuleManager\ModuleInventory;
use Liberu\Foundation\ModuleManager\ModuleLoader;
use Liberu\Foundation\ModuleManager\ModuleManagement;
use Liberu\Foundation\ModuleManager\ModuleManagerServiceProvider;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use Liberu\Foundation\ModuleManager\ModuleValidationGuard;
use Liberu\Foundation\ModuleManager\ModuleValidator;
use Liberu\Foundation\ModuleManagerFilament\Pages\ModuleManagement as ModuleManagementPage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function makeTempModule(
    string $root,
    string $dirName,
    array $manifestOverrides = [],
    ?array $composerData = null,
    bool $writeComposer = true,
): string {
    $dir = $root.'/'.$dirName;
    File::ensureDirectoryExists($dir);

    $name = $manifestOverrides['name'] ?? $dirName;
    $manifest = array_merge([
        'name' => $name,
        'display_name' => ucfirst($name),
        'description' => 'Test module '.$name,
        'version' => '1.0.0',
        'category' => 'capability',
        'module_type' => 'feature',
        'provider' => ModuleManagerServiceProvider::class,
        'requires' => [],
        'capabilities' => [],
        'features' => ['Feature '.$name],
        'default_enabled' => true,
    ], $manifestOverrides);

    File::put($dir.'/module.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    if ($writeComposer) {
        $composer = $composerData ?? [
            'name' => 'liberusoftware/test-'.$name,
            'type' => 'liberu-module',
            'extra' => ['liberu' => ['name' => $name]],
        ];
        File::put($dir.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    }

    return $dir;
}

class DummyFailingModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        throw new RuntimeException('Provider exploded');
    }
}

class DummyOkModuleProvider extends ServiceProvider
{
    public function register(): void {}
}

it('covers Manifest validation branches and getters', function (): void {
    $tmp = sys_get_temp_dir().'/mm-manifest-'.uniqid();
    File::ensureDirectoryExists($tmp);

    try {
        // Missing required key
        $p = $tmp.'/missing.json';
        File::put($p, json_encode(['name' => 'x'], JSON_THROW_ON_ERROR));
        expect(fn () => Manifest::fromFile($p))->toThrow(InvalidManifest::class, 'missing required key');

        // Invalid name
        $d = makeTempModule($tmp, 'bad-name', ['name' => 'Bad_Name']);
        expect(fn () => Manifest::fromFile($d.'/module.json'))->toThrow(InvalidManifest::class, 'invalid module name');

        // Invalid requires/capabilities/features
        $d = makeTempModule($tmp, 'bad-req', ['requires' => 'not-array']);
        expect(fn () => Manifest::fromFile($d.'/module.json'))->toThrow(InvalidManifest::class, 'invalid requires');

        // Invalid category
        $d = makeTempModule($tmp, 'bad-cat', ['category' => 'bogus']);
        expect(fn () => Manifest::fromFile($d.'/module.json'))->toThrow(InvalidManifest::class, 'invalid category');

        // Invalid module_type
        $d = makeTempModule($tmp, 'bad-type', ['module_type' => 'bogus']);
        expect(fn () => Manifest::fromFile($d.'/module.json'))->toThrow(InvalidManifest::class, 'module_type must be');

        // Invalid default_enabled
        $d = makeTempModule($tmp, 'bad-def', ['default_enabled' => 'yes']);
        expect(fn () => Manifest::fromFile($d.'/module.json'))->toThrow(InvalidManifest::class, 'default_enabled must be boolean');

        // Invalid capability
        $d = makeTempModule($tmp, 'bad-cap', ['capabilities' => ['BAD CAP']]);
        expect(fn () => Manifest::fromFile($d.'/module.json'))->toThrow(InvalidManifest::class, 'invalid capability');

        // Empty features
        $d = makeTempModule($tmp, 'empty-feat', ['features' => []]);
        expect(fn () => Manifest::fromFile($d.'/module.json'))->toThrow(InvalidManifest::class, 'at least one feature');

        // Invalid feature (leading space)
        $d = makeTempModule($tmp, 'bad-feat', ['features' => [' leading']]);
        expect(fn () => Manifest::fromFile($d.'/module.json'))->toThrow(InvalidManifest::class, 'invalid feature');

        // Duplicate features (case-insensitive)
        $d = makeTempModule($tmp, 'dup-feat', ['features' => ['Alpha', 'alpha']]);
        expect(fn () => Manifest::fromFile($d.'/module.json'))->toThrow(InvalidManifest::class, 'duplicate features');

        // Valid manifest getters
        $d = makeTempModule($tmp, 'good-mod', ['display_name' => 'Good Mod', 'description' => 'Desc']);
        $m = Manifest::fromFile($d.'/module.json');
        expect($m->displayName())->toBe('Good Mod')
            ->and($m->description())->toBe('Desc')
            ->and($m->toArray())->toHaveKey('path', $d);
    } finally {
        File::deleteDirectory($tmp);
    }
});

it('covers RegistryCache load, invalid cache, directory creation, write failure, clear failure', function (): void {
    $tmp = sys_get_temp_dir().'/mm-cache-'.uniqid();
    File::ensureDirectoryExists($tmp);

    try {
        $cachePath = $tmp.'/nested/dir/modules.cache';
        $cache = new RegistryCache();
        $registry = new ModuleRegistry([]);

        // Creates missing parent directory and writes (line 38)
        $cache->write($registry, $cachePath);
        expect(is_file($cachePath))->toBeTrue();

        // Loads valid cache (lines 23, 28)
        $loaded = $cache->load([], true, $cachePath);
        expect($loaded)->toBeInstanceOf(ModuleRegistry::class);

        // Invalid cache throws (lines 24-25)
        File::put($cachePath, serialize(['not' => 'a-registry']));
        expect(fn () => $cache->load([], true, $cachePath))
            ->toThrow(RuntimeException::class, 'cache is invalid');

        // Write failure throws (line 42)
        $failWriteFs = Mockery::mock(Filesystem::class);
        $failWriteFs->shouldReceive('isDirectory')->andReturn(true);
        $failWriteFs->shouldReceive('put')->andReturn(false);
        expect(fn () => (new RegistryCache($failWriteFs))->write($registry, $cachePath))
            ->toThrow(RuntimeException::class, 'Unable to write');

        // Clear failure throws (line 49)
        $failClearFs = Mockery::mock(Filesystem::class);
        $failClearFs->shouldReceive('isFile')->with($cachePath)->andReturn(true);
        $failClearFs->shouldReceive('delete')->with($cachePath)->andReturn(false);
        expect(fn () => (new RegistryCache($failClearFs))->clear($cachePath))
            ->toThrow(RuntimeException::class, 'Unable to clear');
    } finally {
        File::deleteDirectory($tmp);
    }
});

it('covers ModuleRegistry serialization, search, capability resolution, errors, and cycles', function (): void {
    $tmp = sys_get_temp_dir().'/mm-registry-'.uniqid();
    File::ensureDirectoryExists($tmp);

    try {
        // __unserialize valid and invalid (lines 24-28)
        $reg = new ModuleRegistry([]);
        $roundTrip = unserialize(serialize($reg), ['allowed_classes' => [ModuleRegistry::class, Manifest::class]]);
        expect($roundTrip)->toBeInstanceOf(ModuleRegistry::class);

        $badReg = new ModuleRegistry([]);
        expect(fn () => $badReg->__unserialize(['modules' => ['bad' => 'not-manifest']]))
            ->toThrow(RuntimeException::class, 'cache is invalid');

        // Build test manifests
        $dirA = makeTempModule($tmp, 'mod-a', [
            'version' => '1.0.0',
            'capabilities' => ['cap.alpha'],
            'features' => ['Alpha Search', 'Common'],
        ]);
        $dirB = makeTempModule($tmp, 'mod-b', [
            'version' => '1.0.0',
            'requires' => ['capabilities' => ['cap.alpha' => '^1.0']],
            'features' => ['Beta Feature'],
        ]);
        $mA = Manifest::fromFile($dirA.'/module.json');
        $mB = Manifest::fromFile($dirB.'/module.json');

        $registry = new ModuleRegistry(['mod-a' => $mA, 'mod-b' => $mB]);

        // enabled() true and false (lines 43-49)
        expect($registry->enabled('mod-a'))->toBeTrue()
            ->and($registry->enabled('mod-b'))->toBeTrue()
            ->and($registry->enabled('missing'))->toBeFalse();

        // searchFeatures() and providingFeature() (lines 61-87)
        expect($registry->searchFeatures('alpha'))->toBe(['mod-a' => ['Alpha Search']])
            ->and($registry->providingFeature('beta feature'))->toHaveCount(1);

        // Missing library dependency (line 123)
        $dirMissingLib = makeTempModule($tmp, 'mod-missing-lib', [
            'requires' => ['packages' => ['nonexistent/pkg-xyz' => '^1.0']],
        ]);
        $mMissingLib = Manifest::fromFile($dirMissingLib.'/module.json');
        expect(fn () => (new ModuleRegistry(['mod-missing-lib' => $mMissingLib]))->resolve([]))
            ->toThrow(DependencyResolutionFailed::class, 'missing or incompatible library');

        // Incompatible module version (line 130)
        $dirBadVer = makeTempModule($tmp, 'mod-bad-ver', [
            'requires' => ['packages' => ['liberusoftware/test-mod-a' => '^9.0']],
        ]);
        $mBadVer = Manifest::fromFile($dirBadVer.'/module.json');
        expect(fn () => (new ModuleRegistry(['mod-a' => $mA, 'mod-bad-ver' => $mBadVer]))->resolve([]))
            ->toThrow(DependencyResolutionFailed::class, 'installed manifest is');

        // Missing capability (line 138)
        $dirMissingCap = makeTempModule($tmp, 'mod-missing-cap', [
            'requires' => ['capabilities' => ['cap.nope' => '^1.0']],
        ]);
        $mMissingCap = Manifest::fromFile($dirMissingCap.'/module.json');
        expect(fn () => (new ModuleRegistry(['mod-missing-cap' => $mMissingCap]))->resolve([]))
            ->toThrow(DependencyResolutionFailed::class, 'missing capability');

        // Disabled capability owner (line 142)
        expect(fn () => $registry->resolve([], ['mod-a']))
            ->toThrow(DependencyResolutionFailed::class, 'requires enabled capability');

        // Circular dependency (line 154)
        $dirCyc1 = makeTempModule($tmp, 'cyc-one', [
            'requires' => ['packages' => ['liberusoftware/test-cyc-two' => '^1.0']],
        ]);
        $dirCyc2 = makeTempModule($tmp, 'cyc-two', [
            'requires' => ['packages' => ['liberusoftware/test-cyc-one' => '^1.0']],
        ]);
        $cycReg = new ModuleRegistry([
            'cyc-one' => Manifest::fromFile($dirCyc1.'/module.json'),
            'cyc-two' => Manifest::fromFile($dirCyc2.'/module.json'),
        ]);
        expect(fn () => $cycReg->resolve([]))
            ->toThrow(DependencyResolutionFailed::class, 'Circular module dependency');
    } finally {
        File::deleteDirectory($tmp);
    }
});

it('covers ModuleDiscovery error branches and legacy rename handling', function (): void {
    $tmp = sys_get_temp_dir().'/mm-disc-'.uniqid();
    File::ensureDirectoryExists($tmp);

    try {
        $discovery = new ModuleDiscovery();

        // Missing composer.json (lines 68, 88, 135-137)
        $noComp = $tmp.'/no-comp';
        makeTempModule($noComp, 'no-comp-mod', [], null, false);
        expect(fn () => $discovery->discover([$noComp]))
            ->toThrow(InvalidManifest::class, 'has no composer.json');

        // Tolerant mode collects problems instead of throwing (line 140)
        [, $problems] = $discovery->discoverTolerant([$noComp]);
        expect($problems)->toHaveKey('no-comp-mod');

        // Missing composer name (line 93)
        $noName = $tmp.'/no-name';
        makeTempModule($noName, 'no-name-mod', [], ['type' => 'liberu-module']);
        expect(fn () => $discovery->discover([$noName]))
            ->toThrow(InvalidManifest::class, 'missing or duplicate Composer package name');

        // Legacy module-liberu-* skipped when canonical liberu-* sibling exists (lines 102-104)
        $legacy = $tmp.'/legacy';
        makeTempModule($legacy, 'module-liberu-demo', ['name' => 'demo-legacy'], ['name' => 'liberusoftware/module-liberu-demo', 'type' => 'liberu-module']);
        makeTempModule($legacy, 'liberu-demo', ['name' => 'demo-canon'], ['name' => 'liberusoftware/liberu-demo', 'type' => 'liberu-module']);
        $reg = $discovery->discover([$legacy]);
        expect($reg->has('demo-canon'))->toBeTrue()
            ->and($reg->has('demo-legacy'))->toBeFalse();

        // Identical duplicate package skipped (lines 114-117)
        $ident1 = $tmp.'/ident1';
        $ident2 = $tmp.'/ident2';
        makeTempModule($ident1, 'same-mod', ['name' => 'same-mod', 'version' => '1.0.0'], ['name' => 'liberusoftware/same-pkg', 'type' => 'liberu-module']);
        makeTempModule($ident2, 'same-mod', ['name' => 'same-mod', 'version' => '1.0.0'], ['name' => 'liberusoftware/same-pkg', 'type' => 'liberu-module']);
        $regIdent = $discovery->discover([$ident1, $ident2]);
        expect($regIdent->has('same-mod'))->toBeTrue();

        // Conflicting duplicate package name throws (line 120)
        $dupPkg = $tmp.'/dup-pkg';
        makeTempModule($dupPkg, 'pkg-a', ['name' => 'pkg-a', 'version' => '1.0.0'], ['name' => 'liberusoftware/shared-pkg', 'type' => 'liberu-module']);
        makeTempModule($dupPkg, 'pkg-b', ['name' => 'pkg-b', 'version' => '2.0.0'], ['name' => 'liberusoftware/shared-pkg', 'type' => 'liberu-module']);
        expect(fn () => $discovery->discover([$dupPkg]))
            ->toThrow(InvalidManifest::class, 'missing or duplicate Composer package name');

        // Duplicate module name with different composer package throws (line 123)
        $dupMod = $tmp.'/dup-mod';
        makeTempModule($dupMod, 'dir-one', ['name' => 'same-name'], ['name' => 'liberusoftware/pkg-one', 'type' => 'liberu-module']);
        makeTempModule($dupMod, 'dir-two', ['name' => 'same-name'], ['name' => 'liberusoftware/pkg-two', 'type' => 'liberu-module']);
        expect(fn () => $discovery->discover([$dupMod]))
            ->toThrow(InvalidManifest::class, 'Duplicate module');

        // Duplicate capability throws (line 127)
        $dupCap = $tmp.'/dup-cap';
        makeTempModule($dupCap, 'cap-one', ['name' => 'cap-one', 'capabilities' => ['dup.cap']], ['name' => 'liberusoftware/cap-one', 'type' => 'liberu-module']);
        makeTempModule($dupCap, 'cap-two', ['name' => 'cap-two', 'capabilities' => ['dup.cap']], ['name' => 'liberusoftware/cap-two', 'type' => 'liberu-module']);
        expect(fn () => $discovery->discover([$dupCap]))
            ->toThrow(InvalidManifest::class, 'Duplicate capability');
    } finally {
        File::deleteDirectory($tmp);
    }
});

it('covers ModuleValidator, ModuleValidationGuard, ModuleInventory, and RequiredModuleFailed', function (): void {
    $tmp = sys_get_temp_dir().'/mm-val-'.uniqid();
    File::ensureDirectoryExists($tmp);

    try {
        // Pick a real installed liberu package to test version mismatch & compatiblePatchVersion (lines 30, 37, 112-114)
        $installedLib = 'liberusoftware/module-manager';
        if (! InstalledVersions::isInstalled($installedLib)) {
            $installedLib = InstalledVersions::getInstalledPackagesByType('liberu-module')[0];
        }

        // Wrong composer type, mismatched version (major mismatch -> line 37 & 112-114),
        // mismatched liberu deps (line 48), non-autoloadable provider (line 52),
        // bad PHP constraint (line 58), bad Laravel constraint (line 62),
        // filament plugin in non-presentation module (line 68) + non-autoloadable plugin (line 71)
        $dirBad1 = makeTempModule($tmp, 'bad-one', [
            'name' => 'bad-one',
            'version' => '99.0.0',
            'category' => 'capability',
            'provider' => 'NonExistent\\Provider\\ClassX',
            'requires' => [
                'php' => '^5.0',
                'laravel' => '^4.0',
                'packages' => ['liberusoftware/missing-dep' => '^1.0'],
            ],
            'presentation' => ['filament' => ['admin' => ['NonExistent\\Filament\\PluginX']]],
        ], [
            'name' => $installedLib,
            'type' => 'library',
            'extra' => ['liberu' => ['name' => 'wrong-name']],
            'require' => [],
        ]);

        // Provider that exists but does not extend ServiceProvider (line 54) + missing composer name (line 30)
        $dirBad2 = makeTempModule($tmp, 'bad-two', [
            'name' => 'bad-two',
            'version' => '1.0.0',
            'provider' => stdClass::class,
        ], [
            'type' => 'liberu-module',
            'extra' => ['liberu' => ['name' => 'bad-two']],
        ]);

        $registry = new ModuleRegistry([
            'bad-one' => Manifest::fromFile($dirBad1.'/module.json'),
            'bad-two' => Manifest::fromFile($dirBad2.'/module.json'),
        ]);

        $validator = new ModuleValidator();
        $errors = $validator->validate($registry, Application::VERSION);

        expect($errors)->not->toBeEmpty();
        expect(implode("\n", $errors))
            ->toContain('Composer type must be liberu-module')
            ->toContain('installed Composer version does not match')
            ->toContain('Composer and manifest Liberu dependencies differ')
            ->toContain('is not autoloadable')
            ->toContain("must extend Laravel's ServiceProvider")
            ->toContain('PHP ')
            ->toContain('Laravel ')
            ->toContain('only presentation modules may declare Filament plugins')
            ->toContain('presentation plugin');

        // ModuleValidationGuard throws on errors (line 13)
        expect(fn () => (new ModuleValidationGuard($validator))->ensureValid($registry, Application::VERSION))
            ->toThrow(RuntimeException::class, 'Module validation failed');

        // ModuleInventory markFailed & rows with unreadable problem-only module (lines 46-47, 71)
        $m = Manifest::fromFile($dirBad2.'/module.json');
        $inv = new ModuleInventory(['bad-two' => $m], ['bad-two' => $m], ['broken-dir' => ['Invalid JSON']]);
        $inv->markFailed('bad-two', 'Boom');
        expect($inv->isBooted('bad-two'))->toBeFalse();
        $rows = $inv->rows();
        expect($rows)->toHaveKey('broken-dir')
            ->and($rows['broken-dir']['status'])->toBe('invalid');

        // RequiredModuleFailed::because (line 12)
        $ex = RequiredModuleFailed::because('core', 'missing');
        expect($ex->getMessage())->toContain('Required module [core] cannot load: missing');
    } finally {
        File::deleteDirectory($tmp);
    }
});

it('covers ModuleLoader load, cache, audit, exclude, required failure, and unknown owner', function (): void {
    $tmp = sys_get_temp_dir().'/mm-loader-'.uniqid();
    File::ensureDirectoryExists($tmp);

    try {
        // Directory with a broken optional module (non-autoloadable provider -> fails validation & gets excluded)
        $dirOpt = makeTempModule($tmp, 'opt-broken', [
            'name' => 'opt-broken',
            'provider' => 'NonExistent\\OptProvider',
        ], [
            'name' => 'liberusoftware/not-installed-opt',
            'type' => 'liberu-module',
            'extra' => ['liberu' => ['name' => 'opt-broken']],
        ]);

        // Directory with a module that has a missing dependency (triggers DependencyResolutionFailed -> exclude)
        $dirDep = makeTempModule($tmp, 'opt-dep', [
            'name' => 'opt-dep',
            'requires' => ['capabilities' => ['cap.does-not-exist' => '^1.0']],
        ], [
            'name' => 'liberusoftware/not-installed-dep',
            'type' => 'liberu-module',
            'extra' => ['liberu' => ['name' => 'opt-dep']],
        ]);

        $loader = new ModuleLoader(
            new ModuleValidator(),
            new RegistryCache(),
            [$tmp],
            [],
            [],
            [],
        );

        // load() excludes both failing optional modules (lines 38, 40, 65-108, 126-127, 137-139)
        $inv = $loader->load();
        expect($inv->isBooted('opt-broken'))->toBeFalse()
            ->and($inv->isBooted('opt-dep'))->toBeFalse()
            ->and($inv->problems)->toHaveKeys(['opt-broken', 'opt-dep']);

        // audit() reports problems (line 53)
        $audit = $loader->audit();
        expect($audit)->toHaveKey('opt-broken');

        // Missing required module throws RequiredModuleFailed (lines 66-67)
        $missingReqLoader = new ModuleLoader(new ModuleValidator(), new RegistryCache(), [$tmp], [], [], ['not-installed-req']);
        expect(fn () => $missingReqLoader->load())->toThrow(RequiredModuleFailed::class, 'not-installed-req');

        // Failing required module throws RequiredModuleFailed in exclude() (lines 117-118)
        $failReqLoader = new ModuleLoader(new ModuleValidator(), new RegistryCache(), [$tmp], [], [], ['opt-dep']);
        expect(fn () => $failReqLoader->load())->toThrow(RequiredModuleFailed::class, 'opt-dep');

        // Cached load path (lines 34-35)
        $cachePath = $tmp.'/loader.cache';
        (new RegistryCache())->write(new ModuleRegistry([]), $cachePath);
        $cachedLoader = new ModuleLoader(new ModuleValidator(), new RegistryCache(), [$tmp], [], [], [], true, $cachePath);
        expect($cachedLoader->load()->booted)->toBe([]);

        // owner() returning 'unknown' and exclude() throwing RuntimeException (lines 122-123, 143)
        $refOwner = new ReflectionMethod(ModuleLoader::class, 'owner');
        expect($refOwner->invoke($loader, 'some error with no module name', []))->toBe('unknown');

        $refExclude = new ReflectionMethod(ModuleLoader::class, 'exclude');
        $excl = [];
        $probs = [];
        expect(fn () => $refExclude->invokeArgs($loader, ['unknown', 'unowned problem', &$excl, &$probs]))
            ->toThrow(RuntimeException::class, 'Module selection could not settle');
    } finally {
        File::deleteDirectory($tmp);
    }
});

it('covers ModuleActivations::set, ModuleManagement, and ModuleManagerServiceProvider isolating mode', function (): void {
    $hadTable = Schema::hasTable('module_activations');
    if (! $hadTable) {
        Schema::create('module_activations', function (Blueprint $table): void {
            $table->string('module')->primary();
            $table->boolean('enabled');
            $table->timestamps();
        });
    }

    $tmp = sys_get_temp_dir().'/mm-mgmt-'.uniqid();
    File::ensureDirectoryExists($tmp);

    try {
        $activations = new ModuleActivations(app('db'));
        $activations->set('test-mod', true);
        expect($activations->all()['test-mod'] ?? null)->toBeTrue();

        // Find a real installed liberu-module package so InstalledVersions::isInstalled returns true
        $installedPackages = InstalledVersions::getInstalledPackagesByType('liberu-module');
        $realPkg = $installedPackages[0];
        $realVer = ltrim((string) InstalledVersions::getPrettyVersion($realPkg), 'v');

        // Create a valid feature module backed by that installed package
        $featDir = makeTempModule($tmp, 'feat-ok', [
            'name' => 'feat-ok',
            'version' => $realVer,
            'module_type' => 'feature',
            'provider' => DummyOkModuleProvider::class,
        ], [
            'name' => $realPkg,
            'type' => 'liberu-module',
            'extra' => ['liberu' => ['name' => 'feat-ok']],
        ]);

        // Create an infrastructure module backed by that installed package
        $infraDir = makeTempModule($tmp, 'infra-mod', [
            'name' => 'infra-mod',
            'version' => $realVer,
            'module_type' => 'infrastructure',
            'provider' => DummyOkModuleProvider::class,
        ], [
            'name' => $realPkg,
            'type' => 'liberu-module',
            'extra' => ['liberu' => ['name' => 'infra-mod']],
        ]);

        // Create a feature module that fails dependency resolution when enabled
        $depFailDir = makeTempModule($tmp, 'feat-dep-fail', [
            'name' => 'feat-dep-fail',
            'version' => $realVer,
            'module_type' => 'feature',
            'default_enabled' => false,
            'requires' => ['capabilities' => ['cap.missing' => '^1.0']],
            'provider' => DummyOkModuleProvider::class,
        ], [
            'name' => $realPkg,
            'type' => 'liberu-module',
            'extra' => ['liberu' => ['name' => 'feat-dep-fail']],
        ]);

        // Create a feature module that fails validation when enabled (bad PHP constraint)
        $valFailDir = makeTempModule($tmp, 'feat-val-fail', [
            'name' => 'feat-val-fail',
            'version' => $realVer,
            'module_type' => 'feature',
            'default_enabled' => false,
            'requires' => ['php' => '^5.0'],
            'provider' => DummyOkModuleProvider::class,
        ], [
            'name' => $realPkg,
            'type' => 'liberu-module',
            'extra' => ['liberu' => ['name' => 'feat-val-fail']],
        ]);

        $manifests = [
            'feat-ok' => Manifest::fromFile($featDir.'/module.json'),
            'infra-mod' => Manifest::fromFile($infraDir.'/module.json'),
            'feat-dep-fail' => Manifest::fromFile($depFailDir.'/module.json'),
            'feat-val-fail' => Manifest::fromFile($valFailDir.'/module.json'),
        ];

        $packagesMock = Mockery::mock(PackageManifest::class);
        $packagesMock->shouldReceive('providers')->andReturn([]);

        $inventory = new ModuleInventory($manifests, ['feat-ok' => $manifests['feat-ok']], [], []);
        $mgmt = new ModuleManagement($inventory, $activations, new ModuleValidator(), $packagesMock, [], []);

        // modules() returns feature modules only (lines 40-51, 116-118, 125-128)
        $listed = $mgmt->modules();
        expect($listed)->toHaveKeys(['feat-ok', 'feat-dep-fail', 'feat-val-fail'])
            ->not->toHaveKey('infra-mod');

        // enable() and disable() succeed for feat-ok (lines 61, 66, 71-86, 92-105)
        $mgmt->disable('feat-ok');
        $mgmt->enable('feat-ok');

        // Refused: not installed
        expect(fn () => $mgmt->enable('missing-mod'))
            ->toThrow(ModuleActivationRefused::class, 'not installed');

        // Refused: infrastructure
        expect(fn () => $mgmt->enable('infra-mod'))
            ->toThrow(ModuleActivationRefused::class, 'infrastructure');

        // Refused: dependency resolution failed (lines 101-102)
        expect(fn () => $mgmt->enable('feat-dep-fail'))
            ->toThrow(ModuleActivationRefused::class, 'missing capability');

        // Refused: validation failed (lines 107-108)
        expect(fn () => $mgmt->enable('feat-val-fail'))
            ->toThrow(ModuleActivationRefused::class, 'PHP');

        // Refused: locked (required, disabled, auto-discovered) (lines 81-82, 125-127)
        $lockedReq = new ModuleManagement(new ModuleInventory($manifests, [], [], ['feat-ok']), $activations, new ModuleValidator(), $packagesMock, [], []);
        expect(fn () => $lockedReq->disable('feat-ok'))->toThrow(ModuleActivationRefused::class, 'requires this module');

        $lockedDis = new ModuleManagement($inventory, $activations, new ModuleValidator(), $packagesMock, [], ['feat-ok']);
        expect(fn () => $lockedDis->enable('feat-ok'))->toThrow(ModuleActivationRefused::class, 'MODULES_DISABLED');

        $autoDiscPkgs = Mockery::mock(PackageManifest::class);
        $autoDiscPkgs->shouldReceive('providers')->andReturn([DummyOkModuleProvider::class]);
        $lockedAuto = new ModuleManagement($inventory, $activations, new ModuleValidator(), $autoDiscPkgs, [], []);
        expect(fn () => $lockedAuto->disable('feat-ok'))->toThrow(ModuleActivationRefused::class, 'auto-discovers');

        // ModuleManagerServiceProvider::registerIsolating (lines 50, 52, 83-108)
        $origApp = Container::getInstance();
        $origConfig = $origApp->make('config');
        $origDb = $origApp->make('db');

        try {
            $miniApp = new Application(base_path());
            Container::setInstance($origApp);
            $miniApp->instance('config', clone $origConfig);
            $miniApp['config']->set('modules.isolate_failures', true);
            $miniApp['config']->set('modules.paths', [$tmp]);
            $miniApp['config']->set('modules.enabled', []);
            $miniApp['config']->set('modules.disabled', []);
            $miniApp['config']->set('modules.required', []);
            $miniApp['config']->set('modules.cache', false);
            $miniApp->instance('db', $origDb);

            $failOptManifest = Manifest::fromFile(makeTempModule($tmp, 'opt-boom', [
                'name' => 'opt-boom',
                'provider' => DummyFailingModuleProvider::class,
            ]).'/module.json');
            $mmManifest = Manifest::fromFile(makeTempModule($tmp, 'module-manager', [
                'name' => 'module-manager',
                'provider' => ModuleManagerServiceProvider::class,
            ]).'/module.json');

            $mockInv = new ModuleInventory(
                ['module-manager' => $mmManifest, 'opt-boom' => $failOptManifest],
                ['module-manager' => $mmManifest, 'opt-boom' => $failOptManifest],
                [],
                [],
            );
            $mockLoader = new class($mockInv)
            {
                public function __construct(private ModuleInventory $inv) {}

                public function load(): ModuleInventory
                {
                    return $this->inv;
                }
            };

            // Call registerIsolating via provider with isolate_failures=true
            $provider = new ModuleManagerServiceProvider($miniApp);
            $miniApp->singleton(ModuleLoader::class, fn () => $mockLoader);
            $refIso = new ReflectionMethod(ModuleManagerServiceProvider::class, 'registerIsolating');
            $refIso->invoke($provider);

            expect($mockInv->isBooted('opt-boom'))->toBeFalse()
                ->and($mockInv->problems)->toHaveKey('opt-boom');

            Log::shouldReceive('warning')->atLeast()->once();
            $miniApp->boot();

            // Required module provider failure throws RequiredModuleFailed (lines 96-97)
            $miniAppReq = new Application(base_path());
            Container::setInstance($origApp);
            $miniAppReq->instance('config', clone $origConfig);
            $miniAppReq->instance('db', $origDb);
            $mockInvReq = new ModuleInventory(
                ['opt-boom' => $failOptManifest],
                ['opt-boom' => $failOptManifest],
                [],
                ['opt-boom'],
            );
            $miniAppReq->singleton(ModuleLoader::class, fn () => new class($mockInvReq)
            {
                public function __construct(private ModuleInventory $inv) {}

                public function load(): ModuleInventory
                {
                    return $this->inv;
                }
            });
            $providerReq = new ModuleManagerServiceProvider($miniAppReq);
            expect(fn () => $refIso->invoke($providerReq))
                ->toThrow(RequiredModuleFailed::class, 'Provider exploded');

            // Also cover lines 50, 52 of register() when isolate_failures = true
            $origIsolateFlag = config('modules.isolate_failures');
            $origModPaths = config('modules.paths');
            $origModReq = config('modules.required');
            File::ensureDirectoryExists($tmp.'/empty-dir');
            $miniAppReg = new Application(base_path());
            Container::setInstance($origApp);
            $miniAppReg->instance('config', clone $origConfig);
            $miniAppReg->instance('db', $origDb);
            $miniAppReg->instance('files', new Filesystem());
            config([
                'modules.isolate_failures' => true,
                'modules.paths' => [$tmp.'/empty-dir'],
                'modules.required' => [],
            ]);
            try {
                $miniAppReg->singleton(ModuleLoader::class, fn () => new class()
                {
                    public function load(): ModuleInventory
                    {
                        return new ModuleInventory([], []);
                    }
                });
                $providerReg = new ModuleManagerServiceProvider($miniAppReg);
                $providerReg->register();
                expect($miniAppReg->bound(ModuleRegistry::class))->toBeTrue();
            } finally {
                config([
                    'modules.isolate_failures' => $origIsolateFlag,
                    'modules.paths' => $origModPaths,
                    'modules.required' => $origModReq,
                ]);
            }
        } finally {
            Container::setInstance($origApp);
        }
    } finally {
        if (! $hadTable) {
            Schema::dropIfExists('module_activations');
        }
        File::deleteDirectory($tmp);
    }
});

it('covers module artisan commands (list --json, features, cache isolate_failures, validate errors/strict)', function (): void {
    // module:list --json (lines 17, 19)
    expect(Artisan::call('module:list', ['--json' => true]))->toBe(0);

    // module:features (lines 16-25)
    expect(Artisan::call('module:features'))->toBe(0);
    expect(Artisan::call('module:features', ['query' => 'module']))->toBe(0);

    // module:cache with isolate_failures = true and an audit failure (lines 20-23)
    $tmp = sys_get_temp_dir().'/mm-cmd-'.uniqid();
    File::ensureDirectoryExists($tmp);
    $origIsolate = config('modules.isolate_failures');
    $origLoader = app(ModuleLoader::class);
    $origInventory = app(ModuleInventory::class);
    $origPackages = app(PackageManifest::class);

    try {
        makeTempModule($tmp, 'broken-cmd-mod', [
            'name' => 'broken-cmd-mod',
            'provider' => 'NonExistent\\CmdProvider',
        ], [
            'name' => 'liberusoftware/not-installed-cmd',
            'type' => 'liberu-module',
            'extra' => ['liberu' => ['name' => 'broken-cmd-mod']],
        ]);

        $failingLoader = new ModuleLoader(new ModuleValidator(), new RegistryCache(), [$tmp], [], [], []);
        app()->instance(ModuleLoader::class, $failingLoader);
        config(['modules.isolate_failures' => true]);

        expect(Artisan::call('module:cache'))->toBe(1);

        // module:validate with audit errors, refusedDisables, and --strict auto-discovered provider
        $m = Manifest::fromFile($tmp.'/broken-cmd-mod/module.json');
        app()->instance(ModuleInventory::class, new ModuleInventory(
            ['broken-cmd-mod' => $m],
            [],
            [],
            ['broken-cmd-mod'],
            ['broken-cmd-mod'],
        ));
        $pkgMock = Mockery::mock(PackageManifest::class);
        $pkgMock->shouldReceive('providers')->andReturn(['NonExistent\\CmdProvider']);
        app()->instance(PackageManifest::class, $pkgMock);

        expect(Artisan::call('module:validate', ['--strict' => true]))->toBe(1);
    } finally {
        config(['modules.isolate_failures' => $origIsolate]);
        app()->instance(ModuleLoader::class, $origLoader);
        app()->instance(ModuleInventory::class, $origInventory);
        app()->instance(PackageManifest::class, $origPackages);
        File::deleteDirectory($tmp);
    }
});

it('covers ModuleManagement Filament page rows, badges, and change action', function (): void {
    $hadTable = Schema::hasTable('module_activations');
    if (! $hadTable) {
        Schema::create('module_activations', function (Blueprint $table): void {
            $table->string('module')->primary();
            $table->boolean('enabled');
            $table->timestamps();
        });
    }

    $tmp = sys_get_temp_dir().'/mm-fil-'.uniqid();
    File::ensureDirectoryExists($tmp);

    try {
        $installedPackages = InstalledVersions::getInstalledPackagesByType('liberu-module');
        $realPkg = $installedPackages[0];
        $realVer = ltrim((string) InstalledVersions::getPrettyVersion($realPkg), 'v');

        $dEnabled = makeTempModule($tmp, 'fil-enabled', ['name' => 'fil-enabled', 'version' => $realVer, 'module_type' => 'feature', 'provider' => DummyOkModuleProvider::class], ['name' => $realPkg, 'type' => 'liberu-module', 'extra' => ['liberu' => ['name' => 'fil-enabled']]]);
        $dFailed = makeTempModule($tmp, 'fil-failed', ['name' => 'fil-failed', 'version' => $realVer, 'module_type' => 'feature', 'provider' => DummyOkModuleProvider::class], ['name' => $realPkg, 'type' => 'liberu-module', 'extra' => ['liberu' => ['name' => 'fil-failed']]]);
        $dDisabled = makeTempModule($tmp, 'fil-disabled', ['name' => 'fil-disabled', 'version' => $realVer, 'module_type' => 'feature', 'default_enabled' => false, 'provider' => DummyOkModuleProvider::class], ['name' => $realPkg, 'type' => 'liberu-module', 'extra' => ['liberu' => ['name' => 'fil-disabled']]]);

        $manifests = [
            'fil-enabled' => Manifest::fromFile($dEnabled.'/module.json'),
            'fil-failed' => Manifest::fromFile($dFailed.'/module.json'),
            'fil-disabled' => Manifest::fromFile($dDisabled.'/module.json'),
        ];

        $packagesMock = Mockery::mock(PackageManifest::class);
        $packagesMock->shouldReceive('providers')->andReturn([]);

        $inventory = new ModuleInventory($manifests, ['fil-enabled' => $manifests['fil-enabled']], ['fil-failed' => ['Failed to boot']], []);
        $activations = new ModuleActivations(app('db'));
        $mgmt = new ModuleManagement($inventory, $activations, new ModuleValidator(), $packagesMock, [], []);
        app()->instance(ModuleManagement::class, $mgmt);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Gate::define(ModuleManagementPage::ABILITY, fn (): bool => true);
        $user = new class(['name' => 'Admin', 'email' => 'admin@example.com']) extends User
        {
            protected $guarded = [];

            public function hasRole(mixed ...$args): bool
            {
                return false;
            }
        };
        auth()->setUser($user);

        // Render page via Livewire to hit rows(), status colors & icons (lines 68-75, 123-125)
        $component = Livewire::test(ModuleManagementPage::class);
        $component->assertOk();

        // Invoke change() for enable, disable, and refused exception (lines 105, 108-114)
        $page = $component->instance();
        $refChange = new ReflectionMethod(ModuleManagementPage::class, 'change');
        $refChange->invoke($page, 'fil-disabled', true);
        $refChange->invoke($page, 'fil-disabled', false);
        $refChange->invoke($page, 'nonexistent-mod', true);

        // Unauthorized change() aborts 403 (line 105)
        auth()->logout();
        expect(fn () => $refChange->invoke($page, 'fil-disabled', true))
            ->toThrow(HttpException::class);
    } finally {
        if (! $hadTable) {
            Schema::dropIfExists('module_activations');
        }
        File::deleteDirectory($tmp);
    }
});
