<?php

namespace Tests\Feature\Transactions;

use Illuminate\Http\UploadedFile;

/**
 * docs/specs/transaction-search.md ACs 2, 9, 14, 15, 17 and accounting review findings 3 and 5 (R7, R10):
 * the scope set comes from BranchContext only, and incoming rows are read-only everywhere.
 */
class IsolationTest extends TransactionSearchTestCase
{
    private function row($response, string $key): array
    {
        $rows = collect($response->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows->has($this->e[$key]->id), "$key is listed");

        return $rows[$this->e[$key]->id];
    }

    public function test_ac2_incoming_top_up_is_listed_read_only(): void
    {
        $r = $this->kdyList();
        $e5 = $this->row($r, 'E5');
        $this->assertSame('in', $e5['direction']);
        $this->assertFalse($e5['in_active_branch']);
        $own = $this->row($r, 'E1');
        $this->assertNull($own['direction']);
        $this->assertTrue($own['in_active_branch']);

        $this->actingAs($this->nimal)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->postJson("/api/v1/transactions/{$this->e['E5']->id}/reverse", ['reason' => 'x'])->assertNotFound();
    }

    public function test_finding5_incoming_transfer_is_read_only_for_the_receiving_branch(): void
    {
        $galAdmin = $this->userIn($this->gal, 'Branch Admin');
        $r = $this->listAs($galAdmin, $this->gal->id);
        foreach (['E6', 'E7'] as $k) {
            $row = $this->row($r, $k);
            $this->assertSame('in', $row['direction'], $k);
            $this->assertFalse($row['in_active_branch'], $k);
        }
        $this->assertSame($this->e['E7']->ref_no, $this->row($r, 'E6')['reversed_by_ref']);
        $this->assertSame($this->e['E6']->ref_no, $this->row($r, 'E7')['reversal_of_ref']);

        $as = fn () => $this->actingAs($galAdmin)->withHeader('X-Branch-Id', (string) $this->gal->id);
        $as()->postJson("/api/v1/transactions/{$this->e['E6']->id}/reverse", ['reason' => 'x'])->assertNotFound();
        $as()->postJson("/api/v1/transactions/{$this->e['E6']->id}/void", ['reason' => 'x'])->assertNotFound();
        $as()->putJson("/api/v1/transactions/{$this->e['E6']->id}", ['description' => 'x'])->assertNotFound();
        $as()->post("/api/v1/transactions/{$this->e['E6']->id}/attachments", ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')],
            ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame('reversed', $this->e['E6']->fresh()->status);

        // Export Type of an incoming reversal (finding 5).
        $sheet = $this->sheet($this->exportAs($galAdmin, $this->gal->id));
        $byRef = collect($sheet['rows'])->keyBy(fn ($row) => (string) $row['Ref no']->getValue());
        $this->assertSame('Reversal (in)', $byRef[$this->e['E7']->ref_no]['Type']->getValue());
        $this->assertSame('Branch transfer (in)', $byRef[$this->e['E6']->ref_no]['Type']->getValue());
        $this->assertSame('In', $byRef[$this->e['E7']->ref_no]['Direction']->getValue());
    }

    public function test_ac9_creators_follow_the_scope_set(): void
    {
        $names = fn ($r) => collect($r->assertOk()->json())->pluck('name')->sort()->values()->all();
        $kdy = fn () => $this->actingAs($this->nimal)->withHeader('X-Branch-Id', (string) $this->kdy->id)->getJson('/api/v1/transactions/creators');
        $this->assertEqualsCanonicalizing(['Kamal Silva', 'Nimal Perera', $this->admin->name], $names($kdy()));

        $this->kamal->branches()->detach($this->kdy->id);
        $kamal = collect($kdy()->json())->firstWhere('id', $this->kamal->id);
        $this->assertSame('no longer in this branch', $kamal['tag']);

        $gal = $this->asAdmin()->getJson('/api/v1/transactions/creators?'.http_build_query(['branches' => [$this->gal->id]]));
        $this->assertEqualsCanonicalizing(['Saman Fernando', 'Nimal Perera'], $names($gal));
    }

    public function test_ac14_branch_filter_in_branch_mode(): void
    {
        $this->kdyList(['branches' => [$this->gal->id]])->assertForbidden()->assertJsonPath('message', 'You do not have access to branch GAL.');
        $this->assertSame($this->rowIds($this->kdyList()), $this->rowIds($this->kdyList(['branches' => [$this->kdy->id]])));
        $this->actingAs($this->nimal)->withHeader('X-Branch-Id', (string) $this->kdy->id)
            ->getJson('/api/v1/transactions/creators?'.http_build_query(['branches' => [$this->gal->id]]))->assertForbidden();
    }

    public function test_ac15_all_mode_and_branch_narrowing(): void
    {
        $all = $this->rowIds($this->asAdmin()->getJson('/api/v1/transactions?per_page=200'));
        foreach (['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E8', 'E9'] as $k) {
            $this->assertContains($this->e[$k]->id, $all, $k);
        }
        $r = $this->asAdmin()->getJson('/api/v1/transactions?'.http_build_query(['branches' => [$this->gal->id]]));
        $this->assertSame($this->ids('E7', 'E6', 'E8'), $this->rowIds($r));
        $this->assertSame('in', $this->row($r, 'E6')['direction']);
        $this->assertNull($this->row($r, 'E8')['direction']);
        $this->assertTrue($this->row($r, 'E6')['in_active_branch']); // All mode: the Super Admin may act on any row
    }

    public function test_ac17_other_branch_account_matches_only_rows_in_scope(): void
    {
        $this->assertSame($this->ids('E7', 'E6'), $this->rowIds($this->kdyList(['account_id' => $this->galBank->id])));
        $this->assertSame([], $this->rowIds($this->kdyList(['account_id' => $this->galCash->id])));
    }

    /** Finding 3 / AC 34b: a KDY export never holds a GAL-only row, whatever the filters. */
    public function test_finding3_kdy_export_never_holds_a_gal_only_row(): void
    {
        $galOnly = [$this->e['E8']->ref_no];
        foreach ([[], ['account_id' => $this->galBank->id], ['account_id' => $this->galCash->id], ['created_by' => $this->saman->id],
            ['q' => 'CEB'], ['branches' => [$this->kdy->id]]] as $filters) {
            $sheet = $this->sheet($this->exportAs($this->nimal, $this->kdy->id, $filters));
            $this->assertSame([], array_intersect($galOnly, $this->refs($sheet)), json_encode($filters));
            $this->assertContains('Branches: KDY Kandy', $sheet['lines']);
            foreach ($sheet['rows'] as $row) {
                $this->assertSame([], array_intersect(['Saman Fernando'], [(string) $row['Created by']->getValue()]));
            }
        }
        $this->exportAs($this->nimal, $this->kdy->id, ['branches' => [$this->gal->id]])->assertForbidden();
    }
}
