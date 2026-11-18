<?php

namespace Tests\Feature\Reports;

/** AG-085 Daily Cash Book (R7). */
class CashBookReportTest extends ReportTestCase
{
    /** AC 18: E-4 day rows, and the period closing equals the account statement. */
    public function test_ac18_kdy_cash_equals_e4(): void
    {
        $cash = $this->acc($this->kdy, 'Cash');
        $book = $this->book(['from' => '2026-10-01', 'to' => '2026-10-08'], $cash->id);
        $this->assertSame([
            ['2026-10-01', '170000.00', '50000.00', '0.00', '220000.00'],
            ['2026-10-02', '220000.00', '12500.00', '0.00', '232500.00'],
            ['2026-10-03', '232500.00', '0.00', '8200.00', '224300.00'],
            ['2026-10-06', '224300.00', '100000.00', '0.00', '324300.00'],
            ['2026-10-07', '324300.00', '0.00', '30000.00', '294300.00'],
            ['2026-10-08', '294300.00', '0.00', '40000.00', '254300.00'],
        ], $this->days($book));
        $this->assertSame(['170000.00', '162500.00', '78200.00', '254300.00'], [$book['opening'], $book['receipts'], $book['payments'], $book['closing']]);
        $this->assertSame($book['closing'], $this->statementClosing($cash->id, '2026-10-01', '2026-10-08'));

        $book = $this->book(['from' => '2026-10-12', 'to' => '2026-10-16'], $cash->id);
        $this->assertSame([
            ['2026-10-12', '254300.00', '0.00', '5000.00', '249300.00'],
            ['2026-10-14', '249300.00', '5000.00', '0.00', '254300.00'],
            ['2026-10-15', '254300.00', '10000.00', '0.00', '264300.00'],
            ['2026-10-16', '264300.00', '0.00', '10000.00', '254300.00'],
        ], $this->days($book));
        $this->assertSame($book['closing'], $this->statementClosing($cash->id, '2026-10-12', '2026-10-16'));

        // AC 33 (finding 8): gross presentation with cross-references.
        $this->assertSame(['15000.00', '15000.00'], [$book['receipts'], $book['payments']]);
        $this->assertStringContainsString('(reversed by '.$this->txn('rev10')->ref_no.')', $book['days'][0]['lines'][0]['description']);
        $this->assertStringStartsWith('Reversal of '.$this->txn(10)->ref_no, $book['days'][1]['lines'][0]['description']);
        $this->assertSame('reversal', $book['days'][1]['lines'][0]['type']);
    }

    /** AC 5 (cash book part): voided (12) leaves no 10-20 row; opening = closing. */
    public function test_ac5_voided_entry_has_no_row(): void
    {
        $book = $this->book(['from' => '2026-10-20', 'to' => '2026-10-20'], $this->acc($this->gal, 'Cash')->id, $this->gal->id);
        $this->assertSame([], $book['days']);
        $this->assertSame(['133200.00', '133200.00'], [$book['opening'], $book['closing']]);

        $oct = $this->book($this->october(), $this->acc($this->gal, 'Cash')->id, $this->gal->id);
        $this->assertNotContains('2026-10-20', array_column($oct['days'], 'date'));
    }

    /** AC 19 + AC 20 + validation. */
    public function test_ac19_ac20_ranges_and_branches(): void
    {
        $kdy = [$this->kdy->id];
        $this->report('cash-book', ['from' => '2026-10-01', 'to' => '2026-11-01', 'branches' => $kdy])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->report('cash-book', ['from' => '2026-10-01', 'to' => '2026-10-31', 'branches' => $kdy])->assertOk();
        $this->report('income-expense', ['from' => '2025-01-01', 'to' => '2026-01-02'])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->report('income-expense', ['from' => '2025-01-01', 'to' => '2026-01-01'])->assertOk(); // 366 days
        foreach (['branch-comparison', 'transfers'] as $name) {
            $this->report($name, ['from' => '2025-01-01', 'to' => '2026-01-02'])->assertStatus(422);
        }
        $this->report('income-expense', ['from' => '2026-10-31', 'to' => '2026-10-01'])->assertStatus(422);
        $this->report('income-expense', ['from' => 'yesterday', 'to' => '2026-10-01'])->assertStatus(422);

        $this->report('cash-book', $this->october())->assertStatus(422)->assertJsonPath('message', 'Select at least one branch');
        // Accounts must belong to the selected branches.
        $this->report('cash-book', $this->october(['branches' => $kdy, 'accounts' => [$this->acc($this->gal, 'Cash')->id]]))->assertStatus(422)->assertJsonValidationErrors('accounts');
    }

    public function test_branch_user_sees_all_own_accounts(): void
    {
        $acct = $this->userIn($this->kdy, 'Accountant');
        $r = $this->actingAs($acct)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->getJson('/api/v1/reports/cash-book?'.http_build_query(['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->json();
        $this->assertSame(['Bank', 'Cash'], array_column(array_column($r['accounts'], 'account'), 'name'));
        $this->assertSame('40000.00', $r['accounts'][0]['closing']);
    }

    private function book(array $q, int $accountId, ?int $branchId = null): array
    {
        $r = $this->report('cash-book', $q + ['branches' => [$branchId ?? $this->kdy->id], 'accounts' => [$accountId]])->assertOk()->json();
        $this->assertCount(1, $r['accounts']);

        return $r['accounts'][0];
    }

    private function days(array $book): array
    {
        return array_map(fn ($d) => [$d['date'], $d['opening'], $d['receipts'], $d['payments'], $d['closing']], $book['days']);
    }

    private function statementClosing(int $accountId, string $from, string $to): string
    {
        return $this->asAdmin($this->kdy->id)->getJson("/api/v1/accounts/{$accountId}/statement?from={$from}&to={$to}")->assertOk()->json('closing_balance');
    }
}
