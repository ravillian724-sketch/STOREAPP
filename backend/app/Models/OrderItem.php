<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'order_id',
        'sku_id',
        'location_id',
        'public_id',

        'sku_code_snapshot',
        'barcode_snapshot',

        'product_name_ar_snapshot',
        'product_name_en_snapshot',

        'sku_name_ar_snapshot',
        'sku_name_en_snapshot',

        'quantity',

        'unit_net_minor',
        'line_subtotal_minor',
        'discount_minor',

        'tax_rate_bps',
        'tax_minor',

        'line_total_minor',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',

            'unit_net_minor' => 'integer',
            'line_subtotal_minor' => 'integer',
            'discount_minor' => 'integer',

            'tax_rate_bps' => 'integer',
            'tax_minor' => 'integer',

            'line_total_minor' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(
            Tenant::class
        );
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(
            Order::class
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
