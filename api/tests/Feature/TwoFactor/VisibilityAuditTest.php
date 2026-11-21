<?php

namespace Tests\Feature\TwoFactor;

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/** AG-005 ACs 50-52 and 65: who sees 2FA data, no secrets anywhere, no delete route, challenge rows kept. */
class VisibilityAuditTest extends TwoFactorTestCase
{
    /** Real notifications on the database queue, so the queued payloads can be searched (AC 51). */
    protected bool $fakeNotifications = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    public function test_ac50_only_super_admins_see_two_factor_fields(): void
    {
        $ba = $this->member('ba@x.com', 'Branch Admin');
        $c = $this->member('cash@x.com');
        $this->enable($c);

        $this->as($ba, $this->kdy->id)->getJson('/api/v1/users')->assertOk()->assertJsonMissingPath('data.0.two_factor');
        $this->assertStringNotContainsString('two_factor', $this->as($ba, $this->kdy->id)->getJson('/api/v1/users')->getContent());
        $this->assertStringNotContainsString('two_factor', $this->as($ba, $this->kdy->id)->getJson("/api/v1/users/{$c->id}")->assertOk()->getContent());

        $rows = collect($this->as($this->admin)->getJson('/api/v1/users')->assertOk()->json('data'))->keyBy('email');
        $this->assertSame(['enabled' => true, 'required' => false, 'recovery_codes_left' => 10],
            array_intersect_key($rows['cash@x.com']['two_factor'], array_flip(['enabled', 'required', 'recovery_codes_left'])));
        $this->assertFalse($rows['ba@x.com']['two_factor']['enabled']);
        $this->requireForAdmins();
        $this->admin->forceFill(['password' => self::PASSWORD])->save();
        $this->enable($this->admin);
        $this->as($this->admin)->getJson("/api/v1/users/{$ba->id}")->assertOk()
            ->assertJsonPath('two_factor.enabled', false)->assertJsonPath('two_factor.required', true)
            ->assertJsonPath('two_factor.recovery_codes_left', 0)->assertJsonPath('two_factor.reset_blocked_since', null);
        $this->as($this->admin)->getJson("/api/v1/users/{$c->id}")->assertOk()
            ->assertJsonPath('two_factor.enabled', true)->assertJsonPath('two_factor.recovery_codes_left', 10);
    }

    public function test_ac51_no_secret_code_or_token_is_stored_anywhere(): void
    {
        $log = storage_path('logs/laravel.log');
        $logBefore = File::exists($log) ? File::size($log) : 0;
        $this->admin->forceFill(['password' => self::PASSWORD])->save();
        $this->superAdmin('sa2@x.com');

        // Turn on, sign in with a code and with a recovery code, new codes, move phone (F1, F2, F5, F8).
        $c = $this->member('cash@x.com');
        [$s1, $codes1] = $this->enable($c);
        $this->signIn($c, $s1);
        $this->seen[] = $ch = $this->challenge($c);
        $this->seen[] = $this->verify($ch, ['recovery_code' => $codes1[0]])->assertOk()->json('token');
        $codes2 = $this->as($c)->postJson('/api/v1/me/two-factor/recovery-codes', ['password' => self::PASSWORD, 'code' => $this->code($s1)])
            ->assertOk()->json('recovery_codes');
        array_push($this->seen, ...$codes2);
        $this->nextStep();
        $s2 = $this->as($c)->postJson('/api/v1/me/two-factor/setup', ['password' => self::PASSWORD, 'code' => $this->code($s1)])->assertOk()->json('secret');
        $this->seen[] = $s2;
        $this->nextStep(); // R5: the M2 code used this step
        $this->as($c)->postJson('/api/v1/me/two-factor/confirm', ['code' => $this->code($s2)])->assertOk();
        $this->nextStep();

        // A lock (R6) on another user.
        $d = $this->member('d@x.com');
        [$sd] = $this->enable($d);
        for ($i = 0; $i < 10; $i++) {
            if ($i % 4 === 0) {
                $this->seen[] = $chd = $this->challenge($d, 'challenge', "10.4.0.{$i}");
            }
            $this->verify($chd, ['code' => $this->wrongCode($sd)], "10.4.1.{$i}")->assertStatus(422);
        }

        // The setting (S6), forced enrolment (F3), the reset (A1), turning off (F7) and the console (C1).
        [$sa] = $this->enable($this->admin);
        $this->as($this->admin)->putJson('/api/v1/settings', ['two_factor_required_for_admins' => true, 'password' => self::PASSWORD,
            'code' => $this->code($sa)])->assertOk();
        $this->nextStep();
        $ba = $this->member('ba@x.com', 'Branch Admin');
        $this->seen[] = $che = $this->challenge($ba, 'enrol');
        $this->seen[] = $s3 = $this->public()->postJson('/api/v1/auth/two-factor/enrol/start', ['challenge_token' => $che])->json('secret');
        $r = $this->public()->postJson('/api/v1/auth/two-factor/enrol/confirm', ['challenge_token' => $che, 'code' => $this->code($s3)])->assertOk();
        array_push($this->seen, $r->json('token'), ...$r->json('recovery_codes'));
        $this->as($this->admin)->postJson("/api/v1/users/{$c->id}/two-factor/reset", ['reason' => 'Checked in person', 'password' => self::PASSWORD,
            'code' => $this->code($sa)])->assertOk();
        $this->nextStep();
        $this->requireForAdmins(false);
        $this->as($ba)->postJson('/api/v1/me/two-factor/disable', ['password' => self::PASSWORD, 'code' => $this->code($s3)])->assertNoContent();
        $this->assertSame(1, Artisan::call('auth:two-factor-reset', ['email' => 'd@x.com', '--reason' => 'Lost phone, verified in person',
            '--confirm' => 'd@x.com', '--no-interaction' => true])); // R16: d has a recent lock
        $this->enable($this->member('e@x.com'));
        $this->assertSame(0, Artisan::call('auth:two-factor-reset', ['email' => 'e@x.com', '--reason' => 'Lost phone, verified in person',
            '--confirm' => 'e@x.com', '--no-interaction' => true]));

        $this->assertGreaterThan(0, DB::table('jobs')->count(), 'the emails are queued');
        $haystacks = [
            'audit_logs' => DB::table('audit_logs')->get()->toJson(),
            'jobs' => DB::table('jobs')->get()->toJson(),
            'notifications' => DB::table('notifications')->get()->toJson(),
            'laravel.log' => File::exists($log) ? substr((string) File::get($log), $logBefore) : '',
        ];
        $hashes = array_merge(
            DB::table('two_factor_recovery_codes')->pluck('code_hash')->all(),
            DB::table('two_factor_challenges')->pluck('token_hash')->all(),
            DB::table('users')->whereNotNull('two_factor_secret')->pluck('two_factor_secret')->all(),
        );
        $needles = array_values(array_unique(array_filter(array_merge($this->seen, $hashes))));
        $this->assertGreaterThan(60, count($needles));
        foreach ($haystacks as $name => $text) {
            foreach ($needles as $needle) {
                $plain = strlen($needle) === 11 ? strtoupper(str_replace('-', '', $needle)) : null;
                $this->assertStringNotContainsString($needle, $text, "{$name} contains a secret, code, token or hash");
                if ($plain) {
                    $this->assertStringNotContainsString($plain, strtoupper($text), "{$name} contains a recovery code");
                }
            }
        }

        // Every 2FA row has no branch, a label and is in the "Logins" group.
        $events = AuditLog::where('event', 'like', 'auth.two_factor_%')->get();
        $this->assertSame(['auth.two_factor_disabled', 'auth.two_factor_enabled', 'auth.two_factor_locked', 'auth.two_factor_moved',
            'auth.two_factor_recovery_regenerated', 'auth.two_factor_recovery_used', 'auth.two_factor_reset', 'auth.two_factor_setup_started'],
            $events->pluck('event')->unique()->sort()->values()->all());
        foreach ($events as $row) {
            $this->assertNull($row->branch_id, $row->event);
            $this->assertArrayHasKey($row->event, AuditLogController::LABELS);
            $this->assertTrue(str_starts_with($row->event, AuditLogController::GROUPS['logins'][0][0]));
        }
        $listed = collect($this->as($this->admin)->getJson('/api/v1/audit-logs?from=2026-11-20&to=2026-11-20&event_group=logins&event=auth.two_factor_reset')
            ->assertOk()->json('data'));
        $this->assertSame(['2FA reset', '2FA reset'], $listed->pluck('event_label')->all());
        $this->assertSame(['Lost phone, verified in person', 'Checked in person'], $listed->pluck('new_values.reason')->all());
        // A Branch Admin with audit.view never sees them (no branch).
        $this->as($this->member('viewer@x.com', 'Viewer'), $this->kdy->id)
            ->getJson('/api/v1/audit-logs?from=2026-11-20&to=2026-11-20&event_group=logins')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_ac52_no_delete_route_on_any_new_path(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'two-factor')) {
                $this->assertNotContains('DELETE', $route->methods(), $route->uri());
            }
        }
        $c = $this->member('cash@x.com');
        foreach (['/api/v1/auth/two-factor/verify', '/api/v1/auth/two-factor/enrol/start', '/api/v1/auth/two-factor/enrol/confirm'] as $path) {
            $this->public()->deleteJson($path)->assertStatus(405);
        }
        foreach (['/api/v1/me/two-factor', '/api/v1/me/two-factor/setup', '/api/v1/me/two-factor/confirm', '/api/v1/me/two-factor/recovery-codes',
            '/api/v1/me/two-factor/disable'] as $path) {
            $this->as($c)->deleteJson($path)->assertStatus(405);
        }
        $this->as($this->admin)->deleteJson("/api/v1/users/{$c->id}/two-factor/reset")->assertStatus(405);
    }

    public function test_ac65_challenge_rows_are_kept(): void
    {
        $c = $this->member('cash@x.com');
        $this->travel(-400)->days();
        [$secret] = $this->enable($c);
        $old = $this->challenge($c, 'challenge', '10.3.0.1');
        $this->verify($old, ['code' => $this->wrongCode($secret)], '10.3.0.1')->assertStatus(422);
        $this->travel(369)->days();
        $consumed = $this->challenge($c, 'challenge', '10.3.0.2');
        $this->verify($consumed, ['code' => $this->code($secret)], '10.3.0.2')->assertOk();
        $this->challenge($c, 'challenge', '10.3.0.3'); // left open, expires
        $this->travel(31)->days();
        $before = DB::table('two_factor_challenges')->orderBy('id')->get(['id', 'attempts', 'ip', 'user_agent', 'consumed_at'])->toArray();
        $this->assertCount(3, $before);

        foreach (Artisan::all() as $name => $command) {
            if (str_contains($name, 'prune') && ! str_starts_with($name, 'queue:') && ! str_starts_with($name, 'model:') && ! str_starts_with($name, 'sanctum:')) {
                Artisan::call($name, ['--no-interaction' => true]);
            }
        }
        Artisan::call('schedule:run');
        $this->assertEquals($before, DB::table('two_factor_challenges')->orderBy('id')->get(['id', 'attempts', 'ip', 'user_agent', 'consumed_at'])->toArray());
        $this->assertSame(1, (int) $before[0]->attempts);
        $this->assertSame('10.3.0.1', $before[0]->ip);
        $this->verify($old, ['code' => $this->code($secret)])->assertStatus(422)->assertJsonValidationErrors('challenge_token');

        // Nothing in app/ or routes/ deletes from the table.
        foreach (['app', 'routes'] as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                foreach (preg_split('/\R/', (string) File::get($file->getPathname())) as $line) {
                    $this->assertFalse(str_contains($line, 'two_factor_challenges') && str_contains(strtolower($line), 'delete'),
                        "{$file->getRelativePathname()}: {$line}");
                }
            }
        }
    }
}
