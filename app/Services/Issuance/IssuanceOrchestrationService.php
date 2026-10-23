<?php

namespace App\Services\Issuance;

use App\Enums\PolicyStatus;
use App\Exceptions\InsurerException;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\ProviderCommunicationException;
use App\Exceptions\ProviderTimeoutException;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Services\Provider\InsurerClientInterface;
use App\Services\Provider\ProviderResponse;
use App\Services\State\PolicyStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class IssuanceOrchestrationService
{
    public function __construct(
        protected InsurerClientInterface $insurerClient,
        protected PolicyStateMachine $stateMachine
    ) {}

    public function submit(PolicyRequest $policyRequest, ?string $scenario = null): PolicyRequest
    {

        $attemptData = DB::transaction(function () use ($policyRequest) {
            $locked = PolicyRequest::where('id', $policyRequest->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PolicyStatus::ISSUED) {

                return ['status' => 'ALREADY_ISSUED', 'request' => $locked];
            }

            if ($locked->status === PolicyStatus::PENDING_CONFIRMATION) {
                return ['status' => 'AWAITING_CONFIRMATION', 'request' => $locked];
            }

            if ($locked->status === PolicyStatus::NEEDS_RECONCILIATION) {
                return ['status' => 'NEEDS_RECONCILIATION', 'request' => $locked];
            }

            if (! in_array($locked->status, [PolicyStatus::VALIDATED, PolicyStatus::FAILED], true)) {
                throw new InvalidStateTransitionException(
                    fromState: $locked->status->value,
                    toState: PolicyStatus::SUBMITTED->value,
                    message: "Cannot submit request from status '{$locked->status->value}'."
                );
            }

            $attemptNumber = $locked->issuanceAttempts()->count() + 1;
            $idempotencyKey = 'IDEM-' . $locked->id . '-ATTEMPT-' . $attemptNumber . '-' . Str::random(8);

            $this->stateMachine->transition(
                policyRequest: $locked,
                toState: PolicyStatus::SUBMITTED,
                trigger: 'SUBMIT_ISSUANCE',
                reason: "Initiated issuance attempt #{$attemptNumber}.",
                metadata: ['attempt_number' => $attemptNumber, 'idempotency_key' => $idempotencyKey]
            );

            $attempt = IssuanceAttempt::create([
                'policy_request_id' => $locked->id,
                'attempt_number' => $attemptNumber,
                'idempotency_key' => $idempotencyKey,
                'status' => 'STARTED',
                'request_payload' => [
                    'client_request_id' => $locked->client_request_id,
                    'agent_id' => $locked->agent_id,
                    'customer_name' => $locked->customer_name,
                    'plan_code' => $locked->plan_code->value,
                    'sum_insured' => $locked->sum_insured,
                    'premium_amount' => $locked->premium_amount,
                ],
            ]);

            return [
                'status' => 'PROCEED',
                'request' => $locked,
                'attempt' => $attempt,
                'idempotencyKey' => $idempotencyKey,
            ];
        });

        if ($attemptData['status'] !== 'PROCEED') {
            return $attemptData['request'];
        }

        $request = $attemptData['request'];

        $attempt = $attemptData['attempt'];
        $idempotencyKey = $attemptData['idempotencyKey'];

        try {
            $response = $this->insurerClient->submitPolicy([
                'idempotency_key' => $idempotencyKey,
                'client_request_id' => $request->client_request_id,
                'agent_id' => $request->agent_id,
                'customer_name' => $request->customer_name,
                'plan_code' => $request->plan_code->value,
                'sum_insured' => $request->sum_insured,
                'premium_amount' => $request->premium_amount,
                'callback_url' => url('/api/mock-insurer/callback'),
            ], $scenario);

            return $this->handleProviderResponse($request, $attempt, $response);

        } catch (ProviderTimeoutException $e) {

            Log::warning("Provider timeout encountered for policy_request #{$request->id}: {$e->getMessage()}");

            return DB::transaction(function () use ($request, $attempt, $e) {
                $locked = PolicyRequest::where('id', $request->id)->lockForUpdate()->first();

                $attempt->update([
                    'status' => 'TIMEOUT',
                    'provider_reference' => $e->providerReference,
                    'error_code' => $e->errorCode,
                    'error_message' => $e->getMessage(),
                ]);

                $locked->last_error_code = $e->errorCode;
                $locked->last_error_message = $e->getMessage();
                $locked->provider_reference = $e->providerReference ?: $locked->provider_reference;
                $locked->reconciliation_notes = "Provider call timed out. Reconciliation required to verify if policy was created.";
                $locked->save();

                $this->stateMachine->transition(
                    policyRequest: $locked,
                    toState: PolicyStatus::NEEDS_RECONCILIATION,
                    trigger: 'PROVIDER_TIMEOUT',
                    reason: "Provider call timed out. Marked for status reconciliation.",
                    metadata: ['provider_reference' => $e->providerReference, 'error' => $e->getMessage()]
                );

                return $locked->fresh(['issuanceAttempts', 'stateHistory']);
            });

        } catch (ProviderCommunicationException $e) {

            Log::error("Provider communication failure for policy_request #{$request->id}: {$e->getMessage()}");

            return DB::transaction(function () use ($request, $attempt, $e) {
                $locked = PolicyRequest::where('id', $request->id)->lockForUpdate()->first();

                $attempt->update([
                    'status' => 'TEMPORARY_FAILURE',
                    'provider_reference' => $e->providerReference,
                    'error_code' => $e->errorCode,
                    'error_message' => $e->getMessage(),
                ]);

                $locked->last_error_code = $e->errorCode;
                $locked->last_error_message = $e->getMessage();
                $locked->save();

                $this->stateMachine->transition(
                    policyRequest: $locked,
                    toState: PolicyStatus::FAILED,
                    trigger: 'PROVIDER_TEMPORARY_FAILURE',
                    reason: "Provider temporary error: {$e->getMessage()}",
                    metadata: ['error_code' => $e->errorCode, 'is_retriable' => true]
                );

                return $locked->fresh(['issuanceAttempts', 'stateHistory']);
            });

        } catch (\Throwable $e) {
            Log::error("Unexpected error during policy submission #{$request->id}: {$e->getMessage()}");

            return DB::transaction(function () use ($request, $attempt, $e) {
                $locked = PolicyRequest::where('id', $request->id)->lockForUpdate()->first();

                $attempt->update([
                    'status' => 'FAILED',
                    'error_code' => 'SYSTEM_ERROR',
                    'error_message' => $e->getMessage(),
                ]);

                $locked->last_error_code = 'SYSTEM_ERROR';
                $locked->last_error_message = $e->getMessage();
                $locked->save();

                $this->stateMachine->transition(
                    policyRequest: $locked,
                    toState: PolicyStatus::FAILED,
                    trigger: 'SYSTEM_ERROR',
                    reason: "Unhandled system exception: {$e->getMessage()}",
                    metadata: ['exception' => get_class($e)]
                );

                return $locked->fresh(['issuanceAttempts', 'stateHistory']);
            });
        }
    }

    protected function handleProviderResponse(
        PolicyRequest $request,
        IssuanceAttempt $attempt,
        ProviderResponse $response
    ): PolicyRequest {
        return DB::transaction(function () use ($request, $attempt, $response) {
            $locked = PolicyRequest::where('id', $request->id)->lockForUpdate()->first();

            $attempt->update([
                'status' => $response->status,
                'provider_reference' => $response->providerReference,
                'response_payload' => $response->rawPayload,
            ]);

            $locked->provider_reference = $response->providerReference;

            if ($response->isIssued()) {
                $locked->policy_number = $response->policyNumber ?: $this->stateMachine->generatePolicyNumber();
                $locked->save();

                $this->stateMachine->transition(
                    policyRequest: $locked,
                    toState: PolicyStatus::ISSUED,
                    trigger: 'PROVIDER_SUCCESS',
                    reason: "Policy successfully issued by provider.",
                    metadata: [
                        'policy_number' => $locked->policy_number,
                        'provider_reference' => $response->providerReference,
                    ]
                );
            } elseif ($response->isPending()) {
                $locked->save();

                $this->stateMachine->transition(
                    policyRequest: $locked,
                    toState: PolicyStatus::PENDING_CONFIRMATION,
                    trigger: 'PROVIDER_PENDING_CONFIRMATION',
                    reason: "Provider accepted request asynchronously. Awaiting confirmation callback.",
                    metadata: ['provider_reference' => $response->providerReference]
                );
            } else {
                $locked->last_error_code = $response->errorCode ?: 'REJECTED';
                $locked->last_error_message = $response->errorMessage ?: 'Provider rejected issuance';
                $locked->save();

                $this->stateMachine->transition(
                    policyRequest: $locked,
                    toState: PolicyStatus::FAILED,
                    trigger: 'PROVIDER_REJECTION',
                    reason: $locked->last_error_message,
                    metadata: ['error_code' => $locked->last_error_code]
                );
            }

            return $locked->fresh(['issuanceAttempts', 'stateHistory']);
        });
    }
}
