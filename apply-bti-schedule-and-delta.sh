#!/usr/bin/env bash
# apply-bti-schedule-and-delta.sh
# MARKER-DELTA-REAL — BTI joins the chain, and --delta starts working.
#
# TWO FINDINGS FROM READING THE SCHEDULE PROPERLY:
#
# 1. BTI HAS NEVER BEEN SCHEDULED. Only HLC was. BTI's catalog last moved on
#    1 August, by hand. New BTI products and price changes have not landed
#    since.
#
# 2. --delta HAS NEVER SKIPPED A ROW. isUnchanged() reads DateLastModified,
#    and no adapter emits that key — not HLC, not BTI, not QBP. It returns
#    false every time, so every row is written on every sync. The flag on
#    HLC's nightly line has been decorative since the day it was added.
#
#    QBP does carry a timestamp: modifiedTime.iMillis, milliseconds since
#    epoch, on every row. Teaching isUnchanged to read it makes delta real for
#    QBP — 55,891 writes a night become only the rows that actually changed.
#    The 892 API calls still happen; this saves database work, not fetches.
#
#    HLC and BTI keep their current behaviour exactly: no timestamp found
#    still means "sync it", which is the safe answer and always has been.
#
# BTI runs FULL because it has no timestamp to delta against, and because its
# feed regenerates whole on every request anyway.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- delta
p = 'app/Services/Distributors/DistributorCatalogSyncService.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-DELTA-REAL' not in s, 'already applied'

old = """    private function isUnchanged(array $variant, array $product, Carbon $since): bool
    {
        $ts = $variant['DateLastModified'] ?? $product['DateLastModified'] ?? null;
        if (! $ts) {
            return false; // unknown modified date -> always sync
        }
        try {
            return Carbon::parse($ts)->lessThanOrEqualTo($since);
        } catch (\\Throwable) {
            return false;
        }
    }"""
assert s.count(old) == 1, 'D1 isUnchanged anchor'
s = s.replace(old, """    private function isUnchanged(array $variant, array $product, Carbon $since): bool
    {
        $ts = $variant['DateLastModified'] ?? $product['DateLastModified'] ?? null;

        // MARKER-DELTA-REAL — QBP states its timestamp as milliseconds since
        // epoch under modifiedTime.iMillis. Without this the key was never
        // found, isUnchanged always returned false, and --delta wrote every
        // row on every run for every distributor.
        if ($ts === null) {
            $ms = $variant['modifiedTime']['iMillis']
                ?? $product['modifiedTime']['iMillis']
                ?? null;
            if ($ms !== null && is_numeric($ms)) {
                try {
                    return Carbon::createFromTimestampMs((int) $ms)->lessThanOrEqualTo($since);
                } catch (\\Throwable) {
                    return false;
                }
            }
        }

        if (! $ts) {
            return false; // unknown modified date -> always sync
        }
        try {
            return Carbon::parse($ts)->lessThanOrEqualTo($since);
        } catch (\\Throwable) {
            return false;
        }
    }""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- schedule
p = 'routes/console.php'
s = io.open(p, encoding='utf-8').read()

old = """// QBP has no delta mode — products() pages by brand, 892 calls. Long-running,
// so it starts with HLC rather than after it.
Schedule::command('distributors:sync-catalog QBP')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();"""
assert s.count(old) == 1, 'S1 qbp schedule anchor'
s = s.replace(old, """// MARKER-DELTA-REAL — QBP pages by brand: 892 calls regardless, so --delta
// saves database writes rather than fetches. It carries modifiedTime.iMillis,
// which isUnchanged now reads, so unchanged rows are skipped on write.
Schedule::command('distributors:sync-catalog QBP --delta')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

// MARKER-DELTA-REAL — BTI was never scheduled at all; its catalog last moved
// by hand. Full, not delta: BTI supplies no per-row timestamp, and its feed
// regenerates whole on every request anyway.
Schedule::command('distributors:sync-catalog BTI')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->runInBackground();""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- all three distributors scheduled, in chain order ---"
python3 - <<'PY'
import io, re
s = io.open('routes/console.php', encoding='utf-8').read()
pairs = re.findall(r"Schedule::command\('([^']+)'\)\s*\n\s*->dailyAt\('([^']+)'\)", s)
chain = sorted([(t, c) for c, t in pairs if any(k in c for k in
                ['sync-catalog', 'index-identifiers', 'catalog:match', 'sync-tenant'])])
for t, c in chain:
    print('  %s  %s' % (t, c))
codes = {c.split()[1] for _, c in chain if 'sync-catalog' in c}
print('  distributors:', ', '.join(sorted(codes)))
assert codes == {'HLC', 'QBP', 'BTI'}, codes
order = [c for _, c in chain]
def at(f): return next(i for i, c in enumerate(order) if f in c)
assert at('index-identifiers') > at('sync-catalog BTI')
assert at('catalog:match') > at('index-identifiers')
assert at('sync-tenant') > at('catalog:match')
print('  sequence correct: True')
PY

echo
echo "--- delta reads QBP's millisecond timestamp ---"
python3 - <<'PY'
import io, re
s = io.open('app/Services/Distributors/DistributorCatalogSyncService.php', encoding='utf-8').read()
m = re.search(r'private function isUnchanged.*?\n    \}', s, re.S).group(0)
print('  reads modifiedTime.iMillis :', "['modifiedTime']['iMillis']" in m)
print('  uses createFromTimestampMs :', 'createFromTimestampMs' in m)
print('  still defaults to sync     :', 'return false; // unknown modified date' in m)
PY

echo
echo "--- the millisecond logic, on a real QBP value ---"
python3 - <<'PY'
from datetime import datetime, timezone
ms = 1785867380000          # modifiedTime.iMillis from TR00172
when = datetime.fromtimestamp(ms / 1000, tz=timezone.utc)
print('  1785867380000 ->', when.isoformat())
for label, since in [('watermark before it', datetime(2026, 1, 1, tzinfo=timezone.utc)),
                     ('watermark after it',  datetime(2026, 12, 1, tzinfo=timezone.utc))]:
    print('  %-20s unchanged=%s' % (label, when <= since))
assert (when <= datetime(2026, 12, 1, tzinfo=timezone.utc))
assert not (when <= datetime(2026, 1, 1, tzinfo=timezone.utc))
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
for p in ['app/Services/Distributors/DistributorCatalogSyncService.php', 'routes/console.php']:
    s = io.open(p, encoding='utf-8').read()
    i, n, d, par, brk = 0, len(s), 0, 0, 0
    while i < n:
        c = s[i]
        if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
            while i < n and s[i] != '\n': i += 1
        elif c == '/' and i+1 < n and s[i+1] == '*':
            i += 2
            while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
            i += 2
        elif c in '"\'':
            q = c; i += 1
            while i < n and s[i] != q:
                if s[i] == '\\': i += 1
                i += 1
            i += 1
        else:
            if c == '{': d += 1
            elif c == '}': d -= 1
            elif c == '(': par += 1
            elif c == ')': par -= 1
            elif c == '[': brk += 1
            elif c == ']': brk -= 1
            i += 1
    print('%-46s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "apply-bti-schedule-and-delta: OK"
