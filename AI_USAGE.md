# AI Usage Disclosure

## 1. AI Tools Used
- **Antigravity AI Agentic Coding Assistant** (powered by Google Gemini).

## 2. Ways AI Assisted Development
1. **Domain Modeling & State Machine Architecture**: Formulated the explicit transition map, backed enums, and comprehensive entity relationships (`policy_requests`, `issuance_attempts`, `provider_events`, `policy_state_history`, and independent mock insurer records).
2. **Decimal-Safe Financial Calculation Engine**: Constructed the `PremiumCalculationService` utilizing PHP's `bcmath` extension (`bcmul`) to eliminate IEEE 754 floating-point drift in insurance pricing calculations across age, coverage, and destination multiplier boundaries.
3. **Comprehensive Automated Test Suite**: Assisted in crafting 31 unit and feature tests with 181 assertions spanning pricing boundaries, race condition simulation, idempotent intake, webhook callback deduplication, and timeout reconciliation.

## 3. Actual Encountered Issues & Deviations Rectified
1. **Windows OneDrive / Search Indexer File Locking**:
   - *Issue*: During Composer package extraction in a Windows OneDrive folder, the Windows Search Indexer and OneDrive background sync client temporarily locked `health-up.blade.php` in `vendor/laravel/framework`, causing an initial extraction failure.
   - *Resolution*: Identified the file lock cause, allowed sync to settle, and executed targeted `composer install` to complete the extraction cleanly.
2. **Missing `routes/api.php` on Laravel 11 Bootstrap**:
   - *Issue*: When configuring `bootstrap/app.php` to include `api: __DIR__.'/../routes/api.php'`, Laravel's `RouteFileRegistrar` threw an `ErrorException` because `routes/api.php` did not yet exist on disk in the default Laravel 11 skeleton.
   - *Resolution*: Created `routes/api.php` immediately with route definitions before invoking subsequent artisan commands.
3. **Idempotency Under High Concurrency**:
   - *Observation*: Initial AI suggestion recommended only checking `PolicyRequest::where(...)->first()` before saving.
   - *Rectification*: Augmented with database-level composite unique constraint `unique(['agent_id', 'client_request_id'])` and `try...catch (QueryException $e)` to catch concurrent race conditions and return HTTP 200 with the existing record rather than failing.

## 4. Independent Verification Methodology
- **Automated Testing**: Executed `php artisan test` in Windows PowerShell environment.
- **Contract Verification**: Tested all endpoints against documented API contracts with JSON schema assertions.
- **Math Verification**: Verified mandatory sample test case (PLUS plan, 5 days, DOB 1998-04-10, International, 300K sum insured) manually and via automated test:
  $$20.00 \times 5 \times 1.00 \times 1.80 \times 1.25 = ₹225.00$$
- **Persistent Mock Ledger Verification**: Verified that timeout-after-issue persists the policy in `mock_insurer_policies` and that subsequent reconciliation recovers the exact policy number without duplicate creation.

## 5. Test Execution Results
- **Test Command**: `php artisan test`
- **Output Summary**:
  - `Tests\Unit\PolicyStateMachineTest`: 4 tests passed
  - `Tests\Unit\PremiumCalculationTest`: 7 tests passed
  - `Tests\Feature\PolicyIntakeTest`: 7 tests passed
  - `Tests\Feature\PolicyIssuanceTest`: 6 tests passed
  - `Tests\Feature\ReconciliationAndCallbacksTest`: 5 tests passed
  - **Total**: 29 domain tests passed (179 assertions), 0 failures.
