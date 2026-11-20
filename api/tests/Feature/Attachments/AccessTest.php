<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;

/** AG-062 permissions and branch isolation: attachments spec AC 10-17. */
class AccessTest extends AttachmentsTestCase
{
    public function test_ac10_cashier_only_on_own_entries_and_own_files(): void
    {
        $a1 = $this->attach('r1.jpg');
        $this->upload($this->csh2, $this->kdy, $this->e['id'], $this->file('r2.jpg', self::jpeg(seed: 'r2')))
            ->assertForbidden()->assertJsonPath('message', 'You can attach files only to entries you recorded.');
        $this->remove($this->csh2, $this->kdy, $this->e['id'], $a1['id'])
            ->assertForbidden()->assertJsonPath('message', 'You can remove only files you uploaded.');
        $this->assertNull(Attachment::find($a1['id'])->removed_at);

        // What S2 shows CSH2: no Add, no Remove; CSH (recorder and uploader): both.
        $list = $this->listing($this->csh2, $this->kdy, $this->e['id'])->assertOk();
        $list->assertJsonPath('can_add', false)->assertJsonPath('data.0.can_remove', false);
        $this->listing($this->csh, $this->kdy, $this->e['id'])->assertJsonPath('can_add', true)->assertJsonPath('data.0.can_remove', true);

        // A file ACC added to CSH's entry: CSH cannot remove it.
        $acc = $this->attach('acc.pdf', self::pdf(2048), $this->acc);
        $this->remove($this->csh, $this->kdy, $this->e['id'], $acc['id'])->assertForbidden()
            ->assertJsonPath('message', 'You can remove only files you uploaded.');
        $this->assertFalse(collect($this->listing($this->csh, $this->kdy, $this->e['id'])->json('data'))->firstWhere('id', $acc['id'])['can_remove']);
    }

    public function test_ac11_accountant_and_viewer(): void
    {
        $a1 = $this->attach('r1.jpg');
        $this->attach('acc.pdf', self::pdf(2048), $this->acc);
        $this->listing($this->acc, $this->kdy, $this->e['id'])->assertJsonPath('can_add', true)->assertJsonPath('data.0.can_remove', true);
        $this->remove($this->acc, $this->kdy, $this->e['id'], $a1['id'])->assertOk()->assertJsonPath('removed_by.id', $this->acc->id);

        $a2 = $this->attach('r2.jpg');
        $list = $this->listing($this->vw, $this->kdy, $this->e['id'])->assertOk()->assertJsonPath('can_add', false);
        $this->assertFalse(collect($list->json('data'))->contains('can_remove', true));
        $this->download($this->vw, $this->kdy, $this->e['id'], $a2['id'])->assertOk();
        $this->upload($this->vw, $this->kdy, $this->e['id'], $this->file('v.jpg', self::jpeg(seed: 'v')))
            ->assertForbidden()->assertJsonPath('message', 'Missing permission: expense.edit');
        $this->remove($this->vw, $this->kdy, $this->e['id'], $a2['id'])
            ->assertForbidden()->assertJsonPath('message', 'Missing permission: expense.edit');
    }

    public function test_ac12_only_income_and_expense_entries(): void
    {
        $transfer = $this->sa()->postJson('/api/v1/transfers', ['from_account_id' => $this->kdyCash->id, 'to_account_id' => $this->galCash->id,
            'amount' => '10.00', 'txn_date' => '2026-11-12', 'note' => 'Float'])->assertCreated()->json();
        $capital = Transaction::withoutGlobalScopes()->where('type', 'capital')->firstOrFail();
        $e2 = $this->expense('2026-11-12', '20.00');
        $reversal = $this->accKdy()->postJson("/api/v1/transactions/{$e2['id']}/reverse", ['reason' => 'Wrong'])->assertCreated()->json();
        $this->assertSame('reversal', $reversal['type']);

        foreach ([[$transfer['id'], $this->kdy], [$capital->id, $this->main], [$reversal['id'], $this->kdy]] as [$id, $b]) {
            $this->upload($this->admin, $b, $id, $this->file('r.jpg', self::jpeg()))
                ->assertStatus(422)->assertJsonPath('message', 'Files can only be attached to income and expense entries.');
            // Type before permissions, like void.
            if ($b->is($this->kdy)) {
                $this->upload($this->vw, $this->kdy, $id, $this->file('r.jpg', self::jpeg()))
                    ->assertStatus(422)->assertJsonPath('message', 'Files can only be attached to income and expense entries.');
            }
            $this->listing($this->admin, $b, $id)->assertOk()->assertJsonPath('data', [])->assertJsonPath('can_add', false);
        }
        $this->assertSame(0, Attachment::withoutGlobalScopes()->count());
    }

    public function test_ac13_other_branch_is_404_or_403(): void
    {
        $a1 = $this->attach('r1.jpg');
        $routes = $this->routes($this->e['id'], $a1['id']);

        foreach ($routes as [$method, $url, $data]) {
            $this->as($this->ba, $this->gal)->json($method, $url, $data)->assertNotFound();
            // Not a GAL member: refused by SetBranchContext before any attachment code runs.
            $this->as($this->csh, $this->gal)->json($method, $url, $data)->assertForbidden();
        }
        // A member of both branches sending GAL: 404.
        $both = $this->userIn($this->kdy, 'Accountant');
        $both->branches()->attach($this->gal->id);
        setPermissionsTeamId($this->gal->id);
        $both->assignRole($this->branchRole($this->gal, 'Accountant'));
        foreach ($routes as [$method, $url, $data]) {
            $this->as($both, $this->gal)->json($method, $url, $data)->assertNotFound();
        }
        $this->as($both, $this->kdy)->getJson("/api/v1/transactions/{$this->e['id']}/attachments")->assertOk()->assertJsonCount(1, 'data');
        $this->assertNull(Attachment::find($a1['id'])->removed_at);
        $this->assertSame(1, Attachment::withoutGlobalScopes()->count());
    }

    public function test_ac14_attachment_of_another_entry_is_404(): void
    {
        $a1 = $this->attach('r1.jpg');
        $f = $this->expense('2026-11-11', '50.00', $this->csh);
        $this->download($this->acc, $this->kdy, $f['id'], $a1['id'])->assertNotFound();
        $this->remove($this->acc, $this->kdy, $f['id'], $a1['id'])->assertNotFound();
        $this->assertNull(Attachment::find($a1['id'])->removed_at);
        $this->assertSame(0, AuditLog::where('event', 'attachment.downloaded')->count());
    }

    public function test_ac15_super_admin_all_mode_works_in_the_entry_branch(): void
    {
        $g = $this->expense('2026-11-10', '40.00', $this->ba, $this->galCash);
        $a = $this->upload($this->admin, 'all', $g['id'], $this->file('g.pdf', self::pdf(4096, 'g')))->assertCreated()->json();
        $this->listing($this->admin, 'all', $g['id'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('can_add', true);
        $this->download($this->admin, 'all', $g['id'], $a['id'])->assertOk();
        $this->remove($this->admin, 'all', $g['id'], $a['id'])->assertOk();
        $this->download($this->admin, 'all', $g['id'], $a['id'])->assertOk(); // removed: Super Admin always

        $this->assertSame($this->gal->id, Attachment::withoutGlobalScopes()->findOrFail($a['id'])->branch_id);
        $this->assertStringStartsWith("{$this->gal->id}/{$g['id']}/", Attachment::withoutGlobalScopes()->findOrFail($a['id'])->path);
        $rows = AuditLog::where('event', 'like', 'attachment.%')->orderBy('id')->get();
        $this->assertSame(['attachment.added', 'attachment.downloaded', 'attachment.removed', 'attachment.downloaded'], $rows->pluck('event')->all());
        $this->assertSame([$this->gal->id], $rows->pluck('branch_id')->unique()->values()->all());

        // Super Admin with X-Branch-Id KDY: a GAL entry is 404 like for everyone else.
        foreach ($this->routes($g['id'], $a['id']) as [$method, $url, $data]) {
            $this->sa($this->kdy)->json($method, $url, $data)->assertNotFound();
        }
    }

    public function test_ac16_removed_or_deactivated_member_is_refused(): void
    {
        $a1 = $this->attach('r1.jpg');
        $this->csh->branches()->detach($this->kdy->id);
        foreach ($this->routes($this->e['id'], $a1['id']) as [$method, $url, $data]) {
            $res = $this->as($this->csh, $this->kdy)->json($method, $url, $data)->assertForbidden();
            $this->assertStringNotContainsString('JFIF', $res->getContent());
        }
        $this->csh->branches()->attach($this->kdy->id);
        $this->csh->update(['status' => 'inactive']);
        foreach ($this->routes($this->e['id'], $a1['id']) as [$method, $url, $data]) {
            $this->as($this->csh->fresh(), $this->kdy)->json($method, $url, $data)->assertForbidden();
        }
        $this->assertNull(Attachment::find($a1['id'])->removed_at);
        $this->assertSame(0, AuditLog::where('event', 'attachment.downloaded')->count());
    }

    public function test_ac17_transaction_view_is_required(): void
    {
        $a1 = $this->attach('r1.jpg');
        $role = Role::create(['name' => 'Data entry', 'guard_name' => 'web', 'branch_id' => $this->kdy->id]);
        $role->syncPermissions(['expense.create', 'expense.edit']);
        $u = User::factory()->create(['status' => 'active']);
        $u->branches()->attach($this->kdy->id);
        setPermissionsTeamId($this->kdy->id);
        $u->assignRole($role);

        foreach ($this->routes($this->e['id'], $a1['id']) as [$method, $url, $data]) {
            $this->as($u, $this->kdy)->json($method, $url, $data)->assertForbidden()->assertJsonPath('message', 'Missing permission: transaction.view');
        }
    }

    /** Every attachment route with a body that would succeed for an allowed user. */
    private function routes(int $txnId, int $attId): array
    {
        return [
            ['GET', "/api/v1/transactions/{$txnId}/attachments", []],
            ['POST', "/api/v1/transactions/{$txnId}/attachments", ['file' => $this->file('x.jpg', self::jpeg(seed: 'route'))]],
            ['GET', "/api/v1/transactions/{$txnId}/attachments/{$attId}/download", []],
            ['POST', "/api/v1/transactions/{$txnId}/attachments/{$attId}/remove", ['reason' => 'Wrong bill']],
        ];
    }
}
