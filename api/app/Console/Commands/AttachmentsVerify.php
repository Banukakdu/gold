<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\BankStatement;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * AG-062 accounting review finding 9, the counterpart of ledger:verify. Run after every deploy and backup restore.
 * Checks that every attachment row (removed ones included: their files are kept) has its file on disk with the
 * stored sha256. Lists problems by entry ref (AG-033b: "KDY Bank 2026-10-31 (statement #12)" for a bank statement file) and
 * attachment id; exits 1 on any problem. Read-only.
 */
class AttachmentsVerify extends Command
{
    protected $signature = 'attachments:verify';

    protected $description = 'Check every attachment file exists and matches its stored sha256';

    public function handle(): int
    {
        $problems = [];
        $checked = 0;
        Attachment::withoutGlobalScopes()->orderBy('id')->chunkById(200, function ($rows) use (&$problems, &$checked) {
            $refs = Transaction::withoutGlobalScopes()->whereIn('id', $rows->pluck('transaction_id')->filter())->pluck('ref_no', 'id');
            $statements = BankStatement::withoutGlobalScopes()->with('account:id,name')->whereIn('id', $rows->pluck('bank_statement_id')->filter())
                ->get()->keyBy('id');
            foreach ($rows as $a) {
                $checked++;
                $problem = $this->check($a);
                if ($problem !== null) {
                    $problems[] = [$this->ref($a, $refs, $statements), $a->id, $a->original_name,
                        $a->removed_at ? 'removed' : 'active', $problem];
                }
            }
        });

        if ($problems) {
            $this->table(['Ref', 'Attachment', 'Name', 'State', 'Problem'], $problems);
            $this->error(count($problems)." of {$checked} attachment file(s) are missing or do not match the uploaded file.");

            return self::FAILURE;
        }
        $this->info("All {$checked} attachment file(s) are present and match their sha256.");

        return self::SUCCESS;
    }

    private function ref(Attachment $a, $refs, $statements): string
    {
        if ($a->bank_statement_id !== null) {
            $s = $statements[$a->bank_statement_id] ?? null;

            return $s ? trim(($s->account->name ?? '').' '.$s->statement_date->format('Y-m-d'))." (statement #{$s->id})" : "statement #{$a->bank_statement_id}";
        }

        return $refs[$a->transaction_id] ?? "#{$a->transaction_id}";
    }

    private function check(Attachment $a): ?string
    {
        try {
            $disk = Storage::disk($a->disk);
            if (! $disk->exists($a->path)) {
                return 'file missing';
            }
            $stream = $disk->readStream($a->path);
            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $stream);
            fclose($stream);
            $actual = hash_final($ctx);
        } catch (Throwable $e) {
            return 'cannot read file: '.$e->getMessage();
        }

        return hash_equals($a->sha256, $actual) ? null : "sha256 mismatch (file has {$actual})";
    }
}
