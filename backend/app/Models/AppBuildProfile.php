<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class AppBuildProfile extends Model
{
    protected $fillable = [
        'store_provisioning_receipt_id',
        'tenant_id',
        'app_instance_id',
        'slug',
        'display_name',
        'android_application_id',
        'ios_bundle_id',
        'version_name',
        'build_number',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'build_number' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(
            function (AppBuildProfile $profile): void {
                if (
                    trim(
                        (string) $profile->public_id
                    ) === ''
                ) {
                    $profile->public_id =
                        (string) Str::uuid();
                }
            }
        );

        static::updating(
            function (AppBuildProfile $profile): void {
                foreach (
                    [
                        'public_id',
                        'store_provisioning_receipt_id',
                        'tenant_id',
                        'app_instance_id',
                        'slug',
                        'android_application_id',
                        'ios_bundle_id',
                    ] as $immutable
                ) {
                    if (
                        $profile->isDirty(
                            $immutable
                        )
                    ) {
                        throw new LogicException(
                            'Build profile identity cannot be changed.'
                        );
                    }
                }
            }
        );
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(
            StoreProvisioningReceipt::class,
            'store_provisioning_receipt_id',
        );
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
}
