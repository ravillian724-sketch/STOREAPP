<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartCreationReceipt extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'app_instance_id',
        'cart_id',
        'idempotency_key',
        'request_hash',
    ];

    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(
            AppInstance::class
        );
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(
            Cart::class
        );
    }
}
