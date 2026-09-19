<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One garment, one tailor, one customer.
     *
     * Deliberately not BizyFarmers' order: there is no buyer request, no
     * seller application, no waybill and no commission. A tailor and a
     * customer are standing in a shop together; she writes down what is being
     * made and what it costs.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            /*
             * Short and speakable. A customer rings up and says "RC-8FQ2M4" --
             * she is not going to read out a UUID, and an auto-increment id
             * tells the world how many orders the platform has taken.
             */
            $table->string('reference', 16)->unique();

            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tailor_id')->constrained('users')->cascadeOnDelete();

            // Restricted: a garment type is retired, never deleted, precisely
            // so an order placed against it keeps reading correctly.
            $table->foreignId('garment_type_id')->constrained()->restrictOnDelete();

            $table->string('description')->nullable();
            $table->decimal('amount', 14, 2);

            /*
             * What the customer pays up front. Zero means she pays it all on
             * collection. This is the answer to "customer won't collect or
             * can't pay" -- a deposit makes abandoning the garment cost
             * something.
             */
            $table->decimal('deposit_amount', 14, 2)->default(0);

            /*
             * Whether the platform holds the money until collection.
             *
             * Per order, because CLAUDE.md calls escrow optional: plenty of
             * pairs already trust each other and would rather she were paid
             * directly. The platform absorbs the Flutterwave charge either
             * way -- the subscription is the revenue, and charging twice
             * pushes tailors off-platform.
             */
            $table->boolean('escrow')->default(false);

            /*
             * `ready` is the state BizyFarmers has no equivalent of, and it is
             * the one the brief was missing: the garment is finished and
             * waiting, which is when a collection deadline starts running and
             * reminders begin.
             */
            $table->enum('status', [
                'pending_payment',
                'in_progress',
                'ready',
                'collected',
                'completed',
                'cancelled',
                'disputed',
            ])->default('pending_payment');

            $table->date('due_date')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->date('collection_deadline')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            /*
             * Progress, denormalised. Written only by RecalculateOrderProgress
             * in Section 9, recomputed from scratch and never adjusted, so a
             * deleted step cannot leave them drifting -- the same rule as
             * avg_rating on a tailor profile. Created with the table rather
             * than altered in later, because an ALTER for three integers with
             * a default of zero buys nothing.
             */
            $table->unsignedInteger('steps_total')->default(0);
            $table->unsignedInteger('steps_completed')->default(0);
            $table->unsignedInteger('steps_with_photo')->default(0);

            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['tailor_id', 'status']);
            // Finding what is ready and overdue for collection, across everyone.
            $table->index(['status', 'collection_deadline']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
