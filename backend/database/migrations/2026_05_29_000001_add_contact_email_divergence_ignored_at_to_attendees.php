<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendees', static function (Blueprint $table) {
            $table->timestamp('contact_email_divergence_ignored_at')->nullable()->after('contact_link_ignored_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendees', static function (Blueprint $table) {
            $table->dropColumn('contact_email_divergence_ignored_at');
        });
    }
};
