<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Notifications\ReviewReceived;
use App\Services\Reviews\RecalculateTailorRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Held reviews.
 *
 * Without this screen the gate would be a place reviews disappear into, which
 * is worse than having no gate: a customer who took the trouble to praise her
 * tailor deserves either publication or a human decision, not silence.
 *
 * There is no "reject". An admin can release a held review or leave it held,
 * and that is the whole vocabulary -- a button that permanently destroys
 * somebody's opinion of a business is not one this platform needs, and the
 * gate exists to catch invented orders rather than inconvenient praise.
 */
class ReviewController extends Controller
{
    public function __construct(private readonly RecalculateTailorRating $recalculate) {}

    public function index(): JsonResponse
    {
        $reviews = Review::query()
            ->where('status', Review::HELD)
            ->with(['author', 'subject', 'order.garmentType'])
            ->oldest('id')
            ->get();

        return response()->json([
            'data' => $reviews->map(fn (Review $review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'body' => $review->body,
                'author' => $review->author?->only(['id', 'name']),
                'subject' => $review->subject?->only(['id', 'name']),
                'order' => [
                    'id' => $review->order_id,
                    'reference' => $review->order?->reference,
                    'garment' => $review->order?->garmentType?->name,
                    // The numbers the gate actually looked at, so the decision
                    // can be reviewed rather than merely taken.
                    'steps_completed' => $review->order?->steps_completed,
                    'steps_with_photo' => $review->order?->steps_with_photo,
                ],
                'proof_ratio' => $review->proof_ratio_snapshot,
                'created_at' => $review->created_at,
            ]),
        ]);
    }

    public function release(Request $request, Review $review): JsonResponse
    {
        abort_unless($review->status === Review::HELD, 422, 'That review is already published.');

        $review->publish($request->user());

        if ($review->direction === Review::CUSTOMER_TO_TAILOR) {
            $this->recalculate->handle($review->subject);
        }

        // Announced now rather than when it was written, because now is when
        // it became true.
        $review->subject->notify(new ReviewReceived($review->load('author', 'order')));

        return response()->json(['data' => ['status' => $review->fresh()->status]]);
    }
}
