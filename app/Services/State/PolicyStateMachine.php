<?php

namespace App\Services\State;

use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Support\Str;

class PolicyStateMachine
{

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
            PolicyStatus::SUBMITTED->value,
        ],
        PolicyStatus::ISSUED->value => [

        ],
    ];

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

        if ($toStateStr === PolicyStatus::ISSUED->value) {
            if (empty($policyRequest->policy_number) && empty($metadata['policy_number'])) {

                $policyRequest->policy_number = $this->generatePolicyNumber();
            } elseif (!empty($metadata['policy_number'])) {
                $policyRequest->policy_number = $metadata['policy_number'];
            }

            if (!empty($metadata['provider_reference'])) {
                $policyRequest->provider_reference = $metadata['provider_reference'];
            }
        }

        $policyRequest->status = PolicyStatus::from($toStateStr);
        $policyRequest->save();

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

    public function generatePolicyNumber(): string
    {
        return 'POL-' . date('Ymd') . '-' . strtoupper(Str::random(8));
    }

    public function getTransitionMap(): array
    {
        return self::ALLOWED_TRANSITIONS;
    }
}
