<?php

namespace App\Services\Provider;

interface InsurerClientInterface
{
    /**
     * Submit policy issuance request to the insurer.
     */
    public function submitPolicy(array $data, ?string $scenario = null): ProviderResponse;

    /**
     * Query insurer for status of an existing request/reference.
     */
    public function checkStatus(string $providerReference): ProviderResponse;
}
