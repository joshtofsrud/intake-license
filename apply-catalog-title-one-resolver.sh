#!/bin/bash
# catalog-title-one-resolver — one rule ladder, used everywhere.
#   HLC · "Tires > Mountain Tires" showed the catch-all template and
#   "Currently inherited" with no rule named, even though an HLC · Tires rule
#   exists and should win on prefix.
#
#   Cause: three copies of the same resolution.
#     · CatalogTitleComposer::setting() walks candidates with normKey()
#     · ScanCatalogTitleScopes::resolveRule() walked them with exact string
#       equality, so it could miss where the composer matched, writing a null
#       resolved_rule_scope
#     · CatalogTitleReview::inheritedTemplate() then handed that null back to
#       the composer as the category, which is a request for the catch-all
#
#   So a miss in the scan silently became "no rule" in the editor, and the
#   editor's answer disagreed with what recompose would actually do.
#
#   Now the composer exposes matchedSetting() — the one place the ladder
#   lives — and the scan and the page both call it with the scope's real
#   category path. The drawer also names the rule it inherits from, so this
#   class of disagreement is visible instead of silent.
# NO MIGRATION. Server: optimize:clear. Then re-run: php artisan catalog:scan-titles HLC
set -e
if grep -q "MARKER-ONE-RESOLVER" app/Services/Distributors/CatalogTitleComposer.php; then
  echo "catalog-title-one-resolver already applied — aborting."; exit 1
fi

# ------------------------------------------------------- composer: expose it
python3 - <<'COR_0_EOF'
import io
p = 'app/Services/Distributors/CatalogTitleComposer.php'
s = io.open(p, encoding='utf-8').read()

old = """    private function setting(string $code, string $categoryPath = ''): CatalogTitleSetting
    {
        $cacheKey = $code . '|' . $categoryPath;
        if (isset($this->settingCache[$cacheKey])) {
            return $this->settingCache[$cacheKey];
        }

        $rows = $this->settingRows();
        $found = null;

        foreach ([$code, '*'] as $dist) {
            foreach ($this->categoryCandidates($categoryPath) as $cand) {
                foreach ($rows as $row) {
                    if ($row->distributor_code === $dist
                        && $this->normKey((string) $row->category_key) === $this->normKey($cand)) {
                        $found = $row;
                        break 3;
                    }
                }
            }
        }

        return $this->settingCache[$cacheKey] = $found ?? new CatalogTitleSetting([
            'title_template' => self::FALLBACK_TITLE,
            'subtitle_template' => self::FALLBACK_SUBTITLE,
            'color_attribute_priority' => self::FALLBACK_COLOR_PRIORITY,
        ]);
    }"""
assert s.count(old) == 1, s.count(old)

new = """    /**
     * MARKER-ONE-RESOLVER \u2014 THE rule ladder. Anything that needs to know
     * which rule applies to a category calls this; nothing re-implements it.
     * Returns the matched row, or null when only the built-in fallback
     * applies. Callers that want a usable object should use setting().
     */
    public function matchedSetting(string $code, string $categoryPath = ''): ?CatalogTitleSetting
    {
        $rows = $this->settingRows();

        foreach ([$code, '*'] as $dist) {
            foreach ($this->categoryCandidates($categoryPath) as $cand) {
                foreach ($rows as $row) {
                    if ($row->distributor_code === $dist
                        && $this->normKey((string) $row->category_key) === $this->normKey($cand)) {
                        return $row;
                    }
                }
            }
        }
        return null;
    }

    private function setting(string $code, string $categoryPath = ''): CatalogTitleSetting
    {
        $cacheKey = $code . '|' . $categoryPath;
        if (isset($this->settingCache[$cacheKey])) {
            return $this->settingCache[$cacheKey];
        }

        $found = $this->matchedSetting($code, $categoryPath);

        return $this->settingCache[$cacheKey] = $found ?? new CatalogTitleSetting([
            'title_template' => self::FALLBACK_TITLE,
            'subtitle_template' => self::FALLBACK_SUBTITLE,
            'color_attribute_priority' => self::FALLBACK_COLOR_PRIORITY,
        ]);
    }"""
s = s.replace(old, new)

# titleTemplateFor should take a real path, not an already-resolved scope
old = """    public function titleTemplateFor(string $distributorCode, string $categoryKey = ''): string
    {
        return $this->setting($distributorCode, $categoryKey)->title_template
            ?: self::FALLBACK_TITLE;
    }"""
assert s.count(old) == 1
new = """    /**
     * MARKER-ONE-RESOLVER \u2014 pass the item's FULL category path here, not a
     * pre-resolved scope. The ladder is this method's job.
     */
    public function titleTemplateFor(string $distributorCode, string $categoryPath = ''): string
    {
        return $this->setting($distributorCode, $categoryPath)->title_template
            ?: self::FALLBACK_TITLE;
    }"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('composer resolver ok')
COR_0_EOF

# ------------------------------------------------------- scan: use it
python3 - <<'COR_1_EOF'
import io
p = 'app/Console/Commands/ScanCatalogTitleScopes.php'
s = io.open(p, encoding='utf-8').read()

start = s.index("""    /**
     * Which rule row wins for this category, using the same prefix ladder the
     * composer uses.""")
end = s.rindex('}')
head = s[:start]

new_tail = """    /**
     * MARKER-ONE-RESOLVER \u2014 delegates to the composer instead of walking
     * the candidate ladder again. The previous local copy compared category
     * keys with exact string equality while the composer normalises them,
     * so a scope could resolve to "no rule" here and to a real rule at
     * render time \u2014 which is exactly what made the editor show the
     * catch-all for HLC \u00b7 Tires > Mountain Tires.
     *
     * @return array{0:?string,1:bool} [matched category_key or null, is it this scope's own rule]
     */
    private function resolveRule(string $dist, string $cat): array
    {
        $row = app(\\App\\Services\\Distributors\\CatalogTitleComposer::class)
            ->matchedSetting($dist, $cat);

        if (! $row) {
            return [null, false];
        }

        $key = (string) $row->category_key;

        return [
            $key === '' ? null : $key,
            $key !== '' && $key === $cat && $row->distributor_code === $dist,
        ];
    }
}
"""
io.open(p, 'w', encoding='utf-8').write(head + new_tail)
print('scan resolver ok')
COR_1_EOF

# ------------------------------------------------------- page: use the real path
python3 - <<'COR_2_EOF'
import io
p = 'app/Filament/Pages/CatalogTitleReview.php'
s = io.open(p, encoding='utf-8').read()

old = """    private function inheritedTemplate(CatalogTitleScope $scope): string
    {
        return app(CatalogTitleComposer::class)
            ->titleTemplateFor($scope->distributor_code, $scope->resolved_rule_scope ?? '');
    }"""
assert s.count(old) == 1, s.count(old)
new = """    /**
     * MARKER-ONE-RESOLVER \u2014 pass the scope's OWN category path and let the
     * composer walk the ladder. Passing resolved_rule_scope meant a null
     * from the scan turned into a request for the catch-all, so the editor
     * showed a template that recompose would never use.
     */
    private function inheritedTemplate(CatalogTitleScope $scope): string
    {
        return app(CatalogTitleComposer::class)
            ->titleTemplateFor($scope->distributor_code, $scope->category_key);
    }

    /** Which rule the editor is actually showing, for the drawer label. */
    public function getInheritedFromProperty(): ?string
    {
        $scope = $this->editing;
        if (! $scope) return null;

        $row = app(CatalogTitleComposer::class)
            ->matchedSetting($scope->distributor_code, $scope->category_key);

        if (! $row) return 'built-in fallback';

        return $row->distributor_code . ' \u00b7 '
            . ($row->category_key !== '' ? $row->category_key : 'any category');
    }"""
s = s.replace(old, new)

old = """        $this->tpl = $rule?->title_template ?? $this->inheritedTemplate($scope);"""
assert s.count(old) == 1
new = """        // MARKER-ONE-RESOLVER \u2014 own rule if there is one, otherwise whatever
        // the ladder actually resolves for this category path.
        $this->tpl = $rule?->title_template ?? $this->inheritedTemplate($scope);"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('page resolver ok')
COR_2_EOF

# ------------------------------------------------------- drawer: name the rule
python3 - <<'COR_3_EOF'
import io
p = 'resources/views/filament/pages/catalog-title-review.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """                    @if (! $sc->has_own_rule)
                        <p class="text-[11px] text-gray-400 mt-1.5">
                            Currently inherited. Saving creates a rule for this category only.
                        </p>
                    @endif"""
assert s.count(old) == 1, s.count(old)
new = """                    @if (! $sc->has_own_rule)
                        {{-- MARKER-ONE-RESOLVER — name the rule being inherited, so a
                             resolution problem is visible instead of silent. --}}
                        <p class="text-[11px] text-gray-400 mt-1.5">
                            Inherited from <b>{{ $this->inheritedFrom }}</b>.
                            Saving creates a rule for this category only.
                        </p>
                    @endif"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('drawer label ok')
COR_3_EOF

php -l app/Services/Distributors/CatalogTitleComposer.php
php -l app/Console/Commands/ScanCatalogTitleScopes.php
php -l app/Filament/Pages/CatalogTitleReview.php

echo
echo "catalog-title-one-resolver applied."
