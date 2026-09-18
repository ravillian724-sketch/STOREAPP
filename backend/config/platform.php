<?php

return [
    'app_instance_credential_pepper' => env(
        'APP_INSTANCE_CREDENTIAL_PEPPER'
    ),

    'storefront_cart_ttl_days' => (int) env(
        'STOREFRONT_CART_TTL_DAYS',
        30,
    ),

    'storefront_cart_quote_ttl_minutes' => (int) env(
        'STOREFRONT_CART_QUOTE_TTL_MINUTES',
        5,
    ),

    'storefront_cart_max_line_quantity' => (int) env(
        'STOREFRONT_CART_MAX_LINE_QUANTITY',
        999,
    ),
];
