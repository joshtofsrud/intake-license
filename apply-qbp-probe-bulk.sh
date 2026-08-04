#!/usr/bin/env bash
# apply-qbp-probe-bulk.sh
# MARKER-QBP-BULK — can we pull the catalog without 30,000 calls?
#
# 1/product/sku/{sku} returns everything for ONE product. The catalog is over
# 30,000 SKUs, so per-SKU fetching is not a sync, it is a week.
#
# The guide names the bulk path but shows no payloads:
#   GET  /1/product/modellist          all model ids
#   POST /1/model/id                   details for the ids you pass
#   GET  /1/availability/warehouse/{c} all stock in one warehouse
#
# Every previous assumption about QBP's shapes has been wrong — JSON that is
# really XML, collections nested a level deeper than expected, a "1 brands"
# that was 892. So this measures the three before an adapter depends on them,
# rather than after.
#
# WHAT EACH ANSWER DECIDES:
#   modellist size vs skulist size  →  how much grouping model does for us
#   whether POST /1/model/id takes a LIST                 →  batch size
#   whether the model response carries price and stock    →  one pass or three
#   whether warehouse availability returns the whole site →  4 calls or 30,000
#
# Read-only. Nothing is written.
set -e

python3 <<'PY'
import io

p = 'app/Console/Commands/QbpProbe.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-QBP-BULK' not in s, 'already applied'

old = """        {--negotiate : Try several header combinations and report which QBP answers}';"""
assert s.count(old) == 1, 'B1 signature anchor'
s = s.replace(old, """        {--negotiate : Try several header combinations and report which QBP answers}
        {--bulk : Probe the bulk endpoints a real sync would have to use}';""")

old = """        // MARKER-QBP-NEGOTIATE — run this when the normal probe 406s.
        if ($this->option('negotiate')) {
            return $this->negotiate();
        }"""
assert s.count(old) == 1, 'B2 dispatch anchor'
s = s.replace(old, """        // MARKER-QBP-NEGOTIATE — run this when the normal probe 406s.
        if ($this->option('negotiate')) {
            return $this->negotiate();
        }

        // MARKER-QBP-BULK — run this before designing the sync.
        if ($this->option('bulk')) {
            return $this->bulk();
        }""")

old = """    /**
     * MARKER-QBP-NEGOTIATE — the same call with different headers."""
assert s.count(old) == 1, 'B3 method anchor'
s = s.replace(old, """    /**
     * MARKER-QBP-BULK — measure the three endpoints a sync would live on.
     *
     * Prints sizes and the first slice of each, not whole payloads: the
     * question is shape and volume, and a full model list is megabytes.
     */
    private function bulk(): int
    {
        $this->line('Base URL: ' . $this->base);
        $this->newLine();

        // ---- 1. How many models, against how many SKUs?
        $this->line('=== 1/product/modellist');
        $models = $this->probeGet('1/product/modellist', 'Model list');
        $modelIds = [];
        if (is_array($models)) {
            // The collection name is unknown; report every list found so the
            // real path is visible rather than assumed.
            foreach ($this->collections($models) as $path => $items) {
                $this->line('  ' . $path . ' -> ' . count($items) . ' entries');
                $this->line('    first three: ' . mb_substr((string) json_encode(array_slice($items, 0, 3)), 0, 300));
                if (count($items) > count($modelIds)) {
                    $modelIds = $items;
                }
            }
        }
        $this->newLine();

        // ---- 2. Does POST /1/model/id accept a batch, and what comes back?
        $sample = [];
        foreach (array_slice($modelIds, 0, 3) as $m) {
            $sample[] = is_array($m) ? (string) reset($m) : (string) $m;
        }
        $sample = array_values(array_filter($sample, fn ($v) => $v !== ''));

        $this->line('=== POST 1/model/id  with ' . count($sample) . ' ids: ' . implode(', ', $sample));
        if ($sample) {
            foreach ([
                'xml list of <id>' => '<modelIdList>' . implode('', array_map(fn ($i) => '<id>' . htmlspecialchars($i) . '</id>', $sample)) . '</modelIdList>',
                'xml list of <modelId>' => '<modelIdList>' . implode('', array_map(fn ($i) => '<modelId>' . htmlspecialchars($i) . '</modelId>', $sample)) . '</modelIdList>',
            ] as $label => $body) {
                try {
                    $res = Http::withHeaders([
                            'X-QBPAPI-KEY' => $this->key,
                            'Accept'       => 'application/xml',
                            'Content-Type' => 'application/xml',
                        ])
                        ->timeout((int) config('distributors.qbp.timeout', 60))
                        ->withBody($body, 'application/xml')
                        ->post($this->base . '1/model/id');

                    $this->line(sprintf('  %-24s HTTP %-4s %8s bytes', $label, $res->status(), strlen($res->body())));
                    if ($res->successful()) {
                        $this->line('    ' . mb_substr(preg_replace('/\\s+/', ' ', (string) $res->body()), 0, 400));
                    } else {
                        $this->warn('    ' . mb_substr((string) $res->body(), 0, 200));
                    }
                } catch (\\Throwable $e) {
                    $this->error('  ' . $label . ' failed: ' . mb_substr($e->getMessage(), 0, 120));
                }
            }
        } else {
            $this->warn('  No model ids to try — the model list did not parse.');
        }
        $this->newLine();

        // ---- 3. Whole-warehouse stock. Codes seen on a real product.
        $this->line('=== 1/availability/warehouse/{code}   (1000 = Minnesota)');
        $avail = $this->probeGet('1/availability/warehouse/1000', 'Warehouse 1000');
        if (is_array($avail)) {
            foreach ($this->collections($avail) as $path => $items) {
                $this->line('  ' . $path . ' -> ' . count($items) . ' entries');
                $this->line('    first: ' . mb_substr((string) json_encode($items[0] ?? null), 0, 300));
            }
        }

        $this->newLine();
        $this->comment('What the answers mean:');
        $this->line('  If the warehouse call returns thousands of rows, stock is 4 calls a night.');
        $this->line('  If POST 1/model/id returns many products with price inside, the whole');
        $this->line('  catalog is a few hundred batched calls rather than 30,000 single ones.');
        $this->line('  If either only answers for one item, this cannot be a nightly sync and');
        $this->line('  the design has to change — better to know now than after it is built.');

        return self::SUCCESS;
    }

    /**
     * MARKER-QBP-BULK — every list in a payload, with its path.
     *
     * Used only for exploration. Production code names its collections; this
     * exists precisely because the names are what is being discovered.
     *
     * @return array<string,array<int,mixed>>
     */
    private function collections(array $node, string $path = '', int $depth = 0): array
    {
        $found = [];
        if ($depth > 4) {
            return $found;
        }
        foreach ($node as $k => $v) {
            if (! is_array($v)) {
                continue;
            }
            $here = $path === '' ? (string) $k : $path . '.' . $k;
            if (array_is_list($v)) {
                $found[$here] = $v;
            } else {
                $found += $this->collections($v, $here, $depth + 1);
            }
        }
        return $found;
    }

    /**
     * MARKER-QBP-NEGOTIATE — the same call with different headers.""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- bulk mode wired ---"
grep -n "MARKER-QBP-BULK\|--bulk\|private function bulk" app/Console/Commands/QbpProbe.php | head

echo
echo "--- no private override of a Command method ---"
python3 - <<'PY'
import io, re
s = io.open('app/Console/Commands/QbpProbe.php', encoding='utf-8').read()
reserved = {'call','callSilent','handle','run','ask','confirm','choice','table','info','line',
            'comment','question','error','warn','alert','newLine','argument','arguments',
            'option','options','output','components','task'}
bad = [m for m in re.findall(r'(?:private|protected) function (\w+)\(', s) if m in reserved]
print('  clashes:', bad or 'none')
assert not bad
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Console/Commands/QbpProbe.php', encoding='utf-8').read()
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
print('QbpProbe braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-qbp-probe-bulk: OK"
