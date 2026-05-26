<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_mercadopago_platforms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('mp_user_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('public_key')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('setup_completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->index('account_id');
            $table->unique('mp_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_mercadopago_platforms');
    }
};
