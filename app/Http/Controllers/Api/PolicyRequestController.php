<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePolicyRequest;
use App\Models\PolicyRequest;
use App\Services\Idempotency\RequestIdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PolicyRequestController extends Controller
{
    public function __construct(
        protected RequestIdempotencyService $idempotencyService
    ) {}

    /**
     * Intake endpoint: Create a new request or return an existing logical request idempotently.
     */
    public function store(StorePolicyRequest $request): JsonResponse
    {
        $result = $this->idempotencyService->processIntake($request->validated());

        $policyRequest = $result['request']->load(['stateHistory']);
        $isNew = $result['is_new'];

        $statusCode = $isNew ? 201 : 200;

        return response()->json([
            'status' => 'success',
            'is_retry' => ! $isNew,
            'message' => $isNew ? 'Policy request created and validated.' : 'Existing logical policy request returned (idempotent retry).',
            'data' => $this->formatPolicyRequest($policyRequest),
        ], $statusCode);
    }

    /**
     * Retrieve a single policy request with its premium, status, history, and attempts.
     */
    public function show(int|string $id): JsonResponse
    {
        $policyRequest = PolicyRequest::with(['stateHistory', 'issuanceAttempts', 'providerEvents'])->find($id);

        if (! $policyRequest) {
            return response()->json([
                'error' => 'Not Found',
                'message' => "Policy request #{$id} does not exist.",
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->formatPolicyRequest($policyRequest, true),
        ], 200);
    }

    /**
     * List policy requests with optional status filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::query()->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->query('agent_id'));
        }

        $perPage = min((int) $request->query('per_page', 15), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => array_map([$this, 'formatPolicyRequest'], $paginated->items()),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * Format policy request for consistent API response.
     */
    protected function formatPolicyRequest(PolicyRequest $req, bool $includeDetails = false): array
    {
        $data = [
            'id' => $req->id,
            'client_request_id' => $req->client_request_id,
            'agent_id' => $req->agent_id,
            'customer_name' => $req->customer_name,
            'date_of_birth' => $req->date_of_birth?->toDateString(),
            'destination' => $req->destination?->value ?? (string) $req->destination,
            'trip_start' => $req->trip_start?->toDateString(),
            'trip_end' => $req->trip_end?->toDateString(),
            'plan_code' => $req->plan_code?->value ?? (string) $req->plan_code,
            'sum_insured' => $req->sum_insured,
            'status' => $req->status?->value ?? (string) $req->status,
            'premium' => [
                'amount' => $req->premium_amount,
                'currency' => $req->premium_currency,
                'snapshot' => $req->premium_calculation_snapshot,
            ],
            'issuance' => [
                'policy_number' => $req->policy_number,
                'provider_reference' => $req->provider_reference,
                'last_error_code' => $req->last_error_code,
                'last_error_message' => $req->last_error_message,
                'reconciliation_notes' => $req->reconciliation_notes,
                'reconciled_at' => $req->reconciled_at?->toIso8601String(),
            ],
            'created_at' => $req->created_at?->toIso8601String(),
            'updated_at' => $req->updated_at?->toIso8601String(),
        ];

        if ($includeDetails) {
            $data['state_history'] = $req->stateHistory->map(function ($h) {
                return [
                    'id' => $h->id,
                    'from_state' => $h->from_state,
                    'to_state' => $h->to_state,
                    'trigger' => $h->trigger,
                    'reason' => $h->reason,
                    'metadata' => $h->metadata,
                    'timestamp' => $h->created_at?->toIso8601String(),
                ];
            });

            $data['issuance_attempts'] = $req->issuanceAttempts->map(function ($a) {
                return [
                    'attempt_number' => $a->attempt_number,
                    'status' => $a->status,
                    'provider_reference' => $a->provider_reference,
                    'error_code' => $a->error_code,
                    'error_message' => $a->error_message,
                    'created_at' => $a->created_at?->toIso8601String(),
                ];
            });
        }

        return $data;
    }
}
