<?php

namespace App\Support\ControlPlane;

use App\Models\AppBuildProfile;

final class ConfiguredBuildProfile
{
    public function __construct(
        public readonly AppBuildProfile $profile,
        public readonly bool $created,
    ) {}
}
