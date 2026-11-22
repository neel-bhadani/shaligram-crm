<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer is looking for: keys from config('crm.residence_types')
 * and config('crm.floors'). Null means nothing was chosen. The old free-text
 * `requirement` column is left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->json('residence_types')->nullable();
            $table->json('preferred_floors')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['residence_types', 'preferred_floors']);
        });
    }
};
