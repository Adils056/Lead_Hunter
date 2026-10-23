<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 30)->default('sales')->after('password')->index();
            $table->boolean('is_active')->default(true)->after('role')->index();
            $table->string('avatar')->nullable()->after('is_active');
            $table->string('phone', 50)->nullable()->after('avatar');
            $table->timestamp('last_login_at')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active', 'avatar', 'phone', 'last_login_at']);
        });
    }
};
