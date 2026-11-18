<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/** AC 21: a mail transport failure never fails N2 (R13). Real notifications, a transport that always throws. */
class ResetMailFailureTest extends PasswordResetTestCase
{
    protected bool $fakeNotifications = false;

    public function test_ac21_mail_failure_does_not_fail_the_reset(): void
    {
        Mail::extend('broken', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('SMTP is down');
            }

            public function __toString(): string
            {
                return 'broken://';
            }
        });
        config(['mail.mailers.broken' => ['transport' => 'broken'], 'mail.default' => 'broken']);
        $u = $this->member('ba@x.com', 'Branch Admin'); // also triggers the Super Admin alert mail
        $token = Password::broker()->createToken($u);

        Exceptions::fake();
        $this->reset('ba@x.com', $token)->assertNoContent();
        Exceptions::assertReported(TransportException::class); // the transport really ran and failed

        $this->assertTrue(AuditLog::where('event', 'auth.password_reset')->where('auditable_id', $u->id)->exists());
        $this->login('ba@x.com', 'newpass123')->assertOk();
    }
}
