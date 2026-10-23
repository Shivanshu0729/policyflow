<?php

namespace App\Exceptions;

use Exception;

class InvalidStateTransitionException extends Exception
{
    public function __construct(
        public readonly string $fromState,
        public readonly string $toState,
        string $message = "",
        int $code = 422,
        ?Throwable $previous = null
    ) {
        $msg = $message ?: "Invalid state transition from '{$fromState}' to '{$toState}'.";
        parent::__construct($msg, $code, $previous);
    }
}
