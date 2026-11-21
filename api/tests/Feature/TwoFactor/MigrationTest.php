<?php

namespace Tests\Feature\TwoFactor;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * AG-005 section 5 "Schema": columns, tables and the settings row exist, no permission is added, rollback refuses once
 * 2FA data exists, and rollback + migrate is clean. No RefreshDatabase: the test changes the schema and MySQL commits
 * DDL. It starts from migrate:fresh and, on MySQL, leaves an empty migrated database for the tests that follow.
 */
class MigrationTest extends TestCase
{
    private const FILE = 'database/migrations/2026_12_04_000000_two_factor.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->artisan('migrate:fresh', ['--force' => true]);
        }
        parent::tearDown();
    }

    public function test_schema_rollback_and_migrate(): void
    {
        foreach (['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_step', 'two_factor_pending_secret', 'two_factor_pending_at'] as $c) {
            $this->assertTrue(Schema::hasColumn('users', $c), $c);
        }
        $this->assertTrue(Schema::hasIndex('two_factor_recovery_codes', ['user_id', 'code_hash']));
        $this->assertTrue(Schema::hasIndex('two_factor_challenges', ['token_hash'], 'unique'));
        $this->assertSame('0', DB::table('settings')->where('key', 'two_factor_required_for_admins')->value('value'));
        $this->assertSame(count(PermissionSeeder::PERMISSIONS), DB::table('permissions')->count()); // no new key
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (['two_factor_recovery_codes', 'two_factor_challenges'] as $table) {
                $fk = collect(Schema::getForeignKeys($table))->firstWhere('columns', ['user_id']);
                $this->assertNotNull($fk);
                $this->assertNotSame('cascade', strtolower((string) $fk['on_delete']), $table); // accounting review 6
            }
        }

        $this->artisan('migrate:rollback', ['--path' => self::FILE, '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('two_factor_challenges'));
        $this->assertFalse(Schema::hasColumn('users', 'two_factor_secret'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('two_factor_challenges'));
        $this->assertSame('0', DB::table('settings')->where('key', 'two_factor_required_for_admins')->value('value'));
    }

    public function test_rollback_refuses_once_two_factor_data_exists(): void
    {
        $u = User::where('is_super_admin', true)->first();
        DB::table('two_factor_challenges')->insert(['user_id' => $u->id, 'token_hash' => str_repeat('a', 64), 'purpose' => 'verify',
            'expires_at' => now(), 'created_at' => now()]);
        try {
            $this->artisan('migrate:rollback', ['--path' => self::FILE, '--force' => true]);
            $this->fail('The rollback should have been refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rollback stopped before any change', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('two_factor_challenges'));
        $this->assertTrue(Schema::hasColumn('users', 'two_factor_secret'));
        // The user cannot be deleted while a challenge row refers to it (no cascade).
        $this->expectException(QueryException::class);
        DB::statement(DB::connection()->getDriverName() === 'sqlite' ? 'PRAGMA foreign_keys = ON' : 'SELECT 1');
        DB::table('users')->where('id', $u->id)->delete();
    }
}
