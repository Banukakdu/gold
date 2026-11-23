<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Models\PeriodLock;
use App\Models\User;
use App\Services\BankReconciliation;
use App\Services\StatementAttachments;
use App\Services\YearEnd;
use App\Support\AttachmentFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * AG-033b bank statement copy on a completed reconciliation statement (docs/specs/bank-statement-attachment.md).
 * Reuses the AG-062 machinery: the `attachments` table and disk, AttachmentFile::inspect, the download checks and headers.
 * Evidence only: it posts nothing, so it does not go through LedgerService. {account} resolves through BranchScoped,
 * {statement} and {attachment} through scoped bindings (another account / statement -> 404). The section 2 rules are
 * checked here (the routes are outside the perm:account.reconcile group: list and download also accept report.view).
 */
class BankStatementAttachmentController extends Controller
{
    public function __construct(private StatementAttachments $files, private BankReconciliation $recon) {}

    public function index(Request $request, Account $account, BankStatement $statement)
    {
        $user = $request->user();
        $this->assertRead($user);
        $this->recon->assertBank($account);

        $rows = $statement->attachments()->orderBy('id')->get();
        $active = $rows->whereNull('removed_at');
        $writable = $user->canDo('account.reconcile') && $statement->status === BankStatement::COMPLETED;
        $shown = $request->boolean('include_removed') ? $rows : $active;

        return [
            'data' => $this->files->present($shown, $statement, $user),
            'can_add' => $writable,
            'can_remove' => $writable && $this->files->closedMessage($statement) === null,
            'max' => Attachment::MAX_ACTIVE,
            'max_total' => Attachment::MAX_TOTAL,
            'active_count' => $active->count(),
            'removed_count' => $rows->count() - $active->count(),
            'total_uploaded' => $rows->count(),
            'can_download_removed' => $user->canDo('audit.view'),
            'note' => $this->files->note($statement),
        ];
    }

    public function store(Request $request, Account $account, BankStatement $statement)
    {
        $user = $request->user();
        $this->assertWrite($user);
        $this->recon->assertBank($account);
        $replacing = $request->filled('replaces_id');
        $d = $request->validate([
            'replaces_id' => 'nullable|integer',
            'reason' => ($replacing ? 'required' : 'nullable').'|string|min:3|max:500',
        ]);
        $replacesId = $replacing ? (int) $d['replaces_id'] : null;
        $reason = $replacing ? trim((string) $d['reason']) : null;

        [$file, $type, $name] = AttachmentFile::inspect($request->file('file'), (int) $request->server('CONTENT_LENGTH'));
        [$mime, $ext] = Attachment::TYPES[$type];
        $sha = hash_file('sha256', $file->getPathname());
        $size = (int) $file->getSize();

        $disk = Storage::disk(Attachment::DISK);
        $dir = "{$statement->branch_id}/bank-statements/{$statement->id}";
        $basename = Str::uuid()->toString().".{$ext}";
        $path = "{$dir}/{$basename}";
        $written = false;

        try {
            [$attachment, $replaced] = DB::transaction(function () use ($statement, $user, $file, $name, $mime, $sha, $size, $disk, $dir, $basename, $path, $replacesId, $reason, &$written) {
                // R3: the statement row lock serialises parallel uploads to one statement (its figures are read only).
                $st = BankStatement::withoutGlobalScopes()->whereKey($statement->id)->lockForUpdate()->firstOrFail();
                abort_if($st->status !== BankStatement::COMPLETED, 422, StatementAttachments::UNDONE_ADD);
                $old = null;
                if ($replacesId !== null) {
                    $old = Attachment::withoutGlobalScopes()->whereKey($replacesId)->where('bank_statement_id', $st->id)
                        ->whereNull('removed_at')->lockForUpdate()->first();
                    abort_if($old === null, 422, 'Choose an active file of this statement to replace.');
                    // R4: the statement date, cumulative locks, closed year; locking read inside the write.
                    if ($refused = $this->files->closedMessage($st, share: true)) {
                        abort(422, $refused);
                    }
                }
                $existing = Attachment::withoutGlobalScopes()->where('bank_statement_id', $st->id)->get(['id', 'sha256', 'removed_at']);
                $active = $existing->whereNull('removed_at');
                // A replace does not grow the active count.
                abort_if($old === null && $active->count() >= Attachment::MAX_ACTIVE, 422, 'A statement can have at most 5 files. Remove one first.');
                abort_if($existing->count() >= Attachment::MAX_TOTAL, 422, 'This statement already had 10 files uploaded (including removed ones). No more can be added.');
                abort_if($active->contains('sha256', $sha), 422, 'This file is already attached to this statement.');

                // Finding 2: added_after from the same locking read as the lock check.
                $date = $st->statement_date->format('Y-m-d');
                $closed = PeriodLock::isLocked((int) $st->branch_id, $date, share: true) || app(YearEnd::class)->closedYearOf($date, share: true) !== null;
                $addedAfter = $closed ? 'close' : null;

                $disk->putFileAs($dir, $file, $basename);
                $written = true;
                @chmod($disk->path($path), 0440);

                $attachment = Attachment::create([
                    'branch_id' => $st->branch_id, // R1: the statement's branch, never the X-Branch-Id header
                    'transaction_id' => null,
                    'bank_statement_id' => $st->id,
                    'disk' => Attachment::DISK,
                    'path' => $path,
                    'original_name' => $name,
                    'mime' => $mime,
                    'size_bytes' => $size,
                    'sha256' => $sha,
                    'added_after' => $addedAfter,
                    'uploaded_by' => $user->id,
                ]);
                $alsoOn = $this->files->alsoOn(collect([$sha]), $st)[$sha] ?? [];
                $context = $this->context($st);
                AuditLog::record('attachment.added', $st, null, $context + [
                    'attachment_id' => $attachment->id,
                    'original_name' => $name,
                    'mime' => $mime,
                    'size_bytes' => $size,
                    'sha256' => $sha,
                    'added_after' => $addedAfter,
                    'by_preparer' => StatementAttachments::byPreparer($attachment, $st),
                    'also_on' => $alsoOn,
                    'replaces_id' => $old?->id,
                ], $st->branch_id);

                if ($old !== null) {
                    $this->markRemoved($st, $old, $user, (string) $reason, $attachment->id);
                }

                return [$attachment, $old];
            });
        } catch (Throwable $e) {
            // AG-062 R6: the file was written but never recorded, so removing it is not a record deletion.
            if ($written) {
                @chmod($disk->path($path), 0640);
                $disk->delete($path);
            }
            throw $e;
        }

        $st = $statement->fresh();
        $out = $this->files->present(collect([$attachment]), $st, $user)[0];
        if ($replaced !== null) {
            $out['replaced'] = $this->files->present(collect([$replaced->fresh()]), $st, $user)[0];
        }

        return response()->json($out, 201);
    }

    public function download(Request $request, Account $account, BankStatement $statement, Attachment $attachment)
    {
        $user = $request->user();
        $this->assertRead($user);
        $this->recon->assertBank($account);
        $removed = $attachment->removed_at !== null;
        abort_if($removed && ! $user->canDo('audit.view'), 403, 'Only auditors can download removed files.');

        $ids = ['attachment_id' => $attachment->id, 'original_name' => $attachment->original_name] + $this->context($statement);
        $label = "statement #{$statement->id}";

        return AttachmentController::stream($attachment, $statement, $ids, $label, $removed);
    }

    public function remove(Request $request, Account $account, BankStatement $statement, Attachment $attachment)
    {
        $user = $request->user();
        $this->assertWrite($user);
        $this->recon->assertBank($account);
        $reason = trim((string) $request->validate(['reason' => 'required|string|min:3|max:500'])['reason']);

        $row = DB::transaction(function () use ($statement, $attachment, $user, $reason) {
            $st = BankStatement::withoutGlobalScopes()->whereKey($statement->id)->lockForUpdate()->firstOrFail();
            $att = Attachment::withoutGlobalScopes()->whereKey($attachment->id)->lockForUpdate()->firstOrFail();
            abort_if($att->removed_at !== null, 422, 'This file has already been removed.');
            abort_if($st->status !== BankStatement::COMPLETED, 422, StatementAttachments::UNDONE_REMOVE);
            if ($refused = $this->files->closedMessage($st, share: true)) {
                abort(422, $refused);
            }
            $this->markRemoved($st, $att, $user, $reason, null);

            return $att;
        });

        return $this->files->present(collect([$row->fresh()]), $statement->fresh(), $user)[0];
    }

    // ------------------------------------------------------------------ rules

    /** Section 2: list and download need account.reconcile or report.view in the statement's branch. */
    private function assertRead(User $u): void
    {
        abort_unless($u->canDo('account.reconcile') || $u->canDo('report.view'), 403, 'Missing permission: report.view');
    }

    /** Add, replace and remove need account.reconcile (without either key, the read message comes first). */
    private function assertWrite(User $u): void
    {
        $this->assertRead($u);
        abort_unless($u->canDo('account.reconcile'), 403, 'Missing permission: account.reconcile');
    }

    /** R6: the values every statement file audit row carries. */
    private function context(BankStatement $s): array
    {
        return ['parent' => 'bank_statement', 'account_id' => (int) $s->account_id,
            'statement_date' => $s->statement_date->format('Y-m-d'), 'statement_status' => $s->status];
    }

    /** R5: the one-time removal (on a replace, in the same transaction as the new row) and its audit row. */
    private function markRemoved(BankStatement $st, Attachment $att, User $user, string $reason, ?int $replacedById): void
    {
        $att->update(['removed_at' => now(), 'removed_by' => $user->id, 'removal_reason' => $reason, 'replaced_by_id' => $replacedById]);
        AuditLog::record('attachment.removed', $st, $this->context($st) + [
            'attachment_id' => $att->id,
            'original_name' => $att->original_name,
            'sha256' => $att->sha256,
            'uploaded_by' => $att->uploaded_by,
            'uploaded_at' => $att->created_at?->toIso8601String(),
            'added_after' => $att->added_after,
            'by_preparer' => StatementAttachments::byPreparer($att, $st),
        ], $this->context($st) + [
            'reason' => $reason,
            'removed_at' => $att->removed_at->toIso8601String(),
            'replaced_by_id' => $replacedById,
            'self' => (int) $att->uploaded_by === (int) $user->id,
        ], $st->branch_id);
    }
}
