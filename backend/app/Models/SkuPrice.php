<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkuPrice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'sku_id',
        'public_id',
        'currency_code',
        'amount_minor',
        'tax_rate_bps',
        'tax_inclusive',
        'effective_from',
        'effective_until',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'tax_rate_bps' => 'integer',
            'tax_inclusive' => 'boolean',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'is_active' => 'boolean',
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
}
