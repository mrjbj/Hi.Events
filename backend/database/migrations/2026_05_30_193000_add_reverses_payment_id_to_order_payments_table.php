<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_payments', static function (Blueprint $table) {
            $table->unsignedInteger('reverses_payment_id')
                ->nullable()
                ->after('id')
                ->comment('When set, this row reverses the referenced order_payments row (negative amount of the same type)');

            $table->foreign('reverses_payment_id')->references('id')->on('order_payments')->onDelete('cascade');
            $table->index('reverses_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_payments', static function (Blueprint $table) {
            $table->dropForeign(['reverses_payment_id']);
            $table->dropIndex(['reverses_payment_id']);
            $table->dropColumn('reverses_payment_id');
        });
    }
};
