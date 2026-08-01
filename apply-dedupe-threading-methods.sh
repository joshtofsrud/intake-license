#!/usr/bin/env bash
# apply-dedupe-threading-methods.sh
# MARKER-TXN-DEDUPE — repair a double-run of apply-transactional-threading.sh.
#
# That patch anchored on emailFromAddress()'s body and INSERTED after it.
# Inserting doesn't alter the anchor, so a second run matched again and
# appended a second copy instead of failing. My mistake: an insert-after
# anchor has to include something the insert itself changes, or the patch
# isn't safe to re-run.
#
# Replaying the double-run against a clean tree shows exactly what happens.
# The script writes Tenant.php, then InboxService.php, then EmailService.php
# — and the second run aborts at the E2 assert, because that anchor WAS
# consumed by the first run. So:
#
#   app/Models/Tenant.php            -> inboundAddress()          x2
#   app/Services/Tenant/InboxService -> recordTransactionalEmail() x2
#   app/Services/EmailService.php    -> untouched by run 2 (correct)
#   settings/index.blade.php         -> never reached (correct)
#
# Only the two duplicates need removing. Everything run 1 did is intact and
# must be left alone.
#
# The two copies are verified byte-identical before anything is deleted, so
# it cannot matter which one goes.
#
# Model + service: optimize:clear + fpm cycle.
set -e

python3 <<'PY'
import io, sys

def block_bounds(s, sig_idx):
    """Span of the whole method: its docblock line through its closing brace."""
    # Back up to the docblock that immediately precedes the signature.
    doc = s.rfind('/**', 0, sig_idx)
    assert doc != -1, 'no docblock found before the duplicate'
    doc_end = s.index('*/', doc) + 2
    assert s[doc_end:sig_idx].strip() == '', 'unexpected code between docblock and signature'

    # Include the docblock's leading indentation.
    start = s.rfind('\n', 0, doc) + 1

    # Brace-match forward from the signature, skipping strings and comments.
    i = s.index('{', sig_idx)
    depth, n = 0, len(s)
    while i < n:
        c = s[i]
        if c == '/' and i + 1 < n and s[i+1] == '/':
            while i < n and s[i] != '\n':
                i += 1
            continue
        if c == '/' and i + 1 < n and s[i+1] == '*':
            i += 2
            while i + 1 < n and not (s[i] == '*' and s[i+1] == '/'):
                i += 1
            i += 2
            continue
        if c in '"\'':
            q = c
            i += 1
            while i < n and s[i] != q:
                if s[i] == '\\':
                    i += 1
                i += 1
            i += 1
            continue
        if c == '{':
            depth += 1
        elif c == '}':
            depth -= 1
            if depth == 0:
                i += 1
                break
        i += 1

    assert depth == 0, 'could not brace-match the duplicate method'

    # Swallow the newline and one following blank line.
    end = i
    while end < n and s[end] in ' \t':
        end += 1
    if end < n and s[end] == '\n':
        end += 1
    if s[end:end+1] == '\n':
        end += 1

    return start, end


def dedupe(path, signature):
    s = io.open(path, encoding='utf-8').read()
    count = s.count(signature)

    if count < 2:
        print('  %s — %d copy, nothing to do' % (path, count))
        return

    first_idx  = s.index(signature)
    second_idx = s.index(signature, first_idx + 1)

    a_start, a_end = block_bounds(s, first_idx)
    b_start, b_end = block_bounds(s, second_idx)

    a, b = s[a_start:a_end], s[b_start:b_end]
    if a.strip() != b.strip():
        print('  %s — copies DIFFER, refusing to guess. Resolve by hand.' % path)
        sys.exit(1)

    s = s[:b_start] + s[b_end:]
    io.open(path, 'w', encoding='utf-8').write(s)
    print('  %s — removed %d duplicate(s), %d bytes' % (path, count - 1, b_end - b_start))

    remaining = io.open(path, encoding='utf-8').read().count(signature)
    assert remaining == 1, '%s still has %d copies' % (path, remaining)


print('deduping:')
dedupe('app/Models/Tenant.php', 'public function inboundAddress(): ?string')
dedupe('app/Services/Tenant/InboxService.php', 'public function recordTransactionalEmail(')
PY

echo
echo "--- one of each, and run 1's work still present ---"
echo "inboundAddress:            $(grep -c 'public function inboundAddress' app/Models/Tenant.php)"
echo "recordTransactionalEmail:  $(grep -c 'public function recordTransactionalEmail' app/Services/Tenant/InboxService.php)"
echo "threadedReplyTo:           $(grep -c 'private function threadedReplyTo' app/Services/EmailService.php)"
echo "settings field:            $(grep -c 'Your inbox email address' resources/views/tenant/settings/index.blade.php)"

echo
echo "--- balance ---"
python3 - <<'PY'
import io
def bal(p):
    s = io.open(p, encoding='utf-8').read()
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
    return d, par
for f in ['app/Models/Tenant.php', 'app/Services/Tenant/InboxService.php']:
    print(f, bal(f))
PY

echo
echo "apply-dedupe-threading-methods: OK"
