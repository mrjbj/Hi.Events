<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('outgoing_messages', function (Blueprint $table) {
            $table->string('original_recipient', 255)->nullable()->after('recipient');
            $table->index('original_recipient');
        });

        Schema::table('outgoing_transaction_messages', function (Blueprint $table) {
            $table->string('original_recipient', 255)->nullable()->after('recipient');
            $table->index('original_recipient');
        });
    }

    public function down(): void
    {
        Schema::table('outgoing_messages', function (Blueprint $table) {
            $table->dropIndex(['original_recipient']);
            $table->dropColumn('original_recipient');
        });

        Schema::table('outgoing_transaction_messages', function (Blueprint $table) {
            $table->dropIndex(['original_recipient']);
            $table->dropColumn('original_recipient');
        });
    }
};
