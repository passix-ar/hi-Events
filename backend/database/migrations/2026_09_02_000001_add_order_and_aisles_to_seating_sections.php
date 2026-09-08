<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seating_sections', function (Blueprint $table) {
            $table->integer('order')->default(0);
            $table->jsonb('aisle_positions')->nullable();

            $table->index(['event_id', 'order']);
        });

        // Existing sections would all share order 0 and fall back to an arbitrary order.
        DB::statement(<<<'SQL'
            UPDATE seating_sections
            SET "order" = ranked.position
            FROM (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY event_id ORDER BY id) - 1 AS position
                FROM seating_sections
            ) AS ranked
            WHERE seating_sections.id = ranked.id
        SQL);
    }

    public function down(): void
    {
        Schema::table('seating_sections', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'order']);
            $table->dropColumn(['order', 'aisle_positions']);
        });
    }
};
