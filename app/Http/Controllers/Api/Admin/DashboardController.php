<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payout;
use App\Models\PlatformSetting;
use App\Models\Review;
use App\Models\Subscription;
use App\Models\SubscriptionTerm;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * What an admin needs to know on opening the platform.
 *
 * Two questions, in this order: IS ANYTHING WAITING FOR ME, and IS ANYTHING
 * QUIETLY BROKEN. The numbers come third, because a figure nobody acts on is
 * decoration.
 *
 * The health panel is the reason this section exists. Nothing on this
 * platform is load-bearing on cron -- escrow release is computed, expiry is
 * computed, pruning is housekeeping -- and that is deliberate, but it is also
 * exactly what makes a stopped schedule invisible. CLAUDE.md has promised
 * since Section 2 that `notifications_pruned_at` "shows on the admin
 * dashboard rather than being discovered when the disk fills". This is that
 * promise, extended to all three commands.
 */
class DashboardController extends Controller
{
    /** Past this many hours with no heartbeat, a daily command is overdue. */
    private const STALE_AFTER_HOURS = 36;

    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'attention' => $this->attention(),
            'health' => $this->health(),
            'numbers' => $this->numbers(),
        ]]);
    }

    /**
     * Things a person has to decide about.
     *
     * Every row carries a count and somewhere to go. An item with nothing to
     * do is omitted rather than shown as zero -- a list of zeroes trains
     * somebody to stop reading the list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function attention(): array
    {
        $items = [];

        $held = Review::query()->where('status', Review::HELD)->count();

        if ($held > 0) {
            $items[] = [
                'key' => 'held_reviews',
                'count' => $held,
                'label' => $held === 1 ? 'review waiting to be checked' : 'reviews waiting to be checked',
                'href' => '/admin/reviews',
                'tone' => 'normal',
            ];
        }

        /*
         * Money that should have moved and has not. A payout stays pending
         * when a tailor has given no bank details, and fails when the
         * transfer was refused -- the second is the one that needs a person.
         */
        $failed = Payout::query()->where('status', Payout::FAILED)->count();

        if ($failed > 0) {
            $items[] = [
                'key' => 'failed_payouts',
                'count' => $failed,
                'label' => $failed === 1 ? 'payout failed' : 'payouts failed',
                'href' => '/admin/orders?status=completed',
                'tone' => 'bad',
            ];
        }

        $stuck = Payout::query()
            ->where('status', Payout::PENDING)
            ->whereHas('order', fn ($q) => $q->whereIn('status', [Order::COLLECTED, Order::COMPLETED]))
            ->count();

        if ($stuck > 0) {
            $items[] = [
                'key' => 'pending_payouts',
                'count' => $stuck,
                'label' => $stuck === 1 ? 'tailor is owed money' : 'tailors are owed money',
                'href' => '/admin/orders?status=completed',
                'tone' => 'normal',
            ];
        }

        // The state the brief was missing, doing its job: garments finished and
        // not collected are the platform's other unpaid problem.
        $overdue = Order::query()
            ->where('status', Order::READY)
            ->whereNotNull('collection_deadline')
            ->whereDate('collection_deadline', '<', now())
            ->count();

        if ($overdue > 0) {
            $items[] = [
                'key' => 'overdue_collection',
                'count' => $overdue,
                'label' => $overdue === 1 ? 'garment is past its collection date' : 'garments are past their collection date',
                'href' => '/admin/orders?status=ready',
                'tone' => 'normal',
            ];
        }

        $disputed = Order::query()->where('status', Order::DISPUTED)->count();

        if ($disputed > 0) {
            $items[] = [
                'key' => 'disputed',
                'count' => $disputed,
                'label' => $disputed === 1 ? 'order in dispute' : 'orders in dispute',
                'href' => '/admin/orders?status=disputed',
                'tone' => 'bad',
            ];
        }

        /*
         * Somebody probing the webhook endpoint. Written down since Section 4
         * precisely so it could be seen; this is where it gets seen.
         */
        $badSignatures = WebhookEvent::query()
            ->where('signature_valid', false)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        if ($badSignatures > 0) {
            $items[] = [
                'key' => 'bad_webhooks',
                'count' => $badSignatures,
                'label' => 'webhook calls with a bad signature this week',
                'href' => null,
                'tone' => 'bad',
            ];
        }

        return $items;
    }

    /**
     * Whether the scheduled commands are still running.
     *
     * Each reports when it last finished. None of them is load-bearing, which
     * is the whole design -- and exactly why a stopped one would otherwise go
     * unnoticed until a tailor complained her money was late.
     *
     * @return array<int, array<string, mixed>>
     */
    private function health(): array
    {
        return collect([
            [
                'key' => PlatformSetting::NOTIFICATIONS_PRUNED_AT,
                'label' => 'Notifications pruned',
                'note' => 'This table grows fastest of any: one nine-stage garment is nine rows.',
            ],
            [
                'key' => PlatformSetting::PAYOUTS_RELEASED_AT,
                'label' => 'Escrow swept',
                'note' => 'A courtesy — a tailor can release her own money the moment it is due.',
            ],
            [
                'key' => PlatformSetting::SUBSCRIPTIONS_REMINDED_AT,
                'label' => 'Listing reminders sent',
                'note' => 'A courtesy — the dashboard banner tells her regardless.',
            ],
        ])->map(function (array $row) {
            $raw = PlatformSetting::get($row['key']);
            $at = $raw ? Carbon::parse($raw) : null;

            return [
                ...$row,
                'last_run_at' => $at,
                // Never run and long overdue are different facts, and the
                // first is normal on a machine that has only just been set up.
                'state' => match (true) {
                    $at === null => 'never',
                    $at->diffInHours(now()) > self::STALE_AFTER_HOURS => 'stale',
                    default => 'ok',
                },
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function numbers(): array
    {
        $listed = Subscription::query()->covering()->count();

        return [
            'orders_in_progress' => Order::query()
                ->whereIn('status', [Order::IN_PROGRESS, Order::READY])
                ->count(),
            'orders_this_month' => Order::query()
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
            'tailors' => User::query()->where('role', User::ROLE_TAILOR)->count(),
            'tailors_listed' => $listed,
            'customers' => User::query()->where('role', User::ROLE_CUSTOMER)->count(),
            /*
             * Subscription income, which is the platform's only revenue --
             * no commission is taken on orders, by design. Summed from the
             * ledger rather than from payments, because a term is the thing
             * that was actually sold.
             */
            'subscription_income_this_month' => (string) SubscriptionTerm::query()
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum('amount'),
            'held_in_escrow' => (string) Payout::query()
                ->where('status', Payout::PENDING)
                ->sum('net_amount'),
        ];
    }
}
