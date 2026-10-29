<?php

namespace App\Notifications;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email to a customer who asked to be told when a reserved pair is free again (sent once).
 * With MAIL_MAILER=log it is written to storage/logs/laravel.log.
 */
class ProductAvailableAgain extends Notification
{
    use Queueable;

    public function __construct(public readonly Product $product) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = "{$this->product->name} ({$this->product->size_label})";

        return (new MailMessage)
            ->subject("{$name} is available again")
            ->line("Good news: {$name} is no longer reserved and can be added to your cart.")
            ->line('Pairs are one of a kind, so whoever adds it first gets to hold it for '.config('shop.cart.hold_minutes').' minutes.')
            ->action('View the pair', route('shop.show', $this->product->slug));
    }
}
