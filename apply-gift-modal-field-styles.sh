#!/bin/bash
# apply-gift-modal-field-styles.sh
#
# MARKER-GC-FIELDSTYLE — the register modal CSS only styled
# input[type=text]; the gift card sell modal's recipient email
# (type=email) and gift-message textarea rendered as unstyled white
# natives. Extend the two selectors to cover email + textarea.
set -e

IDX="resources/views/tenant/register/index.blade.php"

if grep -q "MARKER-GC-FIELDSTYLE" "$IDX" 2>/dev/null; then
  echo "ok: already applied — no-op"
  exit 0
fi

python3 - <<'PY'
import io
p = 'resources/views/tenant/register/index.blade.php'
src = io.open(p, encoding='utf-8').read()

a = "  .reg-modal input[type=text]{width:100%;padding:10px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text);font-size:14px;font-family:inherit}\n  .reg-modal input[type=text]:focus{outline:none;border-color:var(--ia-accent)}"
assert src.count(a) == 1, 'modal input selector not found'
b = "  /* MARKER-GC-FIELDSTYLE -- email + textarea joined by the gift card modal */\n  .reg-modal input[type=text],.reg-modal input[type=email],.reg-modal textarea{width:100%;padding:10px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);color:var(--ia-text);font-size:14px;font-family:inherit}\n  .reg-modal textarea{resize:vertical;min-height:64px}\n  .reg-modal input[type=text]:focus,.reg-modal input[type=email]:focus,.reg-modal textarea:focus{outline:none;border-color:var(--ia-accent)}"
src = src.replace(a, b, 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: modal field selectors extended')
PY

echo "== done. Post-deploy: php artisan optimize:clear (view cache) =="
