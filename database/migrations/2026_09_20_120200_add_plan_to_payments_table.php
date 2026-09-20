<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which subscription plan a payment is buying.
     *
     * `payments` has carried `purpose` and a nullable `subscription_id` since
     * Section 4, against this section existing. This is the one thing that was
     * missing: the term cannot be minted without knowing whether thirty days
     * or three hundred and sixty five were bought.
     *
     * The alternative was inferring it from the amount, which breaks the
     * moment an admin sets two prices the same or changes one while somebody
     * is mid-payment -- and a payment in flight when the price moves is
     * exactly the case the snapshotting elsewhere exists to handle.
     *
     * Null on every order payment, which is all of them so far.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('plan', ['monthly', 'yearly'])->nullable()->after('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('plan');
        });
    }
};
