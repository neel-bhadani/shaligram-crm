<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 2.5 BHK, 4.5 BHK and 5.5 BHK are no longer options (Sky Villa is new and
 * needs nothing here). Their keys are taken out of every lead's
 * residence_types, soft-deleted leads included; what is left stays in config
 * order, and a lead left with nothing goes back to null.
 *
 * The JSON is read and written as plain text in PHP rather than with JSON SQL
 * functions, so the same migration runs on MySQL 8 and on SQLite.
 */
return new class extends Migration
{
    private const RETIRED = ['2_5_bhk', '4_5_bhk', '5_5_bhk'];

    public function up(): void
    {
        $order = array_flip(array_map('strval', array_keys(config('crm.residence_types'))));
        $changed = 0;

        DB::table('leads')
            ->whereNotNull('residence_types')
            ->select(['id', 'residence_types'])
            ->chunkById(500, function ($leads) use ($order, &$changed) {
                foreach ($leads as $lead) {
                    $types = json_decode($lead->residence_types, true);

                    if (! is_array($types) || array_intersect($types, self::RETIRED) === []) {
                        continue;
                    }

                    $kept = array_values(array_diff($types, self::RETIRED));
                    // config order; anything not in config keeps its place after the rest
                    usort($kept, fn ($a, $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

                    DB::table('leads')->where('id', $lead->id)
                        ->update(['residence_types' => $kept === [] ? null : json_encode($kept)]);
                    $changed++;
                }
            });

        Log::info('[migration] removed retired residence types', ['leads_changed' => $changed]);

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "  Removed 2.5/4.5/5.5 BHK from {$changed} lead(s).".PHP_EOL;
        }
    }

    /**
     * Nothing to do: which leads had the removed values, and which of them,
     * is not kept anywhere, so they cannot be put back.
     */
    public function down(): void
    {
        //
    }
};
