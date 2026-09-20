<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\PortfolioItem;
use App\Models\User;
use App\Support\UploadLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The gallery on a tailor's public page.
 *
 * Two doors into one table. The tailor uploads to her own profile so the page
 * is worth visiting on day one; the customer uploads to a finished order --
 * she wore the dress to a party and took pictures -- and those are the ones
 * that actually sell the work, because they show the garment being worn by
 * somebody who chose to show it off.
 *
 * Both are capped, and the caps are settings rather than constants: storage
 * is a real limit on shared hosting and the number is an operational
 * judgement an admin should be able to revise without a deploy.
 */
class PortfolioController extends Controller
{
    /** The tailor's own management view: everything, hidden items included. */
    public function index(Request $request): JsonResponse
    {
        $tailor = $request->user();

        abort_unless($tailor->isTailor(), 404);

        $items = PortfolioItem::query()
            ->with(['uploadedBy', 'order.garmentType'])
            ->where('tailor_id', $tailor->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $items->map(fn (PortfolioItem $item) => $this->shape($item)),
            'own_count' => $items->whereNull('order_id')->count(),
            'max_own' => $this->maxOwn(),
        ]);
    }

    /** The tailor adding one of her own. */
    public function store(Request $request): JsonResponse
    {
        $tailor = $request->user();

        abort_unless($tailor->isTailor(), 404);

        $used = PortfolioItem::query()
            ->where('tailor_id', $tailor->id)
            ->whereNull('order_id')
            ->count();

        abort_if(
            $used >= $this->maxOwn(),
            422,
            'You can keep '.$this->maxOwn().' of your own photographs. Remove one to add another.',
        );

        $validated = $this->validatePhoto($request);

        $item = PortfolioItem::create([
            'tailor_id' => $tailor->id,
            'order_id' => null,
            'uploaded_by' => $tailor->id,
            'path' => $request->file('photo')->store(PortfolioItem::DIRECTORY, 'local'),
            'caption' => $validated['caption'] ?? null,
        ]);

        $this->renumber($tailor);

        return response()->json(['data' => $this->shape($item->fresh())], 201);
    }

    /**
     * The customer adding a photograph of herself wearing it.
     *
     * Only after collection -- before that there is nothing to photograph --
     * and only by the customer on the order. Her choosing to upload is the
     * consent; nothing here reaches into the private step photographs.
     */
    public function storeForOrder(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 404);

        abort_unless(
            in_array($order->status, [Order::COLLECTED, Order::COMPLETED], true),
            422,
            'You can add photographs once you have collected the garment.',
        );

        $cap = $this->maxPerOrder();
        $used = PortfolioItem::query()->where('order_id', $order->id)->count();

        abort_if(
            $used >= $cap,
            422,
            'You can add '.$cap.' photographs to one order.',
        );

        $validated = $this->validatePhoto($request);

        $item = PortfolioItem::create([
            'tailor_id' => $order->tailor_id,
            'order_id' => $order->id,
            'uploaded_by' => $request->user()->id,
            'path' => $request->file('photo')->store(PortfolioItem::DIRECTORY, 'local'),
            'caption' => $validated['caption'] ?? null,
        ]);

        $this->renumber($order->tailor);

        return response()->json(['data' => $this->shape($item->fresh())], 201);
    }

    /** What is on one order, for the customer who put it there. */
    public function forOrder(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->involves($request->user()), 404);

        $items = PortfolioItem::query()
            ->with('uploadedBy')
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $items->map(fn (PortfolioItem $item) => $this->shape($item)),
            'max_per_order' => $this->maxPerOrder(),
            'can_add' => $order->customer_id === $request->user()->id
                && in_array($order->status, [Order::COLLECTED, Order::COMPLETED], true)
                && $items->count() < $this->maxPerOrder(),
        ]);
    }

    /**
     * Take one down.
     *
     * The person who uploaded it may delete it outright. The tailor may
     * delete her own, and may HIDE a customer's -- see hide() -- but never
     * delete somebody else's photograph of her own garment.
     */
    public function destroy(Request $request, PortfolioItem $item): JsonResponse
    {
        abort_unless($item->uploaded_by === $request->user()->id, 404);

        Storage::disk('local')->delete($item->path);
        $tailor = $item->tailor;
        $item->delete();

        if ($tailor) {
            $this->renumber($tailor);
        }

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * The tailor's control over her own shopfront.
     *
     * A public page that somebody else can post to unconditionally is not a
     * page anybody would print on a business card. Hiding leaves the row and
     * the customer's copy alone; it only drops it from the gallery.
     */
    public function hide(Request $request, PortfolioItem $item): JsonResponse
    {
        abort_unless($item->tailor_id === $request->user()->id, 404);

        $item->forceFill(['hidden_at' => now()])->save();

        return response()->json(['data' => ['hidden' => true]]);
    }

    public function show(Request $request, PortfolioItem $item): JsonResponse
    {
        abort_unless($item->tailor_id === $request->user()->id, 404);

        $item->forceFill(['hidden_at' => null])->save();

        return response()->json(['data' => ['hidden' => false]]);
    }

    /**
     * The whole array, PUT. A move, an insert and a removal are one
     * idempotent request -- the same rule as the step arrangement, so a retry
     * on a flaky connection cannot corrupt the order.
     */
    public function reorder(Request $request): JsonResponse
    {
        $tailor = $request->user();

        abort_unless($tailor->isTailor(), 404);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        DB::transaction(function () use ($tailor, $validated) {
            $position = 0;

            foreach ($validated['ids'] as $id) {
                PortfolioItem::query()
                    ->where('tailor_id', $tailor->id)
                    ->whereKey($id)
                    ->update(['position' => ++$position]);
            }
        });

        return response()->json(['data' => ['reordered' => true]]);
    }

    /** @return array<string, mixed> */
    private function validatePhoto(Request $request): array
    {
        return $request->validate([
            'photo' => [
                'required',
                'file',
                'max:'.UploadLimits::maxKilobytes(),
                'mimetypes:image/jpeg,image/png,image/webp',
            ],
            'caption' => ['nullable', 'string', 'max:140'],
        ], [
            'photo.mimetypes' => 'That file is not a photograph this platform can show.',
        ]);
    }

    /** Dense 1..n over the whole gallery, so positions never develop gaps. */
    private function renumber(User $tailor): void
    {
        $position = 0;

        PortfolioItem::query()
            ->where('tailor_id', $tailor->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->each(function (PortfolioItem $item) use (&$position) {
                $item->forceFill(['position' => ++$position])->save();
            });
    }

    private function maxOwn(): int
    {
        return (int) PlatformSetting::get(PlatformSetting::PORTFOLIO_MAX_OWN, 5);
    }

    private function maxPerOrder(): int
    {
        return (int) PlatformSetting::get(PlatformSetting::PORTFOLIO_MAX_PER_ORDER, 5);
    }

    /** @return array<string, mixed> */
    private function shape(PortfolioItem $item): array
    {
        return [
            'id' => $item->id,
            /*
             * The PUBLIC url, not StoredFile's authenticated one. These are
             * the only uploads on the platform that FileAccess does not
             * know about -- /api/files/portfolio/... is refused, because a
             * stranger with a QR code has no token. The app shows the same
             * URL the public page does.
             */
            'url' => route('portfolio.photo', basename($item->path)),
            'caption' => $item->caption,
            'position' => $item->position,
            'hidden' => ! $item->isVisible(),
            'mine' => $item->isHerOwn(),
            'uploaded_by' => $item->uploadedBy?->only(['id', 'name']),
            'order' => $item->order ? [
                'id' => $item->order->id,
                'reference' => $item->order->reference,
                'garment' => $item->order->garmentType?->name,
            ] : null,
            'created_at' => $item->created_at,
        ];
    }
}
