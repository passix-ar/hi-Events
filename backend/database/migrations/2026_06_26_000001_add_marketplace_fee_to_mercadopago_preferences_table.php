<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mercadopago_preferences', function (Blueprint $table) {
            $table->decimal('marketplace_fee', 14, 2)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('mercadopago_preferences', function (Blueprint $table) {
            $table->dropColumn('marketplace_fee');
        });
    }
};
