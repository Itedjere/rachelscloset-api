<?php

namespace App\Notifications;

use App\Models\Order;

/**
 * The tailor is told the money has arrived and she can start.
 *
 * Categorised as ORDERS in NotificationCategories, which she can mute. The
 * in-app record is written regardless -- a toggle must not be able to erase
 * the record of somebody paying.
 */
class OrderPaid extends ClosetNotification
{
    public function __construct(private readonly Order $order) {}

    public function type(): string
    {
        return 'order_paid';
    }

    public function subject(object $notifiable): string
    {
        return 'Payment received';
    }

    public function payload(object $notifiable): array
    {
        $garment = $this->order->garmentType?->name ?? 'an order';

        return [
            'message' => $this->order->customer->name.' has paid for '.$garment.'. You can start.',
            'order_id' => $this->order->id,
            'reference' => $this->order->reference,
        ];
    }

    public function url(object $notifiable): ?string
    {
        return '/orders/'.$this->order->id;
    }
}
