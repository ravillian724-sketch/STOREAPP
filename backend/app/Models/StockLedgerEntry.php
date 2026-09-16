<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockLedgerEntry extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'sku_id',
        'location_id',
        'movement_type',
        'quantity_delta',
        'idempotency_key',
        'reference_type',
        'reference_id',
        'occurred_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',

            'occurred_at' => 'immutable_datetime',

            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(
            function (): never {
                throw new LogicException(
                    'Stock ledger entries are immutable.'
                );
            }
        );

        static::deleting(
            function (): never {
                throw new LogicException(
                    'Stock ledger entries are immutable.'
                );
            }
        );
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
