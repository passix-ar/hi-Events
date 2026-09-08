<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Consolidate any duplicate active check-ins the pre-unique-index race may have created,
        // keeping the earliest (the real first entry) and soft-deleting the rest, so the unique
        // index below can be built.
        DB::statement(<<<'SQL'
            UPDATE attendee_check_ins SET deleted_at = NOW()
            WHERE id IN (
                SELECT id FROM (
                    SELECT id, ROW_NUMBER() OVER (
                        PARTITION BY attendee_id, check_in_list_id ORDER BY id
                    ) AS rn
                    FROM attendee_check_ins
                    WHERE deleted_at IS NULL
                ) ranked
                WHERE ranked.rn > 1
            )
        SQL);

        DB::statement('DROP INDEX IF EXISTS idx_attendee_check_ins_attendee_id_check_in_list_id_deleted_at');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX idx_attendee_check_ins_unique_active
            ON attendee_check_ins (attendee_id, check_in_list_id)
            WHERE deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_attendee_check_ins_unique_active');

        DB::statement(<<<'SQL'
            CREATE INDEX idx_attendee_check_ins_attendee_id_check_in_list_id_deleted_at
            ON attendee_check_ins (attendee_id, check_in_list_id)
            WHERE deleted_at IS NULL
        SQL);
    }
};
