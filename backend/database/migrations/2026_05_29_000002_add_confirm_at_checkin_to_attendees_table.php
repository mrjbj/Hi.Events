<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            if (Schema::hasColumn('attendees', 'confirm_at_checkin')) {
                return;
            }
            $table->boolean('confirm_at_checkin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            if (!Schema::hasColumn('attendees', 'confirm_at_checkin')) {
                return;
            }
            $table->dropColumn('confirm_at_checkin');
        });
    }
};
