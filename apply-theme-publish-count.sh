#!/bin/bash
# theme-publish-count — makes the publish toast agree with the banner.
#   A size token is one field but two rows (it is written to both 'b' and
#   'c'), so publishing a single size change flipped two theme_settings
#   rows and the toast said "2 changes published" under a banner that had
#   correctly said 1.
#   The service's return value is a row count and stays that way — it is
#   the right number for the audit trail. The toast now reports the same
#   field count the banner shows, captured BEFORE the fan-out runs, so
#   the two numbers can't drift apart again. Falls back to the row count
#   if the field count comes back 0, so the toast is never blank.
# NO MIGRATION. Server: optimize:clear.
set -e
if grep -q "MARKER-THEME-PUBLISH-COUNT" app/Filament/Pages/ThemeEditor.php; then
  echo "theme-publish-count already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-THEME-TEXT-SIZE-DIRTY" app/Filament/Pages/ThemeEditor.php; then
  echo "theme-text-sizes-dirty must be applied first — aborting."; exit 1
fi

python3 - <<'TPC_0_EOF'
import io
p = 'app/Filament/Pages/ThemeEditor.php'
s = io.open(p, encoding='utf-8').read()

# --- capture the field count before the fan-out mutates $this->data ------
old = """        $service = app(ThemeSettingsService::class);
        $userId = auth()->id();
        $changes = 0;

        // MARKER-THEME-TEXT-SIZE \u2014 copy the single size tab into both"""
assert s.count(old) == 1
new = """        $service = app(ThemeSettingsService::class);
        $userId = auth()->id();
        $changes = 0;

        // MARKER-THEME-PUBLISH-COUNT \u2014 what the banner is showing right
        // now, read before the fan-out below rewrites $this->data. This is
        // a count of FIELDS the user edited; $service->publish() returns a
        // count of ROWS, and a size field is two rows. Reporting the row
        // count made the toast contradict the banner.
        $reported = $this->getDirtyCountProperty();

        // MARKER-THEME-TEXT-SIZE \u2014 copy the single size tab into both"""
s = s.replace(old, new)

# --- report it -----------------------------------------------------------
old = """        $published = $service->publish(null, $userId);

        Notification::make()->success()
            ->title(\"Published {$published} change\" . ($published === 1 ? '' : 's'))"""
assert s.count(old) == 1
new = """        $published = $service->publish(null, $userId);

        // MARKER-THEME-PUBLISH-COUNT \u2014 prefer the field count; fall back
        // to rows if it somehow came back empty so the toast still reads.
        $shown = $reported > 0 ? $reported : $published;

        Notification::make()->success()
            ->title(\"Published {$shown} change\" . ($shown === 1 ? '' : 's'))"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('publish count ok')
TPC_0_EOF

php -l app/Filament/Pages/ThemeEditor.php

echo
echo "theme-publish-count applied."
