<?php

namespace Tests\Feature\BankStatementAttachments;

use App\Exceptions\ImmutableRecordException;
use App\Models\Attachment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/** AG-033b database protections: bank-statement-attachment.md AC 16 (SQLite here, MySQL in the api-mysql job). */
class SchemaTest extends StatementFilesTestCase
{
    private function assertRefused(array $calls, string $message = 'attachments rows'): void
    {
        foreach ($calls as $label => $call) {
            try {
                DB::transaction($call);
                $this->fail("raw write '{$label}' did not throw");
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $label);
            }
        }
    }

    public function test_ac16_triggers_refuse_raw_changes_to_statement_files(): void
    {
        $a = $this->attach('oct.pdf');
        $s2 = $this->statementTwo();
        $row = fn () => (array) DB::table('attachments')->where('id', $a['id'])->first();
        $before = $row();
        $count = DB::table('attachments')->count();
        $base = ['branch_id' => $this->kdy->id, 'disk' => 'attachments', 'original_name' => 'x.pdf', 'mime' => 'application/pdf',
            'size_bytes' => 10, 'sha256' => str_repeat('b', 64), 'uploaded_by' => $this->acc->id, 'created_at' => now(), 'updated_at' => now()];

        $this->assertRefused([
            'delete' => fn () => DB::table('attachments')->where('id', $a['id'])->delete(),
            'bank_statement_id' => fn () => DB::table('attachments')->where('id', $a['id'])->update(['bank_statement_id' => $s2->id]),
            'sha256' => fn () => DB::table('attachments')->where('id', $a['id'])->update(['sha256' => str_repeat('0', 64)]),
            'to an entry' => fn () => DB::table('attachments')->where('id', $a['id'])->update(['transaction_id' => $this->e1->id]),
            'replaced_by_id alone' => fn () => DB::table('attachments')->where('id', $a['id'])->update(['replaced_by_id' => $a['id']]),
            'both parents' => fn () => DB::table('attachments')->insert($base + ['path' => 'p/both.pdf', 'transaction_id' => $this->e1->id, 'bank_statement_id' => $this->s1->id]),
            'no parent' => fn () => DB::table('attachments')->insert($base + ['path' => 'p/none.pdf']),
        ]);
        $this->assertEquals($before, $row());
        $this->assertSame($count, DB::table('attachments')->count());

        // The one-time removal (with replaced_by_id) is allowed at database level, then frozen.
        $this->remove($a['id'])->assertOk();
        $removed = $row();
        $this->assertRefused([
            'reason again' => fn () => DB::table('attachments')->where('id', $a['id'])->update(['removal_reason' => 'Rewritten']),
            'undo' => fn () => DB::table('attachments')->where('id', $a['id'])->update(['removed_at' => null, 'removed_by' => null]),
            'replaced_by_id later' => fn () => DB::table('attachments')->where('id', $a['id'])->update(['replaced_by_id' => $a['id']]),
        ]);
        $this->assertEquals($removed, $row());

        // The model refuses too.
        $model = Attachment::findOrFail($a['id']);
        foreach ([fn () => $model->delete(), fn () => $model->update(['bank_statement_id' => $s2->id])] as $call) {
            try {
                $call();
                $this->fail('the model allowed a change');
            } catch (ImmutableRecordException) {
                $this->addToAssertionCount(1);
            }
        }

        // A statement with files cannot be deleted (FK restrict).
        $this->assertRefused(['statement' => fn () => DB::table('bank_statements')->where('id', $this->s1->id)->delete()], '');
        $this->assertTrue(DB::table('bank_statements')->where('id', $this->s1->id)->exists());
    }

    public function test_ac16_there_is_no_delete_route(): void
    {
        foreach (Route::getRoutes() as $r) {
            if (str_contains($r->uri(), 'reconciliation/statements/{statement}/attachments')) {
                $this->assertNotContains('DELETE', $r->methods(), $r->uri());
            }
        }
        $a = $this->attach('oct.pdf');
        $this->as($this->acc, $this->kdy)->deleteJson($this->base()."/{$a['id']}")->assertStatus(404);
        $this->as($this->acc, $this->kdy)->deleteJson($this->base())->assertStatus(405);
        $this->assertSame(1, Attachment::count());
    }
}
