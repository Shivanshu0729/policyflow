<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuanceAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'policy_request_id',
        'attempt_number',
        'idempotency_key',
        'status',
        'provider_reference',
        'request_payload',
        'response_payload',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
