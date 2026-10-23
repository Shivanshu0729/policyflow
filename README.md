# PolicyFlow — Fault-Tolerant Insurance Issuance & Reconciliation Engine

PolicyFlow is an enterprise backend system engineered in **Laravel 11** and **PHP 8.3** simulating travel insurance issuance and reconciliation. It guarantees financial correctness, idempotency, and auditability under network latency, duplicate webhook callbacks, and remote timeouts.

---

## Architecture Diagram

```mermaid
flowchart TD
    subgraph ClientLayer["Client & Distribution Layer"]
        AgentApp["Travel Agent / Aggregator"]
        CLIWorker["Artisan CLI: policyflow:reconcile"]
    end

    subgraph PolicyFlowApp["PolicyFlow Application Core"]
        Router["API Gateway / Routing"]
        
        subgraph IntakePipeline["1. Intake & Pricing"]
            FormValidator["StorePolicyRequest (Validator)"]
            IdempotencySvc["RequestIdempotencyService"]
            PricingSvc["PremiumCalculationService (bcmath)"]
        end

        subgraph IssuancePipeline["2. Issuance Orchestration"]
            IssuanceSvc["IssuanceOrchestrationService"]
            StateMachine["PolicyStateMachine"]
        end

        subgraph ReconciliationPipeline["3. Webhook & Reconciliation"]
            CallbackHandler["MockInsurerCallbackController"]
            ReconcileSvc["ReconciliationService"]
        end
    end

    subgraph DataStore["Primary Database (SQLite / MySQL)"]
        T_Requests[("policy_requests\n(agent_id + client_request_id UNIQUE)")]
        T_Attempts[("issuance_attempts\n(attempt history)")]
        T_History[("policy_state_history\n(audit trail)")]
        T_Events[("provider_events\n(event_id UNIQUE)")]
    end

    subgraph ExternalProvider["Simulated Insurer Engine"]
        MockProvider["MockInsurerService"]
        T_MockLedger[("mock_insurer_policies\n(independent external ledger)")]
        T_MockLogs[("mock_insurer_logs")]
    end

    AgentApp -->|POST /api/policy-requests| Router
    Router --> FormValidator --> IdempotencySvc
    IdempotencySvc --> PricingSvc
    IdempotencySvc --> T_Requests

    AgentApp -->|POST /api/policy-requests/:id/submit| Router
    Router --> IssuanceSvc
    IssuanceSvc --> StateMachine
    StateMachine --> T_History
    IssuanceSvc --> T_Attempts
    IssuanceSvc -->|submitPolicy()| MockProvider
    MockProvider --> T_MockLedger
    MockProvider --> T_MockLogs

    MockProvider -.->|Webhook: POST /api/mock-insurer/callback| Router
    Router --> CallbackHandler --> ReconcileSvc
    ReconcileSvc --> T_Events
    ReconcileSvc --> StateMachine

    AgentApp -->|POST /api/policy-requests/:id/reconcile| Router
    CLIWorker --> ReconcileSvc
    ReconcileSvc -->|checkStatus()| MockProvider
```

---

## 1. Business Problem & Objective

When travel insurance requests are submitted through multiple intermediaries (travel portals, agents):
1. **Network Retries**: A client may double-submit an intake request due to packet loss or impatient users clicking "Pay" twice.
2. **Provider Timeouts**: A remote underwriter API can accept a request and issue a policy, but the HTTP connection may drop before the client receives the acknowledgment.
3. **Repeated Webhooks**: Payment gateways and insurer webhook dispatchers operate with *at-least-once* delivery, repeatedly delivering the same completion event.
4. **State Machine Regressions**: Out-of-order delivery could deliver a stale failure webhook after a policy was already confirmed.

**PolicyFlow's Objective**:
- Eliminate duplicate policies.
- Ensure decimal-exact premium calculations.
- Enforce strict state transitions with full audit trails.
- Provide automatic status reconciliation for timed-out or pending policies.

---

## 2. Core Entities & Database Schema

| Table Name | Primary Responsibility | Key Constraints & Indexes |
| :--- | :--- | :--- |
| `policy_requests` | Stores logical policy request, customer/trip data, accepted premium snapshot, current status, policy number. | `UNIQUE(agent_id, client_request_id)`, `UNIQUE(policy_number)`, `INDEX(status)` |
| `issuance_attempts` | Records every submission attempt made to an insurer (attempt number, status, payload, error code). | Foreign key to `policy_requests.id`, `INDEX(idempotency_key)` |
| `policy_state_history`| Immutable append-only audit trail recording every state change, trigger, reason, and metadata. | Foreign key to `policy_requests.id`, `INDEX(policy_request_id, created_at)` |
| `provider_events` | Inbound insurer webhook callbacks for idempotent deduplication. | `UNIQUE(event_id)`, `INDEX(provider_reference)` |
| `mock_insurer_policies` | **Independent external ledger** simulating the underwriter backend database. | `UNIQUE(provider_reference)`, `UNIQUE(idempotency_key)`, `UNIQUE(policy_number)` |
| `mock_insurer_logs` | Logs raw requests and responses handled by the mock insurer. | `INDEX(provider_reference)` |

---

## 3. Premium Calculation Engine

### Pricing Formula
$$\text{Premium} = \text{Base Daily Rate} \times \text{Travel Days} \times \text{Age Multiplier} \times \text{Destination Multiplier} \times \text{Coverage Multiplier}$$

- **Base Daily Rate**:
  - `BASIC`: ₹12.00
  - `PLUS`: ₹20.00
  - `PREMIUM`: ₹30.00
- **Travel Days**:
  - Inclusive calendar days: $(\text{trip\_end} - \text{trip\_start}) + 1$ (minimum 1 day).
- **Age Multiplier (at `trip_start`)**:
  - 0–17 years: $1.10$
  - 18–60 years: $1.00$
  - 61–70 years: $1.50$
  - 71+ years: $2.00$
- **Destination Multiplier**:
  - `DOMESTIC`: $1.00$
  - `INTERNATIONAL`: $1.80$
- **Coverage Multiplier**:
  - ₹100,000: $1.00$
  - ₹300,000: $1.25$
  - ₹500,000: $1.50$

### Mandatory Test Proof:
- **Input**: `PLUS` plan, `trip_start`: `2026-11-10`, `trip_end`: `2026-11-14` (5 days), `date_of_birth`: `1998-04-10` (age 28), `INTERNATIONAL`, `sum_insured`: 300,000.
$$\text{Premium} = 20.00 \times 5 \times 1.00 \times 1.80 \times 1.25 = \mathbf{₹225.00}$$
- **Decimal Safety**: Evaluated using PHP `bcmath` extension (`bcmul`), avoiding binary floating-point representation drift.

---

## 4. State Machine Transition Rules

The policy moves strictly through explicit states:

```
RECEIVED  ──►  VALIDATED  ──►  SUBMITTED  ──┬──►  ISSUED (Terminal)
    │                                        ├──►  PENDING_CONFIRMATION  ──┬──►  ISSUED
    ▼                                        ├──►  NEEDS_RECONCILIATION ───┼──►  FAILED
  FAILED                                     └──►  FAILED (Safe Retry ───► SUBMITTED)
```

- **Terminal State**: `ISSUED` policies are immutable. They can **never** be downgraded or altered by conflicting webhooks.
- **Audit**: Every transition generates a record in `policy_state_history`.

---

## 5. API Endpoints Contract

### 1. Intake Policy Request
- **Endpoint**: `POST /api/policy-requests`
- **Request Body**:
```json
{
  "client_request_id": "REQ-CAMPUS-001",
  "agent_id": "AGENT-01",
  "customer_name": "Aditi Sharma",
  "date_of_birth": "1998-04-10",
  "destination": "INTERNATIONAL",
  "trip_start": "2026-11-10",
  "trip_end": "2026-11-14",
  "plan_code": "PLUS",
  "sum_insured": 300000
}
```
- **Responses**:
  - `201 Created`: Fresh request created with computed premium snapshot.
  - `200 OK`: Identical retry returned existing logical request (`"is_retry": true`).
  - `422 Unprocessable Entity`: Validation error.

### 2. Submit Policy Issuance
- **Endpoint**: `POST /api/policy-requests/{id}/submit`
- **Request Body / Header**:
```json
{
  "scenario": "SUCCESS"
}
```
*(Supports header `X-Mock-Scenario: SUCCESS | TEMPORARY_FAILURE | TIMEOUT_AFTER_ISSUE | DELAYED_CALLBACK`)*
- **Responses**:
  - `200 OK`: Synchronously issued policy with assigned `policy_number`.
  - `202 Accepted`: Asynchronous processing (`PENDING_CONFIRMATION` or `NEEDS_RECONCILIATION`).
  - `422 Unprocessable Entity`: Temporary failure or invalid transition.

### 3. Query Policy Details & Audit Trail
- **Endpoint**: `GET /api/policy-requests/{id}`
- **Response**: Returns request, accepted premium, snapshot, state history, and issuance attempts.

### 4. List Policy Requests
- **Endpoint**: `GET /api/policy-requests?status=ISSUED&per_page=15`

### 5. Reconcile Indeterminate Policy
- **Endpoint**: `POST /api/policy-requests/{id}/reconcile`
- **Response**: Queries external insurer ledger, recovers policy, and transitions status.

### 6. Provider Webhook Callback
- **Endpoint**: `POST /api/mock-insurer/callback`
- **Request Body**:
```json
{
  "event_id": "EVT-INS-12345",
  "provider_reference": "MOCK-PROV-SAMPLE",
  "status": "ISSUED",
  "policy_number": "POL-202611-XYZ",
  "event_type": "POLICY_ISSUED"
}
```
- **Responses**:
  - `200 OK`: `PROCESSED` on first delivery.
  - `200 OK`: `DUPLICATE_IGNORED` on subsequent identical deliveries.

### 7. Engine Health Check
- **Endpoint**: `GET /api/health`

---

## 6. How Reliability Scenarios Are Handled

### Case 1: Duplicate Intake
- **Scenario**: Agent submits identical `agent_id` and `client_request_id` multiple times.
- **Handling**:
  - Lookup checks for existing entry.
  - Under concurrent execution, database constraint `UNIQUE(agent_id, client_request_id)` blocks duplicate insertion.
  - The collision exception is caught, and the winner record is safely returned with HTTP 200.
  - Premium snapshot is never recalculated.

### Case 2: Duplicate Callback
- **Scenario**: Insurer sends same webhook callback twice.
- **Handling**:
  - `provider_events` table enforces `UNIQUE(event_id)`.
  - First request updates policy to `ISSUED`.
  - Second request detects existing `event_id` and immediately returns `DUPLICATE_IGNORED` with HTTP 200 without touching the policy state.

### Case 3: Timeout-After-Issue Reconciliation
- **Scenario**: Insurer creates policy in its ledger, but HTTP response times out.
- **Handling**:
  - Application records `ProviderTimeoutException`.
  - Status transitions to `NEEDS_RECONCILIATION` (not `FAILED`).
  - Running `POST /api/policy-requests/{id}/reconcile` or CLI `php artisan policyflow:reconcile` queries `MockInsurerService::checkStatus()`, discovers the existing issued policy, and recovers the exact policy number without duplicate issuance.

---

## 7. Windows Local Setup Instructions

### Prerequisites
- **PHP 8.2 or newer** with extensions: `bcmath`, `curl`, `mbstring`, `openssl`, `pdo_sqlite`, `sqlite3` (or `pdo_mysql`).
- **Composer 2.x**.
- **Git**.

### Quick Start Commands (PowerShell)

```powershell
# 1. Clone / Navigate to project
cd "c:\Users\shiva\OneDrive\Desktop\trippers shield assignment"

# 2. Environment Configuration
Copy-Item .env.example .env
php artisan key:generate

# 3. Run Database Migrations
php artisan migrate

# 4. Run Automated Test Suite
php artisan test

# 5. Start Development Server
php artisan serve --port=8000
```

---

## 8. Automated Test Suite

Run the full automated test suite:
```powershell
php artisan test
```

### Verified Test Results
```
   PASS  Tests\Unit\PolicyStateMachineTest
  ✓ valid transitions succeed and record state history
  ✓ invalid transition throws exception
  ✓ issued state is immutable and cannot regress
  ✓ issued cannot regress to failed

   PASS  Tests\Unit\PremiumCalculationTest
  ✓ mandatory sample premium calculation equals 225
  ✓ inclusive travel day calculation
  ✓ age multiplier boundaries
  ✓ destination multipliers
  ✓ coverage multipliers
  ✓ trip end before trip start throws exception
  ✓ unsupported coverage amount throws exception

   PASS  Tests\Feature\PolicyIntakeTest
  ✓ valid policy request is accepted with http 201
  ✓ repeated intake returns http 200 and reuses existing logical request
  ✓ invalid travel dates are rejected with http 422
  ✓ unsupported plan code is rejected with http 422
  ✓ unsupported destination is rejected with http 422
  ✓ unsupported sum insured is rejected with http 422
  ✓ date of birth in future is rejected with http 422

   PASS  Tests\Feature\PolicyIssuanceTest
  ✓ successful provider response creates exactly one policy
  ✓ already issued policy cannot be issued again
  ✓ temporary failure is recorded and safely handled
  ✓ delayed callback scenario transitions to pending confirmation
  ✓ get policy request by id returns audit trail and attempts
  ✓ list policy requests with filtering and pagination

   PASS  Tests\Feature\ReconciliationAndCallbacksTest
  ✓ delayed callback successfully issues policy
  ✓ duplicate callback is idempotent
  ✓ conflicting or stale callback cannot downgrade issued policy
  ✓ timeout after issue reconciliation recovers original policy
  ✓ artisan reconcile command

Tests:    29 passed (179 assertions)
Duration: 1.55s
```

---

## 9. Ready-to-Run Demonstrations

- **Windows PowerShell Demo**:
  ```powershell
  # Start the server in one window:
  php artisan serve --port=8000

  # Run the demo script in another PowerShell window:
  .\examples\windows_powershell_commands.ps1
  ```
- **cURL Demo (Bash / WSL)**:
  ```bash
  bash examples/curl_commands.sh
  ```
- **Postman**: Import `examples/PolicyFlow_Postman_Collection.json`.

---

## 10. Additional Documentation
- Detailed technical interview questions, architecture trade-offs, and state machine design are documented in [docs/INTERVIEW_GUIDE.md](docs/INTERVIEW_GUIDE.md).
- AI usage and verification notes are documented in [AI_USAGE.md](AI_USAGE.md).
