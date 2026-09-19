<?php

return [
    'token' => env(
        'CONTROL_PLANE_TOKEN'
    ),

    'rate_limit_per_minute' => (int) env(
        'CONTROL_PLANE_RATE_LIMIT_PER_MINUTE',
        30,
    ),
];
