<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $dbStatus = 'ok';
        $dbError = null;

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $dbStatus = 'unhealthy';
            $dbError = $e->getMessage();
        }

        return response()->json([
            'status' => $dbStatus === 'ok' ? 'healthy' : 'unhealthy',
            'service' => 'PolicyFlow Insurance Issuance Engine',
            'version' => '1.0.0',
            'timestamp' => now()->toIso8601String(),
            'database' => [
                'status' => $dbStatus,
                'connection' => config('database.default'),
                'error' => $dbError,
            ],
        ], $dbStatus === 'ok' ? 200 : 503);
    }
}
