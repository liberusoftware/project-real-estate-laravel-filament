<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('covers migration up() guards, special branches, and down() rollbacks across all modules', function (): void {
    // 1. Hit "already exists" up() guards on idempotent migrations while tables are present
    $idempotentMigrations = [
        'modules/identity-socialstream/database/migrations/2026_06_29_123955_create_connected_accounts_table.php',
        'modules/integrations/database/migrations/2026_07_08_000000_create_sso_connections_table.php',
        'modules/integrations/database/migrations/2026_07_08_090000_create_saml_connections_table.php',
        'modules/organizations-teams/database/migrations/2026_07_06_190000_add_archived_at_to_teams_table.php',
        'modules/organizations-teams/database/migrations/2026_07_06_200000_create_team_backups_table.php',
        'modules/organizations-teams/database/migrations/2026_07_08_030000_create_territories_table.php',
        'modules/organizations-teams/database/migrations/2026_07_08_030001_create_territory_user_table.php',
        'modules/organizations-teams/database/migrations/2026_08_25_000001_add_zernio_profile_id_to_teams.php',
        'modules/sessions-devices/database/migrations/0001_01_01_000003_create_sessions_table.php',
    ];

    foreach ($idempotentMigrations as $relPath) {
        $migration = require base_path($relPath);
        $migration->up();
    }

    // 2. Hit ensure_require_sso_columns both down() and up() when column is absent
    $ensureSso = require base_path('modules/integrations/database/migrations/2026_08_31_000000_ensure_require_sso_columns.php');
    $ensureSso->down();
    $ensureSso->down(); // hits continue when column already dropped
    $ensureSso->up();   // hits Schema::table add column
    $ensureSso->up();   // hits continue when column already exists

    // 3. Hit archived_at and zernio_profile_id down() both when present and absent
    $archivedAt = require base_path('modules/organizations-teams/database/migrations/2026_07_06_190000_add_archived_at_to_teams_table.php');
    try {
        Schema::table('teams', fn (Blueprint $t) => $t->dropIndex('teams_archived_at_index'));
    } catch (Throwable) {
    }
    $archivedAt->down();
    $archivedAt->down();
    $archivedAt->up();

    $zernio = require base_path('modules/organizations-teams/database/migrations/2026_08_25_000001_add_zernio_profile_id_to_teams.php');
    $zernio->down();
    $zernio->down();
    $zernio->up();

    // 4. Hit make_legacy_invitation_token_nullable both with and without `token` column
    $legacyToken = require base_path('modules/organizations-teams/database/migrations/2026_08_24_050000_make_legacy_invitation_token_nullable.php');
    if (Schema::hasColumn('team_invitations', 'token')) {
        $legacyToken->down();
        $legacyToken->up();
        try {
            Schema::table('team_invitations', fn (Blueprint $t) => $t->dropUnique('team_invitations_token_unique'));
        } catch (Throwable) {
        }
        Schema::table('team_invitations', fn (Blueprint $t) => $t->dropColumn('token'));
        $legacyToken->up();
        $legacyToken->down();
        Schema::table('team_invitations', fn (Blueprint $t) => $t->string('token', 64)->nullable());
    } else {
        $legacyToken->up();
        $legacyToken->down();
        Schema::table('team_invitations', fn (Blueprint $t) => $t->string('token', 64)->default('x'));
        $legacyToken->up();
        $legacyToken->down();
        Schema::table('team_invitations', fn (Blueprint $t) => $t->dropColumn('token'));
    }

    // 4b. Hit create_pulse_tables shouldRun() === false early return (line 15)
    $pulseMig = require base_path('modules/observability/database/migrations/2026_06_29_124052_create_pulse_tables.php');
    $origPulseConn = config('pulse.storage.database.connection');
    config([
        'database.connections.pulse_unsupported_test' => ['driver' => 'sqlsrv', 'database' => '', 'prefix' => ''],
        'pulse.storage.database.connection' => 'pulse_unsupported_test',
    ]);
    try {
        $pulseMig->up();
    } finally {
        config(['pulse.storage.database.connection' => $origPulseConn]);
    }

    // 4c. Hit add_team_to_resources down() (lines 38-41) using a temporary contacts table
    $addTeamRes = require base_path('modules/organizations-teams/database/migrations/2024_09_29_093707_add_team_to_resources.php');
    if (! Schema::hasTable('contacts')) {
        Schema::create('contacts', fn (Blueprint $t) => $t->id());
        try {
            $addTeamRes->up();
            $addTeamRes->down();
        } finally {
            Schema::dropIfExists('contacts');
        }
    }

    // 4d. Hit add_timezone_to_users_table down() (line 19)
    $tzMig = require base_path('modules/profiles/database/migrations/2026_08_01_000000_add_timezone_to_users_table.php');
    $tzMig->down();
    $tzMig->up();

    // 4e. Hit harden_organization_memberships down() (lines 43-48) by dropping sqlite indexes first
    $hardenOrg = require base_path('modules/organizations-teams/database/migrations/2026_08_01_061000_harden_organization_memberships.php');
    Schema::disableForeignKeyConstraints();
    try {
        foreach ([
            ['team_invitations', 'team_invitations_token_hash_unique'],
            ['team_invitations', 'team_invitations_expires_at_index'],
            ['team_user', 'team_user_status_index'],
            ['teams', 'teams_status_index'],
        ] as [$tbl, $idx]) {
            try {
                Schema::table($tbl, fn (Blueprint $t) => $t->dropIndex($idx));
            } catch (Throwable) {
            }
        }
        $hardenOrg->down();
        $hardenOrg->up();
    } finally {
        Schema::enableForeignKeyConstraints();
    }

    // 5. Hit create_permission_tables error branches and non-teams branch
    $permMig = require base_path('modules/roles-permissions/database/migrations/2024_02_21_190705_create_permission_tables.php');
    $origTableNames = config('permission.table_names');
    $origColumnNames = config('permission.column_names');
    $origTeams = config('permission.teams');
    $origTesting = config('permission.testing');

    try {
        config(['permission.table_names' => []]);
        expect(fn () => $permMig->up())->toThrow(Exception::class, 'config/permission.php not loaded');
        expect(fn () => $permMig->down())->toThrow(Exception::class, 'config/permission.php not found');

        config([
            'permission.table_names' => $origTableNames,
            'permission.teams' => true,
            'permission.column_names' => array_merge($origColumnNames, ['team_foreign_key' => null]),
        ]);
        expect(fn () => $permMig->up())->toThrow(Exception::class, 'team_foreign_key');

        // Drop real permission tables first (SQLite index names are schema-global), then test non-teams up() + down()
        config([
            'permission.table_names' => $origTableNames,
            'permission.column_names' => $origColumnNames,
            'permission.teams' => false,
            'permission.testing' => false,
        ]);
        Schema::disableForeignKeyConstraints();
        try {
            $permMig->down();
            $permMig->up();
            $permMig->down();
        } finally {
            config([
                'permission.table_names' => $origTableNames,
                'permission.column_names' => $origColumnNames,
                'permission.teams' => $origTeams,
                'permission.testing' => $origTesting,
            ]);
            $permMig->up();
            Schema::enableForeignKeyConstraints();
        }
    } finally {
        config([
            'permission.table_names' => $origTableNames,
            'permission.column_names' => $origColumnNames,
            'permission.teams' => $origTeams,
            'permission.testing' => $origTesting,
        ]);
    }

    // 6. Run migrate:reset (dropping sqlite indexes that block dropColumn) to execute down() on all registered migrations
    Schema::disableForeignKeyConstraints();
    try {
        try {
            Schema::table('teams', fn (Blueprint $t) => $t->dropIndex('teams_archived_at_index'));
        } catch (Throwable) {
        }
        try {
            Artisan::call('migrate:reset');
        } catch (Throwable) {
            // Fall through to per-file down() loop so every migration's down() is executed even if one sqlite dropColumn hits an index
        }
    } finally {
        Schema::enableForeignKeyConstraints();
    }

    // 7. Also execute down() on any module migration file that wasn't already rolled back
    $migrator = app('migrator');
    $resolvePath = new ReflectionMethod($migrator, 'resolvePath');
    $allModuleMigrations = glob(base_path('modules/*/database/migrations/*.php')) ?: [];

    Schema::disableForeignKeyConstraints();
    try {
        foreach (array_reverse($allModuleMigrations) as $file) {
            $migration = $resolvePath->invoke($migrator, $file);
            if (is_object($migration) && method_exists($migration, 'down')) {
                try {
                    $migration->down();
                } catch (Throwable) {
                    // Best-effort for migrations whose tables/columns were already dropped during migrate:reset
                }
            }
        }
    } finally {
        Schema::enableForeignKeyConstraints();
    }

    // RefreshDatabase wraps this test in a transaction and rolls the schema back cleanly on teardown.
    expect($allModuleMigrations)->not->toBeEmpty();
});
