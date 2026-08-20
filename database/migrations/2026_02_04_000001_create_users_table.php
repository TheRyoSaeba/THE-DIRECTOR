<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 50);
            $table->string('email', 255);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password', 255);
            $table->boolean('is_banned')->default(false);
            $table->string('ban_reason', 500)->nullable();
            $table->timestamp('banned_at')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->bigInteger('total_lifetime_earns')->default(0);
            $table->jsonb('achievements')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->unique('username');
            $table->unique('email');
            $table->index('is_banned');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
