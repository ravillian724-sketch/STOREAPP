<?php

namespace App\Support\Cart;

use App\Models\Cart;

final readonly class CreatedCart
{
    public function __construct(
        public Cart $cart,
        public string $token,
    ) {}
}
