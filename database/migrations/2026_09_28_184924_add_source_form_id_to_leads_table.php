<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Meta lead form a lead was submitted on.
 *
 * Nullable and not backfilled: leads typed in by hand have no form, and leads
 * imported before form routing existed never recorded one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('source_form_id')->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('source_form_id');
        });
    }
};
