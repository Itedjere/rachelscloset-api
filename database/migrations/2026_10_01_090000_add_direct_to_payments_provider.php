<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A second payment "provider": the customer's own hand.
 *
 * On a direct order the customer pays the tailor herself -- cash, a bank
 * transfer, a POS -- and nothing passes through Rachels Closet. Until now the
 * only way such an order could leave pending_payment was the Pay button, which
 * charged the customer through Flutterwave INTO THE PLATFORM'S ACCOUNT and
 * recorded no payout, because a direct order has none. The tailor was never
 * paid and the screen said "paid straight to your tailor".
 *
 * The tailor now records what she was handed, as an ordinary payment row with
 * provider `direct`, so "paid so far", "still owing" and the collection
 * reminders keep reading one source. The create_payments_table migration made
 * provider an enum precisely so that a second one would be this migration
 * rather than a rewrite.
 *
 * Raw SQL because changing an enum's members is not something the schema
 * builder does without doctrine/dbal, which this project does not carry.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE payments MODIFY provider ENUM('flutterwave', 'direct') NOT NULL DEFAULT 'flutterwave'"
        );
    }

    public function down(): void
    {
        // Refuses rather than truncating: narrowing the enum with direct rows
        // present would silently rewrite real records of money changing hands.
        if (DB::table('payments')->where('provider', 'direct')->exists()) {
            throw new RuntimeException(
                'Direct payments are recorded; remove them deliberately before rolling this back.'
            );
        }

        DB::statement(
            "ALTER TABLE payments MODIFY provider ENUM('flutterwave') NOT NULL DEFAULT 'flutterwave'"
        );
    }
};
