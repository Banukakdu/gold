<?php

namespace Tests\Feature\BankStatementAttachments;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\BankStatement;
use Illuminate\Support\Facades\Artisan;

/** AG-033b download checks and attachments:verify: bank-statement-attachment.md AC 14, 15. */
class DownloadTest extends StatementFilesTestCase
{
    public function test_ac14_changed_and_missing_files(): void
    {
        $changed = $this->attach('oct.pdf');
        $missing = $this->attach('page2.pdf');
        $this->tamper($changed['id'], $forged = self::pdf(seed: 'forged statement'));
        $this->tamper($missing['id'], null);

        $res = $this->download($changed['id'], $this->vw)->assertStatus(409)
            ->assertJsonPath('message', 'This file does not match the one uploaded. Tell your administrator.');
        $this->assertStringNotContainsString('%PDF', $res->getContent());
        $row = AuditLog::where('event', 'attachment.integrity_failed')->sole();
        $this->assertSame([$this->kdy->id, BankStatement::class, $this->s1->id], [$row->branch_id, $row->auditable_type, $row->auditable_id]);
        $this->assertSame($changed['sha256'], $row->old_values['sha256']);
        $this->assertSame(hash('sha256', $forged), $row->new_values['sha256']);
        $this->assertSame('bank_statement', $row->new_values['parent']);

        $this->download($missing['id'], $this->vw)->assertStatus(410)
            ->assertJsonPath('message', 'The file is missing from storage. Tell your administrator.');
        $row = AuditLog::where('event', 'attachment.missing')->sole();
        $this->assertSame([$this->kdy->id, BankStatement::class, $this->s1->id], [$row->branch_id, $row->auditable_type, $row->auditable_id]);
        $this->assertSame($missing['id'], $row->new_values['attachment_id']);
        $this->assertSame(0, AuditLog::where('event', 'attachment.downloaded')->count());

        $exit = Artisan::call('attachments:verify');
        $out = Artisan::output();
        $this->assertSame(1, $exit);
        $ref = "KDY Bank 2026-10-31 (statement #{$this->s1->id})";
        $this->assertSame(2, substr_count($out, $ref), $out);
        $this->assertMatchesRegularExpression("/\\| {$this->escape($ref)}\\s*\\| {$changed['id']}\\s*\\|.*sha256 mismatch/", $out);
        $this->assertMatchesRegularExpression("/\\| {$this->escape($ref)}\\s*\\| {$missing['id']}\\s*\\|.*file missing/", $out);
    }

    private function escape(string $v): string
    {
        return preg_quote($v, '/');
    }

    public function test_ac15_bytes_headers_and_audit(): void
    {
        $bytes = self::pdf(seed: 'faktura');
        $a = $this->attach('Fáktura 11.pdf', $bytes);
        $this->assertSame('Fáktura 11.pdf', $a['original_name']);
        $before = AuditLog::count();
        $this->listing($this->vw)->assertOk();
        $this->listing($this->vw, removed: true)->assertOk();
        $this->assertSame($before, AuditLog::count(), 'no audit row for list calls');

        $res = $this->download($a['id'], $this->vw)->assertOk();
        $this->assertSame($bytes, $res->getContent());
        $this->assertSame($a['sha256'], hash('sha256', $res->getContent()));
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $cd = $res->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $cd);
        $this->assertStringContainsString('filename="Faktura 11.pdf"', $cd);
        $this->assertStringContainsStringIgnoringCase("filename*=UTF-8''F%C3%A1ktura%2011.pdf", $cd);
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame('sandbox', $res->headers->get('Content-Security-Policy'));
        $cache = array_map('trim', explode(',', (string) $res->headers->get('Cache-Control')));
        $this->assertContains('private', $cache);
        $this->assertContains('no-store', $cache);
        $this->assertSame((string) strlen($bytes), $res->headers->get('Content-Length'));

        $row = AuditLog::where('event', 'attachment.downloaded')->sole();
        $this->assertSame([$this->vw->id, $this->kdy->id, BankStatement::class, $this->s1->id], [$row->user_id, $row->branch_id, $row->auditable_type, $row->auditable_id]);
        $this->assertEquals(['attachment_id' => $a['id'], 'original_name' => 'Fáktura 11.pdf', 'removed' => false, 'parent' => 'bank_statement',
            'account_id' => $this->kdyBank->id, 'statement_date' => '2026-10-31', 'statement_status' => 'completed'], $row->new_values);
        $this->assertSame(1, Attachment::count());
    }

    public function test_ac14_verify_passes_when_files_match(): void
    {
        $this->attach('oct.pdf');
        $this->artisan('attachments:verify')->assertExitCode(0);
    }
}
