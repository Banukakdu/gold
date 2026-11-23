<?php

namespace Tests\Feature\BankStatementAttachments;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/** AG-033b permissions and branch isolation: bank-statement-attachment.md AC 8, 9, 10. */
class AccessTest extends StatementFilesTestCase
{
    /** Every route, with a body that would succeed for an allowed user. */
    private function routes(BankStatement $s, int $attId, ?int $accountId = null): array
    {
        $base = $this->base($s, $accountId);

        return [
            ['GET', $base, []],
            ['POST', $base, ['file' => $this->file('new.pdf', self::bytes('new.pdf'))]],
            ['POST', $base, ['file' => $this->file('rep.pdf', self::bytes('rep.pdf')), 'replaces_id' => $attId, 'reason' => 'Wrong month']],
            ['GET', "{$base}/{$attId}/download", []],
            ['POST', "{$base}/{$attId}/remove", ['reason' => 'Wrong month']],
        ];
    }

    private function hit(User $u, Branch|string $branch, array $route)
    {
        [$method, $url, $data] = $route;
        $has = fn ($d) => collect($d)->contains(fn ($v) => $v instanceof UploadedFile);

        return $this->as($u, $branch)->withHeader('Accept', 'application/json')->{$has($data) ? 'post' : 'json'}(...($has($data) ? [$url, $data] : [$method, $url, $data]));
    }

    private function userWith(array $keys): User
    {
        $role = Role::create(['name' => 'Checker '.implode(' ', $keys), 'guard_name' => 'web', 'branch_id' => $this->kdy->id]);
        $role->syncPermissions($keys);
        $u = User::factory()->create(['status' => 'active']);
        $u->branches()->attach($this->kdy->id);
        setPermissionsTeamId($this->kdy->id);
        $u->assignRole($role);

        return $u;
    }

    public function test_ac8_viewer_reads_cashier_gets_nothing_removed_files_for_auditors(): void
    {
        $a = $this->attach('oct.pdf');
        [$list, $add, $replace, $download, $remove] = $this->routes($this->s1, $a['id']);

        $this->hit($this->vw, $this->kdy, $list)->assertOk()->assertJsonPath('data.0.id', $a['id'])->assertJsonPath('can_add', false);
        $this->assertSame(self::bytes('oct.pdf'), $this->hit($this->vw, $this->kdy, $download)->assertOk()->getContent());
        foreach ([$add, $replace, $remove] as $r) {
            $this->hit($this->vw, $this->kdy, $r)->assertForbidden()->assertJsonPath('message', 'Missing permission: account.reconcile');
        }
        foreach ([$list, $add, $replace, $download, $remove] as $r) {
            $res = $this->hit($this->csh, $this->kdy, $r)->assertForbidden()->assertJsonPath('message', 'Missing permission: report.view');
            $this->assertStringNotContainsString('%PDF', $res->getContent());
        }
        // report.view alone is enough to read (the independent checker); account.reconcile alone too.
        $this->hit($this->userWith(['report.view']), $this->kdy, $list)->assertOk();
        $this->hit($this->userWith(['account.reconcile']), $this->kdy, $list)->assertOk();

        $this->remove($a['id'])->assertOk();
        $this->hit($this->vw, $this->kdy, $download)->assertOk();
        $this->hit($this->ba, $this->kdy, $download)->assertOk();
        $this->hit($this->userWith(['report.view', 'account.view']), $this->kdy, $download)->assertForbidden()
            ->assertJsonPath('message', 'Only auditors can download removed files.');
        $this->assertSame(1, Attachment::withoutGlobalScopes()->count());
    }

    public function test_ac9_branch_isolation_and_scoped_bindings(): void
    {
        $ga = $this->attach('gal.pdf', u: $this->gacc, s: $gs = $this->galStatement());
        $ka = $this->attach('oct.pdf');
        $s2 = $this->statementTwo();
        $kdy2 = $this->bankIn($this->kdy, 'KDY Bank 2');
        $before = [Attachment::withoutGlobalScopes()->count(), AuditLog::count()];

        // ACC (KDY header): a GAL statement or file is 404, a forged id under KDY too.
        foreach ($this->routes($gs, $ga['id']) as $r) {
            $this->hit($this->acc, $this->kdy, $r)->assertNotFound();
        }
        foreach ($this->routes($this->s1, $ga['id']) as $i => $r) {
            if (in_array($i, [3, 4], true)) {
                $this->hit($this->acc, $this->kdy, $r)->assertNotFound();
            }
        }
        // A forged replaces_id is not a file of this statement.
        $this->hit($this->acc, $this->kdy, $this->routes($this->s1, $ga['id'])[2])->assertStatus(422)
            ->assertJsonPath('message', 'Choose an active file of this statement to replace.');
        // A KDY file under another KDY statement, and a statement under another account.
        foreach ($this->routes($s2, $ka['id']) as $i => $r) {
            if (in_array($i, [3, 4], true)) {
                $this->hit($this->acc, $this->kdy, $r)->assertNotFound();
            }
        }
        foreach ($this->routes($this->s1, $ka['id'], $kdy2->id) as $r) {
            $this->hit($this->acc, $this->kdy, $r)->assertNotFound();
        }
        // SA with X-Branch-Id KDY: a GAL statement is 404.
        foreach ($this->routes($gs, $ga['id']) as $r) {
            $this->hit($this->admin, $this->kdy, $r)->assertNotFound();
        }
        $this->assertSame($before, [Attachment::withoutGlobalScopes()->count(), AuditLog::count()]);

        // SA in All mode: every route works on the GAL statement; the row and every audit row are GAL's.
        $first = AuditLog::max('id');
        $this->hit($this->admin, 'all', $this->routes($gs, $ga['id'])[0])->assertOk()->assertJsonPath('data.0.id', $ga['id']);
        $new = $this->hit($this->admin, 'all', $this->routes($gs, $ga['id'])[1])->assertCreated()->json();
        $this->hit($this->admin, 'all', $this->routes($gs, $ga['id'])[3])->assertOk();
        $this->hit($this->admin, 'all', ['POST', $this->base($gs), ['file' => $this->file('rep.pdf', self::bytes('rep.pdf')), 'replaces_id' => $ga['id'], 'reason' => 'Wrong month']])->assertCreated();
        $this->hit($this->admin, 'all', $this->routes($gs, $new['id'])[4])->assertOk();
        $this->assertSame($this->gal->id, Attachment::withoutGlobalScopes()->findOrFail($new['id'])->branch_id);
        $this->assertStringStartsWith("{$this->gal->id}/bank-statements/{$gs->id}/", Attachment::withoutGlobalScopes()->findOrFail($new['id'])->path);
        $rows = AuditLog::where('id', '>', $first)->get();
        $this->assertCount(5, $rows); // added, downloaded, added + removed (replace), removed
        foreach ($rows as $row) {
            $this->assertSame($this->gal->id, $row->branch_id, $row->event);
            $this->assertSame([BankStatement::class, $gs->id], [$row->auditable_type, $row->auditable_id]);
        }
    }

    public function test_ac9_non_bank_account_is_refused(): void
    {
        // A statement can only exist on a bank account, so the cash account's binding is 404 for the statement.
        $this->as($this->acc, $this->kdy)->getJson($this->base($this->s1, $this->kdyCash->id))->assertNotFound();
    }

    public function test_ac10_removed_or_deactivated_member_is_refused(): void
    {
        $a = $this->attach('oct.pdf');
        $this->acc->branches()->detach($this->kdy->id);
        foreach ($this->routes($this->s1, $a['id']) as $r) {
            $res = $this->hit($this->acc, $this->kdy, $r)->assertForbidden();
            $this->assertStringNotContainsString('%PDF', $res->getContent());
        }
        $this->acc->branches()->attach($this->kdy->id);
        $this->acc->update(['status' => 'inactive']);
        foreach ($this->routes($this->s1, $a['id']) as $r) {
            $res = $this->hit($this->acc->fresh(), $this->kdy, $r)->assertForbidden();
            $this->assertStringNotContainsString('%PDF', $res->getContent());
        }
        $this->assertNull(Attachment::findOrFail($a['id'])->removed_at);
        $this->assertSame(1, Attachment::withoutGlobalScopes()->count());
        $this->assertSame(0, AuditLog::where('event', 'attachment.downloaded')->count());
    }

    private function galStatement(): BankStatement
    {
        $top = $this->allocate($this->galBank, '1000.00', '2026-10-02');
        $this->tick([[$top, '2026-10-31']], $this->gacc, acc: $this->galBank)->assertOk();
        $id = $this->complete('2026-10-31', '1000.00', $this->gacc, $this->galBank)->assertCreated()->json('id');

        return BankStatement::withoutGlobalScopes()->findOrFail($id);
    }
}
