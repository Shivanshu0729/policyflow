<?php

namespace App\Services\Provider;

class ProviderResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly string $providerReference,
        public readonly ?string $policyNumber = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $rawPayload = []
    ) {}

    public function isIssued(): bool
    {
        return $this->status === 'ISSUED';
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isTimeout(): bool
    {
        return $this->status === 'TIMEOUT';
    }

    public function isFailed(): bool
    {
        return $this->status === 'FAILED';
    }
}
