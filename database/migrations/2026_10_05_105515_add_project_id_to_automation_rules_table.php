<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auto-send per project.
     *
     * Null is "All projects" — the default each stage sends. A project here
     * makes the rule that project's own choice for its stage, used instead of
     * the default for that project's leads. Only the Auto-send tab writes it.
     *
     * No data is touched: every rule that exists today reads null, so every
     * tag already set becomes the All-projects default and nothing starts or
     * stops sending on deploy.
     *
     * cascadeOnDelete, NOT nullOnDelete. Projects soft-delete, so this only
     * fires on a hard delete in the database — and nullOnDelete would turn the
     * override into a second All-projects rule, sending every lead two tags.
     */
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('trigger_config')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('automation_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
