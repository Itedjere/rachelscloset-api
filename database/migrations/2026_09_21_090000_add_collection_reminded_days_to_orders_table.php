<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How near the collection deadline she was last reminded.
 *
 * The exact analogue of `subscriptions.last_reminder_days`, and there for the
 * same reason: a cron firing twice in a day is the ordinary way these things
 * are misconfigured, and without somewhere to record what was already said,
 * the second run says it again.
 *
 * It counts DOWN -- 3, then 1, then 0 for the day it fell overdue -- so the
 * command can send the smallest milestone still owed and a cron that missed
 * four days says "tomorrow" rather than a stale "three days" followed by the
 * rest in a burst. Zero being terminal is what stops an uncollected garment
 * generating a notification every night for ever, which is how somebody
 * learns to ignore all of them.
 *
 * Null means never reminded, which is why it takes no default: a schema
 * default of 0 would read as "already told her it is overdue".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('collection_reminded_days')
                ->nullable()
                ->after('collection_deadline');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('collection_reminded_days');
        });
    }
};
