<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderStep;

/**
 * A stage has been ticked off.
 *
 * THIS IS THE PRODUCT. Everything else on the platform is scaffolding around
 * this one message: the customer handed over cloth and money and now finds out
 * her clothes have moved on without walking back across town to ask.
 *
 * The label is the SNAPSHOT from the order, not the library, so the words in
 * the notification are the words she is looking at on the order.
 */
class StepCompleted extends ClosetNotification
{
    public function __construct(
        private readonly Order $order,
        private readonly OrderStep $step,
    ) {}

    public function type(): string
    {
        return 'step_completed';
    }

    public function subject(object $notifiable): string
    {
        return $this->step->label;
    }

    public function payload(object $notifiable): array
    {
        $garment = $this->order->garmentType?->name ?? 'your order';

        return [
            // Short, because it is read on a lock screen by somebody who may
            // find reading hard. One fact, in her own terms.
            'message' => $this->step->label.' — '.strtolower($garment).'.',
            'order_id' => $this->order->id,
            'reference' => $this->order->reference,
            'step' => $this->step->position.' of '.$this->order->steps_total,
        ];
    }

    public function url(object $notifiable): ?string
    {
        return '/orders/'.$this->order->id;
    }
}
