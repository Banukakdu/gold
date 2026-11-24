<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AG-033f: one overdue bank reconciliation reminder sent (docs/specs/reconciliation-overdue-reminder.md section 5), the
 * dedupe record of App\Services\ReconciliationReminders. Unique (account_id, overdue_through, round). Never updated or
 * deleted (database triggers); there is no route to it. Posts nothing.
 */
class BankReconciliationReminder extends Model
{
    use BranchScoped;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['overdue_through' => 'date', 'last_statement_date' => 'date', 'round' => 'integer', 'months_overdue' => 'integer',
        'recipient_ids' => 'array', 'super_admin_fallback' => 'boolean'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }
}
