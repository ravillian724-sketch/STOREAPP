<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'payment_id',
        'public_id',
        'idempotency_key',
        'provider_code',
        'method_code',
        'provider_reference',
        'status',
        'currency_code',
        'amount_minor',
        'failure_code',
        'failure_message',
        'started_at',
        'authorized_at',
        'succeeded_at',
        'failed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',

            'started_at' => 'immutable_datetime',
            'authorized_at' => 'immutable_datetime',
            'succeeded_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(
            Tenant::class
        );
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(
            Payment::class
        );
    }
}
