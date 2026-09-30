<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an API send needs to be answerable afterwards.
     *
     *   to_name               the customer's name as it was when the message
     *                         was written — "did Rahul get it" has to survive
     *                         Rahul's lead being renamed or deleted.
     *   whatsapp_template_id  the Meta template it went as.
     *   params                the {{1}}, {{2}}… values, in placeholder_map
     *                         order, fixed when the message is queued. The job
     *                         sends exactly these; nothing re-derives them.
     *   wamid                 Meta's message id. Present means Meta ACCEPTED
     *                         the message — not that it was delivered or read,
     *                         which needs a webhook this CRM does not have.
     *   error_code            Meta's numeric code (190, 131026…) beside the
     *                         text in `error`.
     *   dedupe_key            number + message + parameter values, hashed.
     *                         Two leads on one mobile number reaching the same
     *                         stage get one message, not two — unless the
     *                         values differ (another project), when both go.
     *
     * `status` gains `sending` (a worker has it) and `skipped` (a rule chose
     * not to send, with the reason in `error`).
     */
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->string('to_name')->nullable()->after('to_number');
            $table->foreignId('whatsapp_template_id')
                ->nullable()
                ->after('template_id')
                ->constrained('whatsapp_templates')
                ->nullOnDelete();
            $table->json('params')->nullable()->after('body');
            $table->string('wamid')->nullable()->after('status');
            $table->string('error_code', 20)->nullable()->after('error');
            $table->string('dedupe_key', 64)->nullable()->after('error_code');

            $table->index('wamid');
            $table->index(['dedupe_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex(['dedupe_key', 'created_at']);
            $table->dropIndex(['wamid']);
            $table->dropConstrainedForeignId('whatsapp_template_id');
            $table->dropColumn(['to_name', 'params', 'wamid', 'error_code', 'dedupe_key']);
        });
    }
};
