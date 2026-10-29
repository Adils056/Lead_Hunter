<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Reusable WhatsApp messages; a person opens one per lead in WhatsApp and sends it themselves. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('purpose', 30)->default('first_contact');
            $t->text('body');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
