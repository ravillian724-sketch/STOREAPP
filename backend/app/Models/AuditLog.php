<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class AuditLog extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'actor_user_id',
        'action',
        'subject_type',
        'subject_id',
        'request_id',
        'http_method',
        'path',
        'ip_address',
        'before_values',
        'after_values',
        'metadata',
        'occurred_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before_values' => 'array',
            'after_values' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(
            function (): never {
                throw new LogicException(
                    'Audit log entries are immutable.'
                );
            }
        );

        self::deleting(
            function (): never {
                throw new LogicException(
                    'Audit log entries are immutable.'
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'actor_user_id',
        );
    }
}
