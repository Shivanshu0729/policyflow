<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MockInsurerPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_reference',
        'idempotency_key',
        'client_request_id',
        'agent_id',
        'policy_number',
        'customer_name',
        'plan_code',
        'sum_insured',
        'premium_amount',
        'status',
        'scenario_applied',
        'callback_url',
    ];

    protected $casts = [
        'sum_insured' => 'integer',
        'premium_amount' => 'decimal:2',
    ];
}
