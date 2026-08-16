<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_mercadopago_platforms', function (Blueprint $table) {
            // Set when MercadoPago rejects the refresh with a terminal OAuth error
            // (invalid_grant/unauthorized_client): the connection needs the organizer
            // to re-authorize. The row is kept — deleting it would free the unique
            // mp_user_id — and the mark is cleared on a successful refresh.
            $table->timestamp('revoked_at')->nullable()->after('token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('account_mercadopago_platforms', function (Blueprint $table) {
            $table->dropColumn('revoked_at');
        });
    }
};
