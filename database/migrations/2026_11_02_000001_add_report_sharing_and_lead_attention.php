<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A website report the prospect can open from the e-mail (private link), and whether a person opened it
        Schema::table('website_reports', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique()->after('created_by');
            $table->unsignedInteger('view_count')->default(0)->after('share_token');
            $table->timestamp('first_viewed_at')->nullable()->after('view_count');
            $table->timestamp('last_viewed_at')->nullable()->after('first_viewed_at');
        });
        // The decision-maker a shared inbox (info@) is addressed to, as named on the company's own website
        Schema::table('leads', function (Blueprint $table) {
            $table->string('attention_name', 120)->nullable()->after('job_title');
            $table->string('attention_role', 80)->nullable()->after('attention_name');
        });
    }

    public function down(): void
    {
        Schema::table('website_reports', fn (Blueprint $t) => $t->dropColumn(['share_token', 'view_count', 'first_viewed_at', 'last_viewed_at']));
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn(['attention_name', 'attention_role']));
    }
};
