<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\DummyHash;
use App\Support\LoginThrottle;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/** Reviewer R1-R3 and QA Q1 (server side) for docs/specs/password-reset.md. */
class FixRoundTest extends PasswordResetTestCase
{
    /** Counts Hash::check calls and forwards everything to the real hasher. */
    private function countChecks(): object
    {
        DummyHash::value(); // made once and cached before counting
        $counter = new class(app('hash')) implements Hasher
        {
            public int $checks = 0;

            public function __construct(private $inner) {}

            public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
            {
                $this->checks++;

                return $this->inner->check($value, $hashedValue, $options);
            }

            public function info($hashedValue): array
            {
                return $this->inner->info($hashedValue);
            }

            public function make(#[\SensitiveParameter] $value, array $options = []): string
            {
                return $this->inner->make($value, $options);
            }

            public function needsRehash($hashedValue, array $options = []): bool
            {
                return $this->inner->needsRehash($hashedValue, $options);
            }

            public function __call($method, $args)
            {
                return $this->inner->{$method}(...$args);
            }
        };
        Hash::swap($counter);

        return $counter;
    }

    private function setPassword(string $email, string $token)
    {
        return $this->public()->postJson('/api/v1/auth/set-password', ['token' => $token, 'email' => $email, 'password' => 'newpass123', 'password_confirmation' => 'newpass123']);
    }

    public function test_r1_reset_and_set_password_run_exactly_one_hash_check_on_every_path(): void
    {
        $u = $this->member('u@x.com');
        $inactive = $this->member('off@x.com', 'Cashier', ['status' => 'inactive']);
        $invited = $this->member('inv@x.com', 'Cashier', ['last_login_at' => null]);
        $offToken = Password::broker()->createToken($inactive);
        $loggedInToken = Password::broker()->createToken($u);
        $expired = Password::broker()->createToken($invited);
        DB::table('password_reset_tokens')->where('email', 'inv@x.com')->update(['created_at' => now()->subMinutes(61)]);
        $counter = $this->countChecks();

        $cases = [
            'reset, unknown email' => fn () => $this->reset('nobody@x.com', 'whatever'),
            'reset, no row' => fn () => $this->reset('c@x.com', 'whatever'),
            'reset, inactive user with a live row' => fn () => $this->reset('off@x.com', $offToken),
            'set-password, unknown email' => fn () => $this->setPassword('nobody@x.com', 'whatever'),
            'set-password, user who logged in before' => fn () => $this->setPassword('u@x.com', $loggedInToken),
            'login, unknown email' => fn () => $this->login('ghost@x.com', 'whatever1'),
        ];
        $this->member('c@x.com');
        foreach ($cases as $name => $call) {
            $counter->checks = 0;
            $this->assertSame(422, $call()->status(), $name);
            $this->assertSame(1, $counter->checks, $name);
        }

        // An expired row of an invited user.
        $counter->checks = 0;
        $this->setPassword('inv@x.com', $expired)->assertStatus(422);
        $this->assertSame(1, $counter->checks, 'set-password, expired row');

        // And the success path: one check too.
        $live = Password::broker()->createToken($invited->fresh());
        $counter->checks = 0;
        $this->setPassword('inv@x.com', $live)->assertNoContent();
        $this->assertSame(1, $counter->checks, 'set-password, success');
    }

    public function test_r2_attempts_are_counted_before_the_password_check(): void
    {
        $this->member('u@x.com');
        // Five guesses in flight at once: all counted, none finished yet.
        for ($n = 0; $n < 5; $n++) {
            LoginThrottle::hit('u@x.com', '10.0.0.1');
        }
        $counter = $this->countChecks();

        $this->login('u@x.com', 'oldpass123')->assertStatus(429)->assertJsonPath('message', 'Too many failed attempts. Try again in 15 minutes.');
        $this->assertSame(0, $counter->checks, 'refused before the password check');
        $this->login('u@x.com', 'oldpass123')->assertStatus(429);

        // A slow 5th guess that fails after the lock started does not write a second row.
        $this->assertFalse(LoginThrottle::lock('u@x.com', '10.0.0.1'));
        $this->assertSame(1, AuditLog::where('event', 'auth.locked_out')->count());
    }

    public function test_r2_a_correct_password_within_the_limit_clears_the_count(): void
    {
        $this->member('u@x.com');
        for ($n = 0; $n < 4; $n++) {
            $this->login('u@x.com', 'wrong-pass1')->assertStatus(422);
        }
        $this->login('u@x.com', 'oldpass123')->assertOk();
        for ($n = 0; $n < 4; $n++) {
            $this->login('u@x.com', 'wrong-pass1')->assertStatus(422);
        }
        $this->login('u@x.com', 'oldpass123')->assertOk();
        $this->assertSame(0, AuditLog::where('event', 'auth.locked_out')->count());
    }

    public function test_r3_an_accent_variant_of_an_email_never_reaches_the_account(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // Act like MySQL's accent-insensitive collation: lower() folds accents, so "ú@x.com" finds "u@x.com".
            DB::connection()->getPdo()->sqliteCreateFunction('lower', fn ($v) => $v === null ? null : strtr(mb_strtolower($v), ['ú' => 'u', 'é' => 'e']), 1);
        }
        $u = $this->member('u@x.com');
        $this->assertSame(1, User::whereRaw('lower(email) = lower(?)', ['ú@x.com'])->count(), 'the database matches the variant');
        $this->assertNull(User::findByEmail('ú@x.com'));
        $this->assertSame($u->id, User::findByEmail('U@x.com')->id);

        $this->login('ú@x.com', 'oldpass123')->assertStatus(422)->assertJsonPath('message', 'Invalid email or password.');
        $this->forgot('ú@x.com')->assertStatus(202);
        Notification::assertNothingSent();
        $this->assertSame(0, AuditLog::where('event', 'like', 'auth.password_reset%')->count());

        $token = Password::broker()->createToken($u);
        $this->reset('ú@x.com', $token)->assertStatus(422)->assertJsonPath('errors.token.0', self::TOKEN_MESSAGE);
        $this->assertTrue(Hash::check('oldpass123', $u->fresh()->password));
        $this->reset('u@x.com', $token)->assertNoContent(); // the token was not used up by the variant
    }

    public function test_q1_a_parallel_reset_consumes_the_token_once(): void
    {
        $u = $this->member('u@x.com');
        $token = $this->tokenFor($u);
        // Simulate the winner of a race: right after this request reads the row, another request removes it.
        $done = false;
        DB::listen(function ($q) use (&$done) {
            if (! $done && str_starts_with(strtolower($q->sql), 'select') && str_contains($q->sql, 'password_reset_tokens')) {
                $done = true;
                DB::table('password_reset_tokens')->where('email', 'u@x.com')->delete();
            }
        });

        $this->reset('u@x.com', $token)->assertStatus(422)->assertJsonPath('errors.token.0', self::TOKEN_MESSAGE);
        $this->assertTrue($done);
        $this->assertTrue(Hash::check('oldpass123', $u->fresh()->password));
        $this->assertSame(0, AuditLog::where('event', 'auth.password_reset')->count());
    }

    public function test_q1_a_database_error_never_reports_the_password_hash(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Uses a SQLite trigger to make the update fail.');
        }
        $u = $this->member('u@x.com');
        $token = $this->tokenFor($u);
        DB::statement("CREATE TRIGGER fail_password BEFORE UPDATE OF password ON users BEGIN SELECT RAISE(ABORT, 'disk full'); END");
        Exceptions::fake();

        $this->reset('u@x.com', 'x'.$token)->assertStatus(422); // a wrong token never reaches the update
        $this->reset('u@x.com', $token)->assertStatus(500);

        Exceptions::assertNotReported(QueryException::class);
        Exceptions::assertReported(function (\RuntimeException $e) {
            return $e->getPrevious() === null && ! str_contains($e->getMessage(), '$2y$') && ! str_contains($e->getMessage(), 'newpass123')
                && str_contains($e->getMessage(), 'could not be saved');
        });
        $this->assertTrue(DB::table('password_reset_tokens')->where('email', 'u@x.com')->exists(), 'rolled back, link still usable');
        $this->assertSame(0, AuditLog::where('event', 'auth.password_reset')->count());
    }
}
