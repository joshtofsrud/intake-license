#!/usr/bin/env bash
# apply-qbp-negotiate.sh
# MARKER-QBP-NEGOTIATE — find out what QBP will actually answer.
#
# 1/brand returns HTTP 406 with an empty body. 406 means the server refuses to
# produce anything matching the request's Accept header — the key and the path
# are almost certainly fine, since a bad key gives 401 and a bad path gives
# 404. The guide says to send application/json, so what it says and what it
# does disagree.
#
# Rather than change one header and re-deploy to find out, this asks QBP
# directly: the same request with several header combinations, printing the
# status and the first of the body for each. One run answers it.
#
# Variants chosen for the ways an API typically produces a 406:
#   - the documented Accept
#   - Accept with an explicit charset, which some .NET stacks match on
#   - */* , which cannot be refused by content negotiation
#   - no Accept header at all
#   - XML, since the guide says both are supported and JSON may be the
#     one that is misconfigured
#   - a browser-style Accept plus User-Agent, because some gateways 406 a
#     request with no recognisable client
set -e

python3 <<'PY'
import io

p = 'app/Console/Commands/QbpProbe.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-QBP-NEGOTIATE' not in s, 'already applied'

old = """    protected $signature = 'qbp:probe
        {sku? : SKU to fetch in full. Omitted, one is taken from the SKU list}
        {--raw : Print whole payloads instead of trimmed ones}';"""
assert s.count(old) == 1, 'N1 signature anchor'
s = s.replace(old, """    protected $signature = 'qbp:probe
        {sku? : SKU to fetch in full. Omitted, one is taken from the SKU list}
        {--raw : Print whole payloads instead of trimmed ones}
        {--negotiate : Try several header combinations and report which QBP answers}';""")

old = """        $this->base = rtrim((string) config('distributors.qbp.base_url'), '/') . '/';
        $this->line('Base URL: ' . $this->base);"""
assert s.count(old) == 1, 'N2 base anchor'
s = s.replace(old, """        $this->base = rtrim((string) config('distributors.qbp.base_url'), '/') . '/';

        // MARKER-QBP-NEGOTIATE — run this when the normal probe 406s.
        if ($this->option('negotiate')) {
            return $this->negotiate();
        }

        $this->line('Base URL: ' . $this->base);""")

# Anchor on the signature, not a comment — the comment moved when call()
# was renamed to probeGet().
old = """    private function probeGet(string $path, string $label, bool $dump = false): mixed"""
assert s.count(old) == 1, 'N3 method anchor'
s = s.replace(old, """    /**
     * MARKER-QBP-NEGOTIATE — the same call with different headers.
     *
     * A 406 means content negotiation failed, so the variable is the headers
     * and nothing else. Printing every combination's status side by side
     * turns "which header does it want" into one command rather than a
     * sequence of deploys.
     */
    private function negotiate(): int
    {
        $variants = [
            'documented json'      => ['Accept' => 'application/json'],
            'json + charset'       => ['Accept' => 'application/json; charset=utf-8'],
            'anything'             => ['Accept' => '*/*'],
            'no accept header'     => [],
            'xml'                  => ['Accept' => 'application/xml'],
            'json + user-agent'    => ['Accept' => 'application/json', 'User-Agent' => 'Intake/1.0'],
            'browserish'           => [
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'User-Agent' => 'Mozilla/5.0 (compatible; Intake/1.0)',
            ],
        ];

        // Two paths: one that needs the key, one that should 404 regardless —
        // if BOTH 406, the refusal is happening before routing and the answer
        // is a gateway, not the endpoint.
        $paths = ['1/brand', '1/definitely-not-a-real-endpoint'];

        foreach ($paths as $path) {
            $this->newLine();
            $this->line('=== ' . $this->base . $path);

            foreach ($variants as $label => $headers) {
                $h = $headers + ['X-QBPAPI-KEY' => $this->key];

                try {
                    $res = Http::withHeaders($h)
                        ->timeout((int) config('distributors.qbp.timeout', 60))
                        ->get($this->base . $path);

                    $body = trim((string) $res->body());
                    $this->line(sprintf(
                        '  %-20s HTTP %-4s %6s bytes  %s',
                        $label,
                        $res->status(),
                        strlen($body),
                        mb_substr(preg_replace('/\\s+/', ' ', $body), 0, 70)
                    ));
                } catch (\\Throwable $e) {
                    $this->line(sprintf('  %-20s failed: %s', $label, mb_substr($e->getMessage(), 0, 70)));
                }
            }
        }

        $this->newLine();
        $this->comment('Read it this way:');
        $this->line('  Any row returning 200 names the headers to use.');
        $this->line('  If 1/brand 406s but the fake path 404s, the endpoint is refusing the');
        $this->line('  Accept header. If BOTH 406, something in front of QBP is rejecting the');
        $this->line('  request before it reaches the API and the headers are not the problem.');
        $this->line('  If every row is 401, the key is wrong rather than the headers.');

        return self::SUCCESS;
    }

    private function probeGet(string $path, string $label, bool $dump = false): mixed""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- negotiate wired ---"
grep -n "MARKER-QBP-NEGOTIATE\|--negotiate\|private function negotiate" app/Console/Commands/QbpProbe.php | head

echo
echo "--- still no private override of a Command method ---"
python3 - <<'PY'
import io, re
s = io.open('app/Console/Commands/QbpProbe.php', encoding='utf-8').read()
reserved = {'call','callSilent','callSilently','handle','run','ask','confirm','choice','table','info',
            'line','comment','question','error','warn','alert','newLine','argument','arguments',
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
echo "apply-qbp-negotiate: OK"
