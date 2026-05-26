<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outgoing_message_events', static function (Blueprint $table) {
            $table->id();

            $table->foreignId('outgoing_message_id')
                ->nullable()
                ->constrained('outgoing_messages')
                ->cascadeOnDelete();

            $table->foreignId('outgoing_transaction_message_id')
                ->nullable()
                ->constrained('outgoing_transaction_messages')
                ->cascadeOnDelete();

            $table->string('provider')->default('ses');
            $table->string('event_type');
            $table->string('event_subtype')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('sns_message_id')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('occurred_at')->nullable();

            $table->timestamps();

            $table->index('provider_message_id');
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outgoing_message_events');
    }
};
