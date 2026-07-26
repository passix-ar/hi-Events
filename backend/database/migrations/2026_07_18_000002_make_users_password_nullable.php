<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Users who sign up with Google never choose a password. Storing a random unusable
     * hash instead would make "has this user got a password?" unanswerable, which the
     * login and password-reset flows need to know.
     */
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
