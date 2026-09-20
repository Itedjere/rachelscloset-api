<?php

namespace App\Notifications;

use App\Models\Order;

/**
 * Your clothes are finished and you have not come for them.
 *
 * One of the six problems in the brief, and the half of it the platform could
 * never act on: a tailor's shop fills with finished garments nobody collects,
 * her money is tied up in cloth she has already worked, and her only recourse
 * is to keep ringing. `ready` and the collection deadline have existed since
 * Section 5 precisely so that this could be sent; until now nothing sent it.
 *
 * ONE CLASS, TWO AUDIENCES. At the overdue milestone the tailor is told as
 * well, and she is told something different: the customer's phone number.
 * That is the same answer the dispute screen gives -- when a thing is stuck,
 * the platform's job is to make the phone call possible, not to adjudicate.
 */
class CollectionReminder extends ClosetNotification
{
    /**
     * @param  int  $days  How many days are left. Zero means the deadline has
     *                     passed, which is the last thing either side hears.
     */
    public function __construct(
        private readonly Order $order,
        private readonly int $days,
    ) {}

    public function type(): string
    {
        return 'collection_reminder';
    }

    private function isTailor(object $notifiable): bool
    {
        return $notifiable->id === $this->order->tailor_id;
    }

    private function garment(): string
    {
        return strtolower($this->order->garmentType?->name ?? 'order');
    }

    private function due(): string
    {
        return $this->order->collection_deadline?->format('j F') ?? 'now';
    }

    public function subject(object $notifiable): string
    {
        if ($this->isTailor($notifiable)) {
            return 'A garment has not been collected';
        }

        return match (true) {
            $this->days === 0 => 'Your '.$this->garment().' is still waiting',
            $this->days === 1 => 'Please collect your '.$this->garment(),
            default => 'Your '.$this->garment().' is ready for you',
        };
    }

    public function payload(object $notifiable): array
    {
        return [
            'message' => $this->isTailor($notifiable)
                ? $this->toTailor()
                : $this->toCustomer(),
            'days' => $this->days,
            'order_id' => $this->order->id,
            'reference' => $this->order->reference,
            'due_on' => $this->order->collection_deadline?->toDateString(),
        ];
    }

    /**
     * Her number, because that is the only thing that moves this on.
     *
     * She is not asked to do anything in the app: there is nothing in the app
     * that fetches a garment from a shop.
     */
    private function toTailor(): string
    {
        $who = $this->order->customer?->name ?? 'Your customer';
        $phone = $this->order->customer?->phone;

        return $who.' has not collected her '.$this->garment()
            .'. It was due on '.$this->due().'.'
            .($phone ? ' Her number is '.$phone.'.' : '');
    }

    private function toCustomer(): string
    {
        $where = $this->order->tailor?->name ?? 'your tailor';

        /*
         * EVERY MESSAGE NAMES THE DATE, and none of them says "tomorrow".
         * This command is a courtesy that is allowed to miss a day, so a
         * relative word is a lie waiting for the first night the cron does
         * not run -- and a date is what she would write down anyway.
         */
        $message = match (true) {
            $this->days === 0 => 'Your '.$this->garment().' is still at '.$where
                .'. It was due to be collected on '.$this->due().'.',
            $this->days === 1 => 'Your '.$this->garment().' is waiting at '.$where
                .'. The last day to collect it is '.$this->due().'.',
            default => 'Your '.$this->garment().' is waiting at '.$where
                .'. Please collect it by '.$this->due().'.',
        };

        /*
         * "Won't collect OR CAN'T PAY" is one problem in the brief, not two.
         * Somebody who owes a balance and does not know it turns up, finds
         * out at the counter and goes home again -- so the figure is said
         * here, days ahead, where she can do something about it.
         */
        $owed = bcsub((string) $this->order->amount, $this->order->paidTotal(), 2);

        if (bccomp($owed, '0', 2) === 1) {
            $message .= ' There is ₦'.number_format((float) $owed, 2).' to pay when you collect.';
        }

        return $message;
    }

    public function url(object $notifiable): ?string
    {
        return '/orders/'.$this->order->id;
    }
}
