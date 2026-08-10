#!/usr/bin/env bash
set -euo pipefail
# apply-marketing-funnel-insert-fix.sh — MARKER-MKTFIX
# Marketing traffic recorded ZERO rows despite the tracker being live and the
# platform tenant existing.
#
# CAUSE: tenant_funnel_events has `created_at` ONLY — the migration declares
# $table->timestamp('created_at')->useCurrent() and no updated_at. My insert
# wrote both, so every insert threw "Unknown column 'updated_at'". The catch in
# record() swallowed it — correct for analytics, which must never break a page,
# but it made the failure invisible. It has been logging 'marketing funnel event
# failed' to storage/logs the whole time.
#
# ALSO: the table already had an index on (tenant_id, event_type, created_at)
# named tfe_tenant_event_time. My migration checked by index NAME, so it added a
# duplicate. This drops it and leaves the original.

CTRL=app/Http/Controllers/Platform/MarketingFunnelController.php
MIG=database/migrations/2026_08_09_160000_drop_duplicate_funnel_index.php

[ -f "$CTRL" ] || { echo "MISSING $CTRL — deploy apply-marketing-traffic-report.sh first"; exit 1; }

if grep -q "MARKER-MKTFIX" "$CTRL"; then
  echo "Already applied (MARKER-MKTFIX present) — no-op."
  exit 0
fi

python3 - "$CTRL" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """                'created_at'      => now(),
                'updated_at'      => now(),"""
new = """                // MARKER-MKTFIX — this table has created_at ONLY (declared
                // useCurrent, no updated_at). Writing updated_at threw on every
                // insert, and the catch below made it silent.
                'created_at'      => now(),"""

n = src.count(old)
if n != 1:
    print(f"FAIL insert columns: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   updated_at removed from insert")

# Make the swallowed failure findable next time: log at error level with the
# event type, so a grep for the code path lands immediately.
old_log = """            \\Log::warning('marketing funnel event failed', ["""
new_log = """            // Still swallowed on purpose — analytics must not break a page —
            // but at error level so it surfaces in any log scan. MARKER-MKTFIX
            \\Log::error('marketing funnel event failed', ["""
n = src.count(old_log)
if n != 1:
    print(f"FAIL log level: anchor found {n} times"); sys.exit(1)
src = src.replace(old_log, new_log, 1)
print("ok   failure logged at error level")

open(path, 'w').write(src)
PY

if [ -f "$MIG" ]; then echo "ok   index-drop migration already present"; else
cat <<'EOF' > "$MIG"
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MARKER-MKTFIX — tenant_funnel_events already carried an index on
// (tenant_id, event_type, created_at) named tfe_tenant_event_time. The earlier
// migration checked for an index by NAME, not by columns, so it created a
// second identical one. Drop the duplicate; keep the original.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_funnel_events')) {
            return;
        }

        $names = collect(DB::select('SHOW INDEX FROM tenant_funnel_events'))
            ->pluck('Key_name')->unique();

        if ($names->contains('tfe_tenant_type_created_idx') && $names->contains('tfe_tenant_event_time')) {
            DB::statement('DROP INDEX tfe_tenant_type_created_idx ON tenant_funnel_events');
        }
    }

    public function down(): void
    {
        // Deliberately not recreated — it was redundant.
    }
};
EOF
echo "ok   index-drop migration created"; fi

php -l "$CTRL"

echo ""
echo "SUCCESS — apply-marketing-funnel-insert-fix applied."
echo "After deploy, load a marketing page then re-run the row count."
