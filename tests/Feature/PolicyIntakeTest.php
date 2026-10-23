<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'client_request_id' => 'CLIENT-REQ-1001',
            'agent_id' => 'AGENT-42',
            'customer_name' => 'Aditi Sharma',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ], $overrides);
    }

    public function test_valid_policy_request_is_accepted_with_http_201(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'is_retry',
                'message',
                'data' => [
                    'id',
                    'client_request_id',
                    'agent_id',
                    'customer_name',
                    'date_of_birth',
                    'destination',
                    'trip_start',
                    'trip_end',
                    'plan_code',
                    'sum_insured',
                    'status',
                    'premium' => [
                        'amount',
                        'currency',
                        'snapshot' => [
                            'travel_days',
                            'age_multiplier',
                            'destination_multiplier',
                            'coverage_multiplier',
                            'base_daily_rate',
                        ],
                    ],
                    'issuance' => [
                        'policy_number',
                        'provider_reference',
                    ],
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertEquals('VALIDATED', $response->json('data.status'));
        $this->assertEquals('225.00', $response->json('data.premium.amount'));
        $this->assertFalse($response->json('is_retry'));

        // Assert database record exists
        $this->assertDatabaseHas('policy_requests', [
            'client_request_id' => 'CLIENT-REQ-1001',
            'agent_id' => 'AGENT-42',
            'premium_amount' => 225.00,
            'status' => 'VALIDATED',
        ]);
    }

    public function test_repeated_intake_returns_http_200_and_reuses_existing_logical_request(): void
    {
        $payload = $this->validPayload();

        // 1st request -> 201 Created
        $firstResponse = $this->postJson('/api/policy-requests', $payload);
        $firstResponse->assertStatus(201);
        $firstId = $firstResponse->json('data.id');

        // 2nd identical request -> 200 OK
        $secondResponse = $this->postJson('/api/policy-requests', $payload);
        $secondResponse->assertStatus(200);

        $this->assertTrue($secondResponse->json('is_retry'));
        $this->assertEquals($firstId, $secondResponse->json('data.id'));
        $this->assertEquals('225.00', $secondResponse->json('data.premium.amount'));

        // Only 1 record in database
        $this->assertEquals(1, PolicyRequest::where('agent_id', 'AGENT-42')->where('client_request_id', 'CLIENT-REQ-1001')->count());
    }

    public function test_invalid_travel_dates_are_rejected_with_http_422(): void
    {
        // trip_end before trip_start
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'trip_start' => '2026-11-15',
            'trip_end' => '2026-11-10',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);
    }

    public function test_unsupported_plan_code_is_rejected_with_http_422(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'plan_code' => 'SUPER_PLATINUM_GOLD',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code']);
    }

    public function test_unsupported_destination_is_rejected_with_http_422(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'destination' => 'MOON_ORBIT',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination']);
    }

    public function test_unsupported_sum_insured_is_rejected_with_http_422(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'sum_insured' => 9999999,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sum_insured']);
    }

    public function test_date_of_birth_in_future_is_rejected_with_http_422(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'date_of_birth' => '2099-01-01',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }
}
