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
            $table->integer('position_x')->nullable();
            $table->integer('position_y')->nullable();
        });

        // Sections drawn before the canvas existed sat in a single column, in `order`.
        // Seed that same reading so an existing event opens looking like it did.
        DB::statement(<<<'SQL'
            UPDATE seating_sections
            SET position_x = 0,
                position_y = 80 + ranked.position * 240
            FROM (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY event_id ORDER BY "order", id) - 1 AS position
                FROM seating_sections
            ) AS ranked
            WHERE seating_sections.id = ranked.id
        SQL);

        Schema::create('seating_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained('events')->onDelete('cascade');
            $table->integer('stage_x')->default(0);
            $table->integer('stage_y')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seating_layouts');

        Schema::table('seating_sections', function (Blueprint $table) {
            $table->dropColumn(['position_x', 'position_y']);
        });
    }
};
