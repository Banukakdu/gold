<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Notifications\YearEndNotification;

/** Settings fix round: review N3, N4, N5. */
class ReviewFixesTest extends SettingsTestCase
{
    public function test_n3_currency_decimals_of_the_wrong_type_is_a_422(): void
    {
        foreach ([[2], 'two', 2.5, 3, null] as $bad) {
            $this->save(['currency_decimals' => $bad])->assertStatus(422)->assertJsonPath('errors.currency_decimals.0', 'Only 2 decimal places are supported.');
        }
        $this->save(['currency_decimals' => 2])->assertOk();
    }

    public function test_n4_one_audit_row_per_real_change(): void
    {
        $this->save(['company_name' => 'Gold Traders'])->assertOk();
        $this->save(['company_name' => 'Gold Traders'])->assertOk();
        $rows = AuditLog::where('event', 'settings.updated')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['company_name' => 'Gold Traders'], $rows[0]->new_values);
    }

    public function test_n5_mail_shows_user_text_without_markdown(): void
    {
        $n = new YearEndNotification('reopened', 'FY 2026-27', 'A *B*', '2027-04-10 10:00', '7,500.00', "See [this](http://x.test) **now**\n# heading");
        $html = (string) $n->toMail($this->admin)->render();
        $this->assertStringNotContainsString('<a href="http://x.test"', $html);
        $this->assertStringNotContainsString('<strong>now</strong>', $html);
        $this->assertStringNotContainsString('<h1>heading', $html);
        $this->assertStringContainsString('[this](http://x.test)', html_entity_decode($html));
    }
}
