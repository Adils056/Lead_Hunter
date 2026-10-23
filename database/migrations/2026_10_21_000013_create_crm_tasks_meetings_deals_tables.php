<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_date')->index();
            $table->string('priority', 20)->default('medium')->index(); // low, medium, high, urgent
            $table->string('status', 30)->default('pending')->index(); // pending, in_progress, completed, cancelled
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('meeting_url')->nullable();
            $table->dateTime('scheduled_start')->index();
            $table->dateTime('scheduled_end')->nullable();
            $table->string('status', 30)->default('scheduled')->index(); // scheduled, completed, cancelled, no_show
            $table->text('outcome_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->decimal('value', 12, 2)->default(0.00)->index();
            $table->string('currency', 10)->default('USD');
            $table->string('stage', 50)->default('discovery')->index();
            // discovery, proposal_sent, negotiation, won, lost
            $table->unsignedTinyInteger('probability')->default(20); // 0-100%
            $table->dateTime('expected_close_date')->nullable();
            $table->dateTime('closed_at')->nullable()->index();
            $table->string('lost_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
        Schema::dropIfExists('meetings');
        Schema::dropIfExists('tasks');
    }
};
