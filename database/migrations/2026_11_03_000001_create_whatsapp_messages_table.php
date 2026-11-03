<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WhatsApp messages LeadPilot sent (or queued for approval) to prospects: one per business, with the real outcome
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('to_number', 20)->index();
            $table->string('number_source', 30)->nullable(); // website_whatsapp_link | mobile_number
            $table->text('body');
            $table->string('file_url', 500)->nullable();
            $table->string('status', 30)->index();          // pending_approval | sent | failed | rejected
            $table->text('provider_response')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
