<?php

namespace App\Exceptions;

class ProviderTimeoutException extends InsurerException
{
    public function __construct(
        string $message = "Provider call timed out. Policy status is indeterminate.",
        ?string $providerReference = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            errorCode: 'TIMEOUT',
            message: $message,
            providerReference: $providerReference,
            isRetriable: false,
            code: 504,
            previous: $previous
        );
    }
}
