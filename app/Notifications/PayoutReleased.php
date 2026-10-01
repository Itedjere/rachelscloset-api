<?php

namespace App\Notifications;

use App\Models\Payout;

/** Her money has been sent. */
class PayoutReleased extends ClosetNotification
{
    public function __construct(private readonly Payout $payout) {}

    public function type(): string
    {
        return 'payout_released';
    }

    public function subject(object $notifiable): string
    {
        return 'Money sent to your account';
    }

    public function payload(object $notifiable): array
    {
        return [
            'message' => 'Rachels Closet has sent you the money for order '
                .$this->payout->order->reference.'. It should reach your bank shortly.',
            'order_id' => $this->payout->order_id,
            'amount' => (string) $this->payout->net_amount,
        ];
    }

    public function url(object $notifiable): ?string
    {
        return '/orders/'.$this->payout->order_id;
    }
}
