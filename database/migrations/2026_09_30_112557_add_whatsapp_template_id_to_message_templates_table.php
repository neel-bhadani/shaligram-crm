<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which approved Meta template a message is sent as, when it goes by API.
     *
     * The link, not a copy. The CRM's message keeps its named placeholders and
     * its stored `placeholder_map`, which is what orders the parameters; Meta's
     * template supplies the name, language and approval status. Null means
     * click-to-send only.
     *
     * nullOnDelete: a Meta template vanishing is a reason to stop API sending,
     * not a reason to lose the message.
     */
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->foreignId('whatsapp_template_id')
                ->nullable()
                ->after('approval_status')
                ->constrained('whatsapp_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_template_id');
        });
    }
};
