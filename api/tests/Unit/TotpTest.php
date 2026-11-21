<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

/** AG-005 ACs 1-4: the TOTP core against the RFC 6238 test vectors. */
class TotpTest extends TestCase
{
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // ASCII "12345678901234567890"

    public function test_ac1_rfc6238_sha1_vectors(): void
    {
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::RFC_SECRET));
        $this->assertSame(self::RFC_SECRET, Totp::base32Encode('12345678901234567890'));
        $vectors = [59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924',
            2000000000 => '279037', 20000000000 => '353130'];
        foreach ($vectors as $time => $code) {
            $this->assertSame($code, Totp::codeAt(self::RFC_SECRET, intdiv($time, 30)), "T = {$time}");
        }
    }

    public function test_ac2_match_accepts_one_step_either_side_only(): void
    {
        $secret = Totp::generateSecret();
        $t = 1_800_000_015;
        $at = fn (int $time) => Totp::codeAt($secret, intdiv($time, 30));
        $this->assertSame(intdiv($t - 30, 30), Totp::match($secret, $at($t - 30), $t));
        $this->assertSame(intdiv($t, 30), Totp::match($secret, $at($t), $t));
        $this->assertSame(intdiv($t + 30, 30), Totp::match($secret, $at($t + 30), $t));
        foreach ([$t - 60, $t + 60] as $far) {
            // A far code could equal a near one by chance (1 in a million); only check it when it differs.
            if (! in_array($at($far), [$at($t - 30), $at($t), $at($t + 30)], true)) {
                $this->assertNull(Totp::match($secret, $at($far), $t));
            }
        }
        $this->assertNull(Totp::match($secret, 'abcdef', $t));
        $this->assertNull(Totp::match($secret, '12345', $t));
    }

    public function test_ac3_generated_secrets(): void
    {
        $all = [];
        for ($i = 0; $i < 100; $i++) {
            $s = Totp::generateSecret();
            $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $s);
            $this->assertSame(20, strlen(Totp::base32Decode($s)));
            $all[] = $s;
        }
        $this->assertCount(100, array_unique($all));
    }

    public function test_ac4_uri(): void
    {
        $this->assertSame(
            'otpauth://totp/Lanka%20Traders:u%40x.com?secret=S&issuer=Lanka%20Traders&algorithm=SHA1&digits=6&period=30',
            Totp::uri('Lanka: Traders', 'u@x.com', 'S'),
        );
        $this->assertStringStartsWith('otpauth://totp/'.str_repeat('A', 40).':', Totp::uri(str_repeat('A', 60), 'u@x.com', 'S'));
    }
}
