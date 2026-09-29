<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which project a lead form's leads belong to.
 *
 * Every Meta `leadgen` notification carries the `form_id` it came from, so the
 * form — not the page — is what says which project an advert was for. One row
 * per form per provider, edited in the Facebook settings modal.
 *
 * `project_id` is nullable on purpose: a form nobody has mapped yet is written
 * here the moment its first lead arrives, with no project, so it sits in the
 * table visibly waiting to be assigned while its leads go to the integration's
 * fallback project. `assign_to_user_id` is nullable because most forms are
 * happy with the integration's default telecaller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_form_routes', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('form_id');

            // the name as Meta shows it in Ads Manager; null until one is known
            $table->string('form_name')->nullable();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assign_to_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['provider', 'form_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_form_routes');
    }
};
