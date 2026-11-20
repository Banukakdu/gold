<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * AG-066 R6: an expense template already used for the period of the submitted date (soft block, HTTP 409). The form
 * offers "Save anyway", which resends with confirm_duplicate: true. Thrown inside the posting transaction, so nothing
 * is written.
 */
class DuplicateTemplateUseException extends RuntimeException
{
    /** @param  array<int, array{id: int, ref_no: ?string, date: string, status: string}>  $duplicateOf */
    public function __construct(string $message, public readonly array $duplicateOf)
    {
        parent::__construct($message);
    }

    public function render()
    {
        return response()->json(['message' => $this->getMessage(), 'duplicate_of' => $this->duplicateOf], 409);
    }
}
