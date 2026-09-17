<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartMutationReceipt extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'cart_id',
        'idempotency_key',
        'operation',
        'request_hash',
        'result_item_public_id',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(
            Tenant::class
        );
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(
            Cart::class
        );
    }
}
