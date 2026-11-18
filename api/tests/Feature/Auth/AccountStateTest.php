<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/** Password reset spec: invitations and account state, AC 24-28 and 45. */
class AccountStateTest extends PasswordResetTestCase
{
    private const SET_EXPIRED = 'This link has expired. Ask your administrator to resend the invitation.';

    private function setPassword(string $email, string $token, string $password = 'newpass123')
    {
        return $this->public()->postJson('/api/v1/auth/set-password', ['token' => $token, 'email' => $email, 'password' => $password, 'password_confirmation' => $password]);
    }

    private function asAdminIn($branch)
    {
        return $this->flushHeaders()->actingAs($this->admin)->withHeader('X-Branch-Id', (string) $branch->id);
    }

    public function test_ac24_a_reset_replaces_and_completes_an_invitation(): void
    {
        $this->asAdminIn($this->kdy)->postJson('/api/v1/users', ['name' => 'Invited', 'email' => 'inv@x.com', 'role_id' => $this->branchRole($this->kdy, 'Cashier')->id])->assertCreated();
        $u = User::where('email', 'inv@x.com')->sole();
        $invite = Notification::sent($u, InvitationNotification::class)->last()->token;
        $this->travel(61)->seconds(); // the broker's 60 s throttle counts the invitation row too

        $reset = $this->tokenFor($u);
        $this->setPassword('inv@x.com', $invite)->assertStatus(422)->assertJsonPath('errors.token.0', self::SET_EXPIRED);
        $this->reset('inv@x.com', $reset)->assertNoContent();
        $this->login('inv@x.com', 'newpass123')->assertOk();
        $this->assertTrue(AuditLog::where('event', 'auth.password_reset')->sole()->new_values['first_password']);
    }

    public function test_ac25_set_password_is_for_invitations_only(): void
    {
        $u = $this->member('u@x.com'); // has logged in before
        $token = $this->tokenFor($u);

        $this->setPassword('u@x.com', $token)->assertStatus(422)->assertJsonPath('errors.token.0', self::SET_EXPIRED);
        $this->assertTrue(Hash::check('oldpass123', $u->fresh()->password));
        $this->assertSame(0, AuditLog::where('event', 'user.password_set')->count());
    }

    public function test_ac26_email_change_or_deactivation_kills_the_link(): void
    {
        $a = $this->member('a@x.com');
        $tokenA = $this->tokenFor($a);
        $this->asAdminIn($this->kdy)->putJson("/api/v1/users/{$a->id}", ['email' => 'a2@x.com'])->assertOk();
        $this->assertFalse(DB::table('password_reset_tokens')->where('email', 'a@x.com')->exists());
        $this->reset('a@x.com', $tokenA)->assertStatus(422)->assertJsonPath('errors.token.0', self::TOKEN_MESSAGE);
        $this->reset('a2@x.com', $tokenA)->assertStatus(422)->assertJsonPath('errors.token.0', self::TOKEN_MESSAGE);

        $b = $this->member('b@x.com');
        $tokenB = $this->tokenFor($b);
        $this->asAdminIn($this->kdy)->putJson("/api/v1/users/{$b->id}", ['status' => 'inactive'])->assertOk();
        $this->assertFalse(DB::table('password_reset_tokens')->where('email', 'b@x.com')->exists());
        $this->asAdminIn($this->kdy)->putJson("/api/v1/users/{$b->id}", ['status' => 'active'])->assertOk();
        $this->reset('b@x.com', $tokenB)->assertStatus(422)->assertJsonPath('errors.token.0', self::TOKEN_MESSAGE);
    }

    public function test_ac27_one_policy_on_every_endpoint(): void
    {
        $u = $this->member('u@x.com');
        $messages = [
            $this->asAdminIn($this->kdy)->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@x.com', 'role_id' => $this->branchRole($this->kdy, 'Cashier')->id, 'password' => 'abcdefgh'])
                ->assertStatus(422)->assertJsonValidationErrors('password')->json('errors.password'),
            $this->setPassword('u@x.com', 'whatever', 'abcdefgh')->assertStatus(422)->assertJsonValidationErrors('password')->json('errors.password'),
            $this->reset('u@x.com', 'whatever', 'abcdefgh')->assertStatus(422)->assertJsonValidationErrors('password')->json('errors.password'),
        ];
        $this->assertSame(['The password field must contain at least one number.'], $messages[0]);
        $this->assertSame([$messages[0], $messages[0]], [$messages[1], $messages[2]]);
    }

    public function test_ac28_user_without_a_branch_can_reset_but_not_sign_in(): void
    {
        $u = User::factory()->create(['email' => 'lonely@x.com', 'status' => 'active']);
        $this->reset('lonely@x.com', $this->tokenFor($u))->assertNoContent();
        $this->login('lonely@x.com', 'newpass123')->assertForbidden()
            ->assertJsonPath('message', 'You do not have access to any branch. Contact your administrator.');
    }

    public function test_ac45_the_old_address_is_told_about_an_email_change(): void
    {
        // By a Super Admin.
        $u = $this->member('old@x.com');
        $token = $this->tokenFor($u);
        $this->asAdminIn($this->kdy)->putJson("/api/v1/users/{$u->id}", ['email' => 'new@x.com'])->assertOk();
        Notification::assertSentOnDemand(EmailChangedNotification::class, function ($n, $channels, $notifiable) {
            return $notifiable->routes['mail'] === 'old@x.com'
                && str_contains($n->text(), 'changed to new@x.com by Super Admin');
        });
        $this->reset('old@x.com', $token)->assertStatus(422)->assertJsonPath('errors.token.0', self::TOKEN_MESSAGE);

        // By a Branch Admin; an unchanged email sends nothing.
        $ba = $this->member('ba@x.com', 'Branch Admin');
        $c = $this->member('c@x.com');
        $this->flushHeaders()->actingAs($ba)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->putJson("/api/v1/users/{$c->id}", ['email' => 'c2@x.com'])->assertOk();
        $this->flushHeaders()->actingAs($ba)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->putJson("/api/v1/users/{$c->id}", ['email' => 'C2@x.com', 'name' => 'Renamed'])->assertOk();
        Notification::assertSentOnDemand(EmailChangedNotification::class,
            fn ($n, $ch, $notifiable) => $notifiable->routes['mail'] === 'c@x.com' && $n->newEmail === 'c2@x.com' && $n->admin === $ba->name);
        Notification::assertSentOnDemandTimes(EmailChangedNotification::class, 2);
    }
}
