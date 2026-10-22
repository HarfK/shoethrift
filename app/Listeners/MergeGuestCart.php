<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\ShoppingCart;
use Illuminate\Auth\Events\Login;

/**
 * Keeps what a guest put in their cart when they log in or register (registration logs in too).
 */
class MergeGuestCart
{
    public function __construct(private ShoppingCart $cart) {}

    public function handle(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->cart->mergeGuestCartInto($event->user);
        }
    }
}
