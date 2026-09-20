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
        return 'We have settled '.$this->order->reference;
    }

    public function payload(object $notifiable): array
    {
        $message = match ($this->dispute->outcome) {
            Dispute::REFUNDED => $this->dispute->refunded_amount
                ? 'Money has been sent back to the customer.'
                : 'The payment has been refunded.',
            Dispute::RELEASED => 'The payment has gone to the tailor.',
            default => 'Nothing needed to change.',
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
