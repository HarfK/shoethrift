<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An item for the admin notification bell (database channel only). Sent through
 * App\Services\AdminNotifier, never directly.
 */
class AdminAlert extends Notification
{
    use Queueable;

    public const TYPES = [
        'order' => 'New order',
        'return' => 'Return request',
        'low_stock' => 'Low stock',
        'payment_failed' => 'Payment failed',
        'message' => 'Customer message',
        'review' => 'New review',
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
