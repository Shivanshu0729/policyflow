<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MockInsurerCallbackController;
use App\Http\Controllers\Api\PolicyReconciliationController;
use App\Http\Controllers\Api\PolicyRequestController;
use App\Http\Controllers\Api\PolicySubmissionController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'check']);

Route::post('/policy-requests', [PolicyRequestController::class, 'store']);
Route::get('/policy-requests', [PolicyRequestController::class, 'index']);
Route::get('/policy-requests/{id}', [PolicyRequestController::class, 'show']);

Route::post('/policy-requests/{id}/submit', [PolicySubmissionController::class, 'submit']);

Route::post('/policy-requests/{id}/reconcile', [PolicyReconciliationController::class, 'reconcile']);

Route::post('/mock-insurer/callback', [MockInsurerCallbackController::class, 'handle']);
Route::get('/mock-insurer/policies/{provider_reference}', [PolicyReconciliationController::class, 'insurerStatusLookup']);
