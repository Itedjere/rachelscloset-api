<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the customer confirmed the garment actually reached her.
     *
     * `collected_at` is the TAILOR's action: she handed it over, or she
     * posted it. For somebody in the same town those are the same moment.
     * For the remote customer this platform exists to serve, they are not --
     * the tailor posts it, and the parcel takes a week.
     *
     * That gap was a real hole. The escrow clock ran from `collected_at`, so
     * the money could release three days after the tailor went to the post
     * office, before the customer had opened the box. A complaint would then
     * arrive after the money had gone, which is the one thing escrow exists
     * to prevent.
     *
     * The clock now runs from here instead, with a long backstop from
     * `collected_at` so a customer who never confirms cannot strand the
     * tailor's money for ever. See Order::escrowReleaseDue().
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('collected_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('received_at');
        });
    }
};
