<?php

namespace Tests\Feature\TransferApprovals;

use App\Http\Controllers\Api\V1\SettingsController;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/** AC 25 and 31: the threshold setting and the no-approver warning (R18). */
class SettingsTest extends TransferApprovalTestCase
{
    private function stored(): ?string
    {
        return DB::table('settings')->where('key', 'transfer_approval_threshold')->value('value');
    }

    public function test_ac25_threshold_is_stored_in_cents_and_validated(): void
    {
        $this->threshold(null);
        $count = AuditLog::where('event', 'settings.updated')->count();
        $this->sa()->putJson('/api/v1/settings', ['transfer_approval_threshold' => '100000.00'])->assertOk()
            ->assertJsonPath('transfer_approval_threshold', '100000.00');
        $this->assertSame('10000000', $this->stored());
        $this->sa()->getJson('/api/v1/settings')->assertOk()->assertJsonPath('transfer_approval_threshold', '100000.00');
        $this->assertSame($count + 1, AuditLog::where('event', 'settings.updated')->count());
        $row = AuditLog::where('event', 'settings.updated')->latest('id')->first();
        $this->assertSame(['transfer_approval_threshold' => null], $row->old_values);
        $this->assertSame(['transfer_approval_threshold' => '100000.00'], $row->new_values);
        // The same value again writes nothing.
        $this->sa()->putJson('/api/v1/settings', ['transfer_approval_threshold' => '100000'])->assertOk();
        $this->assertSame($count + 1, AuditLog::where('event', 'settings.updated')->count());

        $this->sa()->putJson('/api/v1/settings', ['transfer_approval_threshold' => '0'])->assertOk()->assertJsonPath('transfer_approval_threshold', '0.00');
        $this->assertSame('0', $this->stored());
        $this->sa()->putJson('/api/v1/settings', ['transfer_approval_threshold' => ''])->assertOk()->assertJsonPath('transfer_approval_threshold', null);
        $this->assertNull($this->stored());

        foreach (['-1', '1.234', 'abc', '1,000.00', ['1']] as $bad) {
            $this->sa()->putJson('/api/v1/settings', ['transfer_approval_threshold' => $bad])->assertStatus(422)
                ->assertJsonPath('errors.transfer_approval_threshold.0', SettingsController::THRESHOLD);
        }
        $this->as($this->mainBa, $this->main)->putJson('/api/v1/settings', ['transfer_approval_threshold' => '1.00'])->assertForbidden();
        $this->assertNull($this->stored());
        // Every signed-in user reads it (the transfer form's hint, S2).
        $this->as($this->acc, $this->kdy)->getJson('/api/v1/settings/public')->assertOk()->assertJsonPath('transfer_approval_threshold', null);
    }

    public function test_ac31_warning_when_main_has_no_approver_besides_the_super_admin(): void
    {
        // setUp made a MAIN Branch Admin: no warning.
        $this->threshold('100000.00')->assertJsonPath('transfer_approval_warning', null);
        $this->mainBa->update(['status' => 'inactive']);
        $this->threshold('5000.00')->assertJsonPath('transfer_approval_warning', SettingsController::NO_APPROVER);
        // A KDY Branch Admin holding the key in KDY does not count (R3).
        $this->assertTrue($this->kdyBa->fresh()->status === 'active');
        $this->sa()->getJson('/api/v1/settings')->assertJsonPath('transfer_approval_warning', SettingsController::NO_APPROVER);
        $this->userIn($this->main, 'Branch Admin');
        $this->sa()->getJson('/api/v1/settings')->assertJsonPath('transfer_approval_warning', null);
        $this->threshold(null)->assertJsonPath('transfer_approval_warning', null);
    }
}
