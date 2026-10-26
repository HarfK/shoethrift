<?php

namespace App\Services;

/**
 * A message about a change ShoppingCart::reconcile() made to the cart, with an optional link
 * (e.g. "See other UK 8.5 shoes"). Plain data, so it can be flashed to the session.
 */
final class CartNotice
{
    public function __construct(
        public readonly string $message,
        public readonly ?string $linkUrl = null,
        public readonly ?string $linkLabel = null,
    ) {}
}
