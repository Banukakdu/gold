<?php

namespace Tests\Feature\TwoFactor;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\TwoFactorAlertNotification;
use App\Notifications\TwoFactorChangedNotification;
use App\Services\TwoFactor;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;

/**
 * AG-005 review and QA fixes: M1 (the R6 attempt is reserved before the check), S1 (open challenges capped),
 * S2 (lock source), N1 (a double submit is not a failure), N2 (a move starts a fresh replay state), N3 (no id probing
 * on A1), N4 (no query per user) and D5 (date format and hours in messages).
 */
class ReviewFixesTest extends TwoFactorTestCase
{
    private const REASON = 'Checked in person';

    /** @return list<string> n in-flight reservations of the user, none settled yet */
    private function inFlight(User $u, int $n, string $source = 'sign_in'): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = TwoFactor::reserve($u, "10.9.0.{$i}", $source);
        }

        return $ids;
    }

    // ------------------------------------------------------------------ M1

    public function test_m1_ten_attempts_in_flight_refuse_the_eleventh_before_its_code_is_checked(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $challenge = $this->challenge($u);
        $ids = $this->inFlight($u, 10); // 10 requests passed the lock check; none of their codes is checked yet
        $step = DB::table('users')->where('id', $u->id)->value('two_factor_last_step');

        // The 11th is refused although its code is right: not checked, the TOTP step not used, no attempt counted.
        $this->verify($challenge, ['code' => $this->code($secret)])->assertStatus(429)
            ->assertJsonPath('message', 'Too many wrong codes. Try again in 1 minute.');
        $this->assertSame(0, (int) DB::table('two_factor_challenges')->value('attempts'));
        $this->assertSame($step, DB::table('users')->where('id', $u->id)->value('two_factor_last_step'));
        $this->assertSame(0, AuditLog::where('event', 'auth.login')->count());

        // The 10 turn out wrong: exactly one lock, with its row, and the user stays locked for 15 minutes.
        foreach ($ids as $id) {
            TwoFactor::failed($u, $id);
        }
        $this->assertSame(1, AuditLog::where('event', 'auth.two_factor_locked')->count());
        $this->assertSame(10, AuditLog::where('event', 'auth.two_factor_locked')->sole()->new_values['failures']);
        $this->verify($challenge, ['code' => $this->code($secret)])->assertStatus(429)
            ->assertJsonPath('message', 'Too many wrong codes. Try again in 15 minutes.');
    }

    public function test_m1_settled_reservations_free_the_window(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $ids = $this->inFlight($u, 10);
        TwoFactor::refund($u, $ids[0]);
        TwoFactor::succeeded($u, $ids[1]);

        $this->verify($this->challenge($u), ['code' => $this->code($secret)])->assertOk();
        $this->assertSame(0, AuditLog::where('event', 'auth.two_factor_locked')->count());
    }

    public function test_m1_step_up_reserves_too(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $this->inFlight($u, 10, 'step_up');

        $this->as($u)->postJson('/api/v1/me/two-factor/disable', ['password' => self::PASSWORD, 'code' => $this->code($secret)])->assertStatus(429);
        $this->assertTrue($u->fresh()->hasTwoFactor());
    }

    public function test_m1_an_attempt_never_settled_counts_as_a_failure(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $challenge = $this->challenge($u);
        $this->inFlight($u, 10); // for example 10 requests that died after the reservation
        $this->travel(TwoFactor::PENDING_SECONDS + 1)->seconds();

        $this->verify($challenge, ['code' => $this->code($secret)])->assertStatus(429)
            ->assertJsonPath('message', 'Too many wrong codes. Try again in 15 minutes.');
        $this->assertSame(1, AuditLog::where('event', 'auth.two_factor_locked')->count());
    }

    public function test_m1_fails_closed_when_the_lock_cannot_be_taken(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $challenge = $this->challenge($u);
        $held = Cache::lock("two-factor:fail-mutex:{$u->id}", 60);
        $this->assertTrue($held->get());
        Sleep::fake(); // the clock is frozen in these tests: the wait for the cache lock moves it on
        Sleep::syncWithCarbon();

        $this->verify($challenge, ['code' => $this->code($secret)])->assertStatus(429); // not a 500, and not checked
        $this->assertSame(0, (int) DB::table('two_factor_challenges')->value('attempts'));
        $held->release();
        $this->verify($challenge, ['code' => $this->code($secret)])->assertOk();
    }

    // ------------------------------------------------------------------ S1

    public function test_s1_at_most_five_open_challenges_per_user_the_oldest_is_voided_not_deleted(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $first = $this->challenge($u);
        for ($i = 0; $i < 5; $i++) {
            $this->challenge($u);
        }

        $this->assertSame(6, DB::table('two_factor_challenges')->count());
        $this->assertSame(5, DB::table('two_factor_challenges')->whereNull('consumed_at')->count());
        $this->assertNotNull(DB::table('two_factor_challenges')->orderBy('id')->first()->consumed_at);
        $this->verify($first, ['code' => $this->code($secret)])->assertStatus(422)->assertJsonValidationErrors('challenge_token');
    }

    // ------------------------------------------------------------------ S2

    public function test_s2_a_lock_from_wrong_step_up_passwords_only_is_alerted_but_does_not_block_the_reset(): void
    {
        $this->admin->forceFill(['password' => self::PASSWORD])->save();
        $aSecret = $this->enable($this->admin)[0];
        $u = $this->member('ba@x.com', 'Branch Admin');
        $this->enable($u);
        for ($i = 0; $i < 10; $i++) { // a stolen token without the password
            $this->as($u)->postJson('/api/v1/me/two-factor/disable', ['password' => 'wrong-pass1', 'code' => '123456'])->assertStatus(422);
        }

        $row = AuditLog::where('event', 'auth.two_factor_locked')->sole();
        $this->assertSame(['step_up', false], [$row->new_values['source'], $row->new_values['password_known']]);
        Notification::assertSentTo($u, TwoFactorChangedNotification::class, fn ($n) => $n->kind === 'locked'
            && str_contains($n->text(), 'A wrong password or code was entered too many times'));
        Notification::assertSentTo($this->admin, TwoFactorAlertNotification::class, fn ($n) => $n->kind === 'locked'
            && str_contains($n->text(), 'A wrong password or code was entered for '));

        $this->as($this->admin)->getJson("/api/v1/users/{$u->id}")->assertOk()->assertJsonPath('two_factor.reset_blocked_since', null);
        $this->as($this->admin)->postJson("/api/v1/users/{$u->id}/two-factor/reset", [
            'reason' => self::REASON, 'password' => self::PASSWORD, 'code' => $this->code($aSecret),
        ])->assertOk();
        $this->assertFalse($u->fresh()->hasTwoFactor());
    }

    public function test_s2_a_wrong_step_up_code_means_the_password_was_known(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        for ($i = 0; $i < 10; $i++) {
            $this->as($u)->postJson('/api/v1/me/two-factor/disable', ['password' => self::PASSWORD, 'code' => $this->wrongCode($secret)])->assertStatus(422);
        }
        $row = AuditLog::where('event', 'auth.two_factor_locked')->sole();
        $this->assertSame(['step_up', true], [$row->new_values['source'], $row->new_values['password_known']]);
        $this->assertNotNull(TwoFactor::resetBlockedBy($u));
    }

    // ------------------------------------------------------------------ N1

    public function test_n1_a_double_submit_of_the_right_code_is_not_a_failure(): void
    {
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $challenge = $this->challenge($u);
        $code = $this->code($secret);
        $step = intdiv(now()->getTimestamp(), 30);
        // The parallel request with the same code wins right after this one counted its attempt.
        $done = false;
        DB::listen(function ($q) use (&$done, $u, $step) {
            if (! $done && str_contains($q->sql, 'two_factor_challenges') && str_contains($q->sql, 'attempts + 1')) {
                $done = true;
                DB::table('two_factor_challenges')->update(['consumed_at' => now()]);
                DB::table('users')->where('id', $u->id)->update(['two_factor_last_step' => $step]);
            }
        });

        $this->verify($challenge, ['code' => $code])->assertStatus(422)->assertJsonValidationErrors('challenge_token');
        $this->assertTrue($done);
        $this->assertSame([], array_filter((array) Cache::get("two-factor:fail-long:{$u->id}", []), fn ($f) => $f[3] === 'failed'));
        $this->assertSame(0, (int) DB::table('two_factor_challenges')->value('attempts'));
    }

    // ------------------------------------------------------------------ N2 (QA D2)

    public function test_n2_moving_phone_in_the_same_step_as_the_old_code_works(): void
    {
        $u = $this->member('cash@x.com');
        [$old] = $this->enable($u);
        $new = $this->as($u)->postJson('/api/v1/me/two-factor/setup', ['password' => self::PASSWORD, 'code' => $this->code($old)])
            ->assertOk()->json('secret');

        $this->as($u)->postJson('/api/v1/me/two-factor/confirm', ['code' => $this->code($new)])->assertOk();
        $this->assertSame(1, AuditLog::where('event', 'auth.two_factor_moved')->count());
        $this->assertSame([], (array) Cache::get("two-factor:fail-long:{$u->id}", []));
        // The new code is used once.
        $this->as($u)->postJson('/api/v1/me/two-factor/disable', ['password' => self::PASSWORD, 'code' => $this->code($new)])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    // ------------------------------------------------------------------ N3, N4

    public function test_n3_a_non_super_admin_gets_403_for_known_and_unknown_ids(): void
    {
        $ba = $this->member('ba@x.com', 'Branch Admin');
        $c = $this->member('cash@x.com');
        $body = ['reason' => self::REASON, 'password' => self::PASSWORD];
        $this->as($ba, $this->kdy->id)->postJson("/api/v1/users/{$c->id}/two-factor/reset", $body)->assertForbidden();
        $this->as($ba, $this->kdy->id)->postJson('/api/v1/users/999999/two-factor/reset', $body)->assertForbidden();
        $this->as($this->admin)->postJson('/api/v1/users/999999/two-factor/reset', $body)->assertNotFound();
    }

    public function test_n4_the_users_list_does_not_query_per_user(): void
    {
        $this->admin->forceFill(['password' => self::PASSWORD])->save();
        $this->enable($this->admin);
        $this->requireForAdmins();
        $this->member('ba@x.com', 'Branch Admin');
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $r = $this->as($this->admin)->getJson('/api/v1/users')->assertOk();
            DB::disableQueryLog();

            return [count(DB::getQueryLog()), $r];
        };
        [$before, $r] = $count();
        $this->assertTrue(collect($r->json('data'))->firstWhere('email', 'ba@x.com')['two_factor']['required']);
        for ($i = 0; $i < 5; $i++) {
            $this->member("ba{$i}@x.com", 'Branch Admin');
        }
        [$after, $r] = $count();
        $this->assertLessThanOrEqual($before, $after); // the same queries for 7 users as for 2 (the settings are memoised)
        $this->assertTrue(collect($r->json('data'))->firstWhere('email', 'ba3@x.com')['two_factor']['required']);
    }

    // ------------------------------------------------------------------ D5

    public function test_d5_the_r16_message_uses_the_date_format_setting(): void
    {
        Setting::put('date_format', 'DD/MM/YYYY');
        app(Settings::class)->flush();
        $u = $this->member('cash@x.com');
        [$secret] = $this->enable($u);
        $challenge = null;
        for ($i = 0; $i < 10; $i++) {
            if ($i % 4 === 0) {
                $challenge = $this->challenge($u);
            }
            $this->verify($challenge, ['code' => $this->wrongCode($secret)])->assertStatus(422);
        }
        $lock = TwoFactor::resetBlockedBy($u);
        $local = $lock->created_at->copy()->setTimezone('Asia/Colombo');
        $this->assertSame("Someone entered this user's password without the code on ".$local->format('d/m/Y H:i')
            .' (Asia/Colombo). Ask them to reset their password first, then reset two-factor.', TwoFactor::resetBlockedMessage($lock));
        $this->assertSame('24 hours', TwoFactor::waitText(TwoFactor::LONG_SECONDS));
        $this->assertSame('15 minutes', TwoFactor::waitText(TwoFactor::SHORT_SECONDS));
    }
}
