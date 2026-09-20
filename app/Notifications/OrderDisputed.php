<?php

namespace App\Notifications;

use App\Models\Dispute;
use App\Models\Order;

/**
 * A customer has said something is wrong.
 *
 * Sent to the tailor, and worded with care. She is about to get a phone
 * call, and finding out from the platform first is kinder than being
 * ambushed by it -- but she has not been judged, and the message must not
 * read as though she has.
 *
 * The customer's words are deliberately NOT included. They were written in
 * frustration to be read by staff, not forwarded verbatim to the person
 * complained about, and a call goes better when it does not open with a
 * quotation.
 */
class OrderDisputed extends ClosetNotification
{
    public function __construct(
        private readonly Order $order,
        private readonly Dispute $dispute,
    ) {}

    public function type(): string
    {
        return 'order_disputed';
    }

    public function subject(object $notifiable): string
    {
        return 'A customer has raised a problem';
    }

    public function payload(object $notifiable): array
    {
        return [
            'message' => 'We are looking into '.$this->order->reference
                .' and will call you. Payment for it is on hold until then.',
            'order_id' => $this->order->id,
            'reference' => $this->order->reference,
        ];
    }

    public function url(object $notifiable): ?string
    {
        return '/orders/'.$this->order->id;
    }
}
