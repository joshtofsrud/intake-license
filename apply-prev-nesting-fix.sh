#!/usr/bin/env bash
set -euo pipefail
# apply-prev-nesting-fix.sh — MARKER-PREVFIX
# design_tokens['_prev'] nests recursively and doubles in size on every
# template apply.
#
# SiteTemplateService::apply() snapshots the tenant's CURRENT design_tokens
# into _prev — but that value already contains its own _prev, which contains
# its own, and so on. Ground Control's column is ten-plus levels deep; a tinker
# dump of it runs for pages. Nothing is broken by it (revert() only ever reads
# the top level, which is why this went unnoticed) but the column grows without
# bound.
#
#   1. apply() now strips _prev before snapshotting, so a snapshot describes
#      one state instead of a chain of every state that came before.
#   2. A migration flattens what is already stored, for every tenant.
#
# Revert behaviour is UNCHANGED: it was always a single level — the docblock
# says "revert to the design captured before the last apply()" — and the deeper
# levels were never reachable. Nothing a tenant can do gets lost.

SVC=app/Services/Tenant/SiteTemplateService.php
MIG=database/migrations/2026_08_09_140000_flatten_tenant_design_token_prev.php

[ -f "$SVC" ] || { echo "MISSING $SVC — run from the repo root"; exit 1; }

if grep -q "MARKER-PREVFIX" "$SVC"; then
  echo "Already applied (MARKER-PREVFIX present) — no-op."
  exit 0
fi

# ---------------------------------------------------------------- service
python3 - "$SVC" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """            // Snapshot current design so a switch is reversible.
            $prev = [
                'site_template' => $tenant->site_template,
                'accent_color'  => $tenant->accent_color,
                'text_color'    => $tenant->text_color,
                'bg_color'      => $tenant->bg_color,
                'font_heading'  => $tenant->font_heading,
                'font_body'     => $tenant->font_body,
                'design_tokens' => $tenant->design_tokens,
            ];"""

new = """            // Snapshot current design so a switch is reversible.
            // MARKER-PREVFIX — drop the existing _prev before storing. Without
            // this the snapshot contains the previous snapshot, which contains
            // the one before it: the column doubles on every apply and revert
            // still only ever reads the top level.
            $currentTokens = (array) ($tenant->design_tokens ?? []);
            unset($currentTokens['_prev']);

            $prev = [
                'site_template' => $tenant->site_template,
                'accent_color'  => $tenant->accent_color,
                'text_color'    => $tenant->text_color,
                'bg_color'      => $tenant->bg_color,
                'font_heading'  => $tenant->font_heading,
                'font_body'     => $tenant->font_body,
                'design_tokens' => $currentTokens ?: null,
            ];"""

n = src.count(old)
if n != 1:
    print(f"FAIL apply snapshot: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   apply() strips _prev before snapshotting")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- migration
if [ -f "$MIG" ]; then echo "ok   flatten migration already present"; else
cat <<'EOF' > "$MIG"
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MARKER-PREVFIX — one-time flatten of the recursive _prev chains already in
// the column. Keeps the top-level snapshot (the only one revert can reach) and
// discards the nested history underneath it.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')
            ->whereNotNull('design_tokens')
            ->orderBy('id')
            ->chunkById(100, function ($tenants) {
                foreach ($tenants as $tenant) {
                    $tokens = json_decode($tenant->design_tokens ?? 'null', true);
                    if (! is_array($tokens) || ! isset($tokens['_prev']) || ! is_array($tokens['_prev'])) {
                        continue;
                    }

                    $prev = $tokens['_prev'];

                    // The snapshot's OWN design_tokens is where the chain
                    // continues — strip its _prev and stop there.
                    if (isset($prev['design_tokens']) && is_array($prev['design_tokens'])) {
                        unset($prev['design_tokens']['_prev']);
                        if ($prev['design_tokens'] === []) {
                            $prev['design_tokens'] = null;
                        }
                    }

                    $tokens['_prev'] = $prev;

                    DB::table('tenants')->where('id', $tenant->id)
                        ->update(['design_tokens' => json_encode($tokens)]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible by nature: the discarded history is gone. No-op rather
        // than pretending otherwise.
    }
};
EOF
echo "ok   flatten migration created"; fi

php -l "$SVC"

echo ""
echo "SUCCESS — apply-prev-nesting-fix applied."
echo "The migration flattens existing rows on deploy. Revert still works and"
echo "is still single-level, exactly as before."
