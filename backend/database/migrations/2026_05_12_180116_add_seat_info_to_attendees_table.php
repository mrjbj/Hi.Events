<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('attendees', static function (Blueprint $table) {
            if (Schema::hasColumn('attendees', 'seat_info')) {
                return;
            }
            $table->string('seat_info', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendees', static function (Blueprint $table) {
            if (!Schema::hasColumn('attendees', 'seat_info')) {
                return;
            }
            $table->dropColumn('seat_info');
        });
    }
};
