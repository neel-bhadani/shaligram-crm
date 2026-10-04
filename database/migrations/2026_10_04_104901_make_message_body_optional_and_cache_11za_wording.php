<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The CRM stops holding message wording. 11za owns the templates.
     *
     *   body           now optional. A message set up from here on has none.
     *                  A message written before keeps its wording exactly as
     *                  it was — nothing is rewritten or cleared — and it is
     *                  still what click-to-send opens WhatsApp with, until
     *                  11za's own wording is known.
     *
     *   provider_body  11za's wording for the template, copied whenever the
     *                  template list is read from 11za, with its own {{1}},
     *                  {{2}}. Never typed by anybody. Used for click-to-send
     *                  and the preview; null when 11za has not said.
     */
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->text('body')->nullable()->change();
            $table->text('provider_body')->nullable()->after('provider_template_language');
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn('provider_body');
        });
    }
};
