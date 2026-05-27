<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_attendees_first_name_trgm
            ON attendees USING gin (first_name gin_trgm_ops)
        ');

        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_attendees_last_name_trgm
            ON attendees USING gin (last_name gin_trgm_ops)
        ');

        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_attendees_email_trgm
            ON attendees USING gin (email gin_trgm_ops)
        ');

        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_attendees_public_id_trgm
            ON attendees USING gin (public_id gin_trgm_ops)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_attendees_first_name_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_attendees_last_name_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_attendees_email_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_attendees_public_id_trgm');
    }
};
