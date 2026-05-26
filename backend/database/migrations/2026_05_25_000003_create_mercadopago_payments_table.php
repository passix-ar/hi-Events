<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mercadopago_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('mp_payment_id')->unique();
            $table->string('preference_id')->nullable();
            $table->string('status');
            $table->string('status_detail')->nullable();
            $table->decimal('transaction_amount', 10, 2)->nullable();
            $table->string('currency_id', 3)->nullable();
            $table->string('payment_type_id')->nullable();
            $table->string('payment_method_id')->nullable();
            $table->decimal('marketplace_fee', 10, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->index('order_id');
            $table->index('mp_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mercadopago_payments');
    }
};
