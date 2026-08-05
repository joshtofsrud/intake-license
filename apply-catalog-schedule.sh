#!/usr/bin/env bash
# apply-catalog-schedule.sh
# MARKER-CATALOG-SCHEDULE — sync, index, match, nightly.
#
# Only the HLC delta sync was scheduled. catalog:index-identifiers and
# catalog:match have never run except by hand — once, when BTI was added. That
# looked fine because both distributors' rows were present for that one sweep,
# so everything since has been quietly unmatched: every product HLC's nightly
# delta has added, and all 55,773 QBP rows.
#
# THE CHAIN IS ORDERED AND IT MATTERS:
#   04:00  QBP catalog        892 brand calls, the long one — goes first
#   04:00  HLC delta          unchanged, runs alongside
#   05:30  index-identifiers  reads the rows both syncs wrote
#   06:00  match              reads the identifiers the index wrote
#   06:30  tenant sync        moved back from 05:00, so per-tenant cost and
#                             stock land AFTER matching, not before
#
# Gaps are deliberate. A step reading a half-written table produces a
# half-built index and a matcher that misses pairs — silently, which is the
# failure mode this whole area keeps producing. Fixed times with room beat
# chaining on completion here, because the sync durations are not yet known
# well enough to size a chain.
#
# QBP runs FULL, not delta: brand-paged fetching has no delta mode, and
# products() is already the only path. 892 calls nightly is what QBP's own
# guide recommends for catalog refresh.
set -e

python3 <<'PY'
import io

p = 'routes/console.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-CATALOG-SCHEDULE' not in s, 'already applied'

old = """Schedule::command('distributors:sync-catalog HLC --delta')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('distributors:sync-tenant --all')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->runInBackground();"""
assert s.count(old) == 1, 'C1 schedule anchor'
s = s.replace(old, """// MARKER-CATALOG-SCHEDULE — the catalog chain. Order matters: each step
// reads what the one before it wrote, and a step that runs early produces a
// half-built index rather than an error.

Schedule::command('distributors:sync-catalog HLC --delta')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

// QBP has no delta mode — products() pages by brand, 892 calls. Long-running,
// so it starts with HLC rather than after it.
Schedule::command('distributors:sync-catalog QBP')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

// Reads the rows both syncs just wrote. Without this, matching sees nothing
// new — which is exactly why 55,773 QBP rows were invisible to the importer.
Schedule::command('catalog:index-identifiers')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->runInBackground();

// Reads the identifiers the index just wrote. Links the same product across
// distributors so the importer can say "already carried" instead of creating
// a duplicate.
Schedule::command('catalog:match')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground();

// Moved from 05:00 to 06:30: per-tenant cost and stock should land after
// matching, so a newly linked source is priced on the same night it links.
Schedule::command('distributors:sync-tenant --all')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->runInBackground();""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- the chain, in order ---"
python3 - <<'PY'
import io, re
s = io.open('routes/console.php', encoding='utf-8').read()
pairs = re.findall(r"Schedule::command\('([^']+)'\)\s*\n\s*->dailyAt\('([^']+)'\)", s)
chain = [(t, c) for c, t in pairs if any(k in c for k in
         ['sync-catalog', 'index-identifiers', 'catalog:match', 'sync-tenant'])]
for t, c in sorted(chain):
    print('  %s  %s' % (t, c))

order = [c for _, c in sorted(chain)]
def at(frag): return next(i for i, c in enumerate(order) if frag in c)
ok = at('index-identifiers') > at('sync-catalog QBP') \
     and at('catalog:match') > at('index-identifiers') \
     and at('sync-tenant') > at('catalog:match')
print('  sequence correct:', ok)
assert ok
PY

echo
echo "--- every scheduled command actually exists ---"
python3 - <<'PY'
import io, re, os
s = io.open('routes/console.php', encoding='utf-8').read()
names = set()
for c in re.findall(r"Schedule::command\('([^']+)'\)", s):
    names.add(c.split()[0])
sigs = set()
for root, _, files in os.walk('app/Console/Commands'):
    for f in files:
        if f.endswith('.php'):
            t = io.open(os.path.join(root, f), encoding='utf-8').read()
            m = re.search(r"protected \$signature\s*=\s*'([^\s']+)", t)
            if m: sigs.add(m.group(1))
missing = sorted(n for n in names if n not in sigs and ':' in n)
print('  scheduled:', len(names))
print('  missing  :', missing or 'none')
PY

echo
echo "--- overlap guards on all of them ---"
python3 - <<'PY'
import io, re
s = io.open('routes/console.php', encoding='utf-8').read()
blocks = re.findall(r"Schedule::command\('([^']+)'\)(.*?);", s, re.S)
bad = [c for c, body in blocks if 'withoutOverlapping' not in body]
print('  without a guard:', bad or 'none')
PY

echo
echo "--- php balance ---"
python3 - <<'PY'
import io
s = io.open('routes/console.php', encoding='utf-8').read()
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
print('console.php braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-catalog-schedule: OK"
