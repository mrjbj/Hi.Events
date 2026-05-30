<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_payments', static function (Blueprint $table) {
            $table->increments('id');
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');

            $table->string('type', 20)->comment('CASH|CHECK|CARD|BANK_TRANSFER|OTHER|COMP|WRITE_OFF|DONATION|REFUND');
            $table->decimal('amount', 14, 2)->comment('Credit against the order balance; REFUND stored as a negative amount');
            $table->string('currency', 3);
            $table->string('reference', 255)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->string('recorded_by_ip', 45)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('order_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
