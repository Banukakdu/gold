<?php

namespace Tests\Feature\Controls;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** AG-090 audit log: controls spec AC 20-28, 33, 39. */
class AuditLogTest extends ControlsTestCase
{
    private const NOV = ['from' => '2026-11-01', 'to' => '2026-11-30'];

    private function row(?Branch $b, string $event, array $extra = []): AuditLog
    {
        return AuditLog::create($extra + ['user_id' => $this->admin->id, 'branch_id' => $b?->id, 'event' => $event, 'created_at' => '2026-11-10 05:00:00']);
    }

    private function list($as, array $q = []): array
    {
        return $as->getJson('/api/v1/audit-logs?'.http_build_query($q + self::NOV))->assertOk()->json();
    }

    private function ids($as, array $q = []): array
    {
        return array_column($this->list($as, $q)['data'], 'id');
    }

    private function sheet($response): Worksheet
    {
        return IOFactory::createReader('Xlsx')->load($response->getFile()->getPathname())->getActiveSheet();
    }

    /** Data rows of an audit export: everything below the column header row. */
    private function dataRows(Worksheet $ws): array
    {
        $rows = $ws->toArray();
        $head = array_search('Date time', array_column($rows, 0), true);

        return array_values(array_filter(array_slice($rows, $head + 1), fn ($r) => $r[0] !== null));
    }

    private function seedBranches(): array
    {
        return [
            'kdy' => $this->row($this->kdy, 'account.updated')->id,
            'gal' => $this->row($this->gal, 'account.updated')->id,
            'none' => $this->row(null, 'auth.login')->id,
        ];
    }

    public function test_ac20_cashier_has_no_access(): void
    {
        $this->as($this->csh, $this->kdy)->getJson('/api/v1/audit-logs?'.http_build_query(self::NOV))->assertForbidden();
        $this->as($this->csh, $this->kdy)->getJson('/api/v1/audit-logs/actors')->assertForbidden();
        $this->as($this->csh, $this->kdy)->postJson('/api/v1/audit-logs/export', self::NOV)->assertForbidden();
    }

    public function test_ac21_branch_users_see_their_branch_only(): void
    {
        $r = $this->seedBranches();
        foreach ([$this->acc, $this->vw] as $u) {
            $ids = $this->ids($this->as($u, $this->kdy));
            $this->assertContains($r['kdy'], $ids);
            $this->assertNotContains($r['gal'], $ids);
            $this->assertNotContains($r['none'], $ids);
            $this->assertSame([$this->kdy->id], array_values(array_unique(array_column(array_column($this->list($this->as($u, $this->kdy))['data'], 'branch'), 'id'))));
            $this->as($u, $this->kdy)->getJson('/api/v1/audit-logs?'.http_build_query(self::NOV + ['branches' => [$this->kdy->id]]))->assertForbidden();
            $this->as($u, $this->kdy)->getJson('/api/v1/audit-logs?'.http_build_query(self::NOV + ['branches' => [$this->gal->id]]))->assertForbidden();
        }
    }

    public function test_ac22_super_admin_scopes(): void
    {
        $r = $this->seedBranches();
        $this->row(null, 'period.locked');
        $this->row(null, 'report.exported', ['new_values' => ['name' => 'branch-comparison']]);

        $all = $this->list($this->sa());
        $ids = array_column($all['data'], 'id');
        foreach ($r as $id) {
            $this->assertContains($id, $ids);
        }
        $this->assertContains('Branch Comparison', array_column($all['data'], 'subject'));

        $narrow = $this->list($this->sa(), ['branches' => [(string) $this->gal->id, 'none']])['data'];
        $this->assertNotEmpty($narrow);
        foreach ($narrow as $row) {
            $this->assertContains($row['branch']['id'] ?? null, [$this->gal->id, null]);
        }
        $this->assertContains($r['gal'], array_column($narrow, 'id'));
        $this->assertContains($r['none'], array_column($narrow, 'id'));

        $kdy = $this->list($this->sa($this->kdy))['data'];
        $this->assertSame([$this->kdy->id], array_values(array_unique(array_column(array_column($kdy, 'branch'), 'id'))));
        $this->sa($this->kdy)->getJson('/api/v1/audit-logs?'.http_build_query(self::NOV + ['branches' => ['none']]))->assertForbidden();
        $this->sa()->getJson('/api/v1/audit-logs?'.http_build_query(self::NOV + ['branches' => ['999']]))->assertStatus(422);
    }

    public function test_ac23_event_groups_and_self(): void
    {
        $own = $this->expense('2026-11-10', '100.00');
        $other = $this->expense('2026-11-10', '100.00');
        $rev = $this->expense('2026-11-10', '100.00');
        $this->accKdy()->postJson("/api/v1/transactions/{$own['id']}/void", ['reason' => 'Duplicate'])->assertOk();
        $this->sa($this->kdy)->postJson("/api/v1/transactions/{$other['id']}/void", ['reason' => 'Duplicate'])->assertOk();
        $this->accKdy()->postJson("/api/v1/transactions/{$rev['id']}/reverse", ['reason' => 'Wrong'])->assertCreated();
        $this->row($this->kdy, 'user.updated');
        $this->row($this->kdy, 'role.updated');

        $rows = $this->list($this->sa(), ['event_group' => 'voids', 'self' => 1])['data'];
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertContains($row['event'], ['transaction.voided', 'transaction.reversed']);
            $this->assertTrue($row['self']);
            $this->assertTrue($row['new_values']['self']);
        }
        $this->assertCount(3, $this->list($this->sa(), ['event_group' => 'voids'])['data']);
        $this->assertSame(['user.updated'], array_values(array_unique(array_column($this->list($this->sa(), ['event_group' => 'users'])['data'], 'event'))));
        $this->assertSame(['role.updated'], array_values(array_unique(array_column($this->list($this->sa(), ['event_group' => 'roles'])['data'], 'event'))));
        $this->assertSame('Expense voided', $this->list($this->sa(), ['event' => 'transaction.voided'])['data'][0]['event_label']);

        // Ref no (exact) returns the rows of that transaction.
        $byRef = $this->list($this->sa(), ['ref_no' => $own['ref_no']])['data'];
        $this->assertEqualsCanonicalizing(['transaction.expense', 'transaction.voided'], array_column($byRef, 'event'));
        $this->assertSame([$own['ref_no']], array_values(array_unique(array_column($byRef, 'subject'))));
        // User filter.
        $this->assertSame([$this->admin->id], array_values(array_unique(array_column(array_column($this->list($this->sa(), ['user_id' => $this->admin->id])['data'], 'user'), 'id'))));
    }

    public function test_ac24_dates_are_asia_colombo_days(): void
    {
        $id = $this->row($this->kdy, 'account.updated', ['created_at' => '2026-11-10 17:30:00'])->id; // 23:00 Asia/Colombo
        $rows = $this->list($this->accKdy(), ['from' => '2026-11-10', 'to' => '2026-11-10'])['data'];
        $this->assertSame([$id], array_column($rows, 'id'));
        $this->assertSame('2026-11-10 23:00:00', $rows[0]['created_at_local']);
        $this->assertSame([], $this->ids($this->accKdy(), ['from' => '2026-11-11', 'to' => '2026-11-11']));

        $this->accKdy()->getJson('/api/v1/audit-logs?from=2026-11-10')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->accKdy()->getJson('/api/v1/audit-logs?from=2026-11-10&to=2026-11-09')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->accKdy()->getJson('/api/v1/audit-logs?from=2025-01-01&to=2026-01-02')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->accKdy()->getJson('/api/v1/audit-logs?from=2025-01-02&to=2026-01-02')->assertOk(); // 366 days
    }

    public function test_ac25_detail_values(): void
    {
        $u = $this->userIn($this->kdy, 'Cashier');
        $this->sa($this->kdy)->postJson("/api/v1/users/{$u->id}/role", ['role_id' => $this->branchRole($this->kdy, 'Accountant')->id])->assertOk();
        $row = $this->list($this->accKdy(), ['event' => 'user.role_assigned'])['data'][0];
        $this->assertSame('Cashier', $row['old_values']['role_name']);
        $this->assertSame('Accountant', $row['new_values']['role_name']);
        $this->assertContains('audit.view', array_diff($row['new_values']['permissions'], $row['old_values']['permissions']));
        $this->assertSame($u->name, $row['subject']);
        $this->assertSame('User', $row['auditable_type']);
        $this->assertStringContainsString('role_name: Cashier -> Accountant', $row['summary']);

        $i = $this->income('2026-11-10', '10.00');
        $this->accKdy()->putJson("/api/v1/transactions/{$i['id']}", ['payee_payer' => 'New payer', 'reference' => 'R-1'])->assertOk();
        $row = $this->list($this->accKdy(), ['event' => 'transaction.updated'])['data'][0];
        // MySQL JSON columns do not keep key order, so compare key-sorted arrays.
        $this->assertSame(['payee_payer' => null, 'reference' => null], Arr::sortRecursive($row['old_values']));
        $this->assertSame(['payee_payer' => 'New payer', 'reference' => 'R-1'], Arr::sortRecursive($row['new_values']));
        $this->assertSame('payee_payer: — -> New payer; reference: — -> R-1', $row['summary']);
        $this->assertSame('Entry edited', $row['event_label']);
    }

    public function test_ac26_author_tags(): void
    {
        $x = $this->userIn($this->kdy, 'Accountant');
        $y = $this->userIn($this->kdy, 'Accountant');
        $this->row($this->kdy, 'account.updated', ['user_id' => $x->id]);
        $this->row($this->kdy, 'account.updated', ['user_id' => $y->id]);
        $this->row(null, 'auth.login', ['user_id' => $y->id]);
        $x->update(['status' => 'inactive']);
        DB::table('branch_user')->where('user_id', $y->id)->delete(); // removed from KDY (role rows are not needed for the tag)

        $tags = collect($this->list($this->accKdy())['data'])->filter(fn ($r) => in_array($r['user']['id'] ?? 0, [$x->id, $y->id]))
            ->mapWithKeys(fn ($r) => [$r['user']['id'] => $r['user']]);
        $this->assertSame(['id' => $x->id, 'name' => $x->name, 'tag' => 'inactive'], $tags[$x->id]);
        $this->assertSame('no longer in this branch', $tags[$y->id]['tag']);
        // No tag on rows without a branch.
        $login = collect($this->list($this->sa(), ['event' => 'auth.login'])['data'])->firstWhere('user.id', $y->id);
        $this->assertNull($login['user']['tag']);

        $actors = collect($this->accKdy()->getJson('/api/v1/audit-logs/actors')->assertOk()->json())->keyBy('id');
        $this->assertSame('inactive', $actors[$x->id]['tag']);
        $this->assertSame('no longer in this branch', $actors[$y->id]['tag']);
        $this->assertNull($actors[$this->admin->id]['tag']);
        $this->assertFalse($actors->has($this->ba->id));

        $cells = array_column($this->dataRows($this->sheet($this->accKdy()->post('/api/v1/audit-logs/export', self::NOV)->assertOk())), 2);
        $this->assertContains("{$x->name} (inactive)", $cells);
        $this->assertContains("{$y->name} (no longer in this branch)", $cells);
    }

    public function test_ac27_export_rows_header_and_cap(): void
    {
        $rows = [];
        for ($i = 0; $i < 120; $i++) {
            $rows[] = ['user_id' => $this->acc->id, 'branch_id' => $this->kdy->id, 'event' => 'account.updated', 'created_at' => sprintf('2026-11-%02d %02d:00:00', 2 + $i % 20, $i % 24),
                'new_values' => json_encode(['name' => "N{$i}"])];
        }
        DB::table('audit_logs')->insert($rows);
        $q = self::NOV + ['event' => 'account.updated'];
        $screen = [];
        for ($p = 1; $p <= 3; $p++) {
            $page = $this->list($this->accKdy(), $q + ['page' => $p]);
            $this->assertSame(120, $page['meta']['total']);
            $screen = array_merge($screen, $page['data']);
        }
        $this->assertCount(120, $screen);

        $res = $this->accKdy()->post('/api/v1/audit-logs/export', $q)->assertOk();
        $this->assertSame('attachment; filename="audit-log_2026-11-01_2026-11-30.xlsx"', $res->headers->get('Content-Disposition'));
        $ws = $this->sheet($res);
        $all = $ws->toArray();
        $this->assertSame('Audit log', $all[1][0]);
        $this->assertSame('Branches: KDY Branch KDY', $all[2][0]);
        $this->assertSame('Period: 2026-11-01 to 2026-11-30 (Asia/Colombo days)', $all[3][0]);
        $this->assertSame('Filters: Event: account.updated', $all[4][0]);
        $this->assertSame('Rows: 120', $all[5][0]);
        $this->assertStringStartsWith("Printed by {$this->acc->name} at ", $all[6][0]);
        $this->assertStringEndsWith('(Asia/Colombo)', $all[6][0]);
        $data = $this->dataRows($ws);
        $this->assertCount(120, $data);
        $this->assertSame(array_column($screen, 'created_at_local'), array_column($data, 0));
        $this->assertSame(array_column($screen, 'summary'), array_column($data, 5));

        $log = AuditLog::where('event', 'audit.exported')->sole();
        $this->assertSame(120, $log->new_values['rows']);
        $this->assertSame('account.updated', $log->new_values['filters']['event']);
        $this->assertSame($this->kdy->id, $log->branch_id);

        // Over the cap: 422, no file, no audit row.
        $bulk = [];
        for ($i = 0; $i < ReportService::EXPORT_CAP - 119; $i++) {
            $bulk[] = ['user_id' => $this->acc->id, 'branch_id' => $this->kdy->id, 'event' => 'account.updated', 'created_at' => '2026-11-05 01:00:00'];
        }
        foreach (array_chunk($bulk, 500) as $chunk) {
            DB::table('audit_logs')->insert($chunk);
        }
        $this->accKdy()->postJson('/api/v1/audit-logs/export', $q)->assertStatus(422)->assertExactJson(['message' => 'Too many rows (5,001). Narrow the filters.']);
        $this->assertSame(1, AuditLog::where('event', 'audit.exported')->count());
    }

    public function test_ac28_no_route_changes_audit_rows(): void
    {
        $row = $this->row($this->kdy, 'account.updated');
        foreach (['put', 'patch', 'delete'] as $m) {
            $this->assertContains($this->sa()->json($m, "/api/v1/audit-logs/{$row->id}")->status(), [404, 405]);
            $this->assertContains($this->sa()->json($m, '/api/v1/audit-logs')->status(), [404, 405]);
        }
        $this->assertSame('account.updated', AuditLog::find($row->id)->event);
    }

    public function test_ac33_export_reapplies_the_branch_scope(): void
    {
        $r = $this->seedBranches();
        $this->accKdy()->post('/api/v1/audit-logs/export', self::NOV + ['branches' => [$this->gal->id]])->assertForbidden();
        $this->accKdy()->post('/api/v1/audit-logs/export', self::NOV + ['branches' => ['none']])->assertForbidden();

        $total = $this->list($this->accKdy())['meta']['total'];
        $data = $this->dataRows($this->sheet($this->accKdy()->post('/api/v1/audit-logs/export', self::NOV)->assertOk()));
        $this->assertCount($total, $data);
        $this->assertSame(['KDY'], array_values(array_unique(array_column($data, 1))));
        $this->assertSame(1, AuditLog::where('event', 'audit.exported')->where('branch_id', $this->kdy->id)->count());

        // Super Admin All mode with branches[] = GAL + none.
        $data = $this->dataRows($this->sheet($this->sa()->post('/api/v1/audit-logs/export', self::NOV + ['branches' => [(string) $this->gal->id, 'none']])->assertOk()));
        $this->assertEqualsCanonicalizing(['GAL', '—'], array_values(array_unique(array_column($data, 1))));
        $this->assertNotEmpty($r);
    }

    public function test_ac39_secrets_are_masked_everywhere(): void
    {
        $row = $this->row($this->kdy, 'user.updated', ['old_values' => ['api_token' => 'old-secret-value-1'],
            'new_values' => ['name' => 'x', 'nested' => ['password' => 'hunter2-secret-value', 'deep' => ['Client_Secret' => 'deep-secret-value']], 'token' => 'tok-secret-value']]);

        $res = $this->accKdy()->getJson('/api/v1/audit-logs?'.http_build_query(self::NOV));
        foreach (['old-secret-value-1', 'hunter2-secret-value', 'deep-secret-value', 'tok-secret-value'] as $secret) {
            $this->assertStringNotContainsString($secret, $res->getContent());
        }
        $r = collect($res->json('data'))->firstWhere('id', $row->id);
        $this->assertSame('••••', $r['old_values']['api_token']);
        $this->assertSame('••••', $r['new_values']['nested']['password']);
        $this->assertSame('••••', $r['new_values']['nested']['deep']['Client_Secret']);
        $this->assertSame('••••', $r['new_values']['token']);
        $this->assertStringContainsString('api_token: •••• -> —', $r['summary']);

        $cells = collect($this->dataRows($this->sheet($this->accKdy()->post('/api/v1/audit-logs/export', self::NOV)->assertOk())))->firstWhere(8, 'user.updated');
        $text = implode(' ', array_map('strval', $cells));
        foreach (['old-secret-value-1', 'hunter2-secret-value', 'deep-secret-value', 'tok-secret-value'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertStringContainsString('"password":"••••"', $cells[10]);
    }

    public function test_actors_scope(): void
    {
        $this->row($this->gal, 'account.updated', ['user_id' => $this->ba->id]);
        $this->row($this->kdy, 'account.updated', ['user_id' => $this->vw->id]);
        $kdy = array_column($this->as($this->vw, $this->kdy)->getJson('/api/v1/audit-logs/actors')->assertOk()->json(), 'id');
        $this->assertContains($this->vw->id, $kdy);
        $this->assertNotContains($this->ba->id, $kdy);
        $all = array_column($this->sa()->getJson('/api/v1/audit-logs/actors')->assertOk()->json(), 'id');
        $this->assertContains($this->ba->id, $all);
        $this->assertInstanceOf(User::class, User::find($this->ba->id));
    }
}
