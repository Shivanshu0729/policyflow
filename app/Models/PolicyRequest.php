<?php

namespace App\Models;

use App\Enums\Destination;
use App\Enums\PlanCode;
use App\Enums\PolicyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PolicyRequest extends Model
{
    use HasFactory;

    protected $fillable = [
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
        'premium_amount',
        'premium_currency',
        'premium_calculation_snapshot',
        'policy_number',
        'provider_reference',
        'last_error_code',
        'last_error_message',
        'reconciliation_notes',
        'reconciled_at',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'trip_start' => 'date:Y-m-d',
        'trip_end' => 'date:Y-m-d',
        'sum_insured' => 'integer',
        'premium_amount' => 'decimal:2',
        'premium_calculation_snapshot' => 'array',
        'reconciled_at' => 'datetime',
        'destination' => Destination::class,
        'plan_code' => PlanCode::class,
        'status' => PolicyStatus::class,
    ];

    public function issuanceAttempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class)->orderBy('attempt_number', 'asc');
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class)->orderBy('created_at', 'asc');
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class)->orderBy('created_at', 'asc');
    }

    public function isIssued(): bool
    {
        return $this->status === PolicyStatus::ISSUED;
    }

    public function isPending(): bool
    {
        return in_array($this->status, [
            PolicyStatus::SUBMITTED,
            PolicyStatus::PENDING_CONFIRMATION,
            PolicyStatus::NEEDS_RECONCILIATION,
        ], true);
    }
}
