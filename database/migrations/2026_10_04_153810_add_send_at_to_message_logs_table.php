<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Send later.
     *
     *   send_at  when a person asked for the message to go, in the app's
     *            timezone (Asia/Kolkata). Null for "send now" and for every
     *            row written before this. The row stays `queued` until then,
     *            on the Queue tab, and can be cancelled until the worker
     *            claims it. The lead's number and values are read again at
     *            this time, not when it was scheduled.
     */
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->timestamp('send_at')->nullable()->after('status');

            $table->index(['status', 'send_at']);
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex(['status', 'send_at']);
            $table->dropColumn('send_at');
        });
    }
};
