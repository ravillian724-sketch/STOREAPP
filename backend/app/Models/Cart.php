<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    use BelongsToTenant;

    protected $hidden = [
        'token_hash',
    ];

    protected $fillable = [
        'app_instance_id',
        'public_id',
        'token_hash',
        'status',
        'expires_at',
        'converted_at',
        'abandoned_at',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'converted_at' => 'immutable_datetime',
            'abandoned_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(
            CartItem::class
        );
    }
}
