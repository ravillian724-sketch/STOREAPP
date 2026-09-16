<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryPosition extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'sku_id',
        'location_id',
    ];

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
