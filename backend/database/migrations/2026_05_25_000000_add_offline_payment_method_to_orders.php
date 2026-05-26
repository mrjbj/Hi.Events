<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', static function (Blueprint $table) {
            $table->string('offline_payment_method', 32)->nullable();
            $table->string('offline_payment_reference', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', static function (Blueprint $table) {
            $table->dropColumn(['offline_payment_method', 'offline_payment_reference']);
        });
    }
};
