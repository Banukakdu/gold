<?php

namespace App\Exceptions;

use RuntimeException;

/** A business-rule violation while posting money (rendered as HTTP 422). */
class LedgerException extends RuntimeException
{
    public function render()
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
