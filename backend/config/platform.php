<?php

return [
    'app_instance_credential_pepper' => env(
        'APP_INSTANCE_CREDENTIAL_PEPPER'
    ),

    'storefront_cart_ttl_days' => (int) env(
        'STOREFRONT_CART_TTL_DAYS',
        30,
    ),
];
