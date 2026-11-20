<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/** AG-001 login gaps G1, G3, G4 (password reset spec section 7): AC 29-33. */
class LoginRulesTest extends PasswordResetTestCase
{
    public function test_ac29_the_lock_lasts_15_minutes_from_the_fifth_failure(): void
    {
        $this->freezeTime();
        $start = now();
        $this->member('u@x.com');
        for ($n = 0; $n < 4; $n++) {
            $this->login('u@x.com', 'wrong-pass1')->assertStatus(422);
        }
        $this->travelTo($start->copy()->addMinutes(14));
        $this->login('u@x.com', 'wrong-pass1')->assertStatus(422);

        $this->travelTo($start->copy()->addMinutes(20));
        $this->login('u@x.com', 'oldpass123')->assertStatus(429)->assertJsonPath('message', 'Too many failed attempts. Try again in 9 minutes.');
        $this->login('u@x.com', 'oldpass123', '10.0.0.99')->assertOk(); // G2: the key is email|ip

        $this->travelTo($start->copy()->addMinutes(29)->addSecond());
        $this->login('u@x.com', 'oldpass123')->assertOk();
    }

    public function test_ac30_one_locked_out_row_per_lock(): void
    {
        $u = $this->member('u@x.com');
        for ($n = 0; $n < 5; $n++) {
            $this->login('U@x.com', 'wrong-pass1')->assertStatus(422);
        }
        $this->login('u@x.com', 'wrong-pass1')->assertStatus(429);

        $row = AuditLog::where('event', 'auth.locked_out')->sole();
        $this->assertSame([User::class, $u->id, null], [$row->auditable_type, $row->auditable_id, $row->user_id]);
        $this->assertSame(['email' => 'u@x.com'], $row->new_values);

        // An unknown email is locked too, with no subject.
        for ($n = 0; $n < 5; $n++) {
            $this->login('ghost@x.com', 'wrong-pass1');
        }
        $ghost = AuditLog::where('event', 'auth.locked_out')->where('new_values->email', 'ghost@x.com')->sole();
        $this->assertNull($ghost->auditable_id);
    }

    public function test_ac31_logout_revokes_only_that_token_and_is_audited(): void
    {
        $u = $this->member('u@x.com');
        $t1 = $this->login('u@x.com', 'oldpass123')->json('token');
        $t2 = $this->login('u@x.com', 'oldpass123')->json('token');

        $this->public()->withToken($t1)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->public()->withToken($t1)->getJson('/api/v1/me')->assertUnauthorized();
        $this->public()->withToken($t2)->getJson('/api/v1/me')->assertOk();
        $row = AuditLog::where('event', 'auth.logout')->sole();
        $this->assertSame([$u->id, $u->id], [$row->user_id, $row->auditable_id]);
    }

    public function test_ac32_unknown_email_runs_one_hash_check(): void
    {
        // Warm the cached dummy hash first; it is a real bcrypt hash.
        $this->login('warm@x.com', 'whatever1')->assertStatus(422);
        $this->assertTrue(Hash::isHashed(Cache::get('auth:dummy-hash:'.config('hashing.driver').':'.config('hashing.bcrypt.rounds'))));

        Hash::shouldReceive('check')->once()->andReturnFalse();
        $this->login('ghost@x.com', 'whatever1')->assertStatus(422)->assertJsonPath('message', 'Invalid email or password.');
    }

    public function test_ac33_logins_group_lists_every_auth_event_with_labels(): void
    {
        foreach (['auth.login', 'auth.logout', 'auth.locked_out', 'auth.password_reset_requested', 'auth.password_reset_refused', 'auth.password_reset'] as $e) {
            AuditLog::record($e, null, null, $e === 'auth.password_reset' ? ['first_password' => true, 'tokens_revoked' => 2] : null);
        }
        AuditLog::record('user.updated');
        $day = now()->setTimezone('Asia/Colombo')->toDateString();

        $rows = collect($this->flushHeaders()->actingAs($this->admin)->withHeader('X-Branch-Id', 'all')
            ->getJson('/api/v1/audit-logs?'.http_build_query(['from' => $day, 'to' => $day, 'event_group' => 'logins']))->assertOk()->json('data'));

        $this->assertEqualsCanonicalizing(
            ['Login', 'Logout', 'Login locked', 'Password reset requested', 'Password reset refused', 'Password reset'],
            $rows->pluck('event_label')->all());
        // A flag and a count are not secrets: they are shown, not masked.
        $this->assertSame(['first_password' => true, 'tokens_revoked' => 2], $rows->firstWhere('event', 'auth.password_reset')['new_values']);
    }
}
