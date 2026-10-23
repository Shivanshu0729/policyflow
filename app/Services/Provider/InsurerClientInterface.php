<?php

namespace App\Services\Provider;

interface InsurerClientInterface
{

    public function submitPolicy(array $data, ?string $scenario = null): ProviderResponse;

    public function checkStatus(string $providerReference): ProviderResponse;
}
