<?php

namespace App\Notifications;

use App\Models\Order;

/** The garment is finished and waiting to be collected. */
class OrderReady extends ClosetNotification
{
    public function __construct(private readonly Order $order) {}

    public function type(): string
    {
        return 'order_ready';
    }

    public function subject(object $notifiable): string
    {
        return 'Ready to collect';
    }

    public function payload(object $notifiable): array
    {
        $garment = strtolower($this->order->garmentType?->name ?? 'your order');
        $message = 'Your '.$garment.' is finished and waiting at '
            .$this->order->tailor->name.'.';

        return [
            'message' => $this->order->collection_deadline
                ? $message.' Collect by '.$this->order->collection_deadline->format('j F').'.'
                : $message,
            'order_id' => $this->order->id,
            'reference' => $this->order->reference,
        ];
    }

    public function url(object $notifiable): ?string
    {
        return '/orders/'.$this->order->id;
    }
}
