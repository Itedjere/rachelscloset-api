<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use App\Notifications\ReviewReceived;
use App\Services\Reviews\PublishReview;
use App\Services\Reviews\RecalculateTailorRating;
use App\Support\StoredFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Two-way reviews.
 *
 * The customer says whether the garment was right; the tailor says whether
 * the customer collected and paid. Both, because the platform has two
 * problems and a directory that rated only tailors would ask them to carry
 * all the risk of meeting a stranger.
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly PublishReview $publish,
        private readonly RecalculateTailorRating $recalculate,
    ) {}

    /**
     * Somebody's published reviews. Open to any signed-in account, because
     * this is what the directory is made of.
     */
    public function index(User $user): JsonResponse
    {
        $reviews = Review::query()
            ->published()
            ->with('author')
            ->where('subject_id', $user->id)
            ->latest('published_at')
            ->get();

        return response()->json([
            'data' => $reviews->map(fn (Review $review) => $this->shape($review)),
            'summary' => [
                'count' => $reviews->count(),
                'average' => $reviews->isEmpty() ? null : round($reviews->avg('rating'), 2),
            ],
        ]);
    }

    /** What is on this order, and whether the person asking still owes one. */
    public function forOrder(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->involves($request->user()), 404);

        $reviews = $order->reviews()->with('author')->get();
        $mine = $this->directionFor($request->user(), $order);

        return response()->json([
            'data' => $reviews
                // A held review is visible to the person who wrote it, so she
                // is not left wondering whether it saved. Nobody else sees it.
                ->filter(fn (Review $r) => $r->isPublished() || $r->author_id === $request->user()->id)
                ->map(fn (Review $r) => $this->shape($r))
                ->values(),
            'can_review' => $this->canReview($order) && ! $reviews->contains('direction', $mine),
            'direction' => $mine,
        ]);
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $author = $request->user();

        abort_unless($order->involves($author), 404);

        abort_unless(
            $this->canReview($order),
            422,
            'You can leave a review once the garment has been collected.',
        );

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        $direction = $this->directionFor($author, $order);

        // The unique index is the real rule; this is the readable version of
        // the same refusal.
        abort_if(
            $order->reviews()->where('direction', $direction)->exists(),
            422,
            'You have already reviewed this order.',
        );

        $review = DB::transaction(function () use ($order, $author, $direction, $validated) {
            $review = Review::create([
                'order_id' => $order->id,
                'direction' => $direction,
                'author_id' => $author->id,
                'subject_id' => $direction === Review::CUSTOMER_TO_TAILOR
                    ? $order->tailor_id
                    : $order->customer_id,
                'rating' => $validated['rating'],
                'body' => $validated['body'] ?? null,
            ]);

            return $this->publish->handle($review, $order);
        });

        if ($review->direction === Review::CUSTOMER_TO_TAILOR) {
            $this->recalculate->handle($order->tailor);
        }

        /*
         * Only a published review is announced. Telling somebody about a
         * review they cannot read -- and which may never appear -- is worse
         * than saying nothing, and it would also leak the rating of a held
         * one.
         */
        if ($review->isPublished()) {
            $review->subject->notify(new ReviewReceived($review->load('author', 'order')));
        }

        return response()->json(['data' => $this->shape($review)], 201);
    }

    /**
     * Whether this order is far enough along to judge.
     *
     * Collection, not completion. Once she has the garment in her hands she
     * knows whether it is right, and escrow may still be running its waiting
     * period for days afterwards -- making her wait for that would collect
     * reviews long after the fitting, when nobody remembers.
     */
    private function canReview(Order $order): bool
    {
        return in_array($order->status, [Order::COLLECTED, Order::COMPLETED], true);
    }

    private function directionFor(User $user, Order $order): string
    {
        return $user->id === $order->customer_id
            ? Review::CUSTOMER_TO_TAILOR
            : Review::TAILOR_TO_CUSTOMER;
    }

    /** @return array<string, mixed> */
    private function shape(Review $review): array
    {
        return [
            'id' => $review->id,
            'direction' => $review->direction,
            'rating' => $review->rating,
            'body' => $review->body,
            'status' => $review->status,
            'author' => [
                'id' => $review->author?->id,
                'name' => $review->author?->name,
                'avatar_url' => StoredFile::url($review->author?->avatar_url),
            ],
            'published_at' => $review->published_at,
            'created_at' => $review->created_at,
        ];
    }
}
