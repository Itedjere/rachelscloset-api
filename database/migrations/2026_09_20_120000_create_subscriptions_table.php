<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tailor's standing with the platform.
     *
     * NOT A RECURRING BILLING RECORD. She buys 30 or 365 days with a single
     * ordinary Flutterwave charge; nothing here ever charges her again. That
     * is the decision the whole architecture rests on -- see CLAUDE.md §5 --
     * because this host cannot run a worker and a cron-driven biller quietly
     * stops. It also means bank transfer, USSD and Opay all work, where a
     * recurring card charge fails silently on many Nigerian debit cards.
     *
     * THE TIMESTAMPS ARE THE TRUTH. `current_period_end` and `grace_ends_at`
     * decide everything; `status` is a denormalised label for wording only.
     * Every decision -- above all "is she in the directory" -- compares
     * timestamps, so a `status` that has gone stale because no cron ran
     * cannot list a tailor whose term ended. The column is commented in the
     * model to say exactly that, because the next person to read this will
     * reach for `where('status', 'active')`; it is shorter.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            // One per tailor. A second row would be a second answer to a
            // question that has one.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * When the paid days run out, and when the courtesy after them
             * does. Nullable because a row is created the moment she first
             * looks at the page, before she has bought anything.
             */
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_ends_at')->nullable();

            /*
             * active | grace | lapsed.
             *
             * A LABEL, NOT A FACT. Recomputed from the timestamps whenever
             * anything looks at it. Never queried to make a decision.
             */
            $table->enum('status', ['active', 'grace', 'lapsed'])->default('lapsed');

            // For the reminder command: which of T-7/T-3/T-1 has been sent for
            // the current period, so a cron that runs twice does not send
            // twice. Reset when a new term is bought.
            $table->unsignedTinyInteger('last_reminder_days')->nullable();

            $table->timestamps();

            // The directory asks this of everybody at once.
            $table->index('current_period_end');
            $table->index('grace_ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
