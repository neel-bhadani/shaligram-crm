<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The customer asked not to be messaged on WhatsApp.
     *
     *   whatsapp_opted_out_at   when; null means sending is allowed.
     *   whatsapp_opt_out_source how: `manual` (somebody switched it on) today;
     *                           `reply` is kept for a STOP reply from 11za,
     *                           once the CRM receives replies at all.
     *   whatsapp_opted_out_by   who switched it on; null for a reply.
     *
     * Switching it back off clears all three, so the history lives in
     * lead_activities — who switched it off, and when — not here.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('whatsapp_opted_out_at')->nullable()->after('last_activity_at')->index();
            $table->string('whatsapp_opt_out_source', 20)->nullable()->after('whatsapp_opted_out_at');
            $table->foreignId('whatsapp_opted_out_by')->nullable()->after('whatsapp_opt_out_source')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_opted_out_by');
            $table->dropIndex(['whatsapp_opted_out_at']);
            $table->dropColumn(['whatsapp_opted_out_at', 'whatsapp_opt_out_source']);
        });
    }
};
