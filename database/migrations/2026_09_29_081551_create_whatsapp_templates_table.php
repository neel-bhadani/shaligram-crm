<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meta's own templates, as the WhatsApp Business Account last reported them.
     *
     * Not the same thing as `message_templates`. Those are the CRM's click-to-send
     * texts, written here with named placeholders and needing no approval. These
     * are written in Meta's WhatsApp Manager, approved (or not) by Meta, and
     * copied in by "Sync templates from Meta" — the only kind the API will send
     * to a customer who has not replied.
     *
     * Meta identifies a template by name AND language: `booking_update` in
     * `en_US` and in `hi` are two templates with two approvals.
     *
     * `variables` is what the body asks for, in order — ["1","2"] for a
     * positional template, ["first_name"] for a named one. `parameter_map` is
     * the admin's answer to each: {"1": "first_name", "2": "project"}. It is
     * never guessed, and a template with a variable left unmapped cannot be
     * sent. A re-sync keeps the map.
     *
     * `unsupported_reason` is set when the template needs something this CRM
     * cannot fill — a media header, a variable in the header or a button — so
     * it is listed, but not offered.
     */
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('language', 20);
            $table->string('meta_id')->nullable();
            // MARKETING | UTILITY | AUTHENTICATION, as Meta spells them
            $table->string('category')->nullable();
            // APPROVED | PENDING | REJECTED | PAUSED | DISABLED …, and MISSING
            // for one the last sync no longer found in the account
            $table->string('status');
            // positional | named
            $table->string('parameter_format')->default('positional');
            $table->text('body')->nullable();
            $table->json('variables')->nullable();
            $table->json('parameter_map')->nullable();
            $table->string('unsupported_reason')->nullable();
            $table->json('components')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['name', 'language']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
