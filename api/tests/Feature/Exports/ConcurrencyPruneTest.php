<?php

namespace Tests\Feature\Exports;

use App\Jobs\RunExport;
use App\Models\AuditLog;
use App\Models\Export;
use App\Services\BackgroundExports;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/** docs/specs/background-exports.md ACs 21-23 (R7, R10, accounting review finding 9). */
class ConcurrencyPruneTest extends ExportsTestCase
{
    public function test_ac21_two_active_per_user_and_the_queued_twin(): void
    {
        $first = $this->requestEight()->assertStatus(202)->json('export.id');
        $this->exportTxn($this->ak, $this->kdy->id, ['type' => 'expense'])->assertStatus(202);
        $audits = AuditLog::count();

        $this->exportTxn($this->ak, $this->kdy->id, ['from' => '2026-09-10'])->assertStatus(429)
            ->assertJsonPath('message', 'You already have 2 exports being prepared. Wait for one to finish.');
        $this->assertSame(2, Export::count());

        // The identical request (key order and the branch header the same): the queued export, nothing new.
        $this->exportTxn($this->ak, $this->kdy->id, ['type' => 'income', 'to' => '2026-10-31', 'from' => '2026-10-01'])->assertStatus(202)
            ->assertJsonPath('export.id', $first);
        $this->assertSame([2, $audits], [Export::count(), AuditLog::count()]);
        Queue::assertPushed(RunExport::class, 2);

        // Another user is not limited by AK's exports.
        $other = $this->userIn($this->kdy, 'Accountant');
        $this->requestEight($other)->assertStatus(202);
        $this->assertNotSame($first, $this->latest()->id);
    }

    public function test_finding9_a_running_twin_is_not_reused(): void
    {
        $first = $this->requestEight()->assertStatus(202)->json('export.id');
        Export::whereKey($first)->update(['status' => Export::RUNNING, 'started_at' => now()]);
        $second = $this->requestEight()->assertStatus(202)->json('export.id');
        $this->assertNotSame($first, $second);
        $this->assertSame(2, Export::count());
        // Still subject to the limit.
        $this->exportTxn($this->ak, $this->kdy->id, ['type' => 'expense'])->assertStatus(429);
    }

    public function test_the_same_filters_in_another_branch_context_are_a_new_export(): void
    {
        $first = $this->asAdmin()->postJson('/api/v1/transactions/export', ['type' => 'income'])->assertStatus(202)->json('export.id');
        $second = $this->asAdmin($this->kdy->id)->postJson('/api/v1/transactions/export', ['type' => 'income'])->assertStatus(202)->json('export.id');
        $this->assertNotSame($first, $second);
        $this->assertSame([null, $this->kdy->id], [Export::find($first)->branch_id, Export::find($second)->branch_id]);
        $this->assertSame('All branches', Export::find($first)->branch_id === null ? app(BackgroundExports::class)->present(Export::find($first))['branches_label'] : '');
    }

    public function test_ac22_stale_and_orphans(): void
    {
        $this->requestEight();
        $stale = $this->latest();
        $this->travel(121)->minutes();
        $disk = Storage::disk(Export::DISK);
        $disk->put('7/orphan-old.xlsx.part', 'x');
        touch($disk->path('7/orphan-old.xlsx.part'), now()->subHours(26)->getTimestamp());
        $disk->put('7/orphan-new.xlsx.part', 'x');
        touch($disk->path('7/orphan-new.xlsx.part'), now()->subHour()->getTimestamp());

        $this->artisan('exports:prune')->assertSuccessful();
        $stale->refresh();
        $this->assertSame([Export::FAILED, 'stale', 'The export did not run. Try again later.'], [$stale->status, $stale->failure_code, $stale->error]);
        $a = AuditLog::where('event', 'export.failed')->sole();
        $this->assertSame(['stale', true, $this->ak->id], [$a->new_values['failure_code'], $a->new_values['by_system'], (int) $a->user_id]);
        $this->assertFalse($disk->exists('7/orphan-old.xlsx.part'));
        $this->assertTrue($disk->exists('7/orphan-new.xlsx.part'));

        // A queued export of 119 minutes is left alone; a ready file is never an orphan.
        $this->requestEight();
        $this->work();
        $ready = $this->latest();
        touch($disk->path($ready->file_path), now()->subHours(30)->getTimestamp());
        $queued = $this->exportTxn($this->ak, $this->kdy->id, ['type' => 'expense'])->assertStatus(202)->json('export.id');
        $this->travel(119)->minutes();
        $this->artisan('exports:prune')->assertSuccessful();
        $this->assertSame(Export::QUEUED, Export::find($queued)->status);
        $this->assertSame(Export::READY, $ready->fresh()->status);
        $this->assertTrue($disk->exists($ready->file_path));
    }

    public function test_ac23_schedule(): void
    {
        Artisan::call('schedule:list');
        $this->assertMatchesRegularExpression('/^\s*0\s+\*\s+\*\s+\*\s+\*\s+php artisan exports:prune/m', Artisan::output());
    }
}
