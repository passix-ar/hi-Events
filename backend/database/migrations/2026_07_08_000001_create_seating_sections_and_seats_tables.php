<?php

use HiEvents\DomainObjects\Status\SeatingSectionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seating_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('name');
            $table->integer('row_count');
            $table->integer('seats_per_row');
            $table->string('status')->default(SeatingSectionStatus::ACTIVE->name);
            $table->timestamps();
            $table->softDeletes();

            $table->index('event_id');
            $table->index('product_id');
            $table->index('status');
        });

        Schema::create('seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('seating_section_id')->constrained('seating_sections')->onDelete('cascade');
            $table->string('row_label');
            $table->integer('seat_number');
            $table->string('label');
            $table->foreignId('order_id')->nullable()->constrained('orders');
            $table->foreignId('attendee_id')->nullable()->constrained('attendees');
            $table->timestamps();

            $table->unique(['seating_section_id', 'row_label', 'seat_number']);
            $table->index('event_id');
            $table->index('order_id');
            $table->index('attendee_id');
        });

        Schema::table('attendees', function (Blueprint $table) {
            $table->string('seat_label')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->dropColumn('seat_label');
        });

        Schema::dropIfExists('seats');
        Schema::dropIfExists('seating_sections');
    }
};
