<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * For the send that was interrupted part-way.
     *
     *   sending_started_at  when the worker claimed the row (queued → sending)
     *                       and handed it to 11za. A row still `sending` long
     *                       after this was abandoned by a worker that died;
     *                       the sweeper marks it `unknown`.
     *
     *   check_result        how an `unknown` row was settled, after somebody
     *                       looked it up in 11za's own message log:
     *                       `delivered` or `not_sent` (and sent again).
     *   checked_by/_at      who settled it, and when.
     */
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->timestamp('sending_started_at')->nullable()->after('status');
            $table->string('check_result', 20)->nullable()->after('sent_at');
            $table->foreignId('checked_by')->nullable()->after('check_result')->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable()->after('checked_by');

            $table->index(['status', 'sending_started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex(['status', 'sending_started_at']);
            $table->dropConstrainedForeignId('checked_by');
            $table->dropColumn(['sending_started_at', 'check_result', 'checked_at']);
        });
    }
};
