<?php

namespace App\Notifications\Channels;

use App\Notifications\ScheduledReportMail;
use App\Services\ScheduledReportRuns;
use Illuminate\Notifications\Channels\MailChannel;
use Throwable;

/**
 * AG-088 (review S1): the mail channel of a scheduled report, wrapped so an email is sent at most once.
 *
 * 1. prepareSend() re-checks the delivery (R5 at send, A2, R8) and claims it: `queued -> sending` in one conditional
 *    UPDATE. A retry, or a second worker that reserved the same job after `retry_after`, finds `sending` or `sent` and
 *    sends nothing.
 * 2. The mailer throws (the email was not accepted): the claim is released (`sending -> queued`) and the exception goes
 *    to the queue, which retries; the last try marks the delivery failed (ScheduledReportMail::failed).
 * 3. The mailer accepted it: markSent (`sending -> sent`). A database error there is reported and swallowed, so the job
 *    is never retried after the email went out; the delivery stays `sending` and the dispatcher later marks it failed
 *    with `unknown` ("may have been sent"), for a person to check.
 */
class ScheduledReportChannel
{
    public function __construct(private MailChannel $mail) {}

    public function send(object $notifiable, ScheduledReportMail $notification): void
    {
        $runs = app(ScheduledReportRuns::class);
        $ok = $runs->prepareSend($notification->deliveryId);
        if ($ok === null) {
            return;
        }
        $notification->pdf = $ok['pdf'];
        try {
            $this->mail->send($notifiable, $notification);
        } catch (Throwable $e) {
            $runs->releaseSend($notification->deliveryId);
            throw $e;
        } finally {
            $notification->pdf = null;
        }
        try {
            $runs->markSent($notification->deliveryId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
