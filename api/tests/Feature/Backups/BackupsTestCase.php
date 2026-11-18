<?php

namespace Tests\Feature\Backups;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use App\Services\Backups\DatabaseDumper;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * docs/specs/backups.md section 7 setup. Not AccountingTestCase: VACUUM INTO cannot run inside the RefreshDatabase
 * transaction, and the spec asks for a SQLite FILE database. Each test gets its own copy of a database built once per
 * process (migrated, seeded, KDY, SA2/SA3/BA/ACC, one income, 3 attachment rows) and its own temp folder holding the
 * real `attachments` and `branding` disks (3 attachment files + 1 logo). `backups` and `backups_offsite` are faked.
 * The clock is frozen at 2026-11-18 01:30 Asia/Colombo.
 */
abstract class BackupsTestCase extends TestCase
{
    public const PASSWORD = 'test-password-1234567890';

    public const FIRST = 'ag-backup-20261118-013000.zip';

    private static ?string $template = null;

    protected string $work;

    protected User $sa;

    protected User $sa2;

    protected User $sa3;

    protected User $ba;

    protected User $acc;

    protected Branch $main;

    protected Branch $kdy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeze('2026-11-18 01:30:00');
        $this->work = sys_get_temp_dir().'/ag-backup-test-'.Str::random(10);
        foreach (['attachments', 'branding', 'temp'] as $d) {
            mkdir("{$this->work}/{$d}", 0700, true);
        }
        config([
            'database.connections.backup_verify' => ['driver' => 'sqlite', 'database' => "{$this->work}/verify.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'backup.temp_path' => "{$this->work}/temp",
            'backup.password' => self::PASSWORD,
            'backup.offsite_disk' => '',
            'backup.first_run_free_bytes' => 1024 * 1024,
            'filesystems.disks.attachments.root' => "{$this->work}/attachments",
            'filesystems.disks.branding.root' => "{$this->work}/branding",
        ]);
        Storage::forgetDisk(['attachments', 'branding']);
        Storage::fake('backups');
        Storage::fake('backups_offsite');

        if (self::$template === null || ! is_file(self::$template)) {
            self::$template = $this->buildTemplate();
        }
        copy(self::$template, "{$this->work}/live.sqlite");
        $this->useDatabase("{$this->work}/live.sqlite");
        $this->writeFiles();
        Notification::fake();

        $this->sa = User::where('email', 'admin@accountgold.local')->firstOrFail();
        $this->sa2 = User::where('email', 'sa2@test.local')->firstOrFail();
        $this->sa3 = User::where('email', 'sa3@test.local')->firstOrFail();
        $this->ba = User::where('email', 'ba@test.local')->firstOrFail();
        $this->acc = User::where('email', 'acc@test.local')->firstOrFail();
        $this->main = Branch::main();
        $this->kdy = Branch::where('code', 'KDY')->firstOrFail();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        DB::purge('backup_verify');
        File::deleteDirectory($this->work);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    protected function freeze(string $colombo): void
    {
        $t = Carbon::parse($colombo, 'Asia/Colombo')->utc();
        Carbon::setTestNow($t);
        CarbonImmutable::setTestNow($t);
    }

    protected function useDatabase(string $file): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $file, 'database.connections.sqlite.foreign_key_constraints' => true]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
    }

    private function buildTemplate(): string
    {
        $file = sys_get_temp_dir().'/ag-backup-template-'.getmypid().'-'.Str::random(6).'.sqlite';
        touch($file);
        $this->useDatabase($file);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('is_super_admin', true)->firstOrFail();
        $this->actingAs($admin)->withHeader('X-Branch-Id', 'all')->postJson('/api/v1/branches', ['code' => 'KDY', 'name' => 'Kandy'])->assertCreated();
        User::factory()->create(['email' => 'sa2@test.local', 'is_super_admin' => true, 'status' => 'active']);
        User::factory()->create(['email' => 'sa3@test.local', 'is_super_admin' => true, 'status' => 'inactive']);
        $kdy = Branch::where('code', 'KDY')->firstOrFail();
        foreach (['ba@test.local' => 'Branch Admin', 'acc@test.local' => 'Accountant'] as $email => $role) {
            $u = User::factory()->create(['email' => $email, 'status' => 'active']);
            $u->branches()->attach($kdy->id);
            setPermissionsTeamId($kdy->id);
            $u->assignRole(Role::where('name', $role)->where('branch_id', $kdy->id)->first()
                ?? tap(Role::create(['name' => $role, 'guard_name' => 'web', 'branch_id' => $kdy->id]),
                    fn ($r) => $r->syncPermissions(Role::where('name', $role)->whereNull('branch_id')->firstOrFail()->permissionKeys())));
        }
        $main = Branch::main();
        $acc = Account::withoutGlobalScopes()->where('is_main_account', true)->firstOrFail();
        $cat = Category::where('type', 'income')->whereNotNull('parent_id')->firstOrFail();
        $txn = $this->actingAs($admin)->withHeader('X-Branch-Id', (string) $main->id)->postJson('/api/v1/incomes',
            ['account_id' => $acc->id, 'category_id' => $cat->id, 'txn_date' => '2026-11-17', 'amount' => '12500.50'])->assertCreated()->json('id');
        foreach ($this->attachmentFiles() as $i => [$path, $bytes]) {
            DB::table('attachments')->insert(['branch_id' => $main->id, 'transaction_id' => $txn, 'disk' => 'attachments', 'path' => $path,
                'original_name' => "receipt-{$i}.pdf", 'mime' => 'application/pdf', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
                'uploaded_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        DB::purge('sqlite');
        register_shutdown_function(fn () => @unlink($file));

        return $file;
    }

    /** @return array<int, array{0: string, 1: string}> */
    protected function attachmentFiles(): array
    {
        return [1 => ['1/2026/11/a-one.pdf', "%PDF-1.4 one\n"], 2 => ['1/2026/11/a-two.pdf', "%PDF-1.4 two\n"], 3 => ['1/2026/11/a-three.pdf', "%PDF-1.4 three\n"]];
    }

    private function writeFiles(): void
    {
        foreach ($this->attachmentFiles() as [$path, $bytes]) {
            File::ensureDirectoryExists(dirname("{$this->work}/attachments/{$path}"));
            file_put_contents("{$this->work}/attachments/{$path}", $bytes);
        }
        file_put_contents("{$this->work}/branding/logo-abc.png", 'PNGDATA');
    }

    /** Runs an artisan command as the system (no signed-in user), returning [exit code, output]. */
    protected function artisan_(string $command, array $params = []): array
    {
        $this->app['auth']->forgetGuards();
        $code = Artisan::call($command, $params);

        return [$code, Artisan::output()];
    }

    protected function backupRun(array $params = []): array
    {
        return $this->artisan_('backup:run', $params);
    }

    protected function sa(string $branch = 'all')
    {
        return $this->flushHeaders()->actingAs($this->sa)->withHeader('X-Branch-Id', $branch);
    }

    protected function bindDumper(DatabaseDumper $d): void
    {
        $this->app->instance(DatabaseDumper::class, $d);
    }

    protected function openZip(string $path, ?string $password = self::PASSWORD): ZipArchive
    {
        $z = new ZipArchive;
        $this->assertTrue($z->open($path) === true);
        if ($password !== null) {
            $z->setPassword($password);
        }

        return $z;
    }

    protected function localPath(string $file = self::FIRST): string
    {
        return Storage::disk('backups')->path($file);
    }

    /** Sorted keys, so a JSON column read back from MySQL compares equal (key order is not kept). */
    protected static function ksorted(array $a): array
    {
        ksort($a);

        return $a;
    }

    /**
     * Rewrites an archive: extracts it, lets $change edit the extracted folder, re-zips it with the same password and
     * stores the new sha256 on the row and the sidecar (a fixture for tampering that the checksum alone would not catch).
     */
    protected function tamper(string $file, callable $change): void
    {
        $dir = "{$this->work}/tamper";
        File::deleteDirectory($dir);
        mkdir($dir, 0700, true);
        $z = $this->openZip($this->localPath($file));
        $this->assertTrue($z->extractTo($dir));
        $names = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $names[] = $z->getNameIndex($i);
        }
        $z->close();
        $change($dir);
        $new = "{$this->work}/rebuilt.zip";
        @unlink($new);
        $z = new ZipArchive;
        $z->open($new, ZipArchive::CREATE);
        foreach ($names as $n) {
            $z->addFile("{$dir}/{$n}", $n);
            $z->setEncryptionName($n, ZipArchive::EM_AES_256, self::PASSWORD);
        }
        $z->close();
        copy($new, $this->localPath($file));
        $sha = hash_file('sha256', $new);
        Storage::disk('backups')->put("{$file}.sha256", "{$sha}  {$file}\n");
        DB::table('backups')->where('filename', $file)->update(['sha256' => $sha]);
    }

    /** Opens an extracted SQLite dump and drops the immutability triggers of $table (fixture only). */
    protected static function fixtureDb(string $path, string $table): \PDO
    {
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = '{$table}'")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $pdo->exec("DROP TRIGGER {$t}");
        }

        return $pdo;
    }
}
