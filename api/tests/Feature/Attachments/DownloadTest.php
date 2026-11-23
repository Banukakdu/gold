<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Transaction;
use Illuminate\Support\Facades\File;

/** AG-062 download: attachments spec AC 24-27 + accounting review AC 35. */
class DownloadTest extends AttachmentsTestCase
{
    public function test_ac24_bytes_and_headers(): void
    {
        $inv = self::pdf();
        $a = $this->attach('Fáktura 11.pdf', $inv);
        $this->assertSame('Fáktura 11.pdf', $a['original_name']);

        $res = $this->download($this->vw, $this->kdy, $this->e['id'], $a['id'])->assertOk();
        $this->assertSame($inv, $res->getContent());
        $this->assertSame($a['sha256'], hash('sha256', $res->getContent()));
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $cd = $res->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $cd);
        $this->assertStringNotContainsString('inline', $cd);
        $this->assertStringContainsString('filename="Faktura 11.pdf"', $cd);
        $this->assertStringContainsStringIgnoringCase("filename*=UTF-8''F%C3%A1ktura%2011.pdf", $cd);
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame('sandbox', $res->headers->get('Content-Security-Policy'));
        $cache = array_map('trim', explode(',', (string) $res->headers->get('Cache-Control')));
        $this->assertContains('private', $cache);
        $this->assertContains('no-store', $cache);
        $this->assertSame((string) strlen($inv), $res->headers->get('Content-Length'));
    }

    public function test_ac25_every_download_is_audited_list_calls_are_not(): void
    {
        $a = $this->attach('r1.jpg');
        $before = AuditLog::count();
        $this->listing($this->vw, $this->kdy, $this->e['id'])->assertOk();
        $this->listing($this->vw, $this->kdy, $this->e['id'], removed: true)->assertOk();
        $this->assertSame($before, AuditLog::count(), 'no audit row for list calls');

        $this->download($this->vw, $this->kdy, $this->e['id'], $a['id'])->assertOk();
        $row = AuditLog::where('event', 'attachment.downloaded')->sole();
        $this->assertSame([$this->vw->id, $this->kdy->id, Transaction::class, $this->e['id']], [$row->user_id, $row->branch_id, $row->auditable_type, $row->auditable_id]);
        $this->assertEquals(['attachment_id' => $a['id'], 'original_name' => 'r1.jpg', 'removed' => false], $row->new_values); // MySQL JSON reorders keys

        // "Open" calls the same endpoint: audited too.
        $this->download($this->csh, $this->kdy, $this->e['id'], $a['id'])->assertOk();
        $this->assertSame(2, AuditLog::where('event', 'attachment.downloaded')->count());
    }

    public function test_ac26_ac35_missing_file_is_410_and_audited(): void
    {
        $a = $this->attach('r1.jpg');
        $this->tamper($a['id'], null);
        $res = $this->download($this->vw, $this->kdy, $this->e['id'], $a['id'])
            ->assertStatus(410)->assertJsonPath('message', 'The file is missing from storage. Tell your administrator.');
        $this->assertStringNotContainsString('JFIF', $res->getContent());
        $row = AuditLog::where('event', 'attachment.missing')->sole();
        $this->assertSame([$this->kdy->id, $this->e['id'], $this->vw->id], [$row->branch_id, $row->auditable_id, $row->user_id]);
        $this->assertSame($a['id'], $row->new_values['attachment_id']);
        $this->assertSame(0, AuditLog::where('event', 'attachment.downloaded')->count());
        $this->assertNull(Attachment::find($a['id'])->removed_at, 'no attachment row is changed');
    }

    public function test_ac35_changed_file_is_409_and_audited(): void
    {
        $a = $this->attach('r1.jpg');
        $other = self::jpeg(seed: 'forged receipt');
        $this->tamper($a['id'], $other);
        $res = $this->download($this->vw, $this->kdy, $this->e['id'], $a['id'])
            ->assertStatus(409)->assertJsonPath('message', 'This file does not match the one uploaded. Tell your administrator.');
        $this->assertStringNotContainsString('JFIF', $res->getContent(), 'no file bytes are returned');
        $this->assertStringStartsWith('application/json', $res->headers->get('Content-Type'));

        $row = AuditLog::where('event', 'attachment.integrity_failed')->sole();
        $this->assertSame([$this->kdy->id, Transaction::class, $this->e['id']], [$row->branch_id, $row->auditable_type, $row->auditable_id]);
        $this->assertSame($a['sha256'], $row->old_values['sha256']);
        $this->assertSame(hash('sha256', $other), $row->new_values['sha256']);
        $this->assertSame(0, AuditLog::where('event', 'attachment.downloaded')->count());
    }

    public function test_files_are_read_only_on_disk(): void
    {
        $a = $this->attach('r1.jpg');
        $path = $this->disk()->path(Attachment::find($a['id'])->path);
        $this->assertSame('0440', substr(sprintf('%o', fileperms($path)), -4));
    }

    public function test_ac27_private_disk_without_urls(): void
    {
        $cfg = config('filesystems.disks.attachments');
        $this->assertSame('local', $cfg['driver']);
        $this->assertArrayNotHasKey('url', $cfg);
        $this->assertNotTrue($cfg['serve'] ?? false);
        $this->assertSame('private', $cfg['visibility']);
        $this->assertTrue($cfg['throw']);
        $this->assertStringStartsNotWith(storage_path('app/public'), $cfg['root']);
        $this->assertStringStartsNotWith(public_path(), $cfg['root']);
        $this->assertNotContains($cfg['root'], array_values(config('filesystems.links')));

        // Reviewer check, automated: no code builds a URL for a stored file, and only the R6 rollback deletes one.
        foreach (File::allFiles(app_path()) as $f) {
            $src = $f->getContents();
            $this->assertDoesNotMatchRegularExpression('/temporaryUrl\s*\(/', $src, $f->getRelativePathname());
            if (str_contains($src, 'Attachment::DISK') || str_contains($src, "'attachments'")) {
                $this->assertDoesNotMatchRegularExpression('/->url\s*\(/', $src, $f->getRelativePathname());
            }
        }
        $deletes = collect(File::allFiles(app_path()))->filter(fn ($f) => preg_match('/\$disk->delete\(|Storage::disk\([^)]*\)->delete\(/', $f->getContents()))
            ->map(fn ($f) => $f->getRelativePathname())->values()->all();
        // AG-092 R3: the logo store rolls back a logo file whose upload failed, the same exception as attachments.
        // AG-093 R7: local backup retention deletes archives on the `backups` disk only (backups AC 19); R5: the backup
        // store removes its own failed write there.
        // AG-087 R10: background exports delete on the `exports` disk only (prune, and the job's own .part file).
        // AG-088: scheduled report runs delete on the `exports` disk only (file retention, and the run's own .part file).
        // AG-033b: the bank statement copy upload has the same R6 rollback of a file that was never recorded.
        $this->assertSame(['Http/Controllers/Api/V1/AttachmentController.php', 'Http/Controllers/Api/V1/BankStatementAttachmentController.php', 'Services/BackgroundExports.php', 'Services/Backups/BackupPruner.php',
            'Services/Backups/LocalStore.php', 'Services/ScheduledReportRuns.php', 'Support/LogoStore.php'], $deletes);
    }
}
