<?php

namespace Tests\Feature\Transactions;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Export;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\ReportService;
use App\Services\TransactionExporter;
use App\Support\BusinessDate;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/** docs/specs/transaction-search.md ACs 27-34, 34a, 34b and accounting review findings 1, 2, 4, 6-8 (R14). */
class ExportTest extends TransactionSearchTestCase
{
    private function exported(): AuditLog
    {
        return AuditLog::where('event', 'report.exported')->latest('id')->firstOrFail();
    }

    public function test_ac27_export_needs_report_export(): void
    {
        foreach (['Cashier', 'Viewer'] as $role) {
            $u = $this->userIn($this->kdy, $role);
            $this->exportAs($u, $this->kdy->id)->assertForbidden()->assertJsonPath('message', 'Missing permission: report.export');
        }
        $this->assertSame(0, AuditLog::where('event', 'report.exported')->count());
    }

    public function test_ac28_ac33_filtered_export_and_its_audit_row(): void
    {
        $r = $this->exportAs($this->nimal, $this->kdy->id, ['type' => 'expense', 'min' => '100', 'q' => 'CEB']);
        $this->assertSame('attachment; filename="transactions_all_all.xlsx"', $r->headers->get('Content-Disposition'));
        $sheet = $this->sheet($r);
        $this->assertSame($this->refsOf('E2'), $this->refs($sheet));
        $this->assertContains('Transactions', $sheet['lines']);
        $this->assertContains('Branches: KDY Kandy', $sheet['lines']);
        $this->assertContains('Period: All dates', $sheet['lines']);
        $this->assertContains('Filters: Type: Expense; Amount: from 100.00; Search: "CEB"', $sheet['lines']);
        $this->assertContains('Sort: Date, newest first', $sheet['lines']);
        $this->assertContains('Rows: 1', $sheet['lines']);
        $this->assertContains('Amounts in LKR', $sheet['lines']);
        $this->assertNotContains(TransactionExporter::YEAR_END_NOTE, $sheet['lines']);

        $a = $this->exported();
        $this->assertSame($this->kdy->id, (int) $a->branch_id);
        $this->assertSame(self::sorted(['name' => 'transactions', 'format' => 'xlsx', 'rows' => 1, 'params' => [
            'type' => 'expense', 'min' => '100', 'q' => 'CEB', 'sort' => 'date', 'dir' => 'desc', 'scope_branch_ids' => [$this->kdy->id],
        ]]), self::sorted($a->new_values));

        // The audit log screen: "Reports" group, subject "Transactions".
        $row = collect($this->asAdmin()->getJson('/api/v1/audit-logs?'.http_build_query(['event_group' => 'reports', 'from' => BusinessDate::today(), 'to' => BusinessDate::today()]))->assertOk()->json('data'))->firstWhere('id', $a->id);
        $this->assertNotNull($row);
        $this->assertSame('Transactions', $row['subject']);
    }

    public function test_ac33_all_mode_audit_branch_and_scope(): void
    {
        $this->asAdmin()->postJson('/api/v1/transactions/export')->assertOk();
        $a = $this->exported();
        $this->assertNull($a->branch_id);
        $this->assertSame(Branch::orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all(), $a->new_values['params']['scope_branch_ids']);

        $this->asAdmin()->postJson('/api/v1/transactions/export', ['branches' => [$this->gal->id]])->assertOk();
        $a = $this->exported();
        $this->assertSame($this->gal->id, (int) $a->branch_id);
        $this->assertSame([$this->gal->id], $a->new_values['params']['scope_branch_ids']);
        $this->assertSame([$this->gal->id], $a->new_values['params']['branches']);
    }

    public function test_ac29_period_and_real_dates(): void
    {
        Setting::updateOrCreate(['key' => 'date_format'], ['value' => 'DD/MM/YYYY']);
        app(Settings::class)->flush();
        $r = $this->exportAs($this->nimal, $this->kdy->id, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame('attachment; filename="transactions_2026-10-01_2026-10-31.xlsx"', $r->headers->get('Content-Disposition'));
        $sheet = $this->sheet($r);
        $this->assertContains('Period: 01/10/2026 to 31/10/2026', $sheet['lines']);
        $date = $sheet['rows'][0]['Date'];
        $this->assertSame(DataType::TYPE_NUMERIC, $date->getDataType());
        $this->assertSame('dd/mm/yyyy', $date->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertSame('2026-10-21', ExcelDate::excelToDateTimeObject($date->getValue())->format('Y-m-d'));
    }

    public function test_ac30_export_equals_the_list_with_reversal_links(): void
    {
        $sheet = $this->sheet($this->exportAs($this->nimal, $this->kdy->id));
        $this->assertSame($this->refsOf('E7', 'E6', 'E4', 'E3', 'E2', 'E1', 'E5', 'E9'), $this->refs($sheet));
        $by = collect($sheet['rows'])->keyBy(fn ($row) => (string) $row['Ref no']->getValue());

        $e9 = $by[$this->e['E9']->ref_no];
        $this->assertSame('—', $e9['Amount']->getValue());
        $this->assertSame(DataType::TYPE_STRING, $e9['Amount']->getDataType());
        $this->assertContains(TransactionExporter::YEAR_END_NOTE, $sheet['lines']);

        $e5 = $by[$this->e['E5']->ref_no];
        $this->assertSame(ReportService::typeLabel($this->e['E5']->type).' (in)', $e5['Type']->getValue());
        $this->assertSame('In', $e5['Direction']->getValue());
        $this->assertSame('MAIN – '.$this->mainAcc->name, $e5['From']->getValue());
        $this->assertSame('KDY – Cash', $e5['To']->getValue());
        $this->assertNull($e5['Account']->getValue());

        $e2 = $by[$this->e['E2']->ref_no];
        $this->assertSame(DataType::TYPE_NUMERIC, $e2['Amount']->getDataType());
        $this->assertEquals(4200, $e2['Amount']->getValue());
        $this->assertSame('#,##0.00', $e2['Amount']->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertSame('Utilities', $e2['Category']->getValue());
        $this->assertSame('Electricity', $e2['Sub-category']->getValue());
        $this->assertSame('Cash', $e2['Account']->getValue());
        $this->assertSame('Kamal Silva', $e2['Created by']->getValue());
        $this->assertNull($e2['Direction']->getValue());
        $this->assertMatchesRegularExpression('/^\d{1,2} \w{3} \d{4} \d{2}:\d{2}$/', (string) $e2['Created at']->getValue());

        // Finding 2: links in both directions.
        $e6 = $by[$this->e['E6']->ref_no];
        $this->assertSame('Reversed by '.$this->e['E7']->ref_no, $e6['Status']->getValue());
        $this->assertSame($this->e['E7']->ref_no, $e6['Reversed by']->getValue());
        $this->assertSame('Out', $e6['Direction']->getValue());
        $e7 = $by[$this->e['E7']->ref_no];
        $this->assertSame('Reversal of '.$this->e['E6']->ref_no, $e7['Status']->getValue());
        $this->assertSame($this->e['E6']->ref_no, $e7['Reversal of']->getValue());
        $this->assertSame('In', $e7['Direction']->getValue()); // C1: the reversal brings the money back to KDY
        $this->assertSame('Posted', $by[$this->e['E1']->ref_no]['Status']->getValue());
        $this->assertContains(TransactionExporter::DIRECTION_LEGEND, $sheet['lines']);
    }

    /** AC 30a (accountant sign-off C1): Direction is the way the money moved, swapped on reversal rows. */
    public function test_ac30a_direction_follows_the_money(): void
    {
        $dir = function ($response) {
            $sheet = $this->sheet($response);
            $this->assertContains(TransactionExporter::DIRECTION_LEGEND, $sheet['lines']);
            $legend = array_search(TransactionExporter::DIRECTION_LEGEND, $sheet['lines'], true);
            $this->assertSame('Amounts in LKR', $sheet['lines'][$legend - 1]);

            return collect($sheet['rows'])->mapWithKeys(fn ($row) => [(string) $row['Ref no']->getValue() => [
                (string) $row['Type']->getValue(), $row['Direction']->getValue()]])->all();
        };
        $ref = fn (string $k) => $this->e[$k]->ref_no;

        $kdy = $dir($this->exportAs($this->nimal, $this->kdy->id));
        $this->assertSame('Out', $kdy[$ref('E6')][1]);
        $this->assertSame('In', $kdy[$ref('E7')][1]);
        $this->assertSame('In', $kdy[$ref('E5')][1]);
        foreach (['E1', 'E2', 'E3', 'E4', 'E9'] as $k) {
            $this->assertNull($kdy[$ref($k)][1], $k);
        }

        $galAdmin = $this->userIn($this->gal, 'Branch Admin');
        $gal = $dir($this->exportAs($galAdmin, $this->gal->id));
        $this->assertSame(['Branch transfer (in)', 'In'], $gal[$ref('E6')]);
        $this->assertSame(['Reversal (in)', 'Out'], $gal[$ref('E7')]);
        $this->assertNull($gal[$ref('E8')][1]);

        $all = $dir($this->asAdmin()->postJson('/api/v1/transactions/export'));
        $this->assertNotEmpty($all);
        foreach ($all as $refNo => [$type, $direction]) {
            $this->assertNull($direction, $refNo); // capital included
        }
    }

    public function test_ac31_typed_formula_stays_text(): void
    {
        DB::table('transactions')->where('id', $this->e['E2']->id)->update(['payee_payer' => '=HYPERLINK("x")']);
        $sheet = $this->sheet($this->exportAs($this->nimal, $this->kdy->id, ['type' => 'expense', 'status' => 'posted', 'category_id' => $this->electricity->id]));
        $cell = $sheet['rows'][0]['Payee / payer'];
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame('=HYPERLINK("x")', $cell->getValue());
    }

    /** AC 32, as changed by AG-087: 5,001 rows are queued (202, no file, no report.exported); 5,000 download at once. */
    public function test_ac32_export_cap(): void
    {
        Queue::fake();
        $now = now();
        $base = ['branch_id' => $this->kdy->id, 'type' => Transaction::INCOME, 'amount' => '1.00', 'status' => 'posted',
            'account_id' => $this->kdyCash->id, 'category_id' => $this->walkIn->id, 'created_by' => $this->kamal->id,
            'created_at' => $now, 'updated_at' => $now];
        // Stored rows only (the export reads transactions; no balance is involved): 4,993 + the 8 KDY fixture rows = 5,001;
        // from 2026-04-01 leaves out E9 (2026-03-31) = 5,000.
        foreach (array_chunk(range(1, 4993), 500) as $chunk) {
            DB::table('transactions')->insert(array_map(fn ($i) => $base + ['ref_no' => "CAP-TEST-{$i}", 'txn_date' => '2026-09-15'], $chunk));
        }
        $this->exportAs($this->nimal, $this->kdy->id)->assertStatus(202)
            ->assertJsonPath('message', 'This export has 5,001 rows, so it is being prepared in the background. We will email you when it is ready.');
        $this->assertSame(1, Export::count());
        $this->assertSame(0, AuditLog::where('event', 'report.exported')->count());

        $sheet = $this->sheet($this->exportAs($this->nimal, $this->kdy->id, ['from' => '2026-04-01']));
        $this->assertCount(5000, $sheet['rows']);
        $this->assertSame(5000, $this->exported()->new_values['rows']);
    }

    public function test_ac34_reports_route_does_not_know_transactions(): void
    {
        $this->asAdmin()->postJson('/api/v1/reports/transactions/export', ['format' => 'xlsx'])->assertNotFound()->assertJsonPath('message', 'Unknown report.');
    }

    public function test_ac34a_void_evidence_columns(): void
    {
        $sheet = $this->sheet($this->exportAs($this->nimal, $this->kdy->id, ['status' => 'void']));
        $this->assertSame($this->refsOf('E4'), $this->refs($sheet));
        $e4 = $sheet['rows'][0];
        $this->assertSame('Void', $e4['Status']->getValue());
        $this->assertSame('Kamal Silva', $e4['Voided by']->getValue());
        $this->assertSame('duplicate', $e4['Void reason']->getValue());
        $voidedOn = AuditLog::where('event', 'transaction.voided')->where('auditable_id', $this->e['E4']->id)->value('created_at');
        $this->assertSame(Carbon::parse($voidedOn)->setTimezone('Asia/Colombo')->format('Y-m-d'),
            ExcelDate::excelToDateTimeObject($e4['Voided on']->getValue())->format('Y-m-d'));
        $this->assertContains('Filters: Status: Void', $sheet['lines']);

        $all = $this->sheet($this->exportAs($this->nimal, $this->kdy->id));
        foreach ($all['rows'] as $row) {
            if ((string) $row['Ref no']->getValue() !== $this->e['E4']->ref_no) {
                $this->assertNull($row['Voided on']->getValue());
                $this->assertNull($row['Voided by']->getValue());
                $this->assertNull($row['Void reason']->getValue());
            }
        }
    }

    /** Finding 8: a closing entry cancelled by reopening the year reads "Void (year reopened)", with its evidence. */
    public function test_finding8_cancelled_closing_entry(): void
    {
        DB::table('transactions')->where('id', $this->e['E9']->id)->update(['status' => 'void']);
        $this->actingAs($this->admin);
        AuditLog::record('transaction.year_end_reopened', $this->e['E9']->fresh(), ['status' => 'posted'], ['status' => 'void', 'reason' => 'Late invoice']);
        $sheet = $this->sheet($this->exportAs($this->nimal, $this->kdy->id, ['status' => 'void', 'type' => 'year_end_close']));
        $row = $sheet['rows'][0];
        $this->assertSame('Void (year reopened)', $row['Status']->getValue());
        $this->assertSame('Late invoice', $row['Void reason']->getValue());
        $this->assertSame($this->admin->name, $row['Voided by']->getValue());
    }

    public function test_export_validates_like_the_list(): void
    {
        $this->exportAs($this->nimal, $this->kdy->id, ['min' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('min');
        $this->exportAs($this->nimal, $this->kdy->id, ['format' => 'pdf'])->assertStatus(422)->assertJsonValidationErrors('format');
        $this->assertSame(0, AuditLog::where('event', 'report.exported')->count());
    }
}
