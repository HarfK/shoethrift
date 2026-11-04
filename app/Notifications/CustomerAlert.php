<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An item for the customer notification bell (database channel only). Sent through
 * App\Services\CustomerNotifier, never directly. `url` is a relative path (e.g. /account/orders/TS-…),
 * so links work on any host, also when sent from the scheduler.
 */
class CustomerAlert extends Notification
{
    use Queueable;

    public const TYPES = [
        'order' => 'Order',
        'return' => 'Return',
        'hold' => 'Cart hold',
    ];

    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $body = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{kind: string, title: string, body: ?string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
        ];
    }
}
