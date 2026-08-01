#!/bin/bash
# bti-cache-per-run — a sync fetches; it doesn't serve yesterday's file.
#
#   feedFile() reused any download under six hours old. That was written for a
#   paginated sweep where products() would be called once per page, so the
#   alternative was re-pulling 43 MB for every page. But DistributorCatalogSyncService
#   makes ONE call and takes the whole catalog, so the cost that justified the
#   cache mostly isn't there — while the staleness very much is.
#
#   Consequences that already bit us: a manual "Run full sync" could write
#   rows from a file hours old, and tonight the master-admin sync appeared to
#   succeed during a BTI maintenance window because it never touched the
#   network. A sync that can't reach the distributor must fail, not quietly
#   re-import an old copy.
#
#   The cache is now scoped to the adapter INSTANCE: the first call to a feed
#   downloads it, later calls in the same run reuse it. An adapter is built
#   per sync, so every run is fresh and nothing re-downloads within a run.
#
#   The file still lands on disk — it's how fgetcsv streams without loading
#   43 MB into memory — it just isn't reused across runs.
# NO MIGRATION. Server: optimize:clear
set -e
if grep -q "MARKER-CACHE-PER-RUN" app/Services/Distributors/BtiClient.php; then
  echo "bti-cache-per-run already applied — aborting."; exit 1
fi

python3 - <<'CPR_0_EOF'
import io
p = 'app/Services/Distributors/BtiClient.php'
s = io.open(p, encoding='utf-8').read()

# ---------------------------------------------------------------- property
old = """    private int $cacheHours;"""
assert s.count(old) == 1, ('property', s.count(old))
new = """    private int $cacheHours;

    /**
     * MARKER-CACHE-PER-RUN — feeds already downloaded by THIS adapter.
     *
     * The cache used to be time-based (six hours), which meant a manual sync
     * could import a file from hours earlier and, during an outage, succeed
     * without ever reaching the distributor. An adapter is built per run, so
     * scoping to the instance gives one download per run and no repeats
     * inside it.
     *
     * @var array<string,bool>
     */
    private array $fetchedThisRun = [];"""
s = s.replace(old, new)

# ---------------------------------------------------------------- freshness
old = """        $fresh = is_file($path)
            && (time() - filemtime($path)) < ($this->cacheHours * 3600)
            && filesize($path) > 1024;

        if ($fresh) {
            return $path;
        }"""
assert s.count(old) == 1, ('freshness', s.count(old))
new = """        // MARKER-CACHE-PER-RUN — reuse only within this run.
        //
        // Was: any file younger than cacheHours. That let a sync import a
        // stale copy, and let one "succeed" while the distributor was down.
        // The file still lands on disk so fgetcsv can stream it without
        // holding 43 MB in memory; it just isn't reused across runs.
        $key = $full ? 'full' : 'light';

        if (! empty($this->fetchedThisRun[$key])
            && is_file($path) && filesize($path) > 1024) {
            return $path;
        }"""
s = s.replace(old, new)

# ---------------------------------------------------------------- mark it
old = """                // Rename only after a complete download, so a failed attempt
                // can never be served as a valid cache.
                rename($tmp, $path);
                return $path;"""
assert s.count(old) == 1, ('rename', s.count(old))
new = """                // Rename only after a complete download, so a failed attempt
                // can never be served as a valid cache.
                rename($tmp, $path);
                $this->fetchedThisRun[$key] = true;   // MARKER-CACHE-PER-RUN
                return $path;"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('cache scoping ok')
CPR_0_EOF

# ---------------------------------------------------------------- config note
python3 - <<'CPR_1_EOF'
import io
p = 'config/distributors.php'
s = io.open(p, encoding='utf-8').read()

old = """        // How long a downloaded feed is reused before re-fetching.
        'cache_hours'    => (int) env('BTI_CACHE_HOURS', 6),"""
assert s.count(old) == 1, ('config', s.count(old))
new = """        // MARKER-CACHE-PER-RUN — a feed is now reused only within a single
        // sync run, never across runs, so this is no longer consulted. Kept
        // so an existing BTI_CACHE_HOURS in .env doesn't look meaningful.
        'cache_hours'    => (int) env('BTI_CACHE_HOURS', 6),"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('config note ok')
CPR_1_EOF

echo
echo "bti-cache-per-run applied."
