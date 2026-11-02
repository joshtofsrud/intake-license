<?php

namespace App\Services\Tenant\ReportBuilder;

use App\Models\Tenant;

/**
 * Who may use the report builder. (MARKER-REPORT-GATE)
 *
 * The builder is part of extended reports, the same add-on that opens the
 * lists on the other Reports tabs. Every place that runs a report, exports it,
 * emails it, pins it or explains it asks here first, so a shop without
 * extended reports gets nothing from the server, not just a blurred page.
 */
class ReportAccess
{
    public const LOCKED_MESSAGE = 'The report builder is part of extended reports. Upgrade to use it.';

    public static function allowed(?Tenant $tenant): bool
    {
        if (!$tenant) return false;
        try {
            return (bool) $tenant->extended_reports_enabled;
        } catch (\Throwable $e) {
            report($e);
            return false;   // when in doubt, closed
        }
    }
}
