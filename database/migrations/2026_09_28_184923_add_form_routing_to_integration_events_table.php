<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which lead form an enquiry came from, and whether that form sent it to its
 * own project.
 *
 * `routed` is null for rows written before form routing existed and for
 * outcomes where routing never mattered; false is the one the activity log
 * flags — a lead that was created, but in the fallback project because its
 * form has no project mapped. `result` stays `created` for those: the lead
 * exists and has an owner, it is just in the wrong place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_events', function (Blueprint $table) {
            $table->string('form_id')->nullable()->after('external_id');
            $table->string('form_name')->nullable()->after('form_id');
            $table->boolean('routed')->nullable()->after('form_name');

            // the per-form count of leads that went to the fallback project
            $table->index(['provider', 'form_id', 'routed']);
        });
    }

    public function down(): void
    {
        Schema::table('integration_events', function (Blueprint $table) {
            $table->dropIndex(['provider', 'form_id', 'routed']);
            $table->dropColumn(['form_id', 'form_name', 'routed']);
        });
    }
};
