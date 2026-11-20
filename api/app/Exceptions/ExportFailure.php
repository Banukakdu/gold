<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * AG-087 R9: a background export that must fail without a retry. The message is the user-safe text stored in
 * `exports.error`; the code is `exports.failure_code`.
 */
class ExportFailure extends RuntimeException
{
    public const GENERIC = 'The export could not be prepared. Try again later.';

    public function __construct(public readonly string $failureCode, string $message)
    {
        parent::__construct($message);
    }

    public static function accessRevoked(): self
    {
        return new self('access_revoked', 'Your access changed after this export was requested.');
    }

    public static function overLimit(int $rows): self
    {
        return new self('over_limit', 'The export now has '.number_format($rows).' rows; the limit is '
            .number_format((int) config('exports.max_rows')).'. Narrow the filters.');
    }

    public static function tooLarge(): self
    {
        $mb = (int) round((int) config('exports.max_bytes') / 1024 / 1024);

        return new self('too_large', 'The file is larger than '.($mb >= 1 ? "{$mb} MB" : number_format((int) config('exports.max_bytes')).' bytes').'. Narrow the filters.');
    }

    public static function incomplete(): self
    {
        return new self('incomplete', self::GENERIC);
    }

    public static function invalid(): self
    {
        return new self('invalid', 'The filters of this export are no longer valid. Narrow the filters and run it again.');
    }
}
