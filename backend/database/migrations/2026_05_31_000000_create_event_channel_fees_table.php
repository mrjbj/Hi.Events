<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('event_channel_fees', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');

            $table->string('channel', 20)->comment('STRIPE|SQUARE|CASH|CHECK|BANK_TRANSFER|OTHER');
            $table->decimal('fee_amount', 14, 2)->default(0)->comment('Manually-entered processing fee for this channel, in native currency units');
            $table->string('currency', 3);
            $table->text('note')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();

            $table->timestamps();

            $table->unique(['event_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_channel_fees');
    }
};
