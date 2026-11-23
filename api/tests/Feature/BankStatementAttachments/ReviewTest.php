<?php

namespace Tests\Feature\BankStatementAttachments;

use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Services\StatementAttachments;
use Illuminate\Support\Carbon;

/** AG-033b reprint, history, audit log and Lock confirm: bank-statement-attachment.md AC 18-21, 26. */
class ReviewTest extends StatementFilesTestCase
{
    private function export(int $statementId, string $format)
    {
        return $this->as($this->acc, $this->kdy)->postJson('/api/v1/reports/bank-reconciliation/export', ['statement_id' => $statementId, 'format' => $format]);
    }

    private function xlsxText($response): string
    {
        $out = [];
        foreach ($this->xlsx($response)->getAllSheets() as $ws) {
            foreach ($ws->toArray(null, false, false) as $row) {
                $out[] = implode(' ', array_map(fn ($v) => (string) $v, $row));
            }
        }

        return implode("\n", $out);
    }

    public function test_ac18_entry_counts_and_lock_review_ignore_statement_files(): void
    {
        // A KDY entry file removed in October (E1, recorded by ACC) and a statement file removed from S1.
        $entry = $this->as($this->acc, $this->kdy)->withHeader('Accept', 'application/json')
            ->post("/api/v1/transactions/{$this->e1->id}/attachments", ['file' => $this->file('bill.pdf', self::bytes('bill.pdf'))])->assertCreated()->json();
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$this->e1->id}/attachments/{$entry['id']}/remove", ['reason' => 'Wrong bill'])->assertOk();
        $file = $this->attach('oct.pdf');
        $this->attach('page2.pdf');
        $this->remove($file['id'])->assertOk();

        $review = $this->sa()->getJson('/api/v1/period-locks/review?period=2026-10&branch_id='.$this->kdy->id)->assertOk();
        $this->assertSame(1, $review->json('files_removed'));
        $this->assertSame(1, $review->json('files_removed_self'));

        $rows = collect($this->as($this->acc, $this->kdy)->getJson('/api/v1/transactions?per_page=100')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame([0, 1], [$rows[$this->e1->id]['attachments_count'], $rows[$this->e1->id]['attachments_removed_count']]);
        foreach ([$this->i1, $this->e2, $this->i2] as $t) {
            $this->assertSame([0, 0], [$rows[$t->id]['attachments_count'], $rows[$t->id]['attachments_removed_count']]);
        }
        $with = collect($this->as($this->acc, $this->kdy)->getJson('/api/v1/transactions?files=with')->assertOk()->json('data'))->pluck('id');
        $this->assertCount(0, $with);
        $removed = collect($this->as($this->acc, $this->kdy)->getJson('/api/v1/transactions?files=removed')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$this->e1->id], $removed);
    }

    public function test_ac19_reprint_lines_on_screen_pdf_and_excel(): void
    {
        $sep = $this->attach('sep.pdf');
        $oct = $this->replace($sep['id'], 'oct.pdf', 'Wrong month')->assertCreated()->json();

        $st = $this->report(['statement_id' => $this->s1->id])->assertOk()->json('statement');
        $this->assertCount(2, $st['attachments']);
        $this->assertStringNotContainsString('path', json_encode($st['attachments']));
        $lines = StatementAttachments::reprintLines($st['attachments']);
        $uploaded = Carbon::parse($oct['created_at'])->setTimezone('Asia/Colombo')->format('Y-m-d H:i');
        $this->assertSame("Bank statement attached: oct.pdf (PDF, 64 KB), uploaded by {$this->acc->name} on {$uploaded}, sha256 {$oct['sha256']} - uploaded by the preparer", $lines[0]);
        $removedOn = Carbon::now()->setTimezone('Asia/Colombo')->format('Y-m-d');
        $this->assertSame("Replaced by oct.pdf: sep.pdf, sha256 {$sep['sha256']}, by {$this->acc->name} on {$removedOn}: Wrong month", $lines[1]);

        $pdf = implode(' ', $this->pdfPages($this->export($this->s1->id, 'pdf')->assertOk()->getContent()));
        $this->assertStringContainsString('Bank statement copy', $pdf);
        $this->assertStringContainsString("Bank statement attached: oct.pdf (PDF, 64 KB), uploaded by {$this->acc->name}", $pdf);
        $this->assertStringContainsString("sha256 {$oct['sha256']} - uploaded by the preparer", $pdf);
        $this->assertStringContainsString("Replaced by oct.pdf: sep.pdf, sha256 {$sep['sha256']}", $pdf);
        $this->assertLessThan(strpos($pdf, 'Prepared by'), strpos($pdf, 'Bank statement copy'));
        $this->assertGreaterThan(strpos($pdf, 'Difference'), strpos($pdf, 'Bank statement copy'));
        $xlsx = $this->xlsxText($this->export($this->s1->id, 'xlsx')->assertOk());
        foreach ($lines as $line) {
            $this->assertStringContainsString($line, $xlsx);
        }

        // Without files; and the plain As-at report has no block.
        $s2 = $this->statementTwo();
        $this->assertSame([], $this->report(['statement_id' => $s2->id])->json('statement.attachments'));
        $none = 'No bank statement copy attached. File the paper bank statement with this printout.';
        $this->assertStringContainsString($none, implode(' ', $this->pdfPages($this->export($s2->id, 'pdf')->assertOk()->getContent())));
        $this->assertStringContainsString($none, $this->xlsxText($this->export($s2->id, 'xlsx')->assertOk()));
        $this->assertNull($this->report(['as_at' => '2026-11-30'])->assertOk()->json('statement'));
        $plain = $this->as($this->acc, $this->kdy)->postJson('/api/v1/reports/bank-reconciliation/export', ['as_at' => '2026-11-30', 'format' => 'pdf'])->assertOk();
        $this->assertStringNotContainsString('Bank statement copy', implode(' ', $this->pdfPages($plain->getContent())));
    }

    public function test_ac20_history_counts(): void
    {
        $a = $this->attach('oct.pdf');
        $b = $this->attach('page2.pdf');
        $this->remove($b['id'])->assertOk();
        $s2 = $this->statementTwo();
        $statements = collect($this->screen(['statement_date' => '2026-11-30'])->assertOk()->json('statements'))->keyBy('id');
        $this->assertSame([1, 1], [$statements[$this->s1->id]['attachments_count'], $statements[$this->s1->id]['attachments_removed_count']]);
        $this->assertSame([0, 0], [$statements[$s2->id]['attachments_count'], $statements[$s2->id]['attachments_removed_count']]);
        $this->assertSame('completed', $statements[$s2->id]['status']); // shown as "No copy" (S1a)
        $this->assertSame($a['id'], $this->listing()->json('data.0.id'));
    }

    public function test_ac21_audit_log_group_and_subject(): void
    {
        $sep = $this->attach('sep.pdf');
        $this->replace($sep['id'], 'oct.pdf')->assertCreated();
        $this->download($sep['id'], $this->ba)->assertOk();
        $rows = collect($this->as($this->acc, $this->kdy)->getJson('/api/v1/audit-logs?from=2026-11-01&to=2026-11-30&event_group=attachments')->assertOk()->json('data'));
        $this->assertSame(['attachment.added', 'attachment.added', 'attachment.downloaded', 'attachment.removed'], $rows->pluck('event')->sort()->values()->all());
        foreach ($rows as $r) {
            $this->assertSame('11002 KDY Bank, statement 2026-10-31', $r['subject']);
            $this->assertSame('bank_statement', $r['new_values']['parent']);
            $this->assertSame('BankStatement', $r['auditable_type']);
        }
    }

    public function test_ac26_lock_confirm_lines_for_statement_evidence(): void
    {
        $this->attach('oct.pdf');
        $gone = $this->attach('page2.pdf');
        $this->remove($gone['id'])->assertOk();
        // A second KDY bank account with a completed 2026-10-31 statement and no file.
        $kdy2 = $this->bankIn($this->kdy, 'KDY Bank 2');
        $top = $this->allocate($kdy2, '1000.00', '2026-10-03');
        $this->tick([[$top, '2026-10-31']], acc: $kdy2)->assertOk();
        $this->complete('2026-10-31', '1000.00', acc: $kdy2)->assertCreated();

        $review = $this->sa()->getJson('/api/v1/period-locks/review?period=2026-10&branch_id='.$this->kdy->id)->assertOk();
        $this->assertSame(['1 bank statement file removed', '1 completed bank reconciliation without a statement copy'], $review->json('statement_copy_lines'));
        $this->assertSame([1, 1], [$review->json('statement_files_removed'), $review->json('statements_without_copy')]);
        $this->assertSame(0, $review->json('files_removed'), 'entry files only');
        // All branches: the same counts (GAL has no statement).
        $this->assertSame(['1 bank statement file removed', '1 completed bank reconciliation without a statement copy'],
            $this->sa()->getJson('/api/v1/period-locks/review?period=2026-10')->assertOk()->json('statement_copy_lines'));
        // Information only: the lock still works; afterwards October is no longer newly closed.
        $this->lock('2026-10');
        $this->assertSame([], $this->sa()->getJson('/api/v1/period-locks/review?period=2026-10&branch_id='.$this->kdy->id)->json('statement_copy_lines'));
        $this->assertSame(1, AuditLog::where('event', 'period.locked')->count());
        $this->assertInstanceOf(BankStatement::class, $this->s1->fresh());
    }
}
