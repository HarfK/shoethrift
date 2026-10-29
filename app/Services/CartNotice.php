<?php

namespace App\Services;

/**
 * A message about a change to the cart (ShoppingCart::reconcile()), with an optional link
 * (e.g. "See other UK 8.5 shoes") and optional similar available pairs to suggest.
 * Plain data, so it can be flashed to the session.
 */
final class CartNotice
{
    /**
     * @param  list<array{url: string, label: string}>  $similar
     */
    public function __construct(
        public readonly string $message,
        public readonly ?string $linkUrl = null,
        public readonly ?string $linkLabel = null,
        public readonly array $similar = [],
    ) {}
}
