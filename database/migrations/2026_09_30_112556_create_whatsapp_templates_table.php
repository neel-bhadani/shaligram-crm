<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meta's side of a template, as the last sync found it.
     *
     * Written only by WhatsAppTemplateSync. A template is identified in Meta by
     * name AND language — "site_visit" in en and in hi are two templates with
     * two approval statuses — so that pair is the key.
     *
     * `status` is Meta's own word, uppercase, untranslated: APPROVED, PENDING,
     * REJECTED, PAUSED, DISABLED. A template Meta no longer returns is kept and
     * marked DELETED rather than removed, because a message_templates row may
     * still be linked to it and the link is what explains why it stopped
     * sending.
     *
     * `components` is the raw array Meta returned. The body's {{n}} count and
     * anything the CRM cannot fill (a media header, a URL button variable) are
     * read from it on demand — see WhatsAppTemplate.
     */
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('meta_id')->nullable();
            $table->string('name');
            $table->string('language', 15);
            $table->string('status', 20);
            $table->string('category', 30)->nullable();
            $table->text('body')->nullable();
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
