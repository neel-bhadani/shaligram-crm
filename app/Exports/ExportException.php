<?php

namespace App\Exports;

use RuntimeException;

/**
 * An export that cannot be produced — nothing to export, or the process could
 * not prepare the file. The controller turns it into a clear, safe message for
 * the page.
 */
class ExportException extends RuntimeException {}
