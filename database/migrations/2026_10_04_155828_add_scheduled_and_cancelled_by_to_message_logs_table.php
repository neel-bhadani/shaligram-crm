<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who set a message up and who stopped it are two facts, kept apart.
     *
     *   scheduled_at  when somebody chose Send later. `user_id` is who.
     *   cancelled_by  who cancelled it, and cancelled_at when. Cancelling no
     *   cancelled_at  longer writes over `user_id`, so "scheduled by Tara,
     *                 cancelled by Ann" both survive.
     *
     * Rows cancelled before this keep the canceller in `user_id`: who queued
     * or scheduled them was not kept, and is not guessed now.
     */
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('send_at');
            $table->foreignId('cancelled_by')->nullable()->after('checked_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['scheduled_at', 'cancelled_at']);
        });
    }
};
