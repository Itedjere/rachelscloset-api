<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Payment;
use App\Support\Naira;

/**
 * The customer's receipt for money she handed the tailor herself.
 *
 * On a direct order nothing passes through the platform, so this is the only
 * record she gets -- and it is her check on the tailor's arithmetic: if the
 * amount is wrong, she hears about it the moment it is written, not at the
 * counter when she comes to collect. Categorised as MONEY.
 */
class DirectPaymentRecorded extends ClosetNotification
{
    public function __construct(
        private readonly Order $order,
        private readonly Payment $payment,
    ) {}

    public function type(): string
    {
        return 'direct_payment_recorded';
    }

    public function subject(object $notifiable): string
    {
        return 'Your payment was recorded';
    }

    public function payload(object $notifiable): array
    {
        $tailor = $this->order->tailor?->tailorProfile?->business_name
            ?? $this->order->tailor?->name
            ?? 'Your tailor';
        $garment = $this->order->garmentType?->name ?? 'your order';
        $outstanding = bcsub((string) $this->order->amount, $this->order->paidTotal(), 2);

        $message = $tailor.' marked '.Naira::format($this->payment->amount)
            .' as paid for '.$garment.'.';

        $message .= bccomp($outstanding, '0', 2) === 1
            ? ' '.Naira::format($outstanding).' is still to pay.'
            : ' It is fully paid.';

        return [
            'message' => $message.' If that is not right, speak to your tailor.',
            'order_id' => $this->order->id,
            'reference' => $this->order->reference,
            'amount' => $this->payment->amount,
        ];
    }

    public function url(object $notifiable): ?string
    {
        return '/orders/'.$this->order->id;
    }
}
