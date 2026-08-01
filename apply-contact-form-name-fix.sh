#!/usr/bin/env bash
# apply-contact-form-name-fix.sh
# MARKER-CONTACT-NAME-FIX — every contact form submission from a split-name
# page has been silently lost.
#
# PublicController::contact() assigns $name only in the else branch (the
# legacy combined-name form). MARKER-CONTACT-NAMES then made first_name /
# last_name the norm — and on those pages $name is never assigned, while
# postInbound() still passes 'name' => $name in its metadata.
#
# Order of operations is what makes it ugly:
#
#   firstOrCreate customer   -> row written
#   threadFor()              -> thread written
#   update subject           -> written
#   postInbound(... $name)   -> THROWS (undefined variable)
#   catch (\Throwable)       -> swallowed into a log line
#
# So the customer and the thread land, the MESSAGE never does. That is the
# "blank customers in the inbox with no message" symptom exactly, and the
# feature was specifically built "so nothing is silently lost".
#
# Second, quieter half: the fallback email interpolates
# $request->input('name') four times, which a split-name page does not
# send. Those alerts have been going out with an empty subject name and an
# empty Name line — the only copy of the message, half-anonymised.
#
# View/controller change: optimize:clear + fpm cycle. No migration.
set -e

python3 <<'PY'
import io

p = 'app/Http/Controllers/Tenant/PublicController.php'
s = io.open(p, encoding='utf-8').read()

# ---- 1. define $name on BOTH branches
old = """            if ($usesSplitName) {
                $first = trim((string) $request->input('first_name'));
                $last  = trim((string) $request->input('last_name'));
            } else {"""
assert s.count(old) == 1, 'K1 split-name branch anchor'
s = s.replace(old, """            if ($usesSplitName) {
                $first = trim((string) $request->input('first_name'));
                $last  = trim((string) $request->input('last_name'));
                // MARKER-CONTACT-NAME-FIX — $name is read further down for the
                // message metadata. Leaving it unset here threw an
                // ErrorException that the catch below swallowed, so the
                // customer and thread were written and the message was not.
                $name  = trim($first . ' ' . $last);
            } else {""")

# ---- 2. fallback email must not depend on the legacy combined field
old = """                Mail::raw(
                    \"New contact form submission from {$tenant->name}\\n\\n\"
                    . \"Name: {$request->input('name')}\\n\"
                    . \"Email: {$request->input('email')}\\n\"
                    . \"Phone: {$request->input('phone', '—')}\\n\\n\"
                    . \"Message:\\n{$request->input('message')}\",
                    fn($m) => $m->to($to)->subject(\"New message from {$request->input('name')}\")
                );"""
assert s.count(old) == 1, 'K2 fallback mail anchor'
s = s.replace(old, """                // MARKER-CONTACT-NAME-FIX — a split-name page posts no 'name'
                // field, so these interpolations were blank. Rebuild from
                // whichever pair of fields the page actually sent.
                $senderName = trim((string) $request->input('name'))
                    ?: trim(trim((string) $request->input('first_name')) . ' ' . trim((string) $request->input('last_name')))
                    ?: 'Website visitor';

                Mail::raw(
                    \"New contact form submission from {$tenant->name}\\n\\n\"
                    . \"Name: {$senderName}\\n\"
                    . \"Email: {$request->input('email')}\\n\"
                    . \"Phone: {$request->input('phone', '—')}\\n\\n\"
                    . \"Message:\\n{$request->input('message')}\",
                    fn($m) => $m->to($to)->subject(\"New message from {$senderName}\")
                );""")

# ---- 3. stop swallowing the reason silently
old = """        } catch (\\Throwable $e) {
            logger()->error('Contact form inbox post failed: ' . $e->getMessage());
        }"""
assert s.count(old) == 1, 'K3 catch anchor'
s = s.replace(old, """        } catch (\\Throwable $e) {
            // MARKER-CONTACT-NAME-FIX — this catch is right (a public form must
            // never 500), but a bare message made a capture failure look like
            // noise for weeks. Log enough to tie the log line to the blank
            // thread it produced.
            logger()->error('Contact form inbox post failed: ' . $e->getMessage(), [
                'tenant_id' => $tenant?->id,
                'email'     => $request->input('email'),
                'file'      => $e->getFile() . ':' . $e->getLine(),
            ]);
        }""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- \$name now assigned on both branches ---"
grep -n '\$name\s*=\|\$senderName\s*=\|MARKER-CONTACT-NAME-FIX' app/Http/Controllers/Tenant/PublicController.php

echo
echo "--- braces / parens ---"
python3 - <<'PY'
import io
s = io.open('app/Http/Controllers/Tenant/PublicController.php', encoding='utf-8').read()
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
echo "apply-contact-form-name-fix: OK"
