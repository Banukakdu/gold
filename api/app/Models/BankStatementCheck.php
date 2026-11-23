<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AG-033d: the check ("Checked by") of a completed bank reconciliation statement (docs/specs/reconciliation-checked-by.md).
 * Never deleted. A row changes once: an active check becomes superseded (a re-check), withdrawn (by the checker or a
 * Super Admin) or cancelled (the statement was undone), with who, when and why; a superseded check is then linked to
 * the check that replaced it. The database triggers enforce the same (AC 21). Posts nothing.
 */
class BankStatementCheck extends Model
{
    use BranchScoped;

    public const ACTIVE = 'active';

    public const SUPERSEDED = 'superseded';

    public const WITHDRAWN = 'withdrawn';

    public const CANCELLED = 'cancelled';

    public const COMPARED_WITH = ['bank_statement', 'internet_banking'];

    /** Columns that may change, once (the end of an active check, and the supersede link). */
    public const END_COLUMNS = ['status', 'ended_by', 'ended_at', 'end_reason', 'superseded_by_id', 'updated_at'];

    protected $guarded = ['id'];

    protected $hidden = ['active_key'];

    protected $casts = ['checked_at' => 'datetime', 'ended_at' => 'datetime', 'changed_at_check' => 'boolean', 'max_entry_id' => 'integer',
        'late_count_at_check' => 'integer', 'checker_recorded_count' => 'integer', 'checker_uncleared_count' => 'integer', 'entries_count' => 'integer'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new ImmutableRecordException('Bank statement checks cannot be deleted.'));
        static::updating(function (BankStatementCheck $c) {
            $dirty = array_keys($c->getDirty());
            $was = $c->getOriginal('status');
            $end = $was === self::ACTIVE && in_array($c->status, [self::SUPERSEDED, self::WITHDRAWN, self::CANCELLED], true)
                && $c->ended_by !== null && $c->ended_at !== null && ($c->status === self::SUPERSEDED || $c->superseded_by_id === null);
            $link = $was === self::SUPERSEDED && $c->status === self::SUPERSEDED && $c->getOriginal('superseded_by_id') === null
                && $c->superseded_by_id !== null && ! array_intersect($dirty, ['ended_by', 'ended_at', 'end_reason']);
            if (array_diff($dirty, self::END_COLUMNS) || ! ($end || $link)) {
                throw new ImmutableRecordException('Bank statement checks cannot be changed; only the one-time end of an active check is allowed.');
            }
        });
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where($q->getModel()->getTable().'.status', self::ACTIVE);
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id')->withoutGlobalScopes();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
