<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreProvisioningReceipt extends Model
{
    protected $fillable = [
        'public_id',
        'idempotency_key_hash',
        'request_fingerprint',
        'status',
        'tenant_id',
        'branch_id',
        'app_instance_id',
        'app_instance_credential_id',
        'owner_user_id',
    ];

    protected $hidden = [
        'idempotency_key_hash',
        'request_fingerprint',
    ];
}
