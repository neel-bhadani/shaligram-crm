<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     *   batch_id  the bulk send this message belongs to, or null. Batch rows
     *             are kept out of the Queue's message log: the batch is the
     *             one row shown there.
     *   dispatch  which send job may act on the row. Resuming a held batch
     *             bumps it, so a retry left over from before the hold wakes,
     *             finds a different number, and does nothing.
     *
     * A new row status, `held`: waiting, in a batch that is held. The worker
     * only claims `queued`, so nothing held is sent until Resume.
     */
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('rule_id')->constrained('message_batches')->nullOnDelete();
            $table->unsignedInteger('dispatch')->default(0)->after('status');

            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex(['batch_id', 'status']);
            $table->dropConstrainedForeignId('batch_id');
            $table->dropColumn('dispatch');
        });
    }
};
