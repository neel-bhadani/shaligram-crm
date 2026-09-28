<?php

use App\Support\CrmTaxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Details shared" moves to the telecaller desk: the handover to a salesperson
 * now happens at "Site visit scheduled", not one stage earlier.
 *
 * Only the stage row changes. Leads already at `details_shared` stay with
 * whoever holds them — a salesperson mid-conversation keeps it — so for a while
 * that stage has owners on both desks. Nothing here touches `leads`.
 *
 * Guarded on the old value, so a mapping an admin has already edited on the
 * Stages screen is left as they set it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('lead_stages')
            ->where('key', 'details_shared')
            ->where('owner_role', 'salesperson')
            ->update(['owner_role' => 'telecaller']);

        // DB::table() fires no model events, so nothing else busts the cache
        CrmTaxonomy::flush();
    }

    public function down(): void
    {
        DB::table('lead_stages')
            ->where('key', 'details_shared')
            ->where('owner_role', 'telecaller')
            ->update(['owner_role' => 'salesperson']);

        CrmTaxonomy::flush();
    }
};
