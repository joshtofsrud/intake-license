#!/usr/bin/env bash
# apply-qbp-probe-method-clash.sh
# MARKER-QBP-PROBE-CLASH — private call() cannot override Command::call().
#
#   Access level to App\Console\Commands\QbpProbe::call() must be public
#   (as in class Illuminate\Console\Command)
#
# Illuminate\Console\Command already has a public call() — it runs another
# artisan command. Declaring a private one of the same name is a fatal at
# class-load time, not a runtime error, so it took the whole console down
# during migrations rather than failing when the probe ran.
#
# Renamed to probeGet(), which also says what it does. Nothing else changes.
#
# Worth naming the gap: brace and paren checks cannot see this, and there is
# no php binary in my container to lint with, so a signature clash with a
# framework base class gets through every check I have. The deploy caught it
# before the swap, which is the safety net working as intended.
set -e

python3 <<'PY'
import io, re

p = 'app/Console/Commands/QbpProbe.php'
s = io.open(p, encoding='utf-8').read()

assert 'private function call(' in s, 'already renamed, or unexpected state'

# The declaration.
s = s.replace(
    """    /** A 404 here is information, not a failure — never throws. */
    private function call(string $path, string $label, bool $dump = false): mixed""",
    """    /**
     * MARKER-QBP-PROBE-CLASH — NOT named call(). Illuminate\\Console\\Command
     * declares a public call() for invoking other artisan commands, and a
     * private override is a fatal at class load.
     *
     * A 404 here is information, not a failure — this never throws.
     */
    private function probeGet(string $path, string $label, bool $dump = false): mixed""")

# Every call site.
before = s.count('$this->call(')
s = s.replace('$this->call(', '$this->probeGet(')
print('renamed', before, 'call sites')

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- no private override of a Command method remains ---"
python3 - <<'PY'
import io, re
s = io.open('app/Console/Commands/QbpProbe.php', encoding='utf-8').read()

# Public API of Illuminate\Console\Command that a subclass must not privatise.
reserved = {
    'call', 'callSilent', 'callSilently', 'handle', 'run', 'ask', 'anticipate',
    'askWithCompletion', 'secret', 'confirm', 'choice', 'table', 'info', 'line',
    'comment', 'question', 'error', 'warn', 'alert', 'newLine', 'argument',
    'arguments', 'hasArgument', 'option', 'options', 'hasOption', 'output',
    'setHidden', 'isHidden', 'getOutput', 'withProgressBar', 'task', 'components',
}
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
echo "apply-qbp-probe-method-clash: OK"
