#!/usr/bin/env bash
# apply-qbp-test-shape.sh
# MARKER-QBP-TEST-SHAPE — "Connection failed · HTTP ?"
#
# Two separate faults, and the second hid the first.
#
# 1. QbpClient::testConnection() returned ['ok', 'message']. Every other
#    adapter returns ['ok', 'status', 'body'], and the page renders
#    'HTTP ' . $res['status'] — so QBP's carefully written explanation was
#    thrown away and replaced with a question mark. Matched to the contract.
#
# 2. The page only ever shows the status code. Even for HLC and BTI, a
#    failure says "HTTP 401" and nothing about what to do — and when a
#    request never completes there is no status at all, which is exactly the
#    "HTTP ?" case. It now prefers the adapter's own message and falls back to
#    the code, so every distributor gets a readable reason.
#
# Fixing only the first would have shown "HTTP 401" rather than the reason,
# which is better than a question mark and still not an answer.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- adapter
p = 'app/Services/Distributors/QbpClient.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-QBP-TEST-SHAPE' not in s, 'already applied'

old = """        if ($this->apiKey === '') {
            return ['ok' => false, 'message' => 'No API key saved for QBP.'];
        }

        try {
            $res = $this->get('1/brand');
        } catch (\\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach QBP: ' . $e->getMessage()];
        }

        if ($res->status() === 401 || $res->status() === 403) {
            return ['ok' => false, 'message' => 'QBP rejected the key (HTTP ' . $res->status() . ').'];
        }

        if (! $res->successful()) {
            return ['ok' => false, 'message' => 'QBP returned HTTP ' . $res->status() . '.'];
        }

        $json = $res->json();
        $count = is_array($json) ? count($this->listish($json)) : 0;

        return [
            'ok'      => true,
            'message' => 'Connected. QBP returned ' . $count . ' brands.',
        ];"""
assert s.count(old) == 1, 'Q1 testConnection body anchor'
s = s.replace(old, """        // MARKER-QBP-TEST-SHAPE — ok/status/body, matching HlcClient and
        // BtiClient. The page reads 'status'; returning only a message meant
        // it rendered "HTTP ?" and discarded the explanation.
        if ($this->apiKey === '') {
            return ['ok' => false, 'status' => null, 'body' => 'No API key saved for QBP.'];
        }

        try {
            $res = $this->get('1/brand');
        } catch (\\Throwable $e) {
            return ['ok' => false, 'status' => null, 'body' => 'Could not reach QBP: ' . $e->getMessage()];
        }

        $status = $res->status();

        if ($status === 401 || $status === 403) {
            return [
                'ok' => false, 'status' => $status,
                'body' => 'QBP rejected the key. This must be the API1 (Point-of-Sale) key — '
                        . 'a Content License Service key will not work here.',
            ];
        }

        if (! $res->successful()) {
            return [
                'ok' => false, 'status' => $status,
                'body' => 'QBP returned HTTP ' . $status . '. ' . mb_substr((string) $res->body(), 0, 200),
            ];
        }

        $json  = $res->json();
        $count = is_array($json) ? count($this->listish($json)) : 0;

        // A 200 carrying no brands is not a working connection — it usually
        // means the key is valid but the account has no catalog access.
        if ($count === 0) {
            return [
                'ok' => false, 'status' => $status,
                'body' => 'QBP answered but returned no brands. The key works; the account may not '
                        . 'have product access yet.',
            ];
        }

        return [
            'ok'     => true,
            'status' => $status,
            'body'   => 'Connected. QBP returned ' . $count . ' brands.',
        ];""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- page
p = 'app/Filament/Pages/Distributors.php'
s = io.open(p, encoding='utf-8').read()

old = """            $conn->update([
                'last_tested_at' => now(),
                'last_test_status' => $ok ? 'ok' : 'fail',
                'last_test_message' => $ok ? 'Connected' : ('HTTP ' . ($res['status'] ?? '?')),
            ]);

            $ok
                ? Notification::make()->success()->title('Connected to ' . $this->currentCode())->send()
                : Notification::make()->danger()->title('Connection failed')->body('HTTP ' . ($res['status'] ?? '?'))->send();"""
assert s.count(old) == 1, 'P1 result handling anchor'
s = s.replace(old, """            // MARKER-QBP-TEST-SHAPE — show the adapter's own words. Every
            // adapter already returns a 'body' explaining what happened, and
            // this used to discard it for a bare status code — which is
            // useless when the request never completed and there is no code.
            $detail = trim((string) ($res['body'] ?? ''));
            if ($detail === '') {
                $detail = 'HTTP ' . ($res['status'] ?? '?');
            }

            $conn->update([
                'last_tested_at'    => now(),
                'last_test_status'  => $ok ? 'ok' : 'fail',
                'last_test_message' => mb_substr($detail, 0, 255),
            ]);

            $ok
                ? Notification::make()->success()
                    ->title('Connected to ' . $this->currentCode())->body($detail)->send()
                : Notification::make()->danger()
                    ->title('Connection failed')->body($detail)->persistent()->send();""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- QBP now matches the contract the page reads ---"
python3 - <<'PY'
import io, re
for f, name in [('app/Services/Distributors/QbpClient.php', 'QBP'),
                ('app/Services/Distributors/HlcClient.php', 'HLC'),
                ('app/Services/Distributors/BtiClient.php', 'BTI')]:
    s = io.open(f, encoding='utf-8').read()
    body = re.search(r'public function testConnection\(\).*?\n    \}', s, re.S).group(0)
    keys = set(re.findall(r"'(ok|status|body|message)'\s*=>", body))
    print('  %-4s returns: %s' % (name, ', '.join(sorted(keys))))
PY

echo
echo "--- failure messages the page can now show ---"
grep -oE "'body' => '[^']{0,70}" app/Services/Distributors/QbpClient.php | sed "s/'body' => '/  /" | head -6

echo
echo "--- balance ---"
python3 - <<'PY'
import io
for p in ['app/Services/Distributors/QbpClient.php', 'app/Filament/Pages/Distributors.php']:
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
    print('%-32s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "apply-qbp-test-shape: OK"
