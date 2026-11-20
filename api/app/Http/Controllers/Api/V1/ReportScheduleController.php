<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ReportScheduleRun;
use App\Services\ScheduledReports;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AG-088 scheduled email reports (docs/specs/scheduled-reports.md section 5). Super Admin only (`super` middleware,
 * "Super Admin only."), outside `branch`: a schedule carries its own branch scope, so every switcher mode works. No new
 * permission key. There is deliberately no DELETE route: schedules are paused or archived (R11).
 */
class ReportScheduleController extends Controller
{
    public function __construct(private ScheduledReports $schedules) {}

    public function index(Request $request)
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['current', 'archived', 'all'])]])['status'] ?? 'current';

        return ['data' => $this->schedules->presentMany($this->schedules->list($status))];
    }

    public function show(int $id)
    {
        return $this->schedules->present($this->schedules->find($id));
    }

    public function store(Request $request)
    {
        return response()->json($this->schedules->present($this->schedules->store($request->all(), $request->user())), 201);
    }

    public function update(Request $request, int $id)
    {
        return $this->schedules->present($this->schedules->update($id, $request->all(), $request->user()));
    }

    public function pause(Request $request, int $id)
    {
        return $this->schedules->present($this->schedules->pause($id, $request->user()));
    }

    public function resume(Request $request, int $id)
    {
        return $this->schedules->present($this->schedules->resume($id, $request->user()));
    }

    public function archive(Request $request, int $id)
    {
        return $this->schedules->present($this->schedules->archive($id, $request->only('reason'), $request->user()));
    }

    public function restore(Request $request, int $id)
    {
        return $this->schedules->present($this->schedules->restore($id, $request->user()));
    }

    /** S3 runs, newest first, 25 per page, each with its deliveries. */
    public function runs(Request $request, int $id)
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $s = $this->schedules->find($id);
        $page = ReportScheduleRun::where('schedule_id', $s->id)->orderByDesc('planned_at')->orderByDesc('id')->paginate(25);

        return [
            'data' => collect($page->items())->map(fn ($r) => $this->schedules->presentRun($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    public function recipientOptions(Request $request)
    {
        $request->validate(['branches' => 'nullable|array', 'branches.*' => 'integer']);

        return ['data' => $this->schedules->recipientOptions($request->only('branches'))];
    }

    public function previewTime(Request $request)
    {
        return $this->schedules->previewTime($request->all());
    }
}
