<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhookReceipt extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'payment_attempt_id',
        'public_id',
        'provider_code',
        'provider_event_id',
        'provider_reference',
        'event_type',
        'amount_minor',
        'currency_code',
        'payload_sha256',
        'occurred_at',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',

            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(
            PaymentAttempt::class
        );
    }
}
