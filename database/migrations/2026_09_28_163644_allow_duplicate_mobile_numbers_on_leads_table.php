<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     | The same number may now sit on more than one lead in the same project —
     | the add/edit form warns instead of refusing. The unique pair goes; a
     | plain index on mobile_number replaces it, because the duplicate warning,
     | the repeat-enquiry check and the list search all look leads up by number.
     |
     | The plain index is added before the unique one is dropped, so there is
     | no moment without an index on the column. `external_id` keeps its own
     | unique index and is not touched here — it is the webhook's idempotency
     | key.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->index('mobile_number');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique(['mobile_number', 'project_id']);
        });
    }

    /**
     * Refuses once a number is on two leads in one project, rather than
     * choosing which of them to delete.
     */
    public function down(): void
    {
        $clashes = DB::table('leads')
            ->whereNotNull('mobile_number')
            ->select('mobile_number', 'project_id')
            ->groupBy('mobile_number', 'project_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();

        if ($clashes > 0) {
            throw new RuntimeException("{$clashes} mobile numbers are on more than one lead in the same project; merge or delete them before rolling back.");
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->unique(['mobile_number', 'project_id']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['mobile_number']);
        });
    }
};
