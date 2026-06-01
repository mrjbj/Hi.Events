<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_reconciliation_settings', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');

            // Manually-entered total event expenses, in the event's native currency.
            $table->decimal('expenses', 14, 2)->default(0);
            $table->string('currency', 3);
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();

            $table->timestamps();
            $table->unique('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reconciliation_settings');
    }
};
