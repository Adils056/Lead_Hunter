<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WhatsApp messages prospects sent back (EZIMK webhook): replies and STOP requests, with the raw payload kept
        Schema::create('whatsapp_inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->string('provider_message_id', 120)->nullable()->index();
            $table->string('from_number', 20)->nullable()->index();
            $table->text('body')->nullable();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('whatsapp_message_id')->nullable()->constrained('whatsapp_messages')->nullOnDelete();
            $table->string('action', 30)->index(); // opt_out | reply | unmatched | ignored
            $table->text('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_inbound_messages');
    }
};
