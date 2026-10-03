<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WhatsApp now goes out through 11za instead of Meta's Cloud API.
     *
     * Templates live in 11za's panel and there is nothing to sync them from, so
     * the `whatsapp_templates` mirror goes. What a message needs from it — the
     * template's name and language — is copied onto the message first, so a
     * message that was sendable by API yesterday still is today.
     *
     *   message_templates  provider_template_name (was meta_template_name) and
     *                      provider_template_language, both typed by the admin.
     *                      approval_status goes: it was Meta's word from the
     *                      sync, and nothing can refresh it any more.
     *
     *   message_logs       provider_template_name/_language: what the row was
     *                      queued as, replacing the link to the mirror.
     *                      provider_message_id (was wamid): whatever id 11za
     *                      hands back. provider_response: 11za's answer as it
     *                      came, with the auth token removed — their response
     *                      shape is not documented, so it is kept verbatim.
     *                      confirmed: 11za's answer carried a message id. A
     *                      `sent` row without one is "Sent (unconfirmed)" —
     *                      11za said yes but the id lookup found nothing, which
     *                      may only mean the lookup guessed the wrong field.
     *                      Rows Meta accepted with a wamid count as confirmed.
     */
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->renameColumn('meta_template_name', 'provider_template_name');
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('provider_template_language', 15)->nullable()->after('provider_template_name');
        });

        Schema::table('message_logs', function (Blueprint $table) {
            $table->string('provider_template_name')->nullable()->after('template_id');
            $table->string('provider_template_language', 15)->nullable()->after('provider_template_name');
        });

        $this->copyFromMirror('message_templates');
        $this->copyFromMirror('message_logs');

        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_template_id');
            $table->dropColumn('approval_status');
        });

        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_template_id');
            $table->dropIndex(['wamid']);
        });

        Schema::table('message_logs', function (Blueprint $table) {
            $table->renameColumn('wamid', 'provider_message_id');
        });

        Schema::table('message_logs', function (Blueprint $table) {
            $table->text('provider_response')->nullable()->after('provider_message_id');
            $table->boolean('confirmed')->default(false)->after('status');
            $table->index('provider_message_id');
        });

        DB::table('message_logs')->where('status', 'sent')->whereNotNull('provider_message_id')->update(['confirmed' => true]);

        Schema::dropIfExists('whatsapp_templates');
    }

    public function down(): void
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

        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn(['provider_response', 'confirmed', 'provider_template_name', 'provider_template_language']);
        });

        Schema::table('message_logs', function (Blueprint $table) {
            $table->renameColumn('provider_message_id', 'wamid');
        });

        Schema::table('message_logs', function (Blueprint $table) {
            $table->index('wamid');
            $table->foreignId('whatsapp_template_id')->nullable()->after('template_id')
                ->constrained('whatsapp_templates')->nullOnDelete();
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn('provider_template_language');
            $table->string('approval_status')->default('draft')->after('provider_template_name');
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->renameColumn('provider_template_name', 'meta_template_name');
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->foreignId('whatsapp_template_id')->nullable()->after('approval_status')
                ->constrained('whatsapp_templates')->nullOnDelete();
        });
    }

    /** Name and language from the linked mirror row, onto the row itself. */
    private function copyFromMirror(string $table): void
    {
        $mirror = DB::table('whatsapp_templates')->get(['id', 'name', 'language'])->keyBy('id');

        DB::table($table)->whereNotNull('whatsapp_template_id')->orderBy('id')
            ->each(function (object $row) use ($table, $mirror) {
                $template = $mirror->get($row->whatsapp_template_id);

                if ($template) {
                    DB::table($table)->where('id', $row->id)->update([
                        'provider_template_name' => $template->name,
                        'provider_template_language' => $template->language,
                    ]);
                }
            });
    }
};
