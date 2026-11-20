<?php

namespace Tests\Feature\Settings;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\LedgerEntry;
use App\Services\ReportExporter;
use App\Services\ReportService;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\AccountingTestCase;

/** Settings spec AC 7: the currency code changes only while the ledger is empty (fresh install, nothing posted). */
class CurrencyTest extends AccountingTestCase
{
    public function test_ac7_currency_on_an_empty_ledger(): void
    {
        Storage::fake('branding');
        $this->assertFalse(LedgerEntry::exists());
        $this->asAdmin()->getJson('/api/v1/settings')->assertOk()->assertJsonPath('currency_locked', false);
        // QA Q6 (product owner): only 2-decimal currencies are offered and accepted.
        $codes = $this->asAdmin()->getJson('/api/v1/settings')->json('currencies');
        $this->assertSame('LKR', $codes[0]);
        $this->assertSame([], array_intersect(['JPY', 'KRW', 'VND', 'CLP', 'KWD', 'BHD', 'OMR', 'JOD', 'TND', 'IQD', 'LYD'], $codes));
        $this->assertContains('USD', $codes);
        foreach (['usd', 'US', 'XYZ', 'JPY', 'KWD', 'BHD'] as $bad) {
            $this->asAdmin()->putJson('/api/v1/settings', ['currency_code' => $bad])->assertStatus(422);
        }
        $this->asAdmin()->putJson('/api/v1/settings', ['currency_code' => 'USD'])->assertOk()->assertJsonPath('currency_code', 'USD');
        $this->assertSame(['USD'], Account::withoutGlobalScopes()->distinct()->pluck('currency')->all());
        $row = AuditLog::where('event', 'settings.updated')->sole();
        $this->assertSame([['currency_code' => 'LKR'], ['currency_code' => 'USD']], [$row->old_values, $row->new_values]);

        // Every "Amounts in ..." line reads the setting (screen meta, PDF and Excel header).
        $q = ['from' => '2026-10-01', 'to' => '2026-10-31'];
        $this->asAdmin()->getJson('/api/v1/reports/income-expense?'.http_build_query($q))->assertJsonPath('currency', 'USD');
        $head = app(ReportExporter::class)->header('income-expense', app(ReportService::class)->params('income-expense', $q), ['provisional' => false], $this->admin);
        $this->assertContains('Amounts in USD', $head['lines']);
        $this->assertSame('Amounts in USD', $head['currency']);

        // A new account takes the setting too.
        $this->asAdmin()->postJson('/api/v1/branches', ['code' => 'KDY', 'name' => 'Kandy'])->assertCreated();
        $this->assertSame('USD', Account::withoutGlobalScopes()->where('branch_id', Branch::where('code', 'KDY')->value('id'))->where('name', 'Cash')->value('currency'));
    }
}
