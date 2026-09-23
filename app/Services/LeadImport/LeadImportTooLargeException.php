<?php

namespace App\Services\LeadImport;

use RuntimeException;

class LeadImportTooLargeException extends RuntimeException
{
    public function __construct(public readonly int $rows, public readonly int $max)
    {
        parent::__construct("This file has {$rows} rows; the importer accepts up to {$max} at a time.");
    }
}
