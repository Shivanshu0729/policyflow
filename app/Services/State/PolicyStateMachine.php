<?php

namespace App\Services\State;

use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Support\Str;

class PolicyStateMachine
{
    /**
     * Complete transition graph.
     * key: source state
     * values: array of permitted destination states
     */
    protected const ALLOWED_TRANSITIONS = [
        PolicyStatus::RECEIVED->value => [
            PolicyStatus::VALIDATED->value,
            PolicyStatus::FAILED->value,
        ],
        PolicyStatus::VALIDATED->value => [
            PolicyStatus::SUBMITTED->value,
            PolicyStatus::FAILED->value,
        ],
        PolicyStatus::SUBMITTED->value => [
            PolicyStatus::ISSUED->value,
            PolicyStatus::PENDING_CONFIRMATION->value,
            PolicyStatus::NEEDS_RECONCILIATION->value,
            PolicyStatus::FAILED->value,
        ],
        PolicyStatus::PENDING_CONFIRMATION->value => [
            PolicyStatus::ISSUED->value,
            PolicyStatus::FAILED->value,
            PolicyStatus::NEEDS_RECONCILIATION->value,
        ],
        PolicyStatus::NEEDS_RECONCILIATION->value => [
            PolicyStatus::ISSUED->value,
            PolicyStatus::FAILED->value,
            PolicyStatus::SUBMITTED->value,
        ],
        PolicyStatus::FAILED->value => [
            PolicyStatus::SUBMITTED->value, // Explicit safe retry workflow
        ],
        PolicyStatus::ISSUED->value => [
            // TERMINAL STATE: Confirmed issued policies are immutable and can NEVER transition or downgrade!
        ],
    ];

    /**
     * Check if transition is allowed.
     */
    public function canTransition(PolicyStatus|string $from, PolicyStatus|string $to): bool
    {
        $fromStr = $from instanceof PolicyStatus ? $from->value : $from;
        $toStr = $to instanceof PolicyStatus ? $to->value : $to;

        if ($fromStr === $toStr) {
            return false;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$fromStr] ?? [];
        return in_array($toStr, $allowed, true);
    }

    /**
     * Execute transition, update model, and persist history record inside current transaction.
     *
     * @throws InvalidStateTransitionException
     */
    public function transition(
        PolicyRequest $policyRequest,
        PolicyStatus|string $toState,
        string $trigger,
        ?string $reason = null,
        ?array $metadata = null
    ): PolicyRequest {
        $fromState = $policyRequest->status instanceof PolicyStatus ? $policyRequest->status->value : (string) $policyRequest->status;
        $toStateStr = $toState instanceof PolicyStatus ? $toState->value : (string) $toState;

        if (! $this->canTransition($fromState, $toStateStr)) {
            throw new InvalidStateTransitionException(
                fromState: $fromState,
                toState: $toStateStr,
                message: "Illegal state transition attempted from '{$fromState}' to '{$toStateStr}' via trigger '{$trigger}'."
            );
        }

        // Additional business guard: Cannot transition to ISSUED without policy_number and provider_reference
        if ($toStateStr === PolicyStatus::ISSUED->value) {
            if (empty($policyRequest->policy_number) && empty($metadata['policy_number'])) {
                // If policy_number not already set or passed in metadata, generate one
                $policyRequest->policy_number = $this->generatePolicyNumber();
            } elseif (!empty($metadata['policy_number'])) {
                $policyRequest->policy_number = $metadata['policy_number'];
            }

            if (!empty($metadata['provider_reference'])) {
                $policyRequest->provider_reference = $metadata['provider_reference'];
            }
        }

        // Apply new status
        $policyRequest->status = PolicyStatus::from($toStateStr);
        $policyRequest->save();

        // Record history
        PolicyStateHistory::create([
            'policy_request_id' => $policyRequest->id,
            'from_state' => $fromState,
            'to_state' => $toStateStr,
            'trigger' => $trigger,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);

        return $policyRequest;
    }

    /**
     * Generate an official unique policy number.
     */
    public function generatePolicyNumber(): string
    {
        return 'POL-' . date('Ymd') . '-' . strtoupper(Str::random(8));
    }

    /**
     * Get the transition map for documentation and audit purposes.
     */
    public function getTransitionMap(): array
    {
        return self::ALLOWED_TRANSITIONS;
    }
}
