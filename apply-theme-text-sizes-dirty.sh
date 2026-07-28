#!/bin/bash
# theme-text-sizes-dirty — makes the Theme Editor's "unpublished changes"
# counter notice text-size edits.
#   getDirtyCountProperty() only walked $data['b'] and $data['c']. Size
#   values live in $data['size'] and are not fanned out into the themes
#   until publish() runs, so editing one moved nothing on screen even
#   though publishing worked correctly.
#   Two fixes in that method:
#     · count each size key ONCE (it is one field, not one per theme)
#     · when a size token has never been published there is no row to
#       compare against, so fall back to the hardcoded CSS default from
#       sizeDefaults() — otherwise the very first edit to a fresh install
#       still counts as clean, which is the exact bug being reported
#   The b/c walk now skips size keys, so a publish (which copies them into
#   both themes) can't double-count them afterwards.
# NO MIGRATION. Server: optimize:clear.
set -e
if grep -q "MARKER-THEME-TEXT-SIZE-DIRTY" app/Filament/Pages/ThemeEditor.php; then
  echo "theme-text-sizes-dirty already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-THEME-TEXT-SIZE" app/Filament/Pages/ThemeEditor.php; then
  echo "theme-text-sizes must be applied first — aborting."; exit 1
fi

python3 - <<'TTSD_0_EOF'
import io
p = 'app/Filament/Pages/ThemeEditor.php'
s = io.open(p, encoding='utf-8').read()

old = """        foreach (['b', 'c'] as $theme) {
            $values = $this->data[$theme] ?? [];
            foreach ($values as $key => $value) {
                $pub = $published[$theme][$key] ?? null;
                if ($pub !== null && (string) $pub !== (string) $value) {
                    $count++;
                }
            }
        }
        return $count;
    }"""
assert s.count(old) == 1, s.count(old)

new = """        // MARKER-THEME-TEXT-SIZE-DIRTY \u2014 size tokens are counted below,
        // once each. Skipping them here matters after a publish, which
        // copies them into both themes and would otherwise double-count.
        $sizeDefaults = self::sizeDefaults();

        foreach (['b', 'c'] as $theme) {
            $values = $this->data[$theme] ?? [];
            foreach ($values as $key => $value) {
                if (array_key_exists($key, $sizeDefaults)) {
                    continue;
                }
                $pub = $published[$theme][$key] ?? null;
                if ($pub !== null && (string) $pub !== (string) $value) {
                    $count++;
                }
            }
        }

        // MARKER-THEME-TEXT-SIZE-DIRTY \u2014 one field, one count. A token
        // that has never been published has no row, so the CSS default is
        // the thing being changed away from; without this the first edit
        // on a fresh install reads as no change at all.
        foreach ($sizeDefaults as $key => $default) {
            $value = $this->data['size'][$key] ?? null;
            if ($value === null) {
                continue;
            }
            $pub = $published['b'][$key] ?? $default;
            if ((string) $pub !== (string) $value) {
                $count++;
            }
        }

        return $count;
    }"""

io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('getDirtyCountProperty ok')
TTSD_0_EOF

php -l app/Filament/Pages/ThemeEditor.php

echo
echo "theme-text-sizes-dirty applied."
