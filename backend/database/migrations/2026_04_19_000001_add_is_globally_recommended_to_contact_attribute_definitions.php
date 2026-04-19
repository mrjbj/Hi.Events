<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contact_attribute_definitions', function (Blueprint $table) {
            // When true, this attribute is automatically attached as an
            // ORDER-level question on every new event in the account, pre-filled
            // from the contact's stored value and hidden on the checkout form
            // for returning contacts.
            $table->boolean('is_globally_recommended')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('contact_attribute_definitions', function (Blueprint $table) {
            $table->dropColumn('is_globally_recommended');
        });
    }
};
