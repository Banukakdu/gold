<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\RecurringTemplate;
use App\Services\ExpenseTemplates;
use App\Support\BranchContext;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * AG-066 expense templates (docs/specs/recurring-expenses.md section 5). Reading needs expense.create or expense.edit
 * (checked here); changes need expense.edit (route middleware) and a single active branch. Nothing here posts money
 * (R1). There is deliberately no DELETE route: templates are archived and restored (R10).
 */
class ExpenseTemplateController extends Controller
{
    public function __construct(private ExpenseTemplates $templates) {}

    /** S1 list: the active branch's templates (a Super Admin in All mode: every branch's), non-archived unless archived=1. */
    public function index(Request $request)
    {
        $this->assertCanRead($request);
        $request->validate(['archived' => 'nullable|in:0,1']);
        $ctx = app(BranchContext::class);
        $withArchived = $request->boolean('archived');
        $templates = RecurringTemplate::where('type', RecurringTemplate::EXPENSE)
            ->when(! $withArchived, fn ($q) => $q->whereNull('archived_at'))->get();
        $threshold = $ctx->isScoped() ? Branch::whereKey($ctx->branchId)->value('expense_approval_threshold_cents') : null;

        return [
            'threshold' => $threshold === null ? null : Money::fromCents((int) $threshold),
            // S1 "Show archived (N)".
            'archived_count' => RecurringTemplate::where('type', RecurringTemplate::EXPENSE)->whereNotNull('archived_at')->count(),
            'data' => $this->templates->rows($templates, $request->user()),
        ];
    }

    public function show(Request $request, int $templateId)
    {
        $this->assertCanRead($request);

        return $this->templates->row($this->find($templateId), $request->user());
    }

    public function store(Request $request)
    {
        $tpl = $this->templates->store($request->all(), $request->user());

        return response()->json($this->templates->row($tpl, $request->user()), 201);
    }

    public function update(Request $request, int $templateId)
    {
        return $this->templates->row($this->templates->update($templateId, $request->all(), $request->user()), $request->user());
    }

    public function archive(Request $request, int $templateId)
    {
        return $this->templates->row($this->templates->archive($templateId, $request->only('reason'), $request->user()), $request->user());
    }

    public function restore(Request $request, int $templateId)
    {
        return $this->templates->row($this->templates->restore($templateId, $request->user()), $request->user());
    }

    /** Section 2: expense.create or expense.edit in the active branch (Super Admin always). */
    private function assertCanRead(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->canDo('expense.create') || $user->canDo('expense.edit'), 403, 'Missing permission: expense.create');
    }

    /** BranchScoped: another branch's template is a 404 (R2). */
    private function find(int $id): RecurringTemplate
    {
        return RecurringTemplate::where('type', RecurringTemplate::EXPENSE)->findOrFail($id);
    }
}
