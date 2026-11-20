<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AG-094 in-app notifications (docs/specs/notifications.md): who is notified (section 2, R2), writing the rows (R3),
 * closing stale requests (R6) and presenting a row with read-time redaction (R5, accounting review 2).
 *
 * A notification belongs to one user and is a pointer to a record that is already audited elsewhere. Nothing here
 * posts, writes ledger lines, balances or transactions, or writes an audit row (R10).
 */
class InAppNotifications
{
    /** R11 (accounting review Q1): rows older than this are pruned. */
    public const RETENTION_DAYS = 90;

    // ------------------------------------------------------------------ recipients (section 2)

    /**
     * Active members of $branchId holding $permission through an active role in that branch (spatie teams). Super
     * Admins are only included when they hold such a role themselves; callers add or remove them as section 2 says.
     *
     * @return Collection<int, User>
     */
    public function holders(string $permission, int $branchId): Collection
    {
        $ids = $this->holderIds($permission, $branchId);

        return $ids === [] ? collect() : User::whereIn('id', $ids)->where('status', 'active')->orderBy('id')->get();
    }

    /** @return int[] */
    private function holderIds(string $permission, int $branchId, ?int $userId = null): array
    {
        return DB::table('model_has_roles as mr')
            ->join('role_has_permissions as rp', 'rp.role_id', '=', 'mr.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('roles as r', fn ($j) => $j->on('r.id', '=', 'mr.role_id')->where('r.status', '=', 'active'))
            ->join('branch_user as bu', fn ($j) => $j->on('bu.user_id', '=', 'mr.model_id')->where('bu.branch_id', '=', $branchId))
            ->where('mr.model_type', User::class)->where('mr.branch_id', $branchId)->where('p.name', $permission)
            ->when($userId !== null, fn ($q) => $q->where('mr.model_id', $userId))
            ->distinct()->pluck('mr.model_id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return Collection<int, User> every active Super Admin */
    public function superAdmins(): Collection
    {
        return User::where('is_super_admin', true)->where('status', 'active')->orderBy('id')->get();
    }

    /** Section 2 "Approver" (AG-051 R3): `transfer.approve` through a MAIN role, plus every Super Admin. Active only. */
    public function approvers(): Collection
    {
        return $this->superAdmins()->concat($this->holders('transfer.approve', (int) Branch::main()->id))->unique('id')->values();
    }

    /**
     * The same rule for one user, read fresh: the preferences endpoint's `can_approve` and the re-check of a queued
     * approval email (R7, accounting review 4) use it.
     */
    public function isApprover(User $user): bool
    {
        if ($user->status !== 'active') {
            return false;
        }

        return $user->is_super_admin || $this->holderIds('transfer.approve', (int) Branch::main()->id, (int) $user->id) !== [];
    }

    // ------------------------------------------------------------------ writing (R2, R3)

    /**
     * R2: active users only, never the actor, one row per user however often they qualify. Returns the users that
     * remain, keyed by id.
     *
     * @param  iterable<User>  $users
     * @return Collection<int, User>
     */
    public function eligible(iterable $users, ?int $actorId): Collection
    {
        return collect($users)->filter(fn (User $u) => $u->status === 'active' && (int) $u->id !== (int) $actorId)
            ->unique('id')->keyBy('id');
    }

    /**
     * R3: one row, written now through Laravel's database channel (the same row `notifyNow` writes, with the id set
     * here so the approval email can link to it). Returns the row id.
     */
    public function write(User $user, string $kind, array $data): string
    {
        $n = new InAppNotification($kind, $data);
        $n->id = (string) Str::orderedUuid();
        app(DatabaseChannel::class)->send($user, $n);

        return $n->id;
    }

    /**
     * @param  iterable<User>  $users
     * @return array<int, string> row id by user id
     */
    public function send(iterable $users, ?int $actorId, string $kind, array $data): array
    {
        $ids = [];
        foreach ($this->eligible($users, $actorId) as $u) {
            $ids[(int) $u->id] = $this->write($u, $kind, $data);
        }

        return $ids;
    }

    /** R6: unread rows of $kind about this transaction become read. Only read_at changes; no audit (R10). */
    public function markReadFor(string $kind, int $transactionId): int
    {
        $now = now();

        return DatabaseNotification::query()->where('type', $kind)->whereNull('read_at')
            ->where('data->transaction_id', $transactionId)
            ->update(['read_at' => $now, 'updated_at' => $now]);
    }

    // ------------------------------------------------------------------ reading (section 5, R5)

    /**
     * What this user may read in full: `branches` null = every branch (Super Admin), else the current memberships;
     * `approver` = isApprover() now. Computed once per request.
     *
     * @return array{branches: int[]|null, approver: bool}
     */
    public function viewer(User $user): array
    {
        return [
            'branches' => $user->is_super_admin ? null : $user->branches()->pluck('branches.id')->map(fn ($v) => (int) $v)->all(),
            'approver' => $this->isApprover($user),
        ];
    }

    /**
     * Section 5 row. R5 (accounting review 2): a row about a branch the user can no longer see is shown without its
     * body and with a title that carries no amount or name; the stored row is not changed. An approval request is tagged
     * MAIN but describes another branch's transfer, so it is also redacted once the user is no longer an approver
     * (review finding 1).
     *
     * @param  array{branches: int[]|null, approver: bool}  $viewer  from viewer()
     */
    public function present(DatabaseNotification $n, array $viewer): array
    {
        $d = (array) $n->data;
        $branchId = isset($d['branch_id']) ? (int) $d['branch_id'] : null;
        $code = $d['branch_code'] ?? null;
        $redacted = ($branchId !== null && $viewer['branches'] !== null && ! in_array($branchId, $viewer['branches'], true))
            || ($n->type === InAppNotification::APPROVAL_REQUESTED && ! $viewer['approver']);

        return [
            'id' => $n->id,
            'kind' => $n->type,
            'title' => $redacted ? self::redactedTitle((string) $code) : ($d['title'] ?? ''),
            'body' => $redacted ? null : ($d['body'] ?? null),
            'branch' => $branchId === null ? null : ['id' => $branchId, 'code' => $code],
            'url' => $d['url'] ?? null,
            'created_at' => $n->created_at?->toIso8601String(),
            'read_at' => $n->read_at?->toIso8601String(),
        ];
    }

    public static function redactedTitle(string $code): string
    {
        return "Notification for {$code} (no longer available)";
    }
}
