<?php

namespace App\Support;

use App\Models\Tenant\TenantImport;
use Illuminate\Support\Facades\Storage;

/**
 * MARKER-IMPORT-QUEUE-CLEAN — the rows that could not be written, as a CSV
 * shaped like the input so it can be fixed and re-imported.
 *
 * Lived as a private method on ImportController until the run moved to a job;
 * one home, called by whoever finishes the run.
 */
class ImportErrorCsv
{
    public static function write(TenantImport $import, array $rows): string
    {
        $rel = 'imports/' . $import->tenant_id . '/errors-' . $import->id . '.csv';
        $abs = Storage::disk('local')->path($rel);
        @mkdir(dirname($abs), 0775, true);

        $h = fopen($abs, 'w');
        $header = $import->columns ?? [];
        if ($header) { fputcsv($h, array_merge($header, ['Why it was skipped'])); }
        foreach ($rows as [$cells, $why]) {
            fputcsv($h, array_merge((array) $cells, [$why]));
        }
        fclose($h);

        return $abs;
    }
}
