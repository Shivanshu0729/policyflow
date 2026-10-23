<?php

namespace Tests\Unit;

use App\Enums\Destination;
use App\Enums\PlanCode;
use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PolicyRequest;
use App\Services\State\PolicyStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected PolicyStateMachine $stateMachine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateMachine = new PolicyStateMachine();
    }

    protected function createSampleRequest(PolicyStatus $status = PolicyStatus::RECEIVED): PolicyRequest
    {
        return PolicyRequest::create([
            'client_request_id' => 'REQ-' . uniqid(),
            'agent_id' => 'AGT-001',
            'customer_name' => 'John Doe',
            'date_of_birth' => '1995-05-15',
            'destination' => Destination::DOMESTIC,
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-05',
            'plan_code' => PlanCode::BASIC,
            'sum_insured' => 100000,
            'status' => $status,
            'premium_amount' => 60.00,
            'premium_currency' => 'INR',
            'premium_calculation_snapshot' => ['test' => true],
        ]);
    }

    public function test_valid_transitions_succeed_and_record_state_history(): void
    {
        $req = $this->createSampleRequest(PolicyStatus::RECEIVED);

        $this->stateMachine->transition($req, PolicyStatus::VALIDATED, 'TEST_VALIDATE', 'Valid input');
        $this->assertEquals(PolicyStatus::VALIDATED, $req->fresh()->status);

        $this->stateMachine->transition($req, PolicyStatus::SUBMITTED, 'TEST_SUBMIT', 'Submitted to provider');
        $this->assertEquals(PolicyStatus::SUBMITTED, $req->fresh()->status);

        $this->stateMachine->transition($req, PolicyStatus::ISSUED, 'TEST_ISSUANCE', 'Issued by provider', [
            'policy_number' => 'POL-TEST-1234',
            'provider_reference' => 'PROV-REF-1',
        ]);
        $this->assertEquals(PolicyStatus::ISSUED, $req->fresh()->status);
        $this->assertEquals('POL-TEST-1234', $req->fresh()->policy_number);

        $this->assertCount(3, $req->stateHistory);
        $this->assertEquals('RECEIVED', $req->stateHistory[0]->from_state);
        $this->assertEquals('VALIDATED', $req->stateHistory[0]->to_state);
        $this->assertEquals('VALIDATED', $req->stateHistory[1]->from_state);
        $this->assertEquals('SUBMITTED', $req->stateHistory[1]->to_state);
        $this->assertEquals('SUBMITTED', $req->stateHistory[2]->from_state);
        $this->assertEquals('ISSUED', $req->stateHistory[2]->to_state);
    }

    public function test_invalid_transition_throws_exception(): void
    {
        $req = $this->createSampleRequest(PolicyStatus::RECEIVED);

        $this->expectException(InvalidStateTransitionException::class);
        $this->stateMachine->transition($req, PolicyStatus::ISSUED, 'ILLEGAL_JUMP');
    }

    public function test_issued_state_is_immutable_and_cannot_regress(): void
    {
        $req = $this->createSampleRequest(PolicyStatus::ISSUED);
        $req->policy_number = 'POL-CONFIRMED-999';
        $req->save();

        $this->expectException(InvalidStateTransitionException::class);
        $this->stateMachine->transition($req, PolicyStatus::SUBMITTED, 'ATTEMPT_REGRESSION');
    }

    public function test_issued_cannot_regress_to_failed(): void
    {
        $req = $this->createSampleRequest(PolicyStatus::ISSUED);
        $req->policy_number = 'POL-CONFIRMED-999';
        $req->save();

        $this->expectException(InvalidStateTransitionException::class);
        $this->stateMachine->transition($req, PolicyStatus::FAILED, 'STALE_CALLBACK_FAILURE');
    }
}
