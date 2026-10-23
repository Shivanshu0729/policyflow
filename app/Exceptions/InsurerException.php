<?php

namespace App\Exceptions;

use Exception;

class InsurerException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message = "",
        public readonly ?string $providerReference = null,
        public readonly bool $isRetriable = true,
        int $code = 502,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message ?: "Insurer error: {$errorCode}", $code, $previous);
    }
}
