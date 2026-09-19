<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStep;
use App\Models\OrderStepPhoto;
use App\Services\FileAccess;
use App\Services\Orders\RecalculateOrderProgress;
use App\Support\StoredFile;
use App\Support\UploadLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Photographs of the work.
 *
 * The tracker lets a tailor say a stage is done. This lets her show it. That
 * is the difference between a claim and proof, and it is what Section 13's
 * review gate reads before it will publish a five-star rating.
 *
 * Taken on the phone already in her hand, one tap from the stage it belongs
 * to -- the same reasoning as everything else here: tapping over typing.
 */
class OrderStepPhotoController extends Controller
{
    public function __construct(private readonly RecalculateOrderProgress $progress) {}

    public function store(Request $request, Order $order, OrderStep $step): JsonResponse
    {
        abort_unless($order->tailor_id === $request->user()->id, 404);
        abort_unless($step->order_id === $order->id, 404);

        /*
         * The same window in which she may tick the stage off. Proof of work
         * belongs to the period the work is happening in; afterwards the
         * order is settled and the photographs are what the review was
         * judged against.
         */
        abort_unless(
            in_array($order->status, [Order::IN_PROGRESS, Order::READY], true),
            422,
            'This order is not being worked on.',
        );

        abort_if(
            $step->photos()->count() >= OrderStepPhoto::MAX_PER_STEP,
            422,
            'That stage already has '.OrderStepPhoto::MAX_PER_STEP.' photographs.',
        );

        $request->validate([
            'photo' => [
                'required',
                'file',
                'max:'.UploadLimits::maxKilobytes(),
                // What a phone camera produces, plus what a gallery might hand
                // back. HEIC is not listed: browsers cannot display it, and a
                // photograph nobody can open is not proof of anything.
                'mimetypes:image/jpeg,image/png,image/webp',
            ],
        ], [
            'photo.mimetypes' => 'That file is not a photograph this platform can show.',
        ]);

        $photo = OrderStepPhoto::create([
            'order_step_id' => $step->id,
            // Copied off the step, never taken from the request. See the
            // migration for why this column exists at all.
            'order_id' => $step->order_id,
            'path' => $request->file('photo')->store(FileAccess::STEP_PHOTOS, 'local'),
            'uploaded_by' => $request->user()->id,
        ]);

        $this->progress->handle($order);

        return response()->json(['data' => $this->shape($photo)], 201);
    }

    /**
     * Remove one.
     *
     * Allowed only while the order is still being worked on, for the same
     * reason the status check above exists: a blurred picture of the wrong
     * sleeve is an ordinary mistake, but a photograph withdrawn after the
     * customer has the garment is evidence disappearing from a record she may
     * be about to review.
     */
    public function destroy(Request $request, Order $order, OrderStep $step, OrderStepPhoto $photo): JsonResponse
    {
        abort_unless($order->tailor_id === $request->user()->id, 404);
        abort_unless($step->order_id === $order->id, 404);
        abort_unless($photo->order_step_id === $step->id, 404);

        abort_unless(
            in_array($order->status, [Order::IN_PROGRESS, Order::READY], true),
            422,
            'This order is not being worked on.',
        );

        // The row is the record; the file behind it has no other referent, so
        // unlike a step's voice note there is nothing pointing at it to break.
        Storage::disk('local')->delete($photo->path);
        $photo->delete();

        $this->progress->handle($order);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** @return array<string, mixed> */
    private function shape(OrderStepPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'url' => StoredFile::url($photo->path),
            'created_at' => $photo->created_at,
        ];
    }
}
