<?php

namespace App\Services\Provider;

use App\Enums\MockScenario;
use App\Exceptions\ProviderCommunicationException;
use App\Exceptions\ProviderTimeoutException;
use App\Models\MockInsurerLog;
use App\Models\MockInsurerPolicy;
use Illuminate\Support\Str;

class MockInsurerService implements InsurerClientInterface
{
    /**
     * Submit policy issuance request to the simulated external insurer.
     */
    public function submitPolicy(array $data, ?string $scenario = null): ProviderResponse
    {
        $selectedScenario = $scenario ?: (config('policyflow.mock_scenario') ?: MockScenario::SUCCESS->value);

        // Deterministic or generated provider reference
        $providerRef = 'MOCK-PROV-' . strtoupper(Str::random(10));
        $idempotencyKey = $data['idempotency_key'] ?? ('IDEM-' . Str::uuid());
        $clientRequestId = $data['client_request_id'] ?? 'REQ-UNKNOWN';
        $agentId = $data['agent_id'] ?? 'AGT-UNKNOWN';

        // Check if this idempotency key was already submitted to the insurer
        $existing = MockInsurerPolicy::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            MockInsurerLog::create([
                'provider_reference' => $existing->provider_reference,
                'request_type' => 'IDEMPOTENT_SUBMIT_RETRY',
                'request_payload' => $data,
                'response_payload' => [
                    'status' => $existing->status,
                    'policy_number' => $existing->policy_number,
                    'message' => 'Idempotent replay from provider ledger',
                ],
            ]);

            return new ProviderResponse(
                success: $existing->status === 'ISSUED',
                status: $existing->status,
                providerReference: $existing->provider_reference,
                policyNumber: $existing->policy_number,
                rawPayload: ['replayed' => true, 'provider_id' => $existing->id]
            );
        }

        switch ($selectedScenario) {
            case MockScenario::TEMPORARY_FAILURE->value:
                MockInsurerLog::create([
                    'provider_reference' => $providerRef,
                    'request_type' => 'SUBMISSION_TEMPORARY_FAILURE',
                    'request_payload' => $data,
                    'response_payload' => ['error' => 'Simulated upstream gateway timeout/503'],
                ]);

                throw new ProviderCommunicationException(
                    message: "Insurer gateway returned temporary 503 Service Unavailable.",
                    providerReference: $providerRef,
                    isRetriable: true
                );

            case MockScenario::TIMEOUT_AFTER_ISSUE->value:
                // CRUCIAL RELIABILITY SCENARIO:
                // The insurer receives the request, successfully generates and saves the policy in its OWN persistent database,
                // but the network connection breaks or the client times out while waiting for HTTP response!
                $policyNumber = 'INSU-' . date('Ymd') . '-' . strtoupper(Str::random(8));

                MockInsurerPolicy::create([
                    'provider_reference' => $providerRef,
                    'idempotency_key' => $idempotencyKey,
                    'client_request_id' => $clientRequestId,
                    'agent_id' => $agentId,
                    'policy_number' => $policyNumber,
                    'customer_name' => $data['customer_name'] ?? 'N/A',
                    'plan_code' => $data['plan_code'] ?? 'BASIC',
                    'sum_insured' => (int) ($data['sum_insured'] ?? 100000),
                    'premium_amount' => $data['premium_amount'] ?? 0.00,
                    'status' => 'ISSUED',
                    'scenario_applied' => MockScenario::TIMEOUT_AFTER_ISSUE->value,
                    'callback_url' => $data['callback_url'] ?? null,
                ]);

                MockInsurerLog::create([
                    'provider_reference' => $providerRef,
                    'request_type' => 'SUBMISSION_TIMEOUT_AFTER_ISSUE',
                    'request_payload' => $data,
                    'response_payload' => ['status' => 'ISSUED_IN_PROVIDER_LEDGER_BUT_TIMEOUT_DELIVERED'],
                ]);

                // Simulate client timeout
                throw new ProviderTimeoutException(
                    message: "Connection timed out after 30000ms while waiting for provider response.",
                    providerReference: $providerRef
                );

            case MockScenario::DELAYED_CALLBACK->value:
                // Provider accepts the request, marks it PENDING in its records, and will confirm later via callback
                MockInsurerPolicy::create([
                    'provider_reference' => $providerRef,
                    'idempotency_key' => $idempotencyKey,
                    'client_request_id' => $clientRequestId,
                    'agent_id' => $agentId,
                    'policy_number' => 'PENDING-' . strtoupper(Str::random(6)),
                    'customer_name' => $data['customer_name'] ?? 'N/A',
                    'plan_code' => $data['plan_code'] ?? 'BASIC',
                    'sum_insured' => (int) ($data['sum_insured'] ?? 100000),
                    'premium_amount' => $data['premium_amount'] ?? 0.00,
                    'status' => 'PENDING',
                    'scenario_applied' => MockScenario::DELAYED_CALLBACK->value,
                    'callback_url' => $data['callback_url'] ?? null,
                ]);

                MockInsurerLog::create([
                    'provider_reference' => $providerRef,
                    'request_type' => 'SUBMISSION_DELAYED_CALLBACK_ACCEPTED',
                    'request_payload' => $data,
                    'response_payload' => ['status' => 'PENDING', 'provider_reference' => $providerRef],
                ]);

                return new ProviderResponse(
                    success: true,
                    status: 'PENDING',
                    providerReference: $providerRef,
                    policyNumber: null,
                    rawPayload: ['status' => 'PENDING', 'message' => 'Accepted for asynchronous processing']
                );

            case MockScenario::SUCCESS->value:
            default:
                $policyNumber = 'INSU-' . date('Ymd') . '-' . strtoupper(Str::random(8));

                MockInsurerPolicy::create([
                    'provider_reference' => $providerRef,
                    'idempotency_key' => $idempotencyKey,
                    'client_request_id' => $clientRequestId,
                    'agent_id' => $agentId,
                    'policy_number' => $policyNumber,
                    'customer_name' => $data['customer_name'] ?? 'N/A',
                    'plan_code' => $data['plan_code'] ?? 'BASIC',
                    'sum_insured' => (int) ($data['sum_insured'] ?? 100000),
                    'premium_amount' => $data['premium_amount'] ?? 0.00,
                    'status' => 'ISSUED',
                    'scenario_applied' => MockScenario::SUCCESS->value,
                    'callback_url' => $data['callback_url'] ?? null,
                ]);

                MockInsurerLog::create([
                    'provider_reference' => $providerRef,
                    'request_type' => 'SUBMISSION_SUCCESS',
                    'request_payload' => $data,
                    'response_payload' => [
                        'status' => 'ISSUED',
                        'policy_number' => $policyNumber,
                        'provider_reference' => $providerRef,
                    ],
                ]);

                return new ProviderResponse(
                    success: true,
                    status: 'ISSUED',
                    providerReference: $providerRef,
                    policyNumber: $policyNumber,
                    rawPayload: [
                        'status' => 'ISSUED',
                        'policy_number' => $policyNumber,
                        'provider_reference' => $providerRef,
                    ]
                );
        }
    }

    /**
     * Check status of a policy in provider's persistent records (for reconciliation).
     */
    public function checkStatus(string $providerReference): ProviderResponse
    {
        $record = MockInsurerPolicy::where('provider_reference', $providerReference)->first();

        MockInsurerLog::create([
            'provider_reference' => $providerReference,
            'request_type' => 'STATUS_LOOKUP',
            'request_payload' => ['provider_reference' => $providerReference],
            'response_payload' => $record ? $record->toArray() : ['found' => false],
        ]);

        if (! $record) {
            return new ProviderResponse(
                success: false,
                status: 'FAILED',
                providerReference: $providerReference,
                errorCode: 'NOT_FOUND',
                errorMessage: "Provider reference '{$providerReference}' not found in insurer records.",
                rawPayload: ['found' => false]
            );
        }

        return new ProviderResponse(
            success: $record->status === 'ISSUED',
            status: $record->status,
            providerReference: $record->provider_reference,
            policyNumber: $record->status === 'ISSUED' ? $record->policy_number : null,
            rawPayload: $record->toArray()
        );
    }
}
