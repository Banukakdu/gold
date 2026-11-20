<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\InAppNotifications;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * AG-094 notifications (docs/specs/notifications.md section 5). Outside `branch`, like exports: a notification
 * belongs to one user, never to the active branch, so X-Branch-Id is ignored (R5). Only the caller's own rows; any
 * other id is a 404, a Super Admin's included. There is deliberately no DELETE route; rows are only pruned after
 * 90 days (R11). Reading and marking read write no audit row (R10); the email preference change does.
 */
class NotificationController extends Controller
{
    public const PER_PAGE = [10, 20];

    public const PREFERENCE_MESSAGE = 'Approval request email must be true or false.';

    public function __construct(private InAppNotifications $inApp) {}

    /** GET /notifications: newest first, `filter=unread|all`, 10 or 20 per page. */
    public function index(Request $request)
    {
        $user = $this->active($request);
        $v = $request->validate([
            'filter' => ['nullable', 'in:unread,all'],
            'per_page' => ['nullable', 'integer', 'in:10,20'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $fetchedAt = now();
        $page = $this->own($request)
            ->when(($v['filter'] ?? 'all') === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($v['per_page'] ?? 20));
        $visible = $this->inApp->visibleBranchIds($user);

        return [
            'data' => $page->getCollection()->map(fn (DatabaseNotification $n) => $this->inApp->present($n, $visible))->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'unread_count' => $this->unread($request),
            'fetched_at' => $fetchedAt->toIso8601String(),
        ];
    }

    /** GET /notifications/unread-count: the poll endpoint (R9). */
    public function unreadCount(Request $request)
    {
        $this->active($request);

        return ['unread_count' => $this->unread($request)];
    }

    /**
     * GET /notifications/{id}: one own row, redacted like the list (R5). Used by the email link /notifications/:id
     * (S3), which needs the row's branch and url before it can open it.
     */
    public function show(Request $request, string $id)
    {
        $user = $this->active($request);

        return $this->inApp->present($this->find($request, $id), $this->inApp->visibleBranchIds($user));
    }

    /** POST /notifications/{id}/read: idempotent, an already-read row keeps its first read_at (R8). */
    public function read(Request $request, string $id)
    {
        $this->active($request);
        $n = $this->find($request, $id);
        if ($n->read_at === null) {
            $now = now();
            $this->own($request)->whereKey($n->id)->whereNull('read_at')->update(['read_at' => $now, 'updated_at' => $now]);
            $n->refresh();
        }

        return ['id' => $n->id, 'read_at' => $n->read_at?->toIso8601String()];
    }

    /** POST /notifications/read-all: unread rows created at or before `before` (the list's fetched_at), else all (R8). */
    public function readAll(Request $request)
    {
        $this->active($request);
        $v = $request->validate(['before' => ['nullable', 'date']]);
        $now = now();
        $marked = $this->own($request)->whereNull('read_at')
            ->when($v['before'] ?? null, fn ($q, $b) => $q->where('created_at', '<=', Carbon::parse($b)->setTimezone(config('app.timezone'))))
            ->update(['read_at' => $now, 'updated_at' => $now]);

        return ['marked' => $marked];
    }

    /** GET /notifications/preferences */
    public function preferences(Request $request)
    {
        return $this->prefs($this->active($request));
    }

    /** PUT /notifications/preferences: audited only when the value changes (R10). */
    public function updatePreferences(Request $request)
    {
        $user = $this->active($request);
        $new = (bool) $request->validate(['approval_request_email' => ['required', 'boolean']], [
            'approval_request_email.required' => self::PREFERENCE_MESSAGE,
            'approval_request_email.boolean' => self::PREFERENCE_MESSAGE,
        ])['approval_request_email'];
        $old = (bool) $user->fresh()->approval_request_email;
        if ($old !== $new) {
            $user->forceFill(['approval_request_email' => $new])->save();
            AuditLog::record('user.notification_preferences_updated', $user, ['approval_request_email' => $old], ['approval_request_email' => $new], null);
        }

        return $this->prefs($user->fresh());
    }

    // ------------------------------------------------------------------ helpers

    /** The `branch` middleware's first check: an inactive user with a valid token gets nothing (AC 18). */
    private function active(Request $request)
    {
        $user = $request->user();
        abort_if($user->status !== 'active', response()->json(['message' => 'Account inactive.'], 403));

        return $user;
    }

    private function own(Request $request)
    {
        return $request->user()->notifications()->reorder();
    }

    private function find(Request $request, string $id): DatabaseNotification
    {
        return $this->own($request)->whereKey($id)->firstOr(fn () => abort(404, 'Notification not found.'));
    }

    private function unread(Request $request): int
    {
        return $this->own($request)->whereNull('read_at')->count();
    }

    private function prefs($user): array
    {
        return ['approval_request_email' => (bool) ($user->approval_request_email ?? true), 'can_approve' => $this->inApp->isApprover($user)];
    }
}
