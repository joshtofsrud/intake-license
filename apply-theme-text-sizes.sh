#!/bin/bash
# theme-text-sizes — adds text sizing to the master admin Theme Editor.
#   The editor today handles colors only (hex + rgba). This adds six size
#   tokens and points the matching CSS rules at them:
#     --ia-fs-tile-label    11.5px  dashboard status tile label
#     --ia-fs-tile-count    32px    dashboard status tile number
#     --ia-fs-tile-desc     12.5px  dashboard status tile description
#     --ia-fs-cal-appt      11px    calendar appointment block
#     --ia-fs-cal-appt-sub  10px    calendar block service/time lines
#     --ia-fs-table         13px    list table rows
#   Every rule keeps its old value as the var() fallback, so if the token is
#   never set the CSS renders exactly as it does today. No migration: the
#   service's firstOrCreate makes the rows on first publish.
#
#   Sizes are NOT per-theme. The schema is (theme, token_key), so a naive
#   port would put the same field under both the light and dark tabs and
#   invite them drifting apart. Instead there is one "Text sizes" tab bound
#   to $data['size'], and publish() fans each value out to BOTH 'b' and 'c'
#   so the emitted style block covers whichever theme the user is on.
#
#   Not included: the register. Its styles are inline in the blade, not in
#   public/css/tenant, so it needs its own pass.
# NO MIGRATION. Server: optimize:clear (+ hard refresh, the CSS files change).
set -e
if grep -q "MARKER-THEME-TEXT-SIZE" app/Filament/Pages/ThemeEditor.php; then
  echo "theme-text-sizes already applied — aborting."; exit 1
fi

# ---------------------------------------------------------------- CSS wiring
python3 - <<'TTS_0_EOF'
import io

edits = [
    ('public/css/tenant/dashboard-tiles.css',
     ".ia-dash-tile-label {\n  font-size: 11.5px;",
     ".ia-dash-tile-label {\n  font-size: var(--ia-fs-tile-label, 11.5px); /* MARKER-THEME-TEXT-SIZE */"),

    ('public/css/tenant/dashboard-tiles.css',
     ".ia-dash-tile-count {\n  font-size: 32px;",
     ".ia-dash-tile-count {\n  font-size: var(--ia-fs-tile-count, 32px); /* MARKER-THEME-TEXT-SIZE */"),

    ('public/css/tenant/dashboard-tiles.css',
     ".ia-dash-tile-desc {\n  font-size: 12.5px;",
     ".ia-dash-tile-desc {\n  font-size: var(--ia-fs-tile-desc, 12.5px); /* MARKER-THEME-TEXT-SIZE */"),

    ('public/css/tenant/calendar.css',
     "  padding: 5px 8px;\n  font-size: 11px;",
     "  padding: 5px 8px;\n  font-size: var(--ia-fs-cal-appt, 11px); /* MARKER-THEME-TEXT-SIZE */"),

    ('public/css/tenant/calendar.css',
     ".ia-cal-appt-svc {\n  color: var(--ia-text-muted, #888);\n  font-size: 10px;",
     ".ia-cal-appt-svc {\n  color: var(--ia-text-muted, #888);\n  font-size: var(--ia-fs-cal-appt-sub, 10px); /* MARKER-THEME-TEXT-SIZE */"),

    ('public/css/tenant/calendar.css',
     ".ia-cal-appt-time {\n  color: var(--ia-text-muted, #888);\n  font-size: 10px;",
     ".ia-cal-appt-time {\n  color: var(--ia-text-muted, #888);\n  font-size: var(--ia-fs-cal-appt-sub, 10px); /* MARKER-THEME-TEXT-SIZE */"),

    ('public/css/tenant/base.css',
     ".ia-table { width: 100%; border-collapse: collapse; font-size: 13px; }",
     ".ia-table { width: 100%; border-collapse: collapse; font-size: var(--ia-fs-table, 13px); } /* MARKER-THEME-TEXT-SIZE */"),
]

for path, old, new in edits:
    s = io.open(path, encoding='utf-8').read()
    assert s.count(old) == 1, (path, old[:40], s.count(old))
    io.open(path, 'w', encoding='utf-8').write(s.replace(old, new))
    print('css ok:', path, '->', new.split('var(')[1].split(',')[0])
TTS_0_EOF

# ---------------------------------------------------------------- editor
python3 - <<'TTS_1_EOF'
import io
p = 'app/Filament/Pages/ThemeEditor.php'
s = io.open(p, encoding='utf-8').read()

# --- size token definitions, after the GROUPS const -----------------------
old = """    /**
     * HEX_TOKENS: which token keys use #xxxxxx values (vs rgba())."""
assert s.count(old) == 1
new = """    /**
     * MARKER-THEME-TEXT-SIZE
     *
     * SIZE_TOKENS: text sizing, deliberately NOT split per theme. The
     * storage schema is (theme, token_key), so these are written to both
     * 'b' and 'c' with the same value on publish \u2014 one field, two rows.
     * Values are px strings; each CSS rule keeps its original number as
     * the var() fallback, so an unset token changes nothing.
     */
    public const SIZE_TOKENS = [
        'Status tiles' => [
            'ia-fs-tile-label' => ['Tile label', '11.5px'],
            'ia-fs-tile-count' => ['Tile number', '32px'],
            'ia-fs-tile-desc'  => ['Tile description', '12.5px'],
        ],
        'Calendar' => [
            'ia-fs-cal-appt'     => ['Appointment block', '11px'],
            'ia-fs-cal-appt-sub' => ['Block service / time line', '10px'],
        ],
        'Tables' => [
            'ia-fs-table' => ['List table rows', '13px'],
        ],
    ];

    /** Flat key => default map, for prefilling the form. */
    public static function sizeDefaults(): array
    {
        $out = [];
        foreach (self::SIZE_TOKENS as $tokens) {
            foreach ($tokens as $key => [$label, $default]) {
                $out[$key] = $default;
            }
        }
        return $out;
    }

    /**
     * HEX_TOKENS: which token keys use #xxxxxx values (vs rgba())."""
s = s.replace(old, new)

# --- mount(): seed the size tab ------------------------------------------
old = """            $this->data = ['b' => [], 'c' => []];
            return;"""
assert s.count(old) == 1
new = """            $this->data = ['b' => [], 'c' => [], 'size' => self::sizeDefaults()];
            return;"""
s = s.replace(old, new)

old = """        $this->data = $byTheme;
        $this->form->fill($this->data);"""
assert s.count(old) == 1
new = """        // MARKER-THEME-TEXT-SIZE \u2014 sizes live under both themes but are
        // edited once; read 'b' as the source of truth, fall back to the
        // hardcoded CSS value so the field is never blank.
        $sizes = [];
        foreach (self::sizeDefaults() as $key => $default) {
            $sizes[$key] = $byTheme['b'][$key] ?? $default;
        }
        $byTheme['size'] = $sizes;

        $this->data = $byTheme;
        $this->form->fill($this->data);"""
s = s.replace(old, new)

# --- form(): third tab ----------------------------------------------------
old = """                        Tabs\\Tab::make('Dark theme (c)')
                            ->icon('heroicon-o-moon')
                            ->schema($this->themeFields('c')),"""
assert s.count(old) == 1
new = old + """

                        // MARKER-THEME-TEXT-SIZE \u2014 applies to both themes.
                        Tabs\\Tab::make('Text sizes')
                            ->icon('heroicon-o-language')
                            ->schema($this->sizeFields()),"""
s = s.replace(old, new)

# --- sizeFields() ---------------------------------------------------------
old = """    public function publish(): void
    {"""
assert s.count(old) == 1
new = """    /**
     * MARKER-THEME-TEXT-SIZE \u2014 one set of size fields, applied to both
     * themes. Values must be a plain px length; anything else is rejected
     * before it can reach the emitted <style> block.
     */
    private function sizeFields(): array
    {
        $sections = [];
        foreach (self::SIZE_TOKENS as $groupName => $tokens) {
            $fields = [];
            foreach ($tokens as $key => [$label, $default]) {
                $fields[] = TextInput::make(\"size.{$key}\")
                    ->label($label)
                    ->helperText(\"--{$key} \u00b7 default {$default}\")
                    ->placeholder($default)
                    ->required()
                    ->maxLength(12)
                    ->rule('regex:/^\\\\d{1,3}(\\\\.\\\\d)?px$/')
                    ->validationMessages([
                        'regex' => 'Use a px value, e.g. 14px or 12.5px.',
                    ])
                    ->live(debounce: 250);
            }
            $sections[] = Section::make($groupName)
                ->columns(2)
                ->schema($fields)
                ->collapsible();
        }
        return $sections;
    }

    public function publish(): void
    {"""
s = s.replace(old, new)

# --- publish(): fan sizes into both themes -------------------------------
old = """        $service = app(ThemeSettingsService::class);
        $userId = auth()->id();
        $changes = 0;

        foreach (['b', 'c'] as $theme) {"""
assert s.count(old) == 1
new = """        $service = app(ThemeSettingsService::class);
        $userId = auth()->id();
        $changes = 0;

        // MARKER-THEME-TEXT-SIZE \u2014 copy the single size tab into both
        // themes before the normal write loop picks the data up. Doing it
        // here (rather than a separate loop) means dirty-count, audit rows
        // and publish all treat sizes exactly like any other token.
        foreach (($this->data['size'] ?? []) as $key => $value) {
            $this->data['b'][$key] = $value;
            $this->data['c'][$key] = $value;
        }

        foreach (['b', 'c'] as $theme) {"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('ThemeEditor ok')
TTS_1_EOF

php -l app/Filament/Pages/ThemeEditor.php

echo
echo "theme-text-sizes applied."
