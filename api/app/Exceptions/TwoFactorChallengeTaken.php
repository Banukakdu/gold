<?php

namespace App\Exceptions;

/** AG-005 R4: a parallel request consumed the challenge first; the loser's transaction is rolled back. */
final class TwoFactorChallengeTaken extends \RuntimeException {}
