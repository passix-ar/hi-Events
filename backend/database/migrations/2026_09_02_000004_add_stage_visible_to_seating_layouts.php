<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seating_layouts', function (Blueprint $table) {
            // The stage is a piece of the plan like any other: it comes with the room, and a
            // venue that has no stage can take it out.
            $table->boolean('stage_visible')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('seating_layouts', function (Blueprint $table) {
            $table->dropColumn('stage_visible');
        });
    }
};
