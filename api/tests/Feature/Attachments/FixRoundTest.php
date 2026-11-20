<?php

namespace Tests\Feature\Attachments;

use App\Models\AuditLog;
use App\Providers\AppServiceProvider;
use App\Support\AttachmentFile;
use Illuminate\Support\Facades\Log;

/** Attachments fix round: reviewer S1, N1, N4, N6 and QA Q2. */
class FixRoundTest extends AttachmentsTestCase
{
    public function test_s1_downloads_are_rate_limited_per_user(): void
    {
        $a = $this->attach('r1.jpg');
        foreach (range(1, AppServiceProvider::ATTACHMENT_DOWNLOADS_PER_MINUTE) as $i) {
            $this->download($this->vw, $this->kdy, $this->e['id'], $a['id'])->assertOk();
        }
        $this->download($this->vw, $this->kdy, $this->e['id'], $a['id'])->assertStatus(429);
        $this->assertSame(AppServiceProvider::ATTACHMENT_DOWNLOADS_PER_MINUTE, AuditLog::where('event', 'attachment.downloaded')->count());
        // Per user: another user is not affected, and uploads have their own budget.
        $this->download($this->acc, $this->kdy, $this->e['id'], $a['id'])->assertOk();
        $this->attach('r2.jpg');
    }

    public function test_n4_uploads_are_rate_limited_per_user(): void
    {
        foreach (range(1, AppServiceProvider::ATTACHMENT_UPLOADS_PER_MINUTE) as $i) {
            $this->upload($this->csh, $this->kdy, $this->e['id'], $this->file('empty.pdf', ''))->assertStatus(422);
        }
        $this->upload($this->csh, $this->kdy, $this->e['id'], $this->file('r1.jpg', self::jpeg()))->assertStatus(429);
        $this->attach('r1.jpg', u: $this->acc); // another user
        $this->download($this->csh, $this->kdy, $this->e['id'], $this->listing($this->csh, $this->kdy, $this->e['id'])->json('data.0.id'))->assertOk();
    }

    public function test_q2_body_over_post_max_size_is_the_5_mb_message_on_the_upload_route(): void
    {
        $tooBig = ['CONTENT_LENGTH' => (string) (1024 ** 3)];
        $this->as($this->csh, $this->kdy)->withServerVariables($tooBig)->withHeader('Accept', 'application/json')
            ->post("/api/v1/transactions/{$this->e['id']}/attachments")
            ->assertStatus(422)->assertJsonPath('message', 'Each file may be at most 5 MB.')->assertJsonValidationErrors('file');
        // Other routes keep Laravel's 413.
        $this->as($this->csh, $this->kdy)->withServerVariables($tooBig)->withHeader('Accept', 'application/json')
            ->post('/api/v1/expenses')->assertStatus(413);
        $this->assertSame([], $this->disk()->allFiles());
    }

    public function test_n1_ascii_fallback_is_never_a_bare_extension(): void
    {
        $this->assertSame('attachment.pdf', AttachmentFile::asciiFallback('票据.pdf'));
        $this->assertSame('Faktura 11.pdf', AttachmentFile::asciiFallback('Fáktura 11.pdf'));
        $this->assertSame('a_b_c.pdf', AttachmentFile::asciiFallback('a%b/c.pdf'));

        $a = $this->attach('票据.pdf', self::pdf(4096, 'cn'));
        $cd = $this->download($this->vw, $this->kdy, $this->e['id'], $a['id'])->assertOk()->headers->get('Content-Disposition');
        $this->assertStringContainsString('filename=attachment.pdf', $cd);
        $this->assertStringContainsStringIgnoringCase("filename*=UTF-8''%E7%A5%A8%E6%8D%AE.pdf", $cd);
    }

    public function test_n6_a_failed_read_is_logged_with_its_reason(): void
    {
        $a = $this->attach('r1.jpg');
        $this->tamper($a['id'], null);
        Log::spy();
        $this->download($this->vw, $this->kdy, $this->e['id'], $a['id'])->assertStatus(410);
        Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx) => str_contains($msg, "Attachment #{$a['id']}") && isset($ctx['error']))->once();
    }
}
