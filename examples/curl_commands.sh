#!/usr/bin/env bash

BASE_URL="http://127.0.0.1:8000/api"

echo "=== 1. Health Check ==="
curl -s -X GET "${BASE_URL}/health" | jq .

echo -e "\n=== 2. Policy Intake (Fresh Request -> 225.00 INR Expected) ==="
curl -s -X POST "${BASE_URL}/policy-requests" \
  -H "Content-Type: application/json" \
  -d @examples/requests/intake_sample.json | jq .

echo -e "\n=== 3. Policy Intake (Identical Retry -> Reuses Logical Request) ==="
curl -s -X POST "${BASE_URL}/policy-requests" \
  -H "Content-Type: application/json" \
  -d @examples/requests/intake_sample.json | jq .

echo -e "\n=== 4. Submit Issuance (SUCCESS Scenario) ==="
curl -s -X POST "${BASE_URL}/policy-requests/1/submit" \
  -H "Content-Type: application/json" \
  -d '{"scenario": "SUCCESS"}' | jq .

echo -e "\n=== 5. Get Policy Details with Audit Trail ==="
curl -s -X GET "${BASE_URL}/policy-requests/1" | jq .

echo -e "\n=== 6. Submit Issuance (TIMEOUT_AFTER_ISSUE Scenario) ==="
curl -s -X POST "${BASE_URL}/policy-requests" \
  -H "Content-Type: application/json" \
  -d '{
    "client_request_id": "REQ-TIMEOUT-DEMO-01",
    "agent_id": "AGENT-NORTH-07",
    "customer_name": "Rohan Verma",
    "date_of_birth": "1995-02-14",
    "destination": "DOMESTIC",
    "trip_start": "2026-12-01",
    "trip_end": "2026-12-05",
    "plan_code": "BASIC",
    "sum_insured": 100000
  }' | jq .

curl -s -X POST "${BASE_URL}/policy-requests/2/submit" \
  -H "Content-Type: application/json" \
  -d '{"scenario": "TIMEOUT_AFTER_ISSUE"}' | jq .

echo -e "\n=== 7. Reconcile Stale/Timed-out Policy ==="
curl -s -X POST "${BASE_URL}/policy-requests/2/reconcile" | jq .

echo -e "\n=== 8. Provider Callback (Idempotent 1st Call) ==="
curl -s -X POST "${BASE_URL}/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -d @examples/requests/callback_success.json | jq .

echo -e "\n=== 9. Provider Callback (Duplicate Call -> Returns DUPLICATE_IGNORED) ==="
curl -s -X POST "${BASE_URL}/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -d @examples/requests/callback_success.json | jq .
