<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the customer is looking for: option keys from
     * config('crm.residence_types') and config('crm.floors'), null when
     * nothing is ticked.
     *
     * The free-text `requirement` column beside them is left exactly as it
     * is — imports still write it, and nothing guesses keys out of it.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->json('residence_types')->nullable()->after('requirement');
            $table->json('preferred_floors')->nullable()->after('residence_types');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['residence_types', 'preferred_floors']);
        });
    }
};
