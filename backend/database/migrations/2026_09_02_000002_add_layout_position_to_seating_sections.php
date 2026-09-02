<?php

use HiEvents\DomainObjects\Enums\SeatingSectionPosition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seating_sections', function (Blueprint $table) {
            $table->string('layout_position', 20)->default(SeatingSectionPosition::CENTER->name);
        });
    }

    public function down(): void
    {
        Schema::table('seating_sections', function (Blueprint $table) {
            $table->dropColumn('layout_position');
        });
    }
};
