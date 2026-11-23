<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\BankStatement;
use App\Models\PeriodLock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AG-033b bank statement copy (docs/specs/bank-statement-attachment.md): rules and presentation shared by the S1a
 * controller, the S1 History counts, the S3 reprint (screen, PDF, Excel) and the Lock confirm review.
 * It posts nothing and never writes ledger_entries, balances, marks or the stored statement figures.
 */
class StatementAttachments
{
    public const TZ = 'Asia/Colombo';

    public const UNDONE_ADD = 'This statement was undone. Attach the bank statement to the statement that replaces it.';

    public const UNDONE_REMOVE = 'Files of an undone statement cannot be removed.';

    public const NONE_REPRINT = 'No bank statement copy attached. File the paper bank statement with this printout.';

    public function __construct(private YearEnd $yearEnd) {}

    /** R4: the refusal for removing or replacing a file of this statement because of its date, or null. */
    public function closedMessage(BankStatement $s, bool $share = false): ?string
    {
        return $this->yearEnd->closedOrLockedMessage((int) $s->branch_id, $s->statement_date->format('Y-m-d'), share: $share);
    }

    /** F6 / F7 note of S1a, or null. */
    public function note(BankStatement $s): ?string
    {
        if ($s->status === BankStatement::UNDONE) {
            return self::UNDONE_ADD;
        }
        $date = $s->statement_date->format('Y-m-d');
        if ($closed = $this->yearEnd->closedYearOf($date)) {
            return "{$closed->label()} is closed: files can be added as evidence but not removed or replaced.";
        }
        if (PeriodLock::isLocked((int) $s->branch_id, $date)) {
            return PeriodLock::label(substr($date, 0, 7)).' is locked: files can be added as evidence but not removed or replaced.';
        }

        return null;
    }

    /**
     * R3 / finding 3: other statements of the same account holding the same active file (never across accounts or
     * branches), latest first.
     *
     * @return array<string, array<int, array{statement_id: int, statement_date: string, status: string}>> sha256 => list
     */
    public function alsoOn(Collection $shas, BankStatement $s): array
    {
        if ($shas->isEmpty()) {
            return [];
        }

        return DB::table('attachments as a')->join('bank_statements as s', 's.id', '=', 'a.bank_statement_id')
            ->whereIn('a.sha256', $shas->unique()->values())
            ->whereNull('a.removed_at')
            ->where('s.account_id', $s->account_id)
            ->where('s.id', '!=', $s->id)
            ->orderByDesc('s.statement_date')->orderByDesc('s.id')
            ->get(['a.sha256', 's.id', 's.statement_date', 's.status'])
            ->groupBy('sha256')
            ->map(fn ($g) => $g->unique('id')->map(fn ($r) => ['statement_id' => (int) $r->id,
                'statement_date' => substr((string) $r->statement_date, 0, 10), 'status' => $r->status])->values()->all())
            ->all();
    }

    /**
     * The API `Attachment` of a statement file: no path, no URL.
     *
     * @param  Collection<int, Attachment>  $rows
     */
    public function present(Collection $rows, BankStatement $s, ?User $user): array
    {
        $rows = (new EloquentCollection($rows->all()))->loadMissing(['uploader:id,name,status,is_super_admin', 'remover:id,name']);
        $members = DB::table('branch_user')->whereIn('user_id', $rows->pluck('uploaded_by')->unique())->where('branch_id', $s->branch_id)
            ->pluck('user_id')->flip();
        $alsoOn = $this->alsoOn($rows->whereNull('removed_at')->pluck('sha256'), $s);
        $names = Attachment::withoutGlobalScopes()->whereIn('id', $rows->pluck('replaced_by_id')->filter()->unique())->pluck('original_name', 'id');
        $removable = $user !== null && $s->status === BankStatement::COMPLETED && $user->canDo('account.reconcile') && $this->closedMessage($s) === null;

        return $rows->map(function (Attachment $a) use ($s, $members, $alsoOn, $names, $removable) {
            $u = $a->uploader;
            $tag = $u && $u->status !== 'active' ? 'inactive'
                : ($u && ! $u->is_super_admin && ! isset($members[$u->id]) ? 'no longer in this branch' : null);

            return [
                'id' => $a->id,
                'bank_statement_id' => (int) $a->bank_statement_id,
                'original_name' => $a->original_name,
                'mime' => $a->mime,
                'extension' => $a->extension(),
                'size_bytes' => $a->size_bytes,
                'sha256' => $a->sha256,
                'added_after' => $a->added_after,
                'by_preparer' => self::byPreparer($a, $s),
                'also_on' => $a->removed_at === null ? ($alsoOn[$a->sha256] ?? []) : [],
                'uploaded_by' => $u ? ['id' => $u->id, 'name' => $u->name, 'tag' => $tag] : null,
                'created_at' => $a->created_at?->toIso8601String(),
                'removed_at' => $a->removed_at?->toIso8601String(),
                'removed_by' => $a->remover ? ['id' => $a->remover->id, 'name' => $a->remover->name] : null,
                'removal_reason' => $a->removal_reason,
                'replaced_by_id' => $a->replaced_by_id,
                'replaced_by_name' => $a->replaced_by_id ? ($names[$a->replaced_by_id] ?? null) : null,
                'can_remove' => $a->removed_at === null && $removable,
            ];
        })->values()->all();
    }

    /** Finding 1: computed from frozen columns only (the uploader and the statement's preparer). */
    public static function byPreparer(Attachment $a, BankStatement $s): bool
    {
        return (int) $a->uploaded_by === (int) $s->completed_by;
    }

    /**
     * S1 History: active and removed file counts per statement.
     *
     * @return array<int, array{0: int, 1: int}> statement id => [active, removed]
     */
    public static function counts(array $statementIds): array
    {
        if (! $statementIds) {
            return [];
        }

        // only_full_group_by: group by the selected column, aggregate everything else.
        return DB::table('attachments')->whereIn('bank_statement_id', $statementIds)
            ->groupBy('bank_statement_id')
            ->selectRaw('bank_statement_id, SUM(CASE WHEN removed_at IS NULL THEN 1 ELSE 0 END) as active_n, '
                .'SUM(CASE WHEN removed_at IS NULL THEN 0 ELSE 1 END) as removed_n')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->bank_statement_id => [(int) $r->active_n, (int) $r->removed_n]])
            ->all();
    }

    // ------------------------------------------------------------------ S3 reprint text (PDF, Excel)

    /** "1.2 MB" / "200 KB" (integer arithmetic, as the web fileSize()). */
    public static function sizeText(int $bytes): string
    {
        if ($bytes < 1_048_576) {
            return max(1, intdiv($bytes + 512, 1024)).' KB';
        }
        $tenths = intdiv($bytes * 10 + 524_288, 1_048_576);

        return intdiv($tenths, 10).'.'.($tenths % 10).' MB';
    }

    public static function colombo(?string $iso, string $format = 'Y-m-d H:i'): string
    {
        return $iso ? CarbonImmutable::parse($iso)->setTimezone(self::TZ)->format($format) : '';
    }

    /** S3: the line of one file (full sha256), active or removed. */
    public static function reprintLine(array $a): string
    {
        if ($a['removed_at'] !== null) {
            $who = ', by '.($a['removed_by']['name'] ?? '-').' on '.self::colombo($a['removed_at'], 'Y-m-d').': '.$a['removal_reason'];

            return ($a['replaced_by_id'] ? 'Replaced by '.($a['replaced_by_name'] ?? '#'.$a['replaced_by_id']).': ' : 'Removed: ')
                ."{$a['original_name']}, sha256 {$a['sha256']}{$who}";
        }

        return "Bank statement attached: {$a['original_name']} (".strtoupper((string) $a['extension']).', '.self::sizeText((int) $a['size_bytes']).'), '
            .'uploaded by '.($a['uploaded_by']['name'] ?? '-').' on '.self::colombo($a['created_at']).", sha256 {$a['sha256']}"
            .self::tags($a);
    }

    /** Finding 1 and 3 tags of an active file on the reprint. */
    public static function tags(array $a): string
    {
        $out = '';
        if ($a['added_after'] === 'close') {
            $out .= ' - added after close';
        }
        if ($a['by_preparer']) {
            $out .= ' - uploaded by the preparer';
        }
        foreach ($a['also_on'] as $o) {
            $out .= " - also on the {$o['statement_date']} statement ({$o['status']})";
        }

        return $out;
    }

    /** @return string[] the S3 "Bank statement copy" block (active files first, then removed / replaced ones) */
    public static function reprintLines(array $attachments): array
    {
        if (! $attachments) {
            return [self::NONE_REPRINT];
        }
        $active = array_values(array_filter($attachments, fn ($a) => $a['removed_at'] === null));
        $removed = array_values(array_filter($attachments, fn ($a) => $a['removed_at'] !== null));
        $lines = array_map(self::reprintLine(...), [...$active, ...$removed]);

        return $active ? $lines : [self::NONE_REPRINT, ...$lines];
    }

    // ------------------------------------------------------------------ Lock confirm (finding 4)

    /**
     * Finding 4, information only: for bank statements dated in the months a lock newly closes (per branch, after that
     * branch's closed-through and up to $period), the removed statement files and the completed statements without an
     * active copy.
     *
     * @param  array<int, string>  $after  branch id => closed through ('' = nothing closed yet), branches newly closed only
     */
    public static function lockReview(string $period, array $after): array
    {
        $removed = 0;
        $withoutCopy = 0;
        if ($after) {
            $end = CarbonImmutable::createFromFormat('!Y-m', $period)->endOfMonth()->format('Y-m-d');
            $statements = DB::table('bank_statements')->whereIn('branch_id', array_keys($after))->whereDate('statement_date', '<=', $end)
                ->get(['id', 'branch_id', 'statement_date', 'status'])
                ->filter(fn ($s) => substr((string) $s->statement_date, 0, 7) > $after[(int) $s->branch_id]);
            $counts = self::counts($statements->pluck('id')->map(fn ($id) => (int) $id)->all());
            foreach ($statements as $s) {
                [$active, $gone] = $counts[(int) $s->id] ?? [0, 0];
                $removed += $gone;
                if ($s->status === BankStatement::COMPLETED && $active === 0) {
                    $withoutCopy++;
                }
            }
        }
        $lines = [];
        if ($removed) {
            $lines[] = "{$removed} bank statement ".($removed === 1 ? 'file' : 'files').' removed';
        }
        if ($withoutCopy) {
            $lines[] = "{$withoutCopy} completed bank ".($withoutCopy === 1 ? 'reconciliation' : 'reconciliations').' without a statement copy';
        }

        return ['statement_files_removed' => $removed, 'statements_without_copy' => $withoutCopy, 'statement_copy_lines' => $lines];
    }
}
