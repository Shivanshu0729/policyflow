<?php

namespace App\Services\Idempotency;

use App\Enums\PolicyStatus;
use App\Exceptions\DuplicateRequestException;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Services\Pricing\PremiumCalculationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RequestIdempotencyService
{
    public function __construct(
        protected PremiumCalculationService $pricingService
    ) {}

    public function processIntake(array $payload): array
    {
        $agentId = (string) $payload['agent_id'];
        $clientRequestId = (string) $payload['client_request_id'];

        $existing = PolicyRequest::where('agent_id', $agentId)
            ->where('client_request_id', $clientRequestId)
            ->first();

        if ($existing) {
            return [
                'request' => $existing,
                'is_new' => false,
            ];
        }

        $pricing = $this->pricingService->calculate(
            planCode: $payload['plan_code'],
            tripStart: $payload['trip_start'],
            tripEnd: $payload['trip_end'],
            dob: $payload['date_of_birth'],
            destination: $payload['destination'],
            sumInsured: $payload['sum_insured']
        );

        try {
            return DB::transaction(function () use ($payload, $pricing, $agentId, $clientRequestId) {

                $existingInside = PolicyRequest::where('agent_id', $agentId)
                    ->where('client_request_id', $clientRequestId)
                    ->lockForUpdate()
                    ->first();

                if ($existingInside) {
                    return [
                        'request' => $existingInside,
                        'is_new' => false,
                    ];
                }

                $policyRequest = PolicyRequest::create([
                    'client_request_id' => $clientRequestId,
                    'agent_id' => $agentId,
                    'customer_name' => $payload['customer_name'],
                    'date_of_birth' => $payload['date_of_birth'],
                    'destination' => $payload['destination'],
                    'trip_start' => $payload['trip_start'],
                    'trip_end' => $payload['trip_end'],
                    'plan_code' => $payload['plan_code'],
                    'sum_insured' => (int) $payload['sum_insured'],
                    'status' => PolicyStatus::VALIDATED,
                    'premium_amount' => $pricing['premium_amount'],
                    'premium_currency' => 'INR',
                    'premium_calculation_snapshot' => $pricing['snapshot'],
                ]);

                PolicyStateHistory::create([
                    'policy_request_id' => $policyRequest->id,
                    'from_state' => PolicyStatus::RECEIVED->value,
                    'to_state' => PolicyStatus::VALIDATED->value,
                    'trigger' => 'INTAKE_VALIDATION',
                    'reason' => 'Request accepted, validated, and premium snapshot calculated.',
                    'metadata' => [
                        'calculated_premium' => $pricing['premium_amount'],
                        'snapshot' => $pricing['snapshot'],
                    ],
                ]);

                return [
                    'request' => $policyRequest,
                    'is_new' => true,
                ];
            });
        } catch (QueryException $e) {

            if ($this->isUniqueConstraintViolation($e)) {
                Log::info("Handled concurrent intake collision safely for agent {$agentId}, request {$clientRequestId}");
                $recovered = PolicyRequest::where('agent_id', $agentId)
                    ->where('client_request_id', $clientRequestId)
                    ->first();

                if ($recovered) {
                    return [
                        'request' => $recovered,
                        'is_new' => false,
                    ];
                }
            }

            throw $e;
        }
    }

    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $message = strtolower($e->getMessage());

        return $code === '23000'
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate entry');
    }
}
