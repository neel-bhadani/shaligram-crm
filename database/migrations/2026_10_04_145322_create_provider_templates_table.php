<?php

use App\Models\Integration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 11za's template list moves off the settings row into its own table.
     *
     *   provider_templates  one row per template 11za listed: name, language
     *                       and how many variables it has. Nothing else — no
     *                       wording and no raw answer. Replaced whole on every
     *                       successful read, at most 500 rows.
     *
     * The old `template_list` key held the list, 11za's raw answer and the
     * wording, inside the encrypted settings blob that every WhatsApp send
     * decrypts. It is removed here rather than on the next save. Its names,
     * languages and counts are carried over so the dropdown stays filled; its
     * raw answer and wording are dropped. The wording already copied onto each
     * message (message_templates.provider_body) is untouched.
     */
    public function up(): void
    {
        Schema::create('provider_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('language', 32)->nullable();
            $table->unsignedSmallInteger('variables')->nullable();
            $table->timestamps();

            $table->index('name');
        });

        $this->moveOldList();
    }

    /** The old key's names, languages and counts into the table; the key itself removed. */
    public function moveOldList(): void
    {
        $integration = Integration::where('provider', config('automation.whatsapp.provider'))->first();

        if (! $integration || ! array_key_exists('template_list', $integration->settings ?? [])) {
            return;
        }

        $old = (array) $integration->setting('template_list');
        $at = $old['at'] ?? null;

        $rows = collect($old['templates'] ?? [])
            ->filter(fn ($t) => is_array($t) && is_string($t['name'] ?? null) && $t['name'] !== '' && mb_strlen($t['name']) <= 255)
            ->take((int) config('automation.whatsapp.api.list_cap', 500))
            ->map(fn (array $t) => [
                'name' => $t['name'],
                'language' => is_string($t['language'] ?? null) && mb_strlen($t['language']) <= 32 ? $t['language'] : null,
                'variables' => is_int($t['variables'] ?? null) && $t['variables'] >= 0 && $t['variables'] <= 1000 ? $t['variables'] : null,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->values();

        DB::table('provider_templates')->insert($rows->all());

        $settings = $integration->settings;
        unset($settings['template_list']);

        if ($at !== null && $rows->isNotEmpty()) {
            $settings['template_list_read'] = ['at' => $at, 'total' => $rows->count(), 'failed_at' => null];
        }

        $integration->settings = $settings;
        $integration->saveQuietly();
    }

    /** The table goes. The old key is not rebuilt: its raw answer is gone. */
    public function down(): void
    {
        Schema::dropIfExists('provider_templates');

        $integration = Integration::where('provider', config('automation.whatsapp.provider'))->first();

        if ($integration && array_key_exists('template_list_read', $integration->settings ?? [])) {
            $settings = $integration->settings;
            unset($settings['template_list_read']);
            $integration->settings = $settings;
            $integration->saveQuietly();
        }
    }
};
