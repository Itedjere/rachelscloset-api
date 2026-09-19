<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a payout goes.
     *
     * Only needed by tailors who take escrow orders, so every column is
     * nullable and an incomplete profile is an ordinary state rather than a
     * fault: a payout simply stays `pending` until the details exist. Never
     * hold up the work over a form.
     */
    public function up(): void
    {
        Schema::table('tailor_profiles', function (Blueprint $table) {
            // Nigerian bank codes are three digits; stored as a string so a
            // leading zero survives.
            $table->string('bank_code', 10)->nullable()->after('whatsapp_phone');
            $table->string('bank_account_number', 20)->nullable()->after('bank_code');

            /*
             * As the bank returns it, not as she typed it. Resolving the
             * number against the bank gives the real account name, and showing
             * her that before the first payout is what catches a wrong digit
             * while it is still cheap to fix.
             */
            $table->string('bank_account_name')->nullable()->after('bank_account_number');

            // The provider's handle for the above, once created.
            $table->string('transfer_recipient')->nullable()->after('bank_account_name');
        });
    }

    public function down(): void
    {
        Schema::table('tailor_profiles', function (Blueprint $table) {
            $table->dropColumn(['bank_code', 'bank_account_number', 'bank_account_name', 'transfer_recipient']);
        });
    }
};
