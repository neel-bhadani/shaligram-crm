<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the Integrations card's "Connected" badge is decided by.
 *
 *   last_delivery_at   the last provider request that passed authentication
 *   last_rejected_at   the last one refused for a wrong or missing key/signature
 *
 * Backfilled from the activity log so a live integration keeps reading as
 * Connected after deploy: every event a webhook wrote had passed the key or
 * signature check. Meta's testing-tool events (`test`) and the Send test lead
 * button's leads (`test_…` leadgen ids, 99999 phone numbers) are not
 * deliveries and are left out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamp('last_rejected_at')->nullable();
        });

        foreach (DB::table('integrations')->pluck('provider') as $provider) {
            $deliveredAt = DB::table('integration_events')
                ->where('provider', $provider)
                ->where('result', '!=', 'test')
                ->where(fn ($q) => $q->whereNull('external_id')
                    ->orWhere(fn ($q) => $q->where('external_id', 'not like', 'test_%')
                        ->where('external_id', 'not like', '11za:99999%')))
                ->max('created_at');

            if ($deliveredAt !== null) {
                DB::table('integrations')->where('provider', $provider)->update(['last_delivery_at' => $deliveredAt]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn(['last_delivery_at', 'last_rejected_at']);
        });
    }
};
