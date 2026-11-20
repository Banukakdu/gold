<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\LedgerService;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    /** Two-level tree, global + current branch's own sub-categories. */
    public function index(Request $request)
    {
        $branchId = app(BranchContext::class)->branchId;
        $visible = fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->when($branchId, fn ($w) => $w->orWhere('branch_id', $branchId)))
            ->when(! $request->boolean('include_inactive'), fn ($w) => $w->where('is_active', true))
            ->orderBy('name');

        return Category::whereNull('parent_id')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->tap($visible)
            ->with(['children' => $visible])
            ->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required_without:parent_id|in:income,expense',
            'parent_id' => 'nullable|exists:categories,id',
        ]);
        $user = $request->user();
        $branchId = app(BranchContext::class)->branchId;

        if (! empty($data['parent_id'])) {
            $parent = Category::findOrFail($data['parent_id']);
            abort_if($parent->parent_id !== null, 422, 'Only two levels are allowed (Category > Sub-category).');
            $data['type'] = $parent->type;
            // Branch admins (non super admin) create branch-only sub-categories (AG-071)
            $data['branch_id'] = $user->is_super_admin ? null : $branchId;
        } else {
            abort_unless($user->is_super_admin, 403, 'Only Super Admin can create top-level categories.');
        }

        abort_if(Category::where('parent_id', $data['parent_id'] ?? null)->where('name', $data['name'])
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $data['branch_id'] ?? null))->exists(), 422, 'A category with this name already exists here.');

        // Double-entry R1: the category's ledger accounts are created with it, in the same DB transaction.
        return response()->json(DB::transaction(fn () => Category::create($data), LedgerService::ATTEMPTS), 201);
    }

    public function update(Request $request, Category $category)
    {
        $user = $request->user();
        abort_unless($user->is_super_admin || $category->branch_id === app(BranchContext::class)->branchId, 403);
        $data = $request->validate(['name' => 'sometimes|string|max:100', 'is_active' => 'sometimes|boolean']);
        // Renaming or (de)activating a category renames / (de)activates its ledger accounts (double-entry R1).
        // Retried on a deadlock (review R3b): each attempt starts from the stored row, so the change is seen again.
        DB::transaction(fn () => $category->refresh()->update($data), LedgerService::ATTEMPTS);

        return $category;
    }
}
