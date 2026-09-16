<?php

namespace App\Support\AppInstance;

use App\Models\AppInstanceCredential;

final readonly class IssuedAppInstanceCredential
{
    public function __construct(
        public AppInstanceCredential $credential,
        public string $token,
    ) {}
}
