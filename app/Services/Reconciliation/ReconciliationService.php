<?php

namespace App\Services\Reconciliation;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use App\Services\Provider\InsurerClientInterface;
use App\Services\State\PolicyStateMachine;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconciliationService
{
    public function __construct(
        protected InsurerClientInterface $insurerClient,
        protected PolicyStateMachine $stateMachine
    ) {}

    /**
     * Process an incoming provider webhook/callback idempotently.
     */
    public function processCallback(array $payload): array
    {
        $eventId = (string) ($payload['event_id'] ?? '');
        $providerRef = (string) ($payload['provider_reference'] ?? '');
        $eventType = (string) ($payload['event_type'] ?? 'POLICY_ISSUED');
        $callbackStatus = strtoupper((string) ($payload['status'] ?? 'ISSUED'));
        $policyNumber = $payload['policy_number'] ?? null;

        if (empty($eventId)) {
            throw new \InvalidArgumentException("Missing required callback field 'event_id'.");
        }

        // 1. Idempotency check: provider event already registered
        $existingEvent = ProviderEvent::where('event_id', $eventId)->first();
        if ($existingEvent) {
            return [
                'status' => 'DUPLICATE_IGNORED',
                'message' => "Provider event '{$eventId}' was already processed.",
                'event_id' => $eventId,
                'processing_status' => $existingEvent->processing_status,
            ];
        }

        try {
            return DB::transaction(function () use ($eventId, $eventType, $providerRef, $callbackStatus, $policyNumber, $payload) {
                // Find matching policy request
                $policyRequest = PolicyRequest::where('provider_reference', $providerRef)
                    ->lockForUpdate()
                    ->first();

                // Persist provider event with uniqueness on event_id
                $event = ProviderEvent::create([
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'policy_request_id' => $policyRequest?->id,
                    'provider_reference' => $providerRef,
                    'payload' => $payload,
                    'processing_status' => 'PROCESSING',
                ]);

                if (! $policyRequest) {
                    $event->update([
                        'processing_status' => 'ORPHAN_UNMATCHED',
                        'processed_at' => Carbon::now(),
                    ]);

                    return [
                        'status' => 'UNMATCHED',
                        'message' => "No policy request found matching provider_reference '{$providerRef}'.",
                        'event_id' => $eventId,
                    ];
                }

                // CRITICAL SAFETY RULE:
                // Never downgrade an already confirmed ISSUED policy!
                if ($policyRequest->status === PolicyStatus::ISSUED) {
                    $event->update([
                        'processing_status' => 'IGNORED_ALREADY_ISSUED',
                        'processed_at' => Carbon::now(),
                    ]);

                    return [
                        'status' => 'IGNORED_ALREADY_ISSUED',
                        'message' => "Policy request #{$policyRequest->id} is already in final ISSUED state. Callback ignored.",
                        'event_id' => $eventId,
                        'policy_number' => $policyRequest->policy_number,
                    ];
                }

                // Apply valid state transition
                if ($callbackStatus === 'ISSUED') {
                    $assignedNumber = $policyNumber ?: ($policyRequest->policy_number ?: $this->stateMachine->generatePolicyNumber());

                    $this->stateMachine->transition(
                        policyRequest: $policyRequest,
                        toState: PolicyStatus::ISSUED,
                        trigger: 'PROVIDER_CALLBACK',
                        reason: "Confirmation received via provider event {$eventId}.",
                        metadata: [
                            'event_id' => $eventId,
                            'provider_reference' => $providerRef,
                            'policy_number' => $assignedNumber,
                        ]
                    );

                    $event->update([
                        'processing_status' => 'PROCESSED',
                        'processed_at' => Carbon::now(),
                    ]);

                    return [
                        'status' => 'PROCESSED',
                        'message' => "Policy successfully issued via provider callback.",
                        'event_id' => $eventId,
                        'policy_number' => $policyRequest->policy_number,
                    ];
                } else {
                    $this->stateMachine->transition(
                        policyRequest: $policyRequest,
                        toState: PolicyStatus::FAILED,
                        trigger: 'PROVIDER_CALLBACK_REJECTION',
                        reason: "Insurer callback reported rejection/failure: " . ($payload['reason'] ?? 'Rejected by insurer'),
                        metadata: ['event_id' => $eventId, 'payload' => $payload]
                    );

                    $event->update([
                        'processing_status' => 'PROCESSED',
                        'processed_at' => Carbon::now(),
                    ]);

                    return [
                        'status' => 'PROCESSED',
                        'message' => "Policy marked failed via provider callback.",
                        'event_id' => $eventId,
                    ];
                }
            });
        } catch (QueryException $e) {
            // Check for concurrent duplicate event_id insert
            if (str_contains(strtolower($e->getMessage()), 'unique') || (string)$e->getCode() === '23000') {
                return [
                    'status' => 'DUPLICATE_IGNORED',
                    'message' => "Concurrent provider event '{$eventId}' collision detected and ignored safely.",
                    'event_id' => $eventId,
                ];
            }
            throw $e;
        }
    }

    /**
     * Reconcile a request with indeterminate status (TIMEOUT_AFTER_ISSUE / NEEDS_RECONCILIATION / PENDING_CONFIRMATION).
     */
    public function reconcile(PolicyRequest $policyRequest): PolicyRequest
    {
        return DB::transaction(function () use ($policyRequest) {
            $locked = PolicyRequest::where('id', $policyRequest->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PolicyStatus::ISSUED) {
                return $locked;
            }

            if (! in_array($locked->status, [PolicyStatus::NEEDS_RECONCILIATION, PolicyStatus::PENDING_CONFIRMATION, PolicyStatus::SUBMITTED], true)) {
                return $locked;
            }

            if (empty($locked->provider_reference)) {
                $locked->reconciliation_notes = "Cannot reconcile: No provider reference recorded.";
                $locked->reconciled_at = Carbon::now();
                $locked->save();
                return $locked;
            }

            // Query mock insurer's persistent ledger
            $response = $this->insurerClient->checkStatus($locked->provider_reference);

            if ($response->isIssued() && !empty($response->policyNumber)) {
                // Recover the original policy number generated by the provider!
                $locked->policy_number = $response->policyNumber;
                $locked->reconciliation_notes = "Recovered existing policy from insurer ledger during reconciliation.";
                $locked->reconciled_at = Carbon::now();
                $locked->save();

                $this->stateMachine->transition(
                    policyRequest: $locked,
                    toState: PolicyStatus::ISSUED,
                    trigger: 'RECONCILIATION_MATCH',
                    reason: "Reconciliation recovered verified policy {$response->policyNumber} from provider records.",
                    metadata: [
                        'policy_number' => $response->policyNumber,
                        'provider_reference' => $locked->provider_reference,
                    ]
                );
            } elseif ($response->isFailed()) {
                $locked->reconciliation_notes = "Provider confirmed request failed/not found: {$response->errorMessage}";
                $locked->reconciled_at = Carbon::now();
                $locked->save();

                $this->stateMachine->transition(
                    policyRequest: $locked,
                    toState: PolicyStatus::FAILED,
                    trigger: 'RECONCILIATION_NOT_FOUND',
                    reason: "Reconciliation confirmed policy was not issued by provider.",
                    metadata: ['provider_reference' => $locked->provider_reference]
                );
            }

            return $locked->fresh(['issuanceAttempts', 'stateHistory']);
        });
    }
}
