#!/usr/bin/env bash
# apply-test-connection-honesty.sh
# MARKER-TEST-CONNECTION-COPY — say what the button is about to do.
#
# The BTI test takes ~30 seconds and looks hung. Measured on the production
# server rather than guessed:
#
#   GET /inventory?type=json          200, 3,848,122 bytes, ~22s
#   same with Range: bytes=0-2047     200, 3,848,122 bytes, ~22s  (ignored)
#   HEAD /inventory?type=json         200, ~19.5s               (still built)
#   time_starttransfer                24.8s of a 28.4s total
#
# That last line is the whole story: BTI renders the entire feed before
# sending a byte. So there is nothing to optimise client-side —
#   * Range is ignored (and the old code comment blaming a 503 on it is not
#     reproducible; that was almost certainly an outage)
#   * HEAD still builds the body
#   * streaming saves ~3.6s of ~28s, which is not worth a second code path
#   * an authenticated 404 probe returns in 0.49s but returns 404 for BAD
#     credentials too, so it proves nothing
#
# BTI simply has no cheap authenticated endpoint. The honest fix is to stop
# the button looking broken, not to pretend it can be fast.
#
# Matches the runFull action directly above it, which already confirms
# before a long operation. The description adapts: BTI gets the warning,
# HLC and QBP (System/Echo — genuinely quick) get a plain one-liner.
#
# Filament page only: optimize:clear + fpm cycle.
set -e

python3 <<'PY'
import io

p = 'app/Filament/Pages/Distributors.php'
s = io.open(p, encoding='utf-8').read()

old = """            Action::make('test')->label('Test connection')->color('gray')->action('testConnection'),"""
assert s.count(old) == 1, 'D1 test action anchor'
s = s.replace(old, """            // MARKER-TEST-CONNECTION-COPY — a 30s wait with no explanation reads
            // as a hung button. Confirming first turns it into an informed
            // choice, and matches runFull below.
            Action::make('test')->label('Test connection')->color('gray')
                ->requiresConfirmation()
                ->modalHeading(fn () => 'Test ' . strtoupper($this->currentCode()) . ' connection')
                ->modalDescription(function () {
                    if (strtoupper($this->currentCode()) === 'BTI') {
                        return 'BTI has no status endpoint. The only authenticated URL rebuilds '
                             . 'their entire stock feed on every request, so this takes around 30 '
                             . 'seconds — roughly 25 of those are BTI generating the file before '
                             . 'it sends anything. Nothing is wrong; the button will sit spinning '
                             . 'until they answer.';
                    }

                    return 'Sends one authenticated request to ' . strtoupper($this->currentCode())
                         . ' to confirm the stored credentials work. Takes a second or two.';
                })
                ->modalSubmitActionLabel('Run test')
                ->action('testConnection'),""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- the action now reads ---"
sed -n "/MARKER-TEST-CONNECTION-COPY/,/->action('testConnection')/p" app/Filament/Pages/Distributors.php

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Filament/Pages/Distributors.php', encoding='utf-8').read()
i, n, d, par = 0, len(s), 0, 0
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
        i += 1
print('braces', d, 'parens', par)
PY

echo
echo "apply-test-connection-honesty: OK"
