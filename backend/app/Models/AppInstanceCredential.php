<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppInstanceCredential extends Model
{
    protected $fillable = [
        'app_instance_id',
        'public_id',
        'secret_hash',
        'valid_from',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class);
    }
}
