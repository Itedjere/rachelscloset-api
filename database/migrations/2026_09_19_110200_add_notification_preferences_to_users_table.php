<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which kinds of event a person wants pushed to their phone.
     *
     * A column on the user rather than a table of its own, because it is read
     * on the way to sending every single notification -- and at that moment the
     * user row is already loaded, so a table would mean a query per alert for a
     * value that is a handful of booleans.
     *
     * Null means "not chosen yet", treated as all on: a new account hears about
     * everything until it says otherwise, and a group that did not exist when
     * the preferences were saved is not silently muted.
     *
     * This governs push, not email. BizyFarmers' equivalent column was about
     * email, but nothing here requires an address and most tailors have never
     * had one -- so the channel that actually reaches a person is the one worth
     * offering a switch for.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('suspended_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
