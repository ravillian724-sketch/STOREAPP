<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sku extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'product_id',
        'code',
        'barcode',
        'name_ar',
        'name_en',
        'track_inventory',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(
            Tenant::class
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            Product::class
        );
    }
}
