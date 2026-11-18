<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Models\User;
use App\Services\YearEnd;
use App\Support\AttachmentFile;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

/**
 * AG-062 attachments on income and expense entries (docs/specs/attachments.md + accounting review).
 * Evidence only: nothing here touches ledger_entries, balances or reports, so it does not go through LedgerService.
 * {transaction} resolves through BranchScoped (another branch -> 404); {attachment} is scoped to it (->scopeBindings()).
 */
class AttachmentController extends Controller
{
    public const ENTRY_TYPES = [Transaction::INCOME, Transaction::EXPENSE];

    public function index(Request $request, Transaction $transaction)
    {
        $user = $request->user();
        if (! $this->isEntry($transaction)) {
            return ['data' => [], 'can_add' => false, 'max' => Attachment::MAX_ACTIVE, 'max_total' => Attachment::MAX_TOTAL,
                'active_count' => 0, 'removed_count' => 0, 'total_uploaded' => 0, 'can_download_removed' => false, 'note' => null];
        }
        $rows = $transaction->attachments()->with(['uploader:id,name,status,is_super_admin', 'remover:id,name'])->orderBy('id')->get();
        $active = $rows->whereNull('removed_at');
        $locked = $this->isLocked($transaction);
        $shown = $request->boolean('include_removed') ? $rows : $active;

        return [
            'data' => $this->present($shown, $transaction, $user, $locked),
            'can_add' => $this->canAdd($user, $transaction),
            'max' => Attachment::MAX_ACTIVE,
            'max_total' => Attachment::MAX_TOTAL,
            'active_count' => $active->count(),
            'removed_count' => $rows->count() - $active->count(),
            'total_uploaded' => $rows->count(),
            'can_download_removed' => $user->canDo('audit.view'),
            'note' => $this->note($transaction, $locked),
        ];
    }

    public function store(Request $request, Transaction $transaction)
    {
        $this->assertEntry($transaction);
        $user = $request->user();
        if (! $this->canAdd($user, $transaction)) {
            abort(403, $user->canDo("{$transaction->type}.create")
                ? 'You can attach files only to entries you recorded.'
                : "Missing permission: {$transaction->type}.edit");
        }
        [$file, $type, $name] = AttachmentFile::inspect($request->file('file'), (int) $request->server('CONTENT_LENGTH'));
        [$mime, $ext] = Attachment::TYPES[$type];
        $sha = hash_file('sha256', $file->getPathname());
        $size = (int) $file->getSize();

        $disk = Storage::disk(Attachment::DISK);
        $dir = "{$transaction->branch_id}/{$transaction->id}";
        $basename = Str::uuid()->toString().".{$ext}";
        $path = "{$dir}/{$basename}";
        $written = false;

        try {
            [$attachment, $alsoOn] = DB::transaction(function () use ($transaction, $user, $file, $name, $mime, $sha, $size, $disk, $dir, $basename, $path, &$written) {
                // R3: the row lock serialises parallel uploads to one entry, so two of them cannot make 6.
                $txn = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
                $existing = Attachment::where('transaction_id', $txn->id)->get(['id', 'sha256', 'removed_at']);
                $active = $existing->whereNull('removed_at');
                abort_if($active->count() >= Attachment::MAX_ACTIVE, 422, 'An entry can have at most 5 files. Remove one first.');
                abort_if($existing->count() >= Attachment::MAX_TOTAL, 422, 'This entry already had 10 files uploaded (including removed ones). No more can be added.');
                abort_if($active->contains('sha256', $sha), 422, "This file is already attached to {$txn->ref_no}.");

                // Finding 4: evidence added after the close or after the void is marked on the row itself (frozen).
                $date = $txn->txn_date->format('Y-m-d');
                $locked = PeriodLock::isLocked($txn->branch_id, $date, share: true);
                $addedAfter = match (true) {
                    $txn->status === 'void' => 'void',
                    $txn->status === 'reversed' => 'reversal',
                    $locked => 'close',
                    default => null,
                };

                $disk->putFileAs($dir, $file, $basename);
                $written = true;
                // Finding 2: read-only on disk. (Never deleted, except the rollback below of a file that was never recorded.)
                @chmod($disk->path($path), 0440);

                $attachment = Attachment::create([
                    'branch_id' => $txn->branch_id, // R1: the entry's branch, never the X-Branch-Id header
                    'transaction_id' => $txn->id,
                    'disk' => Attachment::DISK,
                    'path' => $path,
                    'original_name' => $name,
                    'mime' => $mime,
                    'size_bytes' => $size,
                    'sha256' => $sha,
                    'added_after' => $addedAfter,
                    'uploaded_by' => $user->id,
                ]);
                $alsoOn = $this->alsoOn(collect([$sha]), $txn)[$sha] ?? [];

                AuditLog::record('attachment.added', $txn, null, [
                    'attachment_id' => $attachment->id,
                    'ref_no' => $txn->ref_no,
                    'original_name' => $name,
                    'mime' => $mime,
                    'size_bytes' => $size,
                    'sha256' => $sha,
                    'txn_status' => $txn->status,
                    'period_locked' => $locked,
                    'own_entry' => (int) $txn->created_by === (int) $user->id,
                    'added_after' => $addedAfter,
                    'also_on' => $alsoOn,
                ], $txn->branch_id);

                return [$attachment, $alsoOn];
            });
        } catch (Throwable $e) {
            // R6: the file was written but never recorded, so removing it is not a record deletion.
            if ($written) {
                @chmod($disk->path($path), 0640);
                $disk->delete($path);
            }
            throw $e;
        }

        $attachment->load(['uploader:id,name,status,is_super_admin', 'remover:id,name']);
        $out = $this->present(collect([$attachment]), $transaction->fresh(), $user, $this->isLocked($transaction))[0];
        $out['also_on'] = $alsoOn;

        return response()->json($out, 201);
    }

    public function download(Request $request, Transaction $transaction, Attachment $attachment)
    {
        $removed = $attachment->removed_at !== null;
        abort_if($removed && ! $request->user()->canDo('audit.view'), 403, 'Only auditors can download removed files.');

        $ids = ['attachment_id' => $attachment->id, 'original_name' => $attachment->original_name];
        try {
            $disk = Storage::disk($attachment->disk);
            $bytes = $disk->exists($attachment->path) ? $disk->get($attachment->path) : null;
        } catch (Throwable $e) {
            $bytes = null;
            $readError = $e->getMessage();
        }
        if ($bytes === null) {
            // Reviewer N6: keep the reason a read failed (permissions, I/O) for the administrator.
            Log::error("Attachment #{$attachment->id} ({$transaction->ref_no}): file missing from storage.",
                ['path' => $attachment->path, 'error' => $readError ?? 'file does not exist']);
            AuditLog::record('attachment.missing', $transaction, null, $ids + ['sha256' => $attachment->sha256], $transaction->branch_id);

            return response()->json(['message' => 'The file is missing from storage. Tell your administrator.'], 410);
        }
        // Finding 2: the bytes we would stream are the bytes we hash (read once, at most 5 MB).
        $actual = hash('sha256', $bytes);
        if (! hash_equals($attachment->sha256, $actual)) {
            Log::error("Attachment #{$attachment->id} ({$transaction->ref_no}): sha256 mismatch.", ['path' => $attachment->path]);
            AuditLog::record('attachment.integrity_failed', $transaction, $ids + ['sha256' => $attachment->sha256], $ids + ['sha256' => $actual], $transaction->branch_id);

            return response()->json(['message' => 'This file does not match the one uploaded. Tell your administrator.'], 409);
        }

        AuditLog::record('attachment.downloaded', $transaction, null, $ids + ['removed' => $removed], $transaction->branch_id);

        return response($bytes, 200, [
            'Content-Type' => $attachment->mime,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $attachment->original_name,
                AttachmentFile::asciiFallback($attachment->original_name)),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => 'sandbox',
        ]);
    }

    public function remove(Request $request, Transaction $transaction, Attachment $attachment)
    {
        $this->assertEntry($transaction);
        $user = $request->user();
        if (! $this->mayRemove($user, $transaction, $attachment)) {
            abort(403, $user->canDo("{$transaction->type}.create")
                ? 'You can remove only files you uploaded.'
                : "Missing permission: {$transaction->type}.edit");
        }
        $reason = $request->validate(['reason' => 'required|string|min:3|max:500'])['reason'];

        $row = DB::transaction(function () use ($transaction, $attachment, $user, $reason) {
            $txn = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $att = Attachment::whereKey($attachment->id)->lockForUpdate()->firstOrFail();
            abort_if($att->removed_at !== null, 422, 'This file has already been removed.');
            abort_if($txn->status !== 'posted', 422, 'Files of a voided or reversed entry cannot be removed.');
            // Finding 3: the entry's own date and branch, cumulative rule, locking read inside the write.
            $date = $txn->txn_date->format('Y-m-d');
            if ($refused = app(YearEnd::class)->closedOrLockedMessage($txn->branch_id, $date, share: true)) {
                abort(422, $refused);
            }

            $att->update(['removed_at' => now(), 'removed_by' => $user->id, 'removal_reason' => $reason]);
            AuditLog::record('attachment.removed', $txn, [
                'attachment_id' => $att->id,
                'original_name' => $att->original_name,
                'sha256' => $att->sha256,
                'uploaded_by' => $att->uploaded_by,
                'uploaded_at' => $att->created_at?->toIso8601String(),
                'added_after' => $att->added_after,
            ], [
                'reason' => $reason,
                'removed_at' => $att->removed_at->toIso8601String(),
                'self' => (int) $att->uploaded_by === (int) $user->id,
                'txn_status' => $txn->status,
            ], $txn->branch_id);

            return $att;
        });

        $row->load(['uploader:id,name,status,is_super_admin', 'remover:id,name']);

        return $this->present(collect([$row]), $transaction, $user, $this->isLocked($transaction))[0];
    }

    // ------------------------------------------------------------------ rules

    private function isEntry(Transaction $t): bool
    {
        return in_array($t->type, self::ENTRY_TYPES, true);
    }

    /** R2: type first, like void. */
    private function assertEntry(Transaction $t): void
    {
        abort_unless($this->isEntry($t), 422, 'Files can only be attached to income and expense entries.');
    }

    private function canAdd(User $u, Transaction $t): bool
    {
        return $this->isEntry($t) && ($u->canDo("{$t->type}.edit")
            || ($u->canDo("{$t->type}.create") && (int) $t->created_by === (int) $u->id));
    }

    /** R2 rights only (state rules are separate). */
    private function mayRemove(User $u, Transaction $t, Attachment $a): bool
    {
        return $u->canDo("{$t->type}.edit") || ($u->canDo("{$t->type}.create") && (int) $a->uploaded_by === (int) $u->id);
    }

    private function isLocked(Transaction $t): bool
    {
        return PeriodLock::isLocked($t->branch_id, $t->txn_date->format('Y-m-d'));
    }

    /** F6 note on S2. */
    private function note(Transaction $t, bool $locked): ?string
    {
        if ($t->status === 'void') {
            return 'This entry is void: files can be added but not removed.';
        }
        if ($t->status === 'reversed') {
            return 'This entry is reversed: files can be added but not removed.';
        }
        if ($locked) {
            return PeriodLock::label($t->txn_date->format('Y-m')).' is locked: files can be added as evidence but not removed.';
        }

        return null;
    }

    /**
     * Finding 7: other income/expense entries holding the same active file. The entry's branch only; a Super Admin
     * in All branches mode sees every branch. Never across branches for anyone else (that would leak them).
     *
     * @return array<string, string[]> sha256 => ref_nos
     */
    private function alsoOn(Collection $shas, Transaction $txn): array
    {
        if ($shas->isEmpty()) {
            return [];
        }
        $ctx = app(BranchContext::class);

        return Attachment::query()
            ->join('transactions', 'transactions.id', '=', 'attachments.transaction_id')
            ->whereIn('attachments.sha256', $shas->unique()->values())
            ->whereNull('attachments.removed_at')
            ->where('attachments.transaction_id', '!=', $txn->id)
            ->whereIn('transactions.type', self::ENTRY_TYPES)
            ->when($ctx->isScoped(), fn ($q) => $q->where('attachments.branch_id', $txn->branch_id))
            ->orderBy('transactions.ref_no')
            ->get(['attachments.sha256', 'transactions.ref_no'])
            ->groupBy('sha256')
            ->map(fn ($g) => $g->pluck('ref_no')->unique()->values()->all())
            ->all();
    }

    // ------------------------------------------------------------------ presentation

    /** The API `Attachment`: no path, no URL. */
    private function present(Collection $rows, Transaction $txn, User $user, bool $locked): array
    {
        $members = DB::table('branch_user')->whereIn('user_id', $rows->pluck('uploaded_by')->unique())->where('branch_id', $txn->branch_id)
            ->pluck('user_id')->flip();
        $alsoOn = $this->alsoOn($rows->whereNull('removed_at')->pluck('sha256'), $txn);
        $removable = $txn->status === 'posted' && ! $locked;

        return $rows->map(function (Attachment $a) use ($txn, $user, $members, $alsoOn, $removable) {
            $u = $a->uploader;
            $tag = $u && $u->status !== 'active' ? 'inactive'
                : ($u && ! $u->is_super_admin && ! isset($members[$u->id]) ? 'no longer in this branch' : null);

            return [
                'id' => $a->id,
                'transaction_id' => $a->transaction_id,
                'original_name' => $a->original_name,
                'mime' => $a->mime,
                'extension' => $a->extension(),
                'size_bytes' => $a->size_bytes,
                'sha256' => $a->sha256,
                'uploaded_by' => $u ? ['id' => $u->id, 'name' => $u->name, 'tag' => $tag] : null,
                'created_at' => $a->created_at?->toIso8601String(),
                'added_after' => $a->added_after,
                'also_on' => $a->removed_at === null ? ($alsoOn[$a->sha256] ?? []) : [],
                'removed_at' => $a->removed_at?->toIso8601String(),
                'removed_by' => $a->remover ? ['id' => $a->remover->id, 'name' => $a->remover->name] : null,
                'removal_reason' => $a->removal_reason,
                'can_remove' => $a->removed_at === null && $removable && $this->mayRemove($user, $txn, $a),
            ];
        })->values()->all();
    }
}
