<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One bulk send: one tag to many leads. Each lead is still its own
     * message_logs row (batch_id); this is the one row the Queue shows.
     *
     *   status          running, held or stopped. "Finished" is worked out
     *                   from the rows, not stored.
     *   created_by      who started it. Never written over: stopping,
     *   stopped_by/_at  holding and resuming each have their own columns.
     *   resumed_by/_at
     *   held_reason/_at why it stopped by itself — 11za rate limiting, or
     *                   failure_streak failed attempts in a row. A held send
     *                   never resumes on its own.
     *   failure_streak  failed attempts in a row; any send resets it.
     *   *_count         as they were when it started: how many were
     *                   selected, left out (with a reason on each row), and
     *                   sent to.
     */
    public function up(): void
    {
        Schema::create('message_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->nullable()->constrained('message_templates')->nullOnDelete();
            $table->string('template_name');
            $table->string('selection', 20);
            $table->unsignedInteger('selected_count');
            $table->unsignedInteger('excluded_count');
            $table->unsignedInteger('recipient_count');
            $table->timestamp('send_at')->nullable();
            $table->string('status', 20)->default('running');
            $table->unsignedInteger('failure_streak')->default(0);
            $table->text('held_reason')->nullable();
            $table->timestamp('held_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('stopped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('stopped_at')->nullable();
            $table->foreignId('resumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_batches');
    }
};
