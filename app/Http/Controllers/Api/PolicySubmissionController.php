<?php

namespace App\Http\Controllers\Api;

use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitPolicyRequest;
use App\Models\PolicyRequest;
use App\Services\Issuance\IssuanceOrchestrationService;
use Illuminate\Http\JsonResponse;

class PolicySubmissionController extends Controller
{
    public function __construct(
        protected IssuanceOrchestrationService $issuanceService
    ) {}

    public function submit(SubmitPolicyRequest $request, int|string $id): JsonResponse
    {
        $policyRequest = PolicyRequest::find($id);

        if (! $policyRequest) {
            return response()->json([
                'error' => 'Not Found',
                'message' => "Policy request #{$id} does not exist.",
            ], 404);
        }

        if ($policyRequest->status === PolicyStatus::ISSUED) {
            return response()->json([
                'status' => 'already_issued',
                'message' => "Policy request #{$id} has already been issued with policy number {$policyRequest->policy_number}.",
                'data' => [
                    'id' => $policyRequest->id,
                    'status' => $policyRequest->status->value,
                    'policy_number' => $policyRequest->policy_number,
                    'provider_reference' => $policyRequest->provider_reference,
                ],
            ], 200);
        }

        $scenario = $request->input('scenario') ?: $request->header('X-Mock-Scenario');

        try {
            $updated = $this->issuanceService->submit($policyRequest, $scenario);

            $statusCode = match ($updated->status) {
                PolicyStatus::ISSUED => 200,
                PolicyStatus::PENDING_CONFIRMATION, PolicyStatus::NEEDS_RECONCILIATION => 202,
                PolicyStatus::FAILED => 422,
                default => 200,
            };

            return response()->json([
                'status' => 'success',
                'current_state' => $updated->status->value,
                'message' => "Policy request submission processed. Current status: {$updated->status->value}.",
                'data' => [
                    'id' => $updated->id,
                    'status' => $updated->status->value,
                    'policy_number' => $updated->policy_number,
                    'provider_reference' => $updated->provider_reference,
                    'last_error' => $updated->last_error_message,
                    'reconciliation_notes' => $updated->reconciliation_notes,
                ],
            ], $statusCode);

        } catch (InvalidStateTransitionException $e) {
            return response()->json([
                'error' => 'Invalid State Transition',
                'message' => $e->getMessage(),
                'from_state' => $e->fromState,
                'to_state' => $e->toState,
            ], 422);
        }
    }
}
