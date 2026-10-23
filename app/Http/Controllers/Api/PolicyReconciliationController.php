<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MockInsurerPolicy;
use App\Models\PolicyRequest;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Http\JsonResponse;

class PolicyReconciliationController extends Controller
{
    public function __construct(
        protected ReconciliationService $reconciliationService
    ) {}

    /**
     * Manually trigger reconciliation for a pending or needs-reconciliation request.
     */
    public function reconcile(int|string $id): JsonResponse
    {
        $policyRequest = PolicyRequest::find($id);

        if (! $policyRequest) {
            return response()->json([
                'error' => 'Not Found',
                'message' => "Policy request #{$id} does not exist.",
            ], 404);
        }

        $reconciled = $this->reconciliationService->reconcile($policyRequest);

        return response()->json([
            'status' => 'success',
            'message' => "Reconciliation completed. Current status: {$reconciled->status->value}.",
            'data' => [
                'id' => $reconciled->id,
                'status' => $reconciled->status->value,
                'policy_number' => $reconciled->policy_number,
                'provider_reference' => $reconciled->provider_reference,
                'reconciled_at' => $reconciled->reconciled_at?->toIso8601String(),
                'reconciliation_notes' => $reconciled->reconciliation_notes,
            ],
        ], 200);
    }

    /**
     * Inspect status in the mock insurer's persistent records.
     */
    public function insurerStatusLookup(string $providerReference): JsonResponse
    {
        $policy = MockInsurerPolicy::where('provider_reference', $providerReference)->first();

        if (! $policy) {
            return response()->json([
                'error' => 'Not Found',
                'message' => "No mock insurer record found for provider_reference '{$providerReference}'.",
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $policy,
        ], 200);
    }
}
