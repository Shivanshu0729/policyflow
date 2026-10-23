<?php

namespace App\Console\Commands;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Console\Command;

class ReconcilePoliciesCommand extends Command
{

    protected $signature = 'policyflow:reconcile {--id= : Optional specific policy request ID to reconcile}';

    protected $description = 'Reconcile pending or indeterminate policy requests against provider records';

    public function handle(ReconciliationService $reconciliationService): int
    {
        $id = $this->option('id');

        if ($id) {
            $request = PolicyRequest::find($id);
            if (! $request) {
                $this->error("Policy request #{$id} not found.");
                return self::FAILURE;
            }

            $this->info("Reconciling policy request #{$request->id} (Status: {$request->status->value})...");
            $reconciled = $reconciliationService->reconcile($request);
            $this->info("Completed. New status: {$reconciled->status->value}. Policy Number: " . ($reconciled->policy_number ?: 'N/A'));
            return self::SUCCESS;
        }

        $pending = PolicyRequest::whereIn('status', [
            PolicyStatus::NEEDS_RECONCILIATION,
            PolicyStatus::PENDING_CONFIRMATION,
        ])->get();

        if ($pending->isEmpty()) {
            $this->info("No policy requests currently need reconciliation.");
            return self::SUCCESS;
        }

        $this->info("Found {$pending->count()} requests needing reconciliation.");

        $successCount = 0;
        foreach ($pending as $req) {
            $this->line("Processing #{$req->id} [{$req->status->value}]...");
            $reconciled = $reconciliationService->reconcile($req);
            $this->info(" -> Result: {$reconciled->status->value} (" . ($reconciled->policy_number ?: 'No policy') . ")");
            if ($reconciled->isIssued()) {
                $successCount++;
            }
        }

        $this->info("Reconciliation cycle complete. {$successCount} policies confirmed issued.");
        return self::SUCCESS;
    }
}
