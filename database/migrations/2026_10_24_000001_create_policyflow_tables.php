<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            $table->string('client_request_id', 100);
            $table->string('agent_id', 100);
            $table->string('customer_name', 150);
            $table->date('date_of_birth');
            $table->string('destination', 50);
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code', 50);
            $table->unsignedInteger('sum_insured');
            $table->string('status', 50)->default('RECEIVED')->index();
            $table->decimal('premium_amount', 10, 2);
            $table->string('premium_currency', 10)->default('INR');
            $table->json('premium_calculation_snapshot');
            $table->string('policy_number', 100)->nullable()->unique();
            $table->string('provider_reference', 100)->nullable()->index();
            $table->string('last_error_code', 100)->nullable();
            $table->text('last_error_message')->nullable();
            $table->text('reconciliation_notes')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'client_request_id'], 'uniq_agent_client_req');
        });

        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->string('idempotency_key', 150)->index();
            $table->string('status', 50);
            $table->string('provider_reference', 100)->nullable()->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 150)->unique();
            $table->string('event_type', 100);
            $table->foreignId('policy_request_id')->nullable()->constrained('policy_requests')->nullOnDelete();
            $table->string('provider_reference', 100)->nullable()->index();
            $table->json('payload');
            $table->string('processing_status', 50)->default('PROCESSED');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('policy_state_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();
            $table->string('from_state', 50);
            $table->string('to_state', 50);
            $table->string('trigger', 100);
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['policy_request_id', 'created_at']);
        });

        Schema::create('mock_insurer_policies', function (Blueprint $table) {
            $table->id();
            $table->string('provider_reference', 100)->unique();
            $table->string('idempotency_key', 150)->unique();
            $table->string('client_request_id', 100);
            $table->string('agent_id', 100);
            $table->string('policy_number', 100)->unique();
            $table->string('customer_name', 150);
            $table->string('plan_code', 50);
            $table->unsignedInteger('sum_insured');
            $table->decimal('premium_amount', 10, 2);
            $table->string('status', 50)->default('ISSUED');
            $table->string('scenario_applied', 50);
            $table->string('callback_url', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('mock_insurer_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider_reference', 100)->nullable()->index();
            $table->string('request_type', 100);
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_insurer_logs');
        Schema::dropIfExists('mock_insurer_policies');
        Schema::dropIfExists('policy_state_history');
        Schema::dropIfExists('provider_events');
        Schema::dropIfExists('issuance_attempts');
        Schema::dropIfExists('policy_requests');
    }
};
