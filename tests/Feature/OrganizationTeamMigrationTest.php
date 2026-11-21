<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\Organizations\Models\Team;

it('upgrades existing permission tables to team-aware roles without losing assignments', function (): void {
    $names = ['roles' => 'legacy_staff_roles', 'model_has_roles' => 'legacy_staff_role_assignments', 'model_has_permissions' => 'legacy_staff_permission_assignments'];
    config([
        'permission.teams' => false,
        'permission.table_names' => [...config('permission.table_names'), ...$names],
        'permission.column_names.team_foreign_key' => 'workspace_id',
        'permission.column_names.model_morph_key' => 'actor_id',
        'permission.column_names.role_pivot_key' => 'staff_role_id',
        'permission.column_names.permission_pivot_key' => 'staff_permission_id',
    ]);

    config(['database.connections.permission_upgrade' => [
        ...config('database.connections.sqlite'),
        'database' => ':memory:',
        'url' => null,
    ]]);

    DB::usingConnection('permission_upgrade', function () use ($names): void {
        Schema::create($names['roles'], function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->unique(['name', 'guard_name']);
        });
        foreach (['model_has_roles' => 'staff_role_id', 'model_has_permissions' => 'staff_permission_id'] as $name => $foreignKey) {
            Schema::create($names[$name], function (Blueprint $table) use ($foreignKey): void {
                $table->unsignedBigInteger($foreignKey);
                $table->unsignedBigInteger('actor_id');
                $table->string('model_type');
                $table->primary([$foreignKey, 'actor_id', 'model_type']);
            });
            DB::table($names[$name])->insert([$foreignKey => 1, 'actor_id' => 7, 'model_type' => User::class]);
        }
        DB::table($names['roles'])->insert(['id' => 1, 'name' => 'editor', 'guard_name' => 'web']);

        $migration = require base_path('modules/organizations-teams/database/migrations/2026_07_06_000002_enable_spatie_teams_columns.php');
        $migration->up();
        expect(Schema::hasColumn($names['roles'], 'workspace_id'))->toBeFalse();

        config(['permission.teams' => true]);
        Cache::put(config('permission.cache.key'), 'stale');
        $migration->up();

        expect(Cache::has(config('permission.cache.key')))->toBeFalse()
            ->and(Schema::hasIndex($names['roles'], ['workspace_id', 'name', 'guard_name'], 'unique'))->toBeTrue()
            ->and(DB::table($names['roles'])->find(1)->workspace_id)->toBeNull();
        DB::table($names['roles'])->insert(['workspace_id' => 10, 'name' => 'editor', 'guard_name' => 'web']);

        foreach (['model_has_roles' => 'staff_role_id', 'model_has_permissions' => 'staff_permission_id'] as $name => $foreignKey) {
            $table = $names[$name];
            expect(Schema::hasIndex($table, ['workspace_id', $foreignKey, 'actor_id', 'model_type'], 'unique'))->toBeTrue()
                ->and(DB::table($table)->sole()->workspace_id)->toBeNull();
            DB::table($table)->insert([
                ['workspace_id' => 10, $foreignKey => 1, 'actor_id' => 7, 'model_type' => User::class],
                ['workspace_id' => 20, $foreignKey => 1, 'actor_id' => 7, 'model_type' => User::class],
            ]);
            expect(DB::table($table)->count())->toBe(3);
        }

        $migration->up();
        $migration->down();
        expect(Schema::hasColumn($names['roles'], 'workspace_id'))->toBeTrue();
    });
});

it('backfills existing support tickets from the requester team and can roll back', function (): void {
    $team = Team::factory()->create();
    $requester = User::factory()->create(['current_team_id' => $team->id]);
    Schema::dropIfExists('tickets');
    Schema::create('tickets', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
    });
    DB::table('tickets')->insert([['id' => 1, 'user_id' => $requester->id], ['id' => 2, 'user_id' => null]]);

    $migration = require base_path('modules/organizations-teams/database/migrations/2026_07_07_120000_add_team_id_to_tickets_table.php');
    $migration->up();

    expect(DB::table('tickets')->find(1)->team_id)->toEqual($team->id)
        ->and(DB::table('tickets')->find(2)->team_id)->toBeNull();

    $migration->up();
    $migration->down();
    $migration->down();
    expect(Schema::hasColumn('tickets', 'team_id'))->toBeFalse()
        ->and(DB::table('tickets')->count())->toBe(2);
});
