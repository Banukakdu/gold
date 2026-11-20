<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Services\ChartOfAccounts;
use App\Support\LogoFile;
use App\Support\LogoStore;
use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-092 settings, fiscal year and year-end close (docs/specs/settings.md section 5 + accounting review finding 1).
 *  - settings rows inserted where missing (never overwritten): company_name, currency, date_format, fiscal_year_start_month;
 *  - a legacy `company_logo` data: URI is checked with R3 and stored as a file on the `branding` disk, or cleared;
 *  - `year_end_closes`, one ACTIVE close per fiscal year enforced by the database (active_key, as period_locks);
 *  - MAIN 32000 "Retained earnings" (type retained_earnings, class equity, zero balance). transactions.type gains
 *    `year_end_close` and ledger_entries.origin gains `close`: both are plain strings (ADD-only, no CHECK).
 * Resumable: every step checks first (MySQL commits each DDL statement).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (Settings::defaults() as $key => $value) {
            DB::table('settings')->insertOrIgnore(['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->migrateLogo();

        if (! Schema::hasTable('year_end_closes')) {
            Schema::create('year_end_closes', function (Blueprint $table) {
                $table->id();
                $table->unsignedSmallInteger('fiscal_year'); // start year
                $table->date('period_start');
                $table->date('period_end');
                $table->string('status', 10); // closed | reopened
                $table->foreignId('closed_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('closed_at');
                $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('reopened_at')->nullable();
                $table->text('reopen_reason')->nullable();
                $table->json('summary');
                // Finding 1: the active global lock for month(E) the close ensured; refused for unlock while active.
                $table->foreignId('period_lock_id')->nullable()->constrained('period_locks')->restrictOnDelete();
                $table->timestamps();
                $table->index(['fiscal_year', 'status']);
            });
            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE year_end_closes ADD active_key SMALLINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'closed', fiscal_year, NULL)) STORED");
                DB::statement('ALTER TABLE year_end_closes ADD UNIQUE year_end_closes_active_key_unique (active_key)');
            } else {
                DB::statement("CREATE UNIQUE INDEX year_end_closes_active_unique ON year_end_closes (fiscal_year) WHERE status = 'closed'");
            }
        }

        // 32000 in MAIN only. On a fresh install MAIN does not exist yet: ChartOfAccounts::setupBranch creates it then.
        if (DB::table('branches')->where('is_main', true)->exists()
            && ! DB::table('accounts')->where('type', Account::RETAINED_EARNINGS)->exists()) {
            DB::transaction(function () {
                $chart = app(ChartOfAccounts::class);
                $chart->takeCreated();
                $acc = $chart->retainedEarnings();
                $chart->takeCreated();
                AuditLog::create(['user_id' => null, 'branch_id' => $acc->branch_id, 'event' => 'account.created',
                    'auditable_type' => Account::class, 'auditable_id' => $acc->id,
                    'new_values' => $acc->only(['code', 'name', 'type', 'class']) + ['system' => true, 'source' => 'migration']]);
            });
        }
    }

    /** Section 5 "Existing logo value": a valid PNG/JPEG data: URI becomes a file; anything else is cleared and audited. */
    private function migrateLogo(): void
    {
        $value = DB::table('settings')->where('key', 'company_logo')->value('value');
        if (! is_string($value) || ! str_starts_with($value, 'data:')) {
            return;
        }
        $error = 'not a base64 PNG or JPEG data URI';
        $info = null;
        if (preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=\s]+)$#', $value, $m) && ($bytes = base64_decode(preg_replace('/\s+/', '', $m[2]), true)) !== false) {
            $tmp = tempnam(sys_get_temp_dir(), 'logo');
            file_put_contents($tmp, $bytes);
            $error = LogoFile::check($tmp, strlen($bytes), null, $info);
            if ($error === null) {
                $stored = LogoStore::put($tmp, $info, 'logo.'.$info['ext'], null);
                DB::table('settings')->where('key', 'company_logo')->update(['value' => json_encode($stored), 'updated_at' => now()]);
                AuditLog::create(['user_id' => null, 'branch_id' => null, 'event' => 'settings.logo_uploaded', 'old_values' => null,
                    'new_values' => array_intersect_key($stored, array_flip(['original_name', 'mime', 'size_bytes', 'width', 'height', 'sha256'])) + ['source' => 'migration']]);
            }
            @unlink($tmp);
        }
        if ($error !== null) {
            DB::table('settings')->where('key', 'company_logo')->update(['value' => null, 'updated_at' => now()]);
            AuditLog::create(['user_id' => null, 'branch_id' => null, 'event' => 'settings.logo_removed',
                'old_values' => ['value' => 'data: URI ('.strlen($value).' characters)'], 'new_values' => ['reason' => 'invalid at migration', 'detail' => $error]]);
        }
    }

    public function down(): void
    {
        // Closing lines are immutable: once a close exists, rolling back would leave lines nothing explains.
        if (Schema::hasTable('year_end_closes') && DB::table('year_end_closes')->exists()) {
            throw new RuntimeException('Rollback stopped before any change: year-end closes exist and their ledger lines cannot be deleted. '
                .'Restore a backup taken before this migration instead.');
        }
        $re = DB::table('accounts')->where('type', Account::RETAINED_EARNINGS)->pluck('id');
        if ($re->isNotEmpty() && DB::table('ledger_entries')->whereIn('account_id', $re)->exists()) {
            throw new RuntimeException('Rollback stopped before any change: Retained earnings has ledger lines.');
        }
        if (DB::connection()->getDriverName() === 'mysql' && Schema::hasTable('year_end_closes')) {
            DB::statement('ALTER TABLE year_end_closes DROP INDEX year_end_closes_active_key_unique');
        }
        Schema::dropIfExists('year_end_closes');
        // The account has no line and is referenced by audit rows only (no foreign key).
        DB::table('accounts')->whereIn('id', $re)->delete();
        // Settings rows stay: the earlier code reads company_name and currency with the same defaults.
    }
};
