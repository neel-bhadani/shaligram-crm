<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an API send needs on the record beside the text.
     *
     * `whatsapp_template_id` is the Meta template it went out as — null for a
     * click-to-send message and for free text inside the 24-hour window.
     * `parameters` is exactly what was handed to Meta for the template's
     * variables, so "what did the customer actually see" can be answered
     * without re-rendering against a lead that has since changed.
     * `meta_message_id` is the wamid Meta returned, which is what a delivery
     * receipt will be matched against once the webhook exists.
     *
     * `attempts` counts API calls, and `error` holds the whole of the last
     * failure — Meta's error object as it arrived, not a summary of it.
     */
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->foreignId('whatsapp_template_id')->nullable()->after('template_id')
                ->constrained('whatsapp_templates')->nullOnDelete();
            $table->json('parameters')->nullable()->after('body');
            $table->string('meta_message_id')->nullable()->after('parameters');
            $table->unsignedTinyInteger('attempts')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_template_id');
            $table->dropColumn(['parameters', 'meta_message_id', 'attempts']);
        });
    }
};
