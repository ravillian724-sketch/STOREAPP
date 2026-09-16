<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReservation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'sku_id',
        'location_id',
        'quantity',
        'status',
        'idempotency_key',
        'reference_type',
        'reference_id',
        'expires_at',
        'released_at',
        'consumed_at',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',

            'expires_at' => 'immutable_datetime',

            'released_at' => 'immutable_datetime',

            'consumed_at' => 'immutable_datetime',

            'expired_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(
            Tenant::class
        );
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(
            Sku::class
        );
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(
            InventoryLocation::class,
            'location_id',
        );
    }
}
