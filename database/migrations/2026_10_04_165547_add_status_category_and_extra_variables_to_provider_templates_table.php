<?php

use App\Models\Integration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fitting the stored list to 11za's real answer, now that it is known.
     *
     *   status           Meta's approval, per language: APPROVED, PENDING,
     *                    REJECTED. A send of anything but APPROVED fails, so
     *                    every tag pointing at one says so.
     *   category         MARKETING, UTILITY or AUTHENTICATION. Meta caps how
     *                    many marketing messages a person gets and lets them
     *                    switch them off, so 11za can accept a marketing send
     *                    that is never delivered.
     *   extra_variables  a template's header and carousel variables, per
     *                    language. A send from this CRM fills only the body's,
     *                    so the tag form warns when there are any.
     *
     * Still no wording here: 11za's wording is copied onto each tag
     * (message_templates.provider_body), as before.
     *
     * The list read under the old guesses has nulls where the language and
     * count should be, so it is emptied: the next Refresh fills it properly.
     * Tags are untouched.
     *
     * And `base_url` goes from the WhatsApp settings. It was never set in
     * production; the host is config only (WHATSAPP_API_BASE).
     */
    public function up(): void
    {
        Schema::table('provider_templates', function (Blueprint $table) {
            $table->string('status', 20)->nullable()->after('language');
            $table->string('category', 30)->nullable()->after('status');
            $table->unsignedSmallInteger('extra_variables')->nullable()->after('variables');
        });

        DB::table('provider_templates')->delete();

        $integration = Integration::where('provider', config('automation.whatsapp.provider'))->first();

        if ($integration) {
            $settings = $integration->settings ?? [];
            unset($settings['base_url'], $settings['template_list_read']);
            $integration->settings = $settings;
            $integration->saveQuietly();
        }
    }

    public function down(): void
    {
        Schema::table('provider_templates', function (Blueprint $table) {
            $table->dropColumn(['status', 'category', 'extra_variables']);
        });
    }
};
