<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the customer last wrote to us on WhatsApp.
     *
     * Meta allows free-form text only for 24 hours after the customer's last
     * message; outside that window only an approved template may be sent. This
     * is what the window is measured from.
     *
     * Nothing writes it yet — that is the reply webhook, which comes later — so
     * every lead is outside the window and template-only until then.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dateTime('last_inbound_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('last_inbound_at');
        });
    }
};
