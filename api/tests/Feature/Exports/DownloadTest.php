<?php

namespace Tests\Feature\Exports;

use App\Models\AuditLog;
use App\Models\Export;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/** docs/specs/background-exports.md ACs 15-20, 31 and 35 (R6, accounting review findings 3, 4 and 8). */
class DownloadTest extends ExportsTestCase
{
    private function ready(): Export
    {
        $this->requestEight()->assertStatus(202);
        $this->work();

        return $this->latest();
    }

    public function test_ac15_download_and_repeat(): void
    {
        $e = $this->ready();
        $r = $this->download($this->ak, $e->id)->assertOk();
        $this->assertSame('attachment; filename="transactions_2026-10-01_2026-10-31.xlsx"', $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $r->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame(hash('sha256', $r->streamedContent()), $e->sha256);
        $e->refresh();
        $this->assertSame(1, $e->download_count);
        $this->assertNotNull($e->downloaded_at);

        $a = AuditLog::where('event', 'export.downloaded')->sole();
        // Finding 3: the download's own client, not the request's.
        $this->assertSame([$this->ak->id, '10.0.0.99', 'DownloadAgent/2.0', $this->kdy->id], [(int) $a->user_id, $a->ip, $a->user_agent, (int) $a->branch_id]);
        $this->assertSame(self::sorted(['export_id' => $e->id, 'name' => 'transactions', 'format' => 'xlsx', 'params' => $e->params, 'rows' => 8,
            'download_count' => 1, 'sha256' => $e->sha256, 'data_as_at' => $e->data_as_at->toIso8601String()]), self::sorted($a->new_values));
        $this->assertArrayNotHasKey('by_system', $a->new_values);

        $this->download($this->ak, $e->id, $this->gal->id)->assertOk();
        $this->assertSame(2, $e->fresh()->download_count);
        $this->assertSame(2, AuditLog::where('event', 'export.downloaded')->count());
    }

    public function test_ac16_only_the_requester(): void
    {
        $e = $this->ready();
        $other = $this->userIn($this->kdy, 'Accountant');
        $this->download($this->admin, $e->id, 'all')->assertNotFound();
        $this->download($other, $e->id)->assertNotFound();
        $this->actingAs($this->admin)->withHeader('X-Branch-Id', 'all')->getJson("/api/v1/exports/{$e->id}")->assertNotFound();
        $this->assertSame([], $this->actingAs($this->admin)->withHeader('X-Branch-Id', 'all')->getJson('/api/v1/exports')->assertOk()->json('data'));
        // A 404 writes nothing (finding 8).
        $this->assertSame(0, AuditLog::whereIn('event', ['export.downloaded', 'export.download_denied'])->count());
    }

    public function test_ac17_not_ready(): void
    {
        $this->requestEight();
        $e = $this->latest();
        $this->download($this->ak, $e->id)->assertStatus(409)->assertJsonPath('message', 'This export is not ready yet.');
    }

    public function test_ac18_expiry_at_request_and_by_prune(): void
    {
        $e = $this->ready();
        $this->travelTo($e->ready_at->copy()->addHours(24)->addMinute());
        $expires = $e->expires_at->copy()->setTimezone('Asia/Colombo');
        $this->download($this->ak, $e->id)->assertStatus(410)
            ->assertJsonPath('message', 'This export expired on '.$expires->format('j M Y H:i').'. Run it again.');
        $this->actingAs($this->ak)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson("/api/v1/exports/{$e->id}")
            ->assertJsonPath('status', 'expired');
        $this->assertTrue(Storage::disk(Export::DISK)->exists($e->file_path), 'prune has not run');

        $this->artisan('exports:prune')->assertSuccessful();
        $e->refresh();
        $this->assertSame([Export::EXPIRED, null], [$e->status, $e->file_path]);
        $this->assertSame([], Storage::disk(Export::DISK)->allFiles());
        $this->assertNotNull(Export::find($e->id));
        $a = AuditLog::where('event', 'export.expired')->sole();
        $this->assertNull($a->user_id);
        $this->assertSame([true, $this->ak->id, $e->id], [$a->new_values['by_system'], $a->new_values['requested_by'], $a->new_values['export_id']]);
        $this->download($this->ak, $e->id)->assertStatus(410);
    }

    public function test_ac19_access_lost_after_ready(): void
    {
        $e = $this->ready();
        $this->branchRole($this->kdy, 'Accountant')->revokePermissionTo('report.export');
        foreach ([$this->kdy->id, $this->gal->id] as $branch) {
            $this->download($this->ak, $e->id, $branch)->assertForbidden()->assertJsonPath('message', 'You no longer have access to this export.');
        }
        // Finding 8: each refusal is audited with the reason and the download's client.
        $denied = AuditLog::where('event', 'export.download_denied')->get();
        $this->assertCount(2, $denied);
        $this->assertSame(['missing_permission', '10.0.0.99'], [$denied[0]->new_values['reason'], $denied[0]->ip]);
        $this->assertSame(0, $e->fresh()->download_count);

        // All mode export of a Super Admin who is demoted.
        $sa = User::factory()->create(['status' => 'active']);
        $sa->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($sa)->withHeader('X-Branch-Id', 'all')->postJson('/api/v1/transactions/export', ['type' => 'income'])->assertStatus(202);
        $this->work();
        $all = $this->latest();
        $this->assertSame(Export::READY, $all->status);
        $sa->forceFill(['is_super_admin' => false])->save();
        $sa->branches()->attach($this->kdy->id);
        $this->download($sa, $all->id)->assertForbidden();
        $this->assertSame('not_super_admin', AuditLog::where('event', 'export.download_denied')->latest('id')->first()->new_values['reason']);
    }

    public function test_ac20_damaged_file(): void
    {
        $e = $this->ready();
        Storage::disk(Export::DISK)->put($e->file_path, 'tampered');
        $this->download($this->ak, $e->id)->assertStatus(500)->assertJsonPath('message', 'This export file is damaged. Run it again.');
        $e->refresh();
        $this->assertSame([Export::FAILED, 'damaged'], [$e->status, $e->failure_code]);
        $a = AuditLog::where('event', 'export.failed')->sole();
        $this->assertSame('damaged', $a->new_values['failure_code']);
        $this->assertSame($e->sha256, $a->old_values['sha256']);
        $this->assertSame(hash('sha256', 'tampered'), $a->new_values['actual_sha256']);
        $this->assertArrayNotHasKey('by_system', $a->new_values);
        $this->assertSame('10.0.0.99', $a->ip);
        $this->download($this->ak, $e->id)->assertStatus(410)->assertJsonPath('message', 'This export failed and has no file.');
    }

    public function test_ac31_missing_file(): void
    {
        $e = $this->ready();
        Storage::disk(Export::DISK)->delete($e->file_path);
        $this->download($this->ak, $e->id)->assertStatus(410)->assertJsonPath('message', 'This export file is missing. Run it again.');
        $this->assertSame([Export::FAILED, 'missing'], [$e->fresh()->status, $e->fresh()->failure_code]);
        $this->assertSame('missing', AuditLog::where('event', 'export.failed')->sole()->new_values['failure_code']);
    }

    public function test_list_and_show_own_exports(): void
    {
        $e = $this->ready();
        $this->travel(1)->minutes();
        $this->exportTxn($this->ak, $this->kdy->id, ['type' => 'expense'])->assertStatus(202);
        $list = $this->actingAs($this->ak)->withHeader('X-Branch-Id', (string) $this->gal->id)->getJson('/api/v1/exports')->assertOk();
        $this->assertSame(2, $list->json('meta.total'));
        $this->assertSame(25, $list->json('meta.per_page'));
        $this->assertSame(['queued', 'ready'], array_column($list->json('data'), 'status'));
        $this->assertSame($e->id, $list->json('data.1.id'));
        $this->assertNotNull($list->json('data.1.data_as_at'));
        $this->assertSame($e->size_bytes, $list->json('data.1.size_bytes'));

        // Older than 30 days: not listed.
        $this->travel(31)->days();
        $this->assertSame([], $this->actingAs($this->ak)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson('/api/v1/exports')->json('data'));

        // A Cashier (no report.export) can open the page; it is simply empty.
        $cashier = $this->userIn($this->kdy, 'Cashier');
        $this->actingAs($cashier)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson('/api/v1/exports')->assertOk()->assertJsonPath('data', []);
    }
}
