$BaseUrl = "http://127.0.0.1:8000/api"

Write-Host "`n=== 1. Health Check ===" -ForegroundColor Cyan
Invoke-RestMethod -Uri "$BaseUrl/health" -Method Get | ConvertTo-Json -Depth 5

Write-Host "`n=== 2. Policy Intake (Fresh Request -> 225.00 INR Expected) ===" -ForegroundColor Cyan
$IntakeBody = @{
    client_request_id = "REQ-CAMPUS-PWSH-001"
    agent_id          = "AGENT-WIN-01"
    customer_name     = "Aditi Sharma"
    date_of_birth     = "1998-04-10"
    destination       = "INTERNATIONAL"
    trip_start        = "2026-11-10"
    trip_end          = "2026-11-14"
    plan_code         = "PLUS"
    sum_insured       = 300000
} | ConvertTo-Json

$req1 = Invoke-RestMethod -Uri "$BaseUrl/policy-requests" -Method Post -Body $IntakeBody -ContentType "application/json"
$req1 | ConvertTo-Json -Depth 5
$policyId = $req1.data.id

Write-Host "`n=== 3. Policy Intake (Identical Retry -> Expect 200 OK & is_retry=true) ===" -ForegroundColor Cyan
$retry = Invoke-RestMethod -Uri "$BaseUrl/policy-requests" -Method Post -Body $IntakeBody -ContentType "application/json"
$retry | ConvertTo-Json -Depth 5

Write-Host "`n=== 4. Submit Issuance (SUCCESS Scenario) ===" -ForegroundColor Cyan
$submitBody = @{ scenario = "SUCCESS" } | ConvertTo-Json
$submit = Invoke-RestMethod -Uri "$BaseUrl/policy-requests/$policyId/submit" -Method Post -Body $submitBody -ContentType "application/json"
$submit | ConvertTo-Json -Depth 5

Write-Host "`n=== 5. Get Policy Details with Audit Trail ===" -ForegroundColor Cyan
Invoke-RestMethod -Uri "$BaseUrl/policy-requests/$policyId" -Method Get | ConvertTo-Json -Depth 5

Write-Host "`n=== 6. Timeout-After-Issue Scenario & Reconciliation ===" -ForegroundColor Cyan
$TimeoutBody = @{
    client_request_id = "REQ-TIMEOUT-PWSH-002"
    agent_id          = "AGENT-WIN-01"
    customer_name     = "Rohan Verma"
    date_of_birth     = "1995-02-14"
    destination       = "DOMESTIC"
    trip_start        = "2026-12-01"
    trip_end          = "2026-12-05"
    plan_code         = "BASIC"
    sum_insured       = 100000
} | ConvertTo-Json

$req2 = Invoke-RestMethod -Uri "$BaseUrl/policy-requests" -Method Post -Body $TimeoutBody -ContentType "application/json"
$req2Id = $req2.data.id

$timeoutSubmit = @{ scenario = "TIMEOUT_AFTER_ISSUE" } | ConvertTo-Json
try {
    $res = Invoke-RestMethod -Uri "$BaseUrl/policy-requests/$req2Id/submit" -Method Post -Body $timeoutSubmit -ContentType "application/json"
    $res | ConvertTo-Json -Depth 5
} catch {
    $_.ErrorDetails.Message
}

Write-Host "`nReconciling policy #$req2Id..." -ForegroundColor Yellow
$recon = Invoke-RestMethod -Uri "$BaseUrl/policy-requests/$req2Id/reconcile" -Method Post -ContentType "application/json"
$recon | ConvertTo-Json -Depth 5

Write-Host "`n=== 7. Duplicate Provider Callback Test ===" -ForegroundColor Cyan
$provRef = $submit.data.provider_reference
$cbBody = @{
    event_id           = "EVT-PWSH-UNIQUE-1"
    provider_reference = $provRef
    status             = "ISSUED"
    policy_number      = $submit.data.policy_number
    event_type         = "POLICY_ISSUED"
} | ConvertTo-Json

Write-Host "Delivering Callback (1st Delivery)..."
Invoke-RestMethod -Uri "$BaseUrl/mock-insurer/callback" -Method Post -Body $cbBody -ContentType "application/json" | ConvertTo-Json -Depth 5

Write-Host "Delivering Duplicate Callback (2nd Delivery)..."
Invoke-RestMethod -Uri "$BaseUrl/mock-insurer/callback" -Method Post -Body $cbBody -ContentType "application/json" | ConvertTo-Json -Depth 5
