#!/bin/bash
# tenant-sync-isolate — one bad distributor stops only itself.
#
#   RunTenantDistributorSyncJob looped every active subscription inside ONE
#   try/catch. A throw from any distributor aborted the loop, so every
#   subscription after it was skipped — silently, because the job still
#   recorded a finished run.
#
#   That is not hypothetical: BTI's credentials fail, BtiClient::feedFile()
#   throws after its retries, and Ground Control's HLC cost and availability
#   sync has not completed since BTI was added. The Catalog attention page
#   still reads "Last checked Jul 30" and its count has been frozen at 206
#   ever since.
#
#   The shape of the bug is the dangerous part: connecting a SECOND
#   distributor can stop the FIRST one working, on a live shop, with no
#   error surfaced anywhere a person looks.
#
#   Each subscription now runs in its own try/catch. A failure is recorded
#   against that distributor by name and the loop continues, so HLC keeps
#   syncing while BTI is broken, and the run's stats say which one failed
#   instead of the whole job looking fine.
# NO MIGRATION. Server: optimize:clear
set -e
if grep -q "MARKER-SYNC-ISOLATE" app/Jobs/RunTenantDistributorSyncJob.php; then
  echo "tenant-sync-isolate already applied — aborting."; exit 1
fi

python3 - <<'TSI_0_EOF'
import io
p = 'app/Jobs/RunTenantDistributorSyncJob.php'
s = io.open(p, encoding='utf-8').read()

old = """        $agg = []; $error = null;
        try {
            $subs = TenantDistributorCatalogSubscription::query()
                ->where('tenant_id', $this->tenantId)->where('is_active', true)->get();
            foreach ($subs as $sub) {
                $res = $service->sync($sub, $this->dryRun);
                foreach ($res as $k => $v) {
                    if (is_numeric($v)) $agg[$k] = ($agg[$k] ?? 0) + $v;
                }
                if (!empty($res['errors'])) {
                    $agg['errors'] = array_merge($agg['errors'] ?? [], (array) $res['errors']);
                }
            }
            if ($subs->isEmpty()) $agg['note'] = 'no active subscriptions';
        } catch (\\Throwable $e) {
            $error = $e->getMessage();
        }"""
assert s.count(old) == 1, ('loop anchor', s.count(old))

new = """        $agg = []; $error = null;
        try {
            $subs = TenantDistributorCatalogSubscription::query()
                ->where('tenant_id', $this->tenantId)->where('is_active', true)->get();

            // MARKER-SYNC-ISOLATE — each distributor in its own try/catch.
            //
            // This loop used to sit inside a single outer try, so a throw from
            // ANY subscription aborted the rest. Connecting a second
            // distributor with bad credentials therefore stopped the first
            // one from syncing at all — silently, since the job still
            // recorded a finished run. A distributor that can't be reached
            // must not take the others down with it.
            foreach ($subs as $sub) {
                $code = (string) $sub->distributor_code;

                try {
                    $res = $service->sync($sub, $this->dryRun);
                } catch (\\Throwable $e) {
                    $agg['errors'][] = $code . ': ' . $e->getMessage();
                    $agg['failed'] = array_values(array_unique(
                        array_merge($agg['failed'] ?? [], [$code])
                    ));
                    continue;           // the next distributor still runs
                }

                foreach ($res as $k => $v) {
                    if (is_numeric($v)) $agg[$k] = ($agg[$k] ?? 0) + $v;
                }
                if (!empty($res['errors'])) {
                    // Name the distributor, so a mixed run says which half
                    // of it went wrong.
                    foreach ((array) $res['errors'] as $msg) {
                        $agg['errors'][] = $code . ': ' . $msg;
                    }
                }
            }

            if ($subs->isEmpty()) $agg['note'] = 'no active subscriptions';
        } catch (\\Throwable $e) {
            // Reaching here now means the run itself failed — loading the
            // subscriptions — not one distributor inside it.
            $error = $e->getMessage();
        }"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('sync isolate ok')
TSI_0_EOF

echo
echo "tenant-sync-isolate applied."
