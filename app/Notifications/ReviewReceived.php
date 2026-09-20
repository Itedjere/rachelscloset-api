<?php

namespace App\Notifications;

use App\Models\Review;

/**
 * Somebody has reviewed you.
 *
 * Only ever sent for a PUBLISHED review. Telling a tailor about one she
 * cannot read, and which may never appear, is worse than saying nothing --
 * and it would leak the rating of a held review, which is the one thing the
 * gate is meant to keep out of the public record until it is earned.
 *
 * The type string `review_received` was mapped into NotificationCategories in
 * Section 2, ahead of this section existing. This is that promise coming due.
 */
class ReviewReceived extends ClosetNotification
{
    public function __construct(private readonly Review $review) {}

    public function type(): string
    {
        return 'review_received';
    }

    public function subject(object $notifiable): string
    {
        return $this->review->rating.' stars from '.($this->review->author?->name ?? 'a customer');
    }

    public function payload(object $notifiable): array
    {
        return [
            // The rating is the message. Whether they wrote anything is
            // secondary, and a lock screen is no place for two sentences.
            'message' => $this->review->author?->name
                ? $this->review->author->name.' left you '.$this->review->rating.' stars.'
                : 'You have a new review: '.$this->review->rating.' stars.',
            'rating' => $this->review->rating,
            'order_id' => $this->review->order_id,
            'reference' => $this->review->order?->reference,
        ];
    }

    public function url(object $notifiable): ?string
    {
        return '/orders/'.$this->review->order_id;
    }
}
