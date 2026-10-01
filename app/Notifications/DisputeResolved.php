<?php

namespace App\Notifications;

use App\Models\Dispute;
use App\Models\Order;

/**
 * What was decided.
 *
 * Both sides are told, and both are told the SAME thing. There is no version
 * of this each party hears separately: a decision somebody would phrase
 * differently depending on who is listening is one that will not survive the
 * two of them comparing notes.
 */
class DisputeResolved extends ClosetNotification
{
    public function __construct(
        private readonly Order $order,
        private readonly Dispute $dispute,
    ) {}

    public function type(): string
    {
        return 'dispute_resolved';
    }

    public function subject(object $notifiable): string
    {
        return 'Order '.$this->order->reference.' has been settled';
    }

    public function payload(object $notifiable): array
    {
        $message = match ($this->dispute->outcome) {
            Dispute::REFUNDED => $this->dispute->refunded_amount
                ? 'Rachels Closet has sent money back to the customer.'
                : 'Rachels Closet has sent your money back to you.',
            Dispute::RELEASED => 'The money has gone to the tailor.',
            default => 'Nothing needed to change, so the order carries on as normal.',
        };

        return [
            'message' => $message,
            'outcome' => $this->dispute->outcome,
            'order_id' => $this->order->id,
            'reference' => $this->order->reference,
        ];
    }

    public function url(object $notifiable): ?string
    {
        return '/orders/'.$this->order->id;
    }
}
