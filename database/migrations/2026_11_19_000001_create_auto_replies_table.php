<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // one row per prospect message LeadPilot answered by itself (or decided to leave to a person), so each is handled once
        Schema::create('auto_replies', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 10);                      // email | whatsapp
            $table->unsignedBigInteger('source_id');            // inbound_replies.id | whatsapp_inbound_messages.id
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->string('status', 12)->index();              // sent | held | skipped | failed
            $table->string('reason', 300)->nullable();
            $table->text('body')->nullable();
            $table->string('writer', 40)->nullable();           // the AI model, or "template"
            $table->timestamps();
            $table->unique(['channel', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_replies');
    }
};
