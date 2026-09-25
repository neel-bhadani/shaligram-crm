<?php

namespace App\Console\Commands;

use App\Services\LeadImport\LeadImportStore;
use Illuminate\Console\Command;

class PruneLeadImports extends Command
{
    protected $signature = 'leads:prune-imports';

    protected $description = 'Delete expired private bulk lead uploads, plans and results';

    public function handle(LeadImportStore $store): int
    {
        $this->info('Expired imports deleted: '.$store->prune());

        return self::SUCCESS;
    }
}
