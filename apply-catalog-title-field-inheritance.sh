#!/bin/bash
# catalog-title-field-inheritance — a category rule overrides only what it sets.
#
#   Symptom: recomposed items showed a good title and then just the MPN,
#   while untouched items kept their full descriptor line.
#
#   Cause: setting() picked ONE winning row and used it whole. The review
#   page writes a rule with title_template only, so subtitle_template came
#   back null and fell through to the '{mpn}' constant.
#
#   The quiet half is worse. search_template has no constant fallback —
#   compose() renders (string) ($setting->search_template ?? ''), so the
#   SEARCH BLOB WAS EMPTIED for every item in a category anyone edited.
#   Shop search relevance runs on that blob, so those items were quietly
#   getting harder to find while the titles looked better.
#
#   Fix is structural rather than a repair script: the ladder now resolves
#   FIELD BY FIELD. A rule for "Tires > Mountain Tires" that sets only a
#   title inherits subtitle, search, color and size priority from the HLC
#   catch-all, exactly as you'd expect an override to behave. Rules already
#   written this way start working correctly on the next recompose — nothing
#   to back-fill.
#
#   matchedSetting() is unchanged and still answers "which row owns this
#   scope", which is what the editor's inherited-from label needs.
# NO MIGRATION. Server: optimize:clear, then RECOMPOSE to restore the blobs:
#   php artisan distributor:recompose HLC && php artisan inventory:sync-titles
set -e
if grep -q "MARKER-FIELD-INHERITANCE" app/Services/Distributors/CatalogTitleComposer.php; then
  echo "catalog-title-field-inheritance already applied — aborting."; exit 1
fi

python3 - <<'CTFI_0_EOF'
import io
p = 'app/Services/Distributors/CatalogTitleComposer.php'
s = io.open(p, encoding='utf-8').read()

old = """    private function setting(string $code, string $categoryPath = ''): CatalogTitleSetting
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
assert s.count(old) == 1, s.count(old)

new = """    /**
     * MARKER-FIELD-INHERITANCE \u2014 resolve each field up the ladder on its own.
     *
     * A rule that sets only a title used to discard its parent's subtitle,
     * search blob and colour priority, because the first matching ROW won
     * outright. The review page writes title-only rules, so editing a
     * category silently replaced its descriptor with '{mpn}' and emptied
     * its search blob \u2014 search_template has no constant fallback.
     *
     * Now the most specific rule that actually SETS a field wins that field,
     * and everything it leaves blank keeps coming from the parent. That is
     * what "override" has to mean for partial rules to be safe to write.
     */
    private function setting(string $code, string $categoryPath = ''): CatalogTitleSetting
    {
        $cacheKey = $code . '|' . $categoryPath;
        if (isset($this->settingCache[$cacheKey])) {
            return $this->settingCache[$cacheKey];
        }

        $rows = $this->settingRows();

        $strings = ['title_template' => null, 'subtitle_template' => null, 'search_template' => null];
        $arrays  = ['color_attribute_priority' => null, 'size_attribute_priority' => null];

        // Walk the whole ladder \u2014 no early break. Each field is filled by the
        // first rule that has a value for it, so specificity is per field.
        foreach ([$code, '*'] as $dist) {
            foreach ($this->categoryCandidates($categoryPath) as $cand) {
                foreach ($rows as $row) {
                    if ($row->distributor_code !== $dist
                        || $this->normKey((string) $row->category_key) !== $this->normKey($cand)) {
                        continue;
                    }
                    foreach (array_keys($strings) as $f) {
                        if ($strings[$f] === null && trim((string) $row->$f) !== '') {
                            $strings[$f] = (string) $row->$f;
                        }
                    }
                    foreach (array_keys($arrays) as $f) {
                        if ($arrays[$f] === null && ! empty($row->$f)) {
                            $arrays[$f] = $row->$f;
                        }
                    }
                }
            }
        }

        return $this->settingCache[$cacheKey] = new CatalogTitleSetting([
            'title_template'           => $strings['title_template']    ?? self::FALLBACK_TITLE,
            'subtitle_template'        => $strings['subtitle_template'] ?? self::FALLBACK_SUBTITLE,
            // No constant for search: an empty blob is a legitimate choice at
            // the top of the ladder, it just must not be an ACCIDENT of a
            // partial rule below it.
            'search_template'          => $strings['search_template']   ?? '',
            'color_attribute_priority' => $arrays['color_attribute_priority'] ?? self::FALLBACK_COLOR_PRIORITY,
            'size_attribute_priority'  => $arrays['size_attribute_priority']  ?? [],
        ]);
    }"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('field inheritance ok')
CTFI_0_EOF

php -l app/Services/Distributors/CatalogTitleComposer.php

echo
echo "catalog-title-field-inheritance applied."
