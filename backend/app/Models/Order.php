<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use BelongsToTenant;

    protected $hidden = [
        'guest_access_token_hash',
    ];

    protected $fillable = [
        'app_instance_id',
        'customer_id',
        'cart_id',
        'public_id',
        'guest_access_token_hash',
        'status',
        'currency_code',
        'customer_name',
        'customer_phone',
        'customer_email',
        'shipping_address_snapshot',
        'subtotal_minor',
        'discount_minor',
        'tax_minor',
        'shipping_minor',
        'total_minor',
        'confirmed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'shipping_address_snapshot' => 'array',

            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'shipping_minor' => 'integer',
            'total_minor' => 'integer',

            'confirmed_at' => 'immutable_datetime',

            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(
            Tenant::class
        );
    }

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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(
            Customer::class
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            OrderItem::class
        );
    }

    public function payment(): HasOne
    {
        return $this->hasOne(
            Payment::class
        );
    }
}
