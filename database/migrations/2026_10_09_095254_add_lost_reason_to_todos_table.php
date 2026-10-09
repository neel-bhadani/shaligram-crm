<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Why this particular loss happened, on the to-do that records it.
 *
 * `leads.reason` is the lead's CURRENT reason: losing it a second time
 * overwrites the first. A lead lost in 2024 for budget and again in 2026 for
 * location would put both losses under location on any report that read the
 * lead column — and 3,343 leads in the legacy import were lost more than once.
 * A reason on the event is the only thing a date-ranged report can trust.
 *
 * The backfill gives each lead's MOST RECENT loss the lead's reason, which is
 * the one loss that column can honestly speak for. Every earlier loss stays
 * null and reads "No reason recorded": an honest gap rather than a 2026 reason
 * confidently attached to a 2024 loss. Latest is completed_at, then id, the
 * same order LossEvents uses to pick a lead's loss inside a window.
 *
 * `leads.reason` is still written alongside this; nothing that reads it
 * changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->string('lost_reason')->nullable()->after('outcome_stage');
        });

        // the derived table is what lets MySQL read todos while updating it
        DB::statement(<<<'SQL'
            UPDATE todos
            SET lost_reason = (SELECT leads.reason FROM leads WHERE leads.id = todos.lead_id)
            WHERE id IN (
                SELECT id FROM (
                    SELECT id, ROW_NUMBER() OVER (PARTITION BY lead_id ORDER BY completed_at DESC, id DESC) AS rn
                    FROM todos
                    WHERE outcome_stage = 'lost'
                ) latest
                WHERE rn = 1
            )
            AND EXISTS (
                SELECT 1 FROM leads
                WHERE leads.id = todos.lead_id AND leads.reason IS NOT NULL AND leads.reason <> ''
            )
            SQL);
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropColumn('lost_reason');
        });
    }
};
