<?php

namespace App\Exceptions;

use LogicException;

/** Thrown when code tries to update or delete a ledger entry or an audit row (nothing is hard-deleted). */
class ImmutableRecordException extends LogicException {}
