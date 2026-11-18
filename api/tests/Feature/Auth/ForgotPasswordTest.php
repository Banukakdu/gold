<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PasswordResetAlertNotification;
use App\Notifications\PasswordResetLinkNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/** Password reset spec N1: AC 1-10, 40, 42, 44. */
class ForgotPasswordTest extends PasswordResetTestCase
{
    public function test_ac1_link_is_sent_to_an_active_user(): void
    {
        config(['app.frontend_url' => 'https://gold.example/']);
        $u = $this->member('u@x.com');

        $this->forgot('U@x.com')->assertStatus(202)->assertExactJson(['message' => self::REQUESTED]);

        Notification::assertSentTo($u, PasswordResetLinkNotification::class, function ($n) use ($u) {
            $url = $n->url($u);
            $mail = $n->toMail($u);

            return str_starts_with($url, 'https://gold.example/reset-password?token=') && str_contains($url, 'email=u%40x.com')
                && $mail->actionUrl === $url && $mail->subject === 'Reset your '.config('app.name').' password';
        });
        $row = AuditLog::where('event', 'auth.password_reset_requested')->sole();
        $this->assertSame($u->id, $row->auditable_id);
        $this->assertSame(['email' => 'u@x.com'], $row->new_values);
        $this->assertNull($row->user_id);
        $this->assertNull($row->branch_id);
    }

    public function test_ac2_same_response_for_unknown_inactive_and_active(): void
    {
        $active = $this->member('active@x.com');
        $inactive = $this->member('inactive@x.com', 'Cashier', ['status' => 'inactive']);

        $responses = array_map(fn ($e) => $this->forgot($e), ['nobody@x.com', 'inactive@x.com', 'active@x.com']);
        foreach ($responses as $r) {
            $r->assertStatus(202);
            $this->assertSame($responses[0]->json(), $r->json());
            $keys = array_keys($r->headers->all());
            sort($keys);
            $first = array_keys($responses[0]->headers->all());
            sort($first);
            $this->assertSame($first, $keys);
        }
        Notification::assertSentTo($active, PasswordResetLinkNotification::class);
        Notification::assertNotSentTo($inactive, PasswordResetLinkNotification::class);
        Notification::assertSentTimes(PasswordResetLinkNotification::class, 1);
    }

    public function test_ac3_inactive_user_gets_nothing_and_refusal_is_audited(): void
    {
        $u = $this->member('gone@x.com', 'Cashier', ['status' => 'inactive']);
        $this->forgot('gone@x.com')->assertStatus(202);

        Notification::assertNothingSent();
        $row = AuditLog::where('event', 'auth.password_reset_refused')->sole();
        $this->assertSame(['email' => 'gone@x.com', 'reason' => 'inactive'], $row->new_values);
        $this->assertSame($u->id, $row->auditable_id);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_ac4_unknown_email_leaves_no_trace(): void
    {
        $this->forgot('stranger@x.com')->assertStatus(202);
        Notification::assertNothingSent();
        $this->assertSame(0, AuditLog::where('event', 'like', 'auth.%')->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_ac5_invalid_email_is_422(): void
    {
        $this->forgot('')->assertStatus(422)->assertJsonValidationErrors('email');
        $this->forgot('not-an-email', '10.0.0.2')->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_ac6_broker_throttle_is_quiet_and_a_new_link_replaces_the_old(): void
    {
        $this->freezeTime();
        $u = $this->member('u@x.com');
        $first = $this->tokenFor($u);

        $this->travel(30)->seconds();
        $this->forgot('u@x.com')->assertStatus(202)->assertExactJson(['message' => self::REQUESTED]);
        Notification::assertSentToTimes($u, PasswordResetLinkNotification::class, 1);

        $this->travel(31)->seconds();
        $this->forgot('u@x.com')->assertStatus(202);
        Notification::assertSentToTimes($u, PasswordResetLinkNotification::class, 2);
        $this->assertNotSame($first, $this->linkToken($u));
        $this->reset('u@x.com', $first)->assertStatus(422)->assertJsonPath('errors.token.0', self::TOKEN_MESSAGE);
    }

    public function test_ac7_three_requests_per_email_per_15_minutes(): void
    {
        $this->member('u@x.com');
        foreach (['u@x.com', 'ghost@x.com'] as $i => $email) {
            for ($n = 1; $n <= 3; $n++) {
                $this->forgot($email, "10.1.$i.$n")->assertStatus(202);
            }
            $this->forgot(strtoupper($email), "10.1.$i.9")->assertStatus(429)
                ->assertExactJson(['message' => 'Too many requests. Try again in 15 minutes.']);
        }
    }

    public function test_ac8_five_requests_per_ip_per_minute(): void
    {
        for ($n = 1; $n <= 5; $n++) {
            $this->forgot("person$n@x.com", '10.2.0.1')->assertStatus(202);
        }
        $this->forgot('person6@x.com', '10.2.0.1')->assertStatus(429)->assertJsonPath('message', 'Too many requests. Try again in 1 minutes.');
        $this->forgot('person6@x.com', '10.2.0.2')->assertStatus(202);
    }

    public function test_ac9_only_a_hash_of_the_token_is_stored(): void
    {
        $u = $this->member('u@x.com');
        $plain = $this->tokenFor($u);
        $row = DB::table('password_reset_tokens')->where('email', 'u@x.com')->sole();

        $this->assertSame(64, strlen($plain));
        $this->assertNotSame($plain, $row->token);
        $this->assertTrue(Hash::check($plain, $row->token));
    }

    public function test_ac10_forged_host_does_not_change_the_link(): void
    {
        config(['app.frontend_url' => 'https://gold.example']);
        $u = $this->member('u@x.com');
        $this->public()->withHeader('Host', 'evil.test')->postJson('http://evil.test/api/v1/auth/forgot-password', ['email' => 'u@x.com'])->assertStatus(202);

        Notification::assertSentTo($u, PasswordResetLinkNotification::class,
            fn ($n) => str_starts_with($n->url($u), 'https://gold.example/reset-password?') && ! str_contains($n->url($u), 'evil.test'));
    }

    public function test_ac40_super_admin_email_reset_is_off_by_default(): void
    {
        $this->assertFalse(config('auth.super_admin_email_reset'));
        $b = $this->secondSuperAdmin();

        $known = $this->forgot($this->admin->email)->assertStatus(202);
        $this->assertSame($this->forgot('nobody@x.com', '10.0.0.2')->json(), $known->json());

        Notification::assertNotSentTo($this->admin, PasswordResetLinkNotification::class);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $row = AuditLog::where('event', 'auth.password_reset_refused')->sole();
        $this->assertSame(['email' => $this->admin->email, 'reason' => 'super_admin_email_reset_disabled'], $row->new_values);
        // R13c: the other Super Admin is told, the target is not.
        Notification::assertSentTo($b, PasswordResetAlertNotification::class,
            fn ($n) => $n->kind === 'refused' && $n->subject() === "Password reset requested for Super Admin {$this->admin->email}, refused");
        Notification::assertNotSentTo($this->admin, PasswordResetAlertNotification::class);
    }

    public function test_ac42_switch_on_needs_another_active_super_admin(): void
    {
        config(['auth.super_admin_email_reset' => true]);
        $this->forgot($this->admin->email)->assertStatus(202);
        Notification::assertNothingSent();
        $this->assertSame('no_other_super_admin', AuditLog::where('event', 'auth.password_reset_refused')->sole()->new_values['reason']);

        $b = $this->secondSuperAdmin();
        $b->forceFill(['status' => 'inactive'])->save();
        $this->forgot($this->admin->email, '10.0.0.2')->assertStatus(202);
        Notification::assertNothingSent();

        $b->forceFill(['status' => 'active'])->save();
        $this->forgot($this->admin->email, '10.0.0.3')->assertStatus(202);
        Notification::assertSentTo($this->admin, PasswordResetLinkNotification::class);
    }

    public function test_ac44_queued_job_writes_the_requesters_ip_and_user_agent(): void
    {
        config(['queue.default' => 'database']);
        $u = $this->member('u@x.com');
        $this->member('off@x.com', 'Cashier', ['status' => 'inactive']);

        $this->public('10.1.2.3')->withHeader('User-Agent', 'UA-test')->postJson('/api/v1/auth/forgot-password', ['email' => 'u@x.com'])->assertStatus(202);
        $this->public('10.1.2.3')->withHeader('User-Agent', 'UA-test')->postJson('/api/v1/auth/forgot-password', ['email' => 'off@x.com'])->assertStatus(202);
        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertSame(0, AuditLog::where('event', 'like', 'auth.password_reset%')->count());

        // The worker runs later, in a context with another IP and user agent.
        $this->app->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.9.9.9', 'HTTP_USER_AGENT' => 'worker']));
        $this->artisan('queue:work', ['--once' => true])->assertExitCode(0);
        $this->artisan('queue:work', ['--once' => true])->assertExitCode(0);

        foreach (['auth.password_reset_requested', 'auth.password_reset_refused'] as $event) {
            $row = AuditLog::where('event', $event)->sole();
            $this->assertSame('10.1.2.3', $row->ip, $event);
            $this->assertSame('UA-test', $row->user_agent, $event);
        }
        Notification::assertSentTo($u, PasswordResetLinkNotification::class);
    }
}
