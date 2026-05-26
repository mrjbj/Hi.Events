<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_payment_adjustments', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('original_total_gross', 14, 2);
            $table->decimal('original_total_before_additions', 14, 2);
            $table->decimal('original_total_tax', 14, 2);
            $table->decimal('original_total_fee', 14, 2);
            $table->decimal('adjusted_total_gross', 14, 2);
            $table->string('payment_method', 32);
            $table->string('payment_reference', 255)->nullable();
            $table->unsignedBigInteger('adjusted_by_user_id')->nullable();
            $table->string('adjusted_by_ip', 45)->nullable();
            $table->timestamp('created_at');

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payment_adjustments');
    }
};
