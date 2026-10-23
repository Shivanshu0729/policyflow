<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyIssuanceTest extends TestCase
{
    use RefreshDatabase;

    protected function createValidatedRequest(string $clientRequestId = 'REQ-ISSUE-1'): PolicyRequest
    {
        $response = $this->postJson('/api/policy-requests', [
            'client_request_id' => $clientRequestId,
            'agent_id' => 'AGENT-77',
            'customer_name' => 'Rohan Mehta',
            'date_of_birth' => '1995-06-20',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-03',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);

        return PolicyRequest::find($response->json('data.id'));
    }

    public function test_successful_provider_response_creates_exactly_one_policy(): void
    {
        $policyRequest = $this->createValidatedRequest('REQ-SUCCESS-1');

        $response = $this->postJson("/api/policy-requests/{$policyRequest->id}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'current_state' => 'ISSUED',
            ]);

        $policyNumber = $response->json('data.policy_number');
        $providerRef = $response->json('data.provider_reference');

        $this->assertNotEmpty($policyNumber);
        $this->assertNotEmpty($providerRef);

        $fresh = $policyRequest->fresh();
        $this->assertEquals('ISSUED', $fresh->status->value);
        $this->assertEquals($policyNumber, $fresh->policy_number);
        $this->assertEquals($providerRef, $fresh->provider_reference);

        $this->assertCount(1, $fresh->issuanceAttempts);
        $this->assertEquals('ISSUED', $fresh->issuanceAttempts->first()->status);
    }

    public function test_already_issued_policy_cannot_be_issued_again(): void
    {
        $policyRequest = $this->createValidatedRequest('REQ-REPEAT-ISSUE');

        $firstResponse = $this->postJson("/api/policy-requests/{$policyRequest->id}/submit", ['scenario' => 'SUCCESS']);
        $firstResponse->assertStatus(200);
        $originalPolicyNumber = $firstResponse->json('data.policy_number');

        $secondResponse = $this->postJson("/api/policy-requests/{$policyRequest->id}/submit", ['scenario' => 'SUCCESS']);
        $secondResponse->assertStatus(200)
            ->assertJson([
                'status' => 'already_issued',
            ]);

        $this->assertEquals($originalPolicyNumber, $secondResponse->json('data.policy_number'));

        $this->assertCount(1, $policyRequest->fresh()->issuanceAttempts);
    }

    public function test_temporary_failure_is_recorded_and_safely_handled(): void
    {
        $policyRequest = $this->createValidatedRequest('REQ-TEMP-FAIL');

        $response = $this->postJson("/api/policy-requests/{$policyRequest->id}/submit", [
            'scenario' => 'TEMPORARY_FAILURE',
        ]);

        $response->assertStatus(422);

        $fresh = $policyRequest->fresh();
        $this->assertEquals('FAILED', $fresh->status->value);
        $this->assertNull($fresh->policy_number);
        $this->assertCount(1, $fresh->issuanceAttempts);
        $this->assertEquals('TEMPORARY_FAILURE', $fresh->issuanceAttempts->first()->status);

        $retryResponse = $this->postJson("/api/policy-requests/{$policyRequest->id}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $retryResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'current_state' => 'ISSUED',
            ]);

        $freshAfterRetry = $policyRequest->fresh();
        $this->assertEquals('ISSUED', $freshAfterRetry->status->value);
        $this->assertNotNull($freshAfterRetry->policy_number);

        $this->assertCount(2, $freshAfterRetry->issuanceAttempts);
    }

    public function test_delayed_callback_scenario_transitions_to_pending_confirmation(): void
    {
        $policyRequest = $this->createValidatedRequest('REQ-DELAYED-1');

        $response = $this->postJson("/api/policy-requests/{$policyRequest->id}/submit", [
            'scenario' => 'DELAYED_CALLBACK',
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'status' => 'success',
                'current_state' => 'PENDING_CONFIRMATION',
            ]);

        $fresh = $policyRequest->fresh();
        $this->assertEquals('PENDING_CONFIRMATION', $fresh->status->value);
        $this->assertNull($fresh->policy_number);
        $this->assertNotEmpty($fresh->provider_reference);
    }

    public function test_get_policy_request_by_id_returns_audit_trail_and_attempts(): void
    {
        $policyRequest = $this->createValidatedRequest('REQ-DETAILS-1');
        $this->postJson("/api/policy-requests/{$policyRequest->id}/submit", ['scenario' => 'SUCCESS']);

        $response = $this->getJson("/api/policy-requests/{$policyRequest->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'id',
                    'client_request_id',
                    'status',
                    'premium',
                    'issuance',
                    'state_history',
                    'issuance_attempts',
                ],
            ]);

        $this->assertGreaterThanOrEqual(2, count($response->json('data.state_history')));
    }

    public function test_list_policy_requests_with_filtering_and_pagination(): void
    {
        $this->createValidatedRequest('REQ-LIST-1');
        $this->createValidatedRequest('REQ-LIST-2');

        $response = $this->getJson('/api/policy-requests?status=VALIDATED&per_page=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data',
                'pagination' => [
                    'current_page',
                    'per_page',
                    'total',
                ],
            ]);

        $this->assertGreaterThanOrEqual(2, $response->json('pagination.total'));
    }
}
