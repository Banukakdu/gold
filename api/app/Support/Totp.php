<?php

namespace App\Support;

/**
 * AG-005 R1: time-based one-time passwords (RFC 6238 over RFC 4226), written here so no package is needed.
 * SHA1, 6 digits, a 30-second period and T0 = 0. Secrets are Base32 (RFC 4648, upper case, no padding).
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    /** Steps accepted either side of the current one (R1: +-30 s). */
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** R2: 160 random bits as 32 Base32 characters. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The 6-digit code of a time step, left-padded with zeros. */
    public static function codeAt(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        // 8-byte big-endian counter (pack 'J' is unsigned 64-bit big endian).
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function stepAt(int $time): int
    {
        return intdiv($time, self::PERIOD);
    }

    /**
     * The matching step among t-1, t, t+1 (t = floor(now / 30)), or null. Every step is compared (hash_equals), so the
     * time taken does not depend on which one matched. Replay protection (R5) is the caller's job.
     */
    public static function match(string $secret, string $code, int $now): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $t = self::stepAt($now);
        $found = null;
        for ($s = $t - self::WINDOW; $s <= $t + self::WINDOW; $s++) {
            if (hash_equals(self::codeAt($secret, $s), $code) && $found === null) {
                $found = $s;
            }
        }

        return $found;
    }

    /** otpauth:// URI for authenticator apps. The issuer loses any ':' and is cut to 40 characters. */
    public static function uri(string $issuer, string $email, string $secret): string
    {
        $issuer = mb_substr(trim(str_replace(':', '', $issuer)), 0, 40);

        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($email)
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $bytes === '' ? '' : $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(str_replace([' ', '='], '', $text));
        $bits = '';
        foreach (str_split($text) as $c) {
            $i = strpos(self::ALPHABET, $c);
            if ($i === false) {
                throw new \InvalidArgumentException('The secret is not Base32.');
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
