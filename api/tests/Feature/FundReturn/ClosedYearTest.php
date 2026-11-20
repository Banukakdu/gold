<?php

namespace Tests\Feature\FundReturn;

use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use Tests\Feature\YearEnd\YearEndTestCase;

/** Fund return AC 8 / R5 on the Y-dataset ("today" 2027-04-10): nothing dated in a closed year, before any lock check. */
class ClosedYearTest extends YearEndTestCase
{
    public function test_ac8_no_fund_return_or_reversal_in_a_closed_year(): void
    {
        $galCash = $this->acc($this->gal, 'Cash');
        $galAcc = $this->userIn($this->gal, 'Accountant');
        $post = fn (string $date) => $this->as($galAcc, $this->gal)->postJson('/api/v1/transfers', ['from_account_id' => $galCash->id,
            'to_account_id' => $this->mainBank->id, 'amount' => '1000.00', 'txn_date' => $date, 'note' => 'Surplus']);
        $t = $post('2027-03-10')->assertCreated()->json();
        $this->assertSame('fund_return', $t['type']);
        $this->closeY();

        $state = fn () => [Transaction::withoutGlobalScopes()->count(), LedgerEntry::withoutGlobalScopes()->count(), AuditLog::count()];
        $before = $state();
        // March 2027 is also locked (global lock of the close): the closed-year message comes first.
        $post('2027-03-20')->assertStatus(422)->assertJsonPath('message', 'FY 2026-27 is closed.');
        $this->sa()->postJson("/api/v1/transactions/{$t['id']}/reverse", ['reason' => 'Wrong', 'txn_date' => '2027-03-15'])
            ->assertStatus(422)->assertJsonPath('message', 'FY 2026-27 is closed.');
        $this->assertSame($before, $state());
    }
}
