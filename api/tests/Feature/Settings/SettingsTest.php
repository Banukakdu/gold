<?php

namespace Tests\Feature\Settings;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Setting;
use App\Services\ReportExporter;
use App\Services\ReportService;
use App\Support\FiscalYear;
use App\Support\Settings;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;

/** Settings spec ACs 1-9, 16-18, 21, 22 (read, edit, currency, date format, fiscal year). */
class SettingsTest extends SettingsTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_11_23_000000_settings_and_year_end.php');
    }

    public function test_ac1_migration_inserts_missing_rows_and_keeps_existing(): void
    {
        DB::table('settings')->whereIn('key', ['currency', 'date_format', 'fiscal_year_start_month'])->delete();
        $this->assertSame('Account-gold', Setting::find('company_name')->value);
        $this->migration()->up();
        $this->assertSame(['Account-gold', 'LKR', 'D MMM YYYY', '4'],
            [Setting::find('company_name')->value, Setting::find('currency')->value, Setting::find('date_format')->value, Setting::find('fiscal_year_start_month')->value]);
        // MAIN 32000 exists once (the migration does not add a second one).
        $this->assertSame(1, Account::withoutGlobalScopes()->where('type', 'retained_earnings')->where('code', '32000')->where('branch_id', $this->main->id)->count());
    }

    public function test_migration_converts_a_valid_data_uri_logo_and_clears_an_invalid_one(): void
    {
        Setting::put('company_logo', 'data:image/png;base64,'.base64_encode($this->png(100, 40)));
        $this->migration()->up();
        $logo = app(Settings::class)->logo();
        $this->assertSame(['image/png', 100, 40], [$logo['mime'], $logo['width'], $logo['height']]);
        $this->assertNotNull(app(Settings::class)->logoDataUri());

        Setting::put('company_logo', 'data:image/svg+xml;base64,PHN2Zz4=');
        $this->migration()->up();
        $this->assertNull(Setting::find('company_logo')->value);
        $row = AuditLog::where('event', 'settings.logo_removed')->latest('id')->first();
        $this->assertSame('invalid at migration', $row->new_values['reason']);
    }

    public function test_ac2_every_user_reads_public_settings_only_super_admin_the_rest(): void
    {
        $this->as($this->csh, $this->kdy)->getJson('/api/v1/settings/public')->assertOk()
            ->assertJsonPath('currency_code', 'LKR')->assertJsonPath('currency_decimals', 2)->assertJsonPath('date_format', 'D MMM YYYY')
            ->assertJsonPath('fiscal_year_start_month', 4)->assertJsonPath('current_fiscal_year.label', 'FY 2026-27')
            ->assertJsonPath('current_fiscal_year.start', '2026-04-01')->assertJsonPath('logo', null);
        $csh = $this->as($this->csh, $this->kdy);
        $csh->getJson('/api/v1/settings')->assertForbidden()->assertJsonPath('message', 'Super Admin only.');
        $csh->putJson('/api/v1/settings', ['company_name' => 'X Ltd'])->assertForbidden()->assertJsonPath('message', 'Super Admin only.');
        $csh->postJson('/api/v1/settings/logo')->assertForbidden()->assertJsonPath('message', 'Super Admin only.');
        $csh->postJson('/api/v1/settings/logo/remove')->assertForbidden()->assertJsonPath('message', 'Super Admin only.');
    }

    public function test_ac3_branch_admin_cannot_edit_and_no_permission_key_exists(): void
    {
        $this->save(['company_name' => 'X Ltd'], $this->as($this->ba, $this->kdy))->assertForbidden();
        foreach (PermissionSeeder::PERMISSIONS as $key => $label) {
            $this->assertStringNotContainsString('setting', is_string($key) ? $key : $label);
        }
    }

    public function test_ac4_company_name_everywhere_and_one_audit_row(): void
    {
        $this->save(['company_name' => 'Lanka Traders (Pvt) Ltd'])->assertOk()->assertJsonPath('company_name', 'Lanka Traders (Pvt) Ltd')
            ->assertJsonPath('last_changed.company_name.by.name', $this->admin->name);
        $rows = AuditLog::where('event', 'settings.updated')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['company_name' => 'Account-gold'], $rows[0]->old_values);
        $this->assertSame(['company_name' => 'Lanka Traders (Pvt) Ltd'], $rows[0]->new_values);
        $this->assertNull($rows[0]->branch_id);

        $q = ['from' => '2026-10-01', 'to' => '2026-10-31'];
        $this->sa()->getJson('/api/v1/reports/income-expense?'.http_build_query($q))->assertJsonPath('company', 'Lanka Traders (Pvt) Ltd');
        $p = app(ReportService::class)->params('income-expense', $q);
        $head = app(ReportExporter::class)->header('income-expense', $p, ['provisional' => false], $this->admin);
        $this->assertSame('Lanka Traders (Pvt) Ltd', $head['lines'][0]);
        $this->sa()->postJson('/api/v1/audit-logs/export', $q + ['to' => '2026-11-18'])->assertOk();

        $this->save(['company_name' => 'Lanka Traders (Pvt) Ltd'])->assertOk();
        $this->assertSame(1, AuditLog::where('event', 'settings.updated')->count(), 'same value: no audit row');
    }

    public function test_ac5_company_name_rules_and_unknown_keys(): void
    {
        foreach (['', 'A', str_repeat('x', 121)] as $bad) {
            $this->save(['company_name' => $bad])->assertStatus(422);
        }
        foreach (['expense_negative_balance' => 'warn', 'company_logo' => 'x'] as $k => $v) {
            $this->save([$k => $v])->assertStatus(422)->assertJsonPath('message', "Unknown setting: {$k}.");
        }
        $this->assertSame('Account-gold', Setting::find('company_name')->value);
        $this->assertSame('block', Setting::find('expense_negative_balance')->value);
        $this->assertSame(0, AuditLog::where('event', 'settings.updated')->count());
    }

    public function test_ac6_two_fields_one_audit_row(): void
    {
        $this->save(['company_name' => 'Lanka Traders (Pvt) Ltd', 'date_format' => 'DD/MM/YYYY', 'fiscal_year_start_month' => 4])->assertOk();
        $row = AuditLog::where('event', 'settings.updated')->sole();
        $this->assertEqualsCanonicalizing(['company_name', 'date_format'], array_keys($row->new_values));
        $this->assertEqualsCanonicalizing(['company_name', 'date_format'], array_keys($row->old_values));
    }

    public function test_ac8_currency_locked_after_entries(): void
    {
        $this->assertTrue(LedgerEntry::exists());
        $this->save(['currency_code' => 'USD'])->assertStatus(422)->assertJsonPath('message', 'The currency cannot be changed after entries have been posted.');
        $this->sa()->getJson('/api/v1/settings')->assertJsonPath('currency_locked', true);
        $this->save(['currency_code' => 'LKR'])->assertOk();
        $this->assertSame(0, AuditLog::where('event', 'settings.updated')->count());
    }

    public function test_ac9_decimals_fixed_at_two(): void
    {
        foreach ([0, 3] as $d) {
            $this->save(['currency_decimals' => $d])->assertStatus(422)->assertJsonPath('message', 'Only 2 decimal places are supported.');
        }
        $this->save(['currency_decimals' => 2])->assertOk();
        $this->assertSame(0, AuditLog::where('event', 'settings.updated')->count());
    }

    public function test_ac17_unknown_date_format(): void
    {
        $this->save(['date_format' => 'MM/DD/YY'])->assertStatus(422);
        $this->save(['date_format' => 'MM/DD/YYYY'])->assertStatus(422);
    }

    public function test_ac18_fiscal_year_of_a_date(): void
    {
        $this->assertSame('FY 2026-27', FiscalYear::of('2027-03-31', 4)->label());
        $this->assertSame('FY 2027-28', FiscalYear::of('2027-04-01', 4)->label());
        $this->assertSame('FY 2026', FiscalYear::of('2026-11-18', 1)->label());
        $this->assertSame(['2026-01-01', '2026-12-31'], [FiscalYear::of('2026-11-18', 1)->start(), FiscalYear::of('2026-11-18', 1)->end()]);
        $this->save(['fiscal_year_start_month' => 1])->assertOk();
        $this->as($this->csh, $this->kdy)->getJson('/api/v1/settings/public')->assertJsonPath('current_fiscal_year.label', 'FY 2026');
    }

    public function test_ac21_fiscal_year_period_label(): void
    {
        $this->assertSame('FY 2026-27, 1 Apr 2026 to 31 Mar 2027', ReportExporter::periodLabel('2026-04-01', '2027-03-31'));
        $this->save(['date_format' => 'YYYY-MM-DD'])->assertOk();
        $this->assertSame('FY 2026-27, 2026-04-01 to 2027-03-31', ReportExporter::periodLabel('2026-04-01', '2027-03-31'));
        $this->assertSame('FY 2025-26, 2025-04-01 to 2026-03-31', ReportExporter::periodLabel('2025-04-01', '2026-03-31'));
        $this->assertSame('Year 2026 (calendar), 2026-01-01 to 2026-12-31', ReportExporter::periodLabel('2026-01-01', '2026-12-31'));
        $this->save(['fiscal_year_start_month' => 1])->assertOk();
        $this->assertSame('FY 2026, 2026-01-01 to 2026-12-31', ReportExporter::periodLabel('2026-01-01', '2026-12-31'));
    }

    public function test_ac22_fiscal_start_locked_after_a_close(): void
    {
        $this->save(['fiscal_year_start_month' => 7])->assertOk();
        $this->assertSame(1, AuditLog::where('event', 'settings.updated')->count());
        DB::table('year_end_closes')->insert(['fiscal_year' => 2025, 'period_start' => '2025-07-01', 'period_end' => '2026-06-30', 'status' => 'reopened',
            'closed_by' => $this->admin->id, 'closed_at' => now(), 'summary' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->save(['fiscal_year_start_month' => 4])->assertStatus(422)->assertJsonPath('message', 'The fiscal year start cannot be changed after a year-end close.');
        $this->sa()->getJson('/api/v1/settings')->assertJsonPath('fiscal_year_start_locked', true);
    }
}
