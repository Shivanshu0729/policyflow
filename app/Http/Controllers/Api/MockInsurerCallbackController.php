<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProviderCallbackRequest;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Http\JsonResponse;

class MockInsurerCallbackController extends Controller
{
    public function __construct(
        protected ReconciliationService $reconciliationService
    ) {}

    /**
     * Receive and validate simulated provider callback events.
     */
    public function handle(ProviderCallbackRequest $request): JsonResponse
    {
        $result = $this->reconciliationService->processCallback($request->validated());

        return response()->json([
            'status' => 'success',
            'result' => $result,
        ], 200);
    }
}
