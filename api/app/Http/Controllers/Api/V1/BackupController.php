<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Services\Backups\BackupStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AG-093 S1 Backups (docs/specs/backups.md section 5): read-only status for Super Admins. There is deliberately no
 * POST, PUT or DELETE route: backups are started, restored and pruned on the server only.
 */
class BackupController extends Controller
{
    public const PER_PAGE = 50;

    public function index(Request $request)
    {
        // R12: a deactivated Super Admin cannot open S1 (deactivation also revokes their tokens; this is outside `branch`).
        abort_unless($request->user()->status === 'active', 403, 'Account inactive.');
        $f = $request->validate([
            'status' => ['nullable', Rule::in([Backup::SUCCEEDED, Backup::FAILED])],
            'page' => 'nullable|integer|min:1',
        ]);
        $page = Backup::query()->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('started_at')->orderByDesc('id')->paginate(self::PER_PAGE);

        return [
            'data' => $page->getCollection()->map(fn (Backup $b) => self::present($b))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'summary' => BackupStatus::summary(),
        ];
    }

    /** No paths, no disk names, no URLs. */
    public static function present(Backup $b): array
    {
        $c = fn (?string $t) => BackupStatus::clean($t);

        return [
            'id' => $b->id,
            'filename' => $b->filename,
            'status' => $b->status,
            'triggered_by' => $b->triggered_by,
            'started_at' => $b->started_at?->toIso8601String(),
            'finished_at' => $b->finished_at?->toIso8601String(),
            'duration_seconds' => $b->durationSeconds(),
            'size_bytes' => $b->size_bytes,
            'sha256' => $b->sha256,
            'db_driver' => $b->db_driver,
            'encrypted' => $b->encrypted,
            'files_count' => $b->files_count,
            'kept_as' => $b->kept_as,
            'fiscal_year' => $b->fiscal_year,
            'fiscal_year_label' => $b->fiscalYearLabel(),
            'offsite_status' => $b->offsite_status,
            'offsite_error' => $c($b->offsite_error),
            'local_pruned_at' => $b->local_pruned_at?->toIso8601String(),
            'verify_status' => $b->verify_status,
            'verified_at' => $b->verified_at?->toIso8601String(),
            'verify_error' => $c($b->verify_error),
            'failed_stage' => $b->failed_stage,
            'error' => $c($b->error),
        ];
    }
}
