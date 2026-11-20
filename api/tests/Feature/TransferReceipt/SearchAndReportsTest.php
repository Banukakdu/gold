<?php

namespace Tests\Feature\TransferReceipt;

use App\Models\AuditLog;

/** AC 22, 23 and S4 / S5: the Receipt filter of the Transactions list, its export, and the Transfers report. */
class SearchAndReportsTest extends TransferReceiptTestCase
{
    /** @return array<string, int> one transfer in each receipt state, plus one with none */
    private function everyState(): array
    {
        $ids = [];
        $ids['awaiting'] = $this->t1('100.00')->id;
        $ids['received'] = $this->t1('200.00')->id;
        $this->confirm($ids['received'])->assertOk();
        $ids['disputed'] = $this->t1('300.00')->id;
        $this->dispute($ids['disputed'])->assertOk();
        $ids['cancelled'] = $this->t1('400.00')->id;
        $this->accKdy()->postJson("/api/v1/transactions/{$ids['cancelled']}/reverse", ['reason' => 'Wrong'])->assertCreated();
        $this->setting(false);
        $ids['none'] = $this->t1('500.00')->id;
        $this->setting(true);
        $this->expense('2026-11-19', '10.00');
        $this->income('2026-11-19', '20.00');

        return $ids;
    }

    private function list(string $query, $user = null, $branch = null)
    {
        return $this->as($user ?? $this->acc, $branch ?? $this->kdy)->getJson('/api/v1/transactions?'.$query);
    }

    public function test_ac22_receipt_filter_of_the_list_and_its_export(): void
    {
        $ids = $this->everyState();
        $rows = $this->list('receipt=awaiting,disputed')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$ids['awaiting'], $ids['disputed']], array_column($rows, 'id'));
        $disputed = collect($rows)->firstWhere('id', $ids['disputed']);
        $this->assertSame(['disputed', 'Short by 500', ['id' => $this->galCsh->id, 'name' => $this->galCsh->name]],
            [$disputed['receipt_status'], $disputed['receipt_note'], $disputed['receipt_by']]);
        // The receiving branch sees the same rows (incoming).
        $this->assertEqualsCanonicalizing([$ids['awaiting'], $ids['disputed']], array_column($this->list('receipt=awaiting,disputed', $this->galCsh, $this->gal)->json('data'), 'id'));
        $this->assertSame([$ids['received']], array_column($this->list('receipt=received')->json('data'), 'id'));
        $this->assertSame([$ids['cancelled']], array_column($this->list('receipt=cancelled')->json('data'), 'id'));
        $this->assertSame('2026-11-19', collect($this->list('receipt=received')->json('data'))[0]['received_on']);

        $this->list('receipt=pending')->assertStatus(422)->assertJsonPath('errors.receipt.0', 'Unknown receipt status: pending.');
        $this->list('receipt=awaiting,pending')->assertStatus(422)->assertJsonPath('errors.receipt.0', 'Unknown receipt status: pending.');

        $sheet = $this->xlsxSheet($this->as($this->acc, $this->kdy)->postJson('/api/v1/transactions/export', ['receipt' => 'awaiting,disputed']));
        $status = array_search('Status', $sheet['labels'], true);
        $this->assertSame(['Receipt', 'Received on', 'Receipt by', 'Receipt note'], array_slice($sheet['labels'], $status + 1, 4));
        $this->assertContains('Filters: Receipt: Awaiting receipt, Discrepancy reported', $sheet['lines']);
        $byRef = collect($sheet['rows'])->keyBy('Ref no');
        $d = $byRef[$this->txn($ids['disputed'])->ref_no];
        $this->assertSame(['Discrepancy reported', $this->galCsh->name, 'Short by 500'], [$d['Receipt'], $d['Receipt by'], $d['Receipt note']]);
        $this->assertSame('Awaiting receipt', $byRef[$this->txn($ids['awaiting'])->ref_no]['Receipt']);
        $this->assertCount(2, $sheet['rows']);

        $all = $this->xlsxSheet($this->as($this->acc, $this->kdy)->postJson('/api/v1/transactions/export', ['receipt' => 'received']));
        $r = $all['rows'][0];
        $this->assertSame('Received', $r['Receipt']);
        $this->assertNotSame('', (string) $r['Received on']);
    }

    public function test_ac23_transfers_report_receipt_filter_and_unchanged_figures(): void
    {
        $t1 = $this->t1('100.00');
        $t2 = $this->t1('200.00');
        $q = ['from' => '2026-11-01', 'to' => '2026-11-30'];
        $before = $this->sa()->getJson('/api/v1/reports/transfers?'.http_build_query($q))->assertOk()->json();

        $this->confirm($t2->id)->assertOk();
        $after = $this->sa()->getJson('/api/v1/reports/transfers?'.http_build_query($q))->assertOk()->json();
        $this->assertSame($before['net_moved'], $after['net_moved']);
        $this->assertSame($before['matrix'], $after['matrix']);
        $this->assertSame([], $after['receipt']);
        $this->assertNull($after['in_transit']);

        $awaiting = $this->sa()->getJson('/api/v1/reports/transfers?'.http_build_query($q + ['receipt' => ['awaiting']]))->assertOk()->json();
        $this->assertSame([$t1->id], array_column($awaiting['movements']['data'], 'id'));
        $this->assertSame('awaiting', $awaiting['movements']['data'][0]['receipt_status']);
        $this->assertSame('100.00', $awaiting['net_moved']);
        // The rows of the unfiltered report carry the receipt (null for those before this release).
        $byId = collect($after['movements']['data'])->keyBy('id');
        $this->assertSame(['received', '2026-11-19'], [$byId[$t2->id]['receipt_status'], $byId[$t2->id]['received_on']]);

        // Excel: a Receipt column after Status and the filter in the header.
        $book = $this->xlsxSheet($this->sa()->postJson('/api/v1/reports/transfers/export', $q + ['format' => 'xlsx', 'receipt' => ['awaiting']]));
        $status = array_search('Status', $book['labels'], true);
        $this->assertSame('Receipt', $book['labels'][$status + 1]);
        $this->assertContains('Receipt: Awaiting receipt', $book['lines']);
        $row = collect($book['rows'])->firstWhere('Ref no', $t1->ref_no);
        $this->assertSame('Awaiting receipt', $row['Receipt']);
        $this->assertSame(['receipt' => ['awaiting']], array_intersect_key(AuditLog::where('event', 'report.exported')->latest('id')->first()->new_values['params'], ['receipt' => 1]));

        $inTransit = $this->xlsxSheet($this->sa()->postJson('/api/v1/reports/transfers/export', $q + ['format' => 'xlsx', 'receipt' => ['in_transit']]));
        $this->assertContains('Receipt: In transit at end date (30 Nov 2026), any date up to it', $inTransit['lines']);

        // PDF renders with the column.
        $this->sa()->postJson('/api/v1/reports/transfers/export', $q + ['format' => 'pdf', 'receipt' => ['awaiting']])->assertOk();
    }
}
