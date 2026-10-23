<?php

namespace App\Exceptions;

class ProviderCommunicationException extends InsurerException
{
    public function __construct(
        string $message = "Communication failure with insurer provider.",
        ?string $providerReference = null,
        bool $isRetriable = true,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            errorCode: 'COMMUNICATION_FAILURE',
            message: $message,
            providerReference: $providerReference,
            isRetriable: $isRetriable,
            code: 502,
            previous: $previous
        );
    }
}
