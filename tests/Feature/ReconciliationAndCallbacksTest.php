<?php

namespace Tests\Feature;

use App\Models\MockInsurerPolicy;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationAndCallbacksTest extends TestCase
{
    use RefreshDatabase;

    protected function createPendingRequest(string $clientRequestId = 'REQ-RECON-1'): PolicyRequest
    {
        $intake = $this->postJson('/api/policy-requests', [
            'client_request_id' => $clientRequestId,
            'agent_id' => 'AGENT-88',
            'customer_name' => 'Vikram Seth',
            'date_of_birth' => '1990-08-12',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-12-10',
            'trip_end' => '2026-12-15',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ]);

        $id = $intake->json('data.id');

        // Submit with DELAYED_CALLBACK to put it in PENDING_CONFIRMATION
        $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'DELAYED_CALLBACK',
        ]);

        return PolicyRequest::find($id);
    }

    public function test_delayed_callback_successfully_issues_policy(): void
    {
        $policyRequest = $this->createPendingRequest('REQ-CB-SUCCESS');

        $this->assertEquals('PENDING_CONFIRMATION', $policyRequest->status->value);
        $providerRef = $policyRequest->provider_reference;

        // Simulate external provider callback
        $callbackResponse = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'EVT-CB-001',
            'provider_reference' => $providerRef,
            'status' => 'ISSUED',
            'policy_number' => 'POL-PROVIDER-CONFIRMED-1',
            'event_type' => 'POLICY_ISSUED',
        ]);

        $callbackResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'result' => [
                    'status' => 'PROCESSED',
                    'policy_number' => 'POL-PROVIDER-CONFIRMED-1',
                ],
            ]);

        $fresh = $policyRequest->fresh();
        $this->assertEquals('ISSUED', $fresh->status->value);
        $this->assertEquals('POL-PROVIDER-CONFIRMED-1', $fresh->policy_number);

        // Verify provider_events record
        $this->assertDatabaseHas('provider_events', [
            'event_id' => 'EVT-CB-001',
            'processing_status' => 'PROCESSED',
        ]);
    }

    public function test_duplicate_callback_is_idempotent(): void
    {
        $policyRequest = $this->createPendingRequest('REQ-CB-DUP');
        $providerRef = $policyRequest->provider_reference;

        $callbackPayload = [
            'event_id' => 'EVT-DUP-999',
            'provider_reference' => $providerRef,
            'status' => 'ISSUED',
            'policy_number' => 'POL-DUP-1',
            'event_type' => 'POLICY_ISSUED',
        ];

        // 1st delivery
        $res1 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $res1->assertStatus(200);
        $this->assertEquals('PROCESSED', $res1->json('result.status'));

        // 2nd delivery of the EXACT same callback
        $res2 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $res2->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'result' => [
                    'status' => 'DUPLICATE_IGNORED',
                ],
            ]);

        // 3rd delivery
        $res3 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $res3->assertStatus(200);
        $this->assertEquals('DUPLICATE_IGNORED', $res3->json('result.status'));

        // Event exists only once in provider_events
        $this->assertEquals(1, ProviderEvent::where('event_id', 'EVT-DUP-999')->count());
        $this->assertEquals('POL-DUP-1', $policyRequest->fresh()->policy_number);
    }

    public function test_conflicting_or_stale_callback_cannot_downgrade_issued_policy(): void
    {
        $policyRequest = $this->createPendingRequest('REQ-NO-DOWNGRADE');
        $providerRef = $policyRequest->provider_reference;

        // First issue it successfully
        $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'EVT-LEGIT-1',
            'provider_reference' => $providerRef,
            'status' => 'ISSUED',
            'policy_number' => 'POL-LEGIT-1',
        ]);

        $this->assertEquals('ISSUED', $policyRequest->fresh()->status->value);

        // Now deliver a conflicting/stale REJECTED callback with a different event ID
        $staleResponse = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'EVT-STALE-REJECT',
            'provider_reference' => $providerRef,
            'status' => 'REJECTED',
            'reason' => 'Stale event from older attempt',
        ]);

        $staleResponse->assertStatus(200);
        $this->assertEquals('IGNORED_ALREADY_ISSUED', $staleResponse->json('result.status'));

        // Policy MUST remain ISSUED with original policy number!
        $fresh = $policyRequest->fresh();
        $this->assertEquals('ISSUED', $fresh->status->value);
        $this->assertEquals('POL-LEGIT-1', $fresh->policy_number);
    }

    public function test_timeout_after_issue_reconciliation_recovers_original_policy(): void
    {
        // 1. Create request
        $intake = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-TIMEOUT-RECON-1',
            'agent_id' => 'AGENT-88',
            'customer_name' => 'Kavita Roy',
            'date_of_birth' => '1985-03-25',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-20',
            'trip_end' => '2026-11-25',
            'plan_code' => 'PREMIUM',
            'sum_insured' => 500000,
        ]);
        $id = $intake->json('data.id');

        // 2. Submit with TIMEOUT_AFTER_ISSUE scenario
        // In this scenario, mock insurer creates the policy in mock_insurer_policies,
        // but simulates network timeout to caller!
        $submitResponse = $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        // Expect HTTP 202 Accepted (indeterminate state)
        $submitResponse->assertStatus(202)
            ->assertJson([
                'current_state' => 'NEEDS_RECONCILIATION',
            ]);

        $req = PolicyRequest::find($id);
        $this->assertEquals('NEEDS_RECONCILIATION', $req->status->value);
        $this->assertNull($req->policy_number);
        $this->assertNotNull($req->provider_reference);

        // Verify insurer ledger actually has the policy stored independently
        $providerLedgerRecord = MockInsurerPolicy::where('provider_reference', $req->provider_reference)->first();
        $this->assertNotNull($providerLedgerRecord);
        $this->assertEquals('ISSUED', $providerLedgerRecord->status);
        $expectedPolicyNumber = $providerLedgerRecord->policy_number;

        // 3. Trigger Reconciliation
        $reconcileResponse = $this->postJson("/api/policy-requests/{$id}/reconcile");

        $reconcileResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'status' => 'ISSUED',
                    'policy_number' => $expectedPolicyNumber,
                ],
            ]);

        // Verify request is now ISSUED with the EXACT recovered provider policy number
        $fresh = $req->fresh();
        $this->assertEquals('ISSUED', $fresh->status->value);
        $this->assertEquals($expectedPolicyNumber, $fresh->policy_number);
        $this->assertNotNull($fresh->reconciled_at);

        // Verify that only ONE policy exists in mock_insurer_policies for this request (no duplicate creation!)
        $this->assertEquals(1, MockInsurerPolicy::where('client_request_id', 'REQ-TIMEOUT-RECON-1')->count());
    }

    public function test_artisan_reconcile_command(): void
    {
        // Create a request with timeout scenario
        $intake = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-CLI-RECON',
            'agent_id' => 'AGENT-CLI',
            'customer_name' => 'CLI Tester',
            'date_of_birth' => '1992-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-02',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);
        $id = $intake->json('data.id');

        $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $this->assertEquals('NEEDS_RECONCILIATION', PolicyRequest::find($id)->status->value);

        // Run artisan command
        $this->artisan('policyflow:reconcile', ['--id' => $id])
            ->assertExitCode(0);

        $this->assertEquals('ISSUED', PolicyRequest::find($id)->status->value);
    }
}
