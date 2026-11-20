<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Export;
use App\Services\BackgroundExports;
use Illuminate\Http\Request;

/**
 * AG-087 S2 Exports page (docs/specs/background-exports.md section 5). Only the requester's own exports: anyone
 * else's id is a 404, a Super Admin's included (oversight is through the audit log, not the file). Signed in is
 * enough to list: a user who lost report.export still sees their earlier exports, and download re-checks access.
 */
class ExportController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(private BackgroundExports $exports) {}

    public function index(Request $request)
    {
        $this->active($request);
        $page = Export::where('user_id', $request->user()->id)
            ->where('requested_at', '>=', now()->subDays((int) config('exports.list_days', 30)))
            ->orderByDesc('requested_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $branches = BackgroundExports::branchesOf($page->getCollection());

        return [
            'data' => $page->getCollection()->map(fn (Export $e) => $this->exports->present($e, $branches))->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    public function show(Request $request, string $exportId)
    {
        $this->active($request);

        return $this->exports->present($this->own($request, $exportId));
    }

    public function download(Request $request, string $exportId)
    {
        return $this->exports->download($request, $this->own($request, $exportId));
    }

    /** The `branch` middleware's first check, kept for the list (download records an inactive user's attempt instead). */
    private function active(Request $request): void
    {
        abort_if($request->user()->status !== 'active', response()->json(['message' => 'Account inactive.'], 403));
    }

    private function own(Request $request, string $id): Export
    {
        return Export::where('user_id', $request->user()->id)->whereKey($id)->firstOr(fn () => abort(404, 'Export not found.'));
    }
}
