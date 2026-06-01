<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the overloaded order_payments.type column into two orthogonal axes:
 *
 *   transaction_type  what the ledger row is   PAYMENT | DONATION | COMP | WRITE_OFF
 *   payment_method    how money moved          CASH | CHECK | CREDIT_CARD | BANK_TRANSFER | OTHER
 *
 * payment_method is null for COMP/WRITE_OFF (no money changes hands). Reversals
 * copy both axes from the original row, so the backfill handles them uniformly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_payments', static function (Blueprint $table) {
            $table->string('transaction_type', 20)->nullable()->after('order_id')
                ->comment('PAYMENT|DONATION|COMP|WRITE_OFF');
            $table->string('payment_method', 20)->nullable()->after('transaction_type')
                ->comment('CASH|CHECK|CREDIT_CARD|BANK_TRANSFER|OTHER; null for COMP/WRITE_OFF');
        });

        DB::statement(<<<'SQL'
            UPDATE order_payments
            SET transaction_type = CASE
                    WHEN type IN ('COMP', 'WRITE_OFF', 'DONATION') THEN type
                    ELSE 'PAYMENT'
                END,
                payment_method = CASE type
                    WHEN 'CARD' THEN 'CREDIT_CARD'
                    WHEN 'CASH' THEN 'CASH'
                    WHEN 'CHECK' THEN 'CHECK'
                    WHEN 'BANK_TRANSFER' THEN 'BANK_TRANSFER'
                    WHEN 'OTHER' THEN 'OTHER'
                    ELSE NULL
                END
        SQL);

        Schema::table('order_payments', static function (Blueprint $table) {
            $table->string('transaction_type', 20)->nullable(false)->change();
            $table->dropColumn('type');
            $table->index('transaction_type');
            $table->index('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('order_payments', static function (Blueprint $table) {
            $table->string('type', 20)->nullable()
                ->comment('CASH|CHECK|CARD|BANK_TRANSFER|OTHER|COMP|WRITE_OFF|DONATION|REFUND')
                ->after('order_id');
        });

        DB::statement(<<<'SQL'
            UPDATE order_payments
            SET type = CASE
                    WHEN transaction_type = 'PAYMENT' THEN CASE payment_method
                        WHEN 'CREDIT_CARD' THEN 'CARD'
                        ELSE payment_method
                    END
                    ELSE transaction_type
                END
        SQL);

        Schema::table('order_payments', static function (Blueprint $table) {
            $table->string('type', 20)->nullable(false)->change();
            $table->dropColumn(['transaction_type', 'payment_method']);
            $table->index('type');
        });
    }
};
