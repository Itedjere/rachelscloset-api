<?php

namespace App\Notifications;

use App\Models\Order;

/** The customer is told money is coming back. */
class OrderRefunded extends ClosetNotification
{
    public function __construct(
        private readonly Order $order,
        private readonly string $amount,
        private readonly ?string $reason = null,
    ) {}

    public function type(): string
    {
        return 'order_refunded';
    }

    public function subject(object $notifiable): string
    {
        return 'Money refunded';
    }

    public function payload(object $notifiable): array
    {
        $message = 'We have refunded '.number_format((float) $this->amount, 2)
            .' naira on order '.$this->order->reference.'.';

        return [
            'message' => $this->reason ? $message.' '.$this->reason : $message,
            'order_id' => $this->order->id,
            'amount' => $this->amount,
        ];
    }

    public function url(object $notifiable): ?string
    {
        return '/orders/'.$this->order->id;
    }
}
