<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-005 two-factor sign-in (docs/specs/two-factor.md section 5 "Schema"). Posts nothing.
 *  - users: the encrypted secret, the pending secret of a setup, when 2FA was confirmed and the last used time step;
 *  - two_factor_recovery_codes: HMAC-SHA256 of each code only; used and superseded codes are kept;
 *  - two_factor_challenges: the second sign-in step; rows are kept (accounting review 6), with no cascading key;
 *  - settings row two_factor_required_for_admins = 0 where missing.
 * Every foreign key has a single-column index of its own, created before the composite index that starts with the same
 * column, so MySQL never has to use (and later drop) the composite index for the constraint (error 1553).
 * down() refuses once any 2FA data exists (the rows are sign-in evidence), else drops what up() added.
 */
return new class extends Migration
{
    public const USER_COLUMNS = ['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_step', 'two_factor_pending_secret',
        'two_factor_pending_at'];

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'two_factor_secret')) {
            Schema::table('users', function (Blueprint $t) {
                $t->text('two_factor_secret')->nullable();
                $t->timestamp('two_factor_confirmed_at')->nullable();
                $t->unsignedBigInteger('two_factor_last_step')->nullable();
                $t->text('two_factor_pending_secret')->nullable();
                $t->timestamp('two_factor_pending_at')->nullable();
            });
        }

        if (! Schema::hasTable('two_factor_recovery_codes')) {
            Schema::create('two_factor_recovery_codes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $t->char('code_hash', 64);
                $t->uuid('batch');
                $t->timestamp('created_at')->nullable();
                $t->timestamp('used_at')->nullable();
                $t->string('used_ip', 45)->nullable();
                $t->timestamp('superseded_at')->nullable();

                $t->index('user_id', 'two_factor_recovery_codes_user_id_fk');
                $t->index(['user_id', 'code_hash']);
            });
        }

        if (! Schema::hasTable('two_factor_challenges')) {
            Schema::create('two_factor_challenges', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $t->char('token_hash', 64)->unique();
                $t->string('purpose', 10); // verify | enrol
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->unsignedTinyInteger('starts')->default(0);
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('consumed_at')->nullable();
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->timestamp('created_at')->nullable();

                $t->index('user_id', 'two_factor_challenges_user_id_fk');
                $t->index(['user_id', 'consumed_at']);
            });
        }

        $now = now();
        DB::table('settings')->insertOrIgnore(['key' => 'two_factor_required_for_admins', 'value' => '0', 'created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $data = (Schema::hasColumn('users', 'two_factor_confirmed_at') && DB::table('users')->whereNotNull('two_factor_confirmed_at')->exists())
            || (Schema::hasTable('two_factor_challenges') && DB::table('two_factor_challenges')->exists())
            || (Schema::hasTable('two_factor_recovery_codes') && DB::table('two_factor_recovery_codes')->exists());
        if ($data) {
            throw new RuntimeException('Rollback stopped before any change: two-factor sign-in data exists (users with 2FA, recovery codes '
                .'or sign-in challenges), and rolling back would drop it. Restore a backup taken before this migration instead.');
        }

        // Dropping a whole table drops its indexes and keys together, so no 1553 ordering is needed here.
        Schema::dropIfExists('two_factor_challenges');
        Schema::dropIfExists('two_factor_recovery_codes');
        $present = array_values(array_filter(self::USER_COLUMNS, fn ($c) => Schema::hasColumn('users', $c)));
        if ($present) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn($present));
        }
        DB::table('settings')->where('key', 'two_factor_required_for_admins')->delete();
    }
};
