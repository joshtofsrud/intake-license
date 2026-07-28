#!/bin/bash
# catalog-title-drop-size-field — removes "Size comes from" from the drawer.
#
#   {size} exists to be a category-agnostic size token, fed either by a named
#   attribute or by scraping the description. Now that rules are per-category,
#   that indirection earns nothing: {attr:Labeled Size} states the same thing
#   in the template where you can see it, instead of in a second box whose
#   effect is invisible until you read the output.
#
#   What this does NOT do is drop the column or clear stored values. The
#   seeded HLC · Tires rule uses {size} together with a stored
#   size_attribute_priority, and that keeps working exactly as it does today.
#   save() previously wrote this field on every save, so removing the input
#   without changing save() would have nulled that stored value the first
#   time anyone edited that rule — the field is gone from the form AND from
#   the write, so existing values are left untouched.
#
#   Still using {size}: the subtitle and search-blob templates on the Title
#   templates page. Those accept {attr:NAME} too, so the same swap works
#   there when you get to them.
# NO MIGRATION. Server: optimize:clear.
set -e
if grep -q "MARKER-DROP-SIZE-FIELD" app/Filament/Pages/CatalogTitleReview.php; then
  echo "catalog-title-drop-size-field already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------- page
python3 - <<'CTDS_0_EOF'
import io
p = 'app/Filament/Pages/CatalogTitleReview.php'
s = io.open(p, encoding='utf-8').read()

# save(): stop writing the field, and say why
old = """        $sizes = array_values(array_filter(array_map('trim', explode(',', $this->sizeAttr))));

        CatalogTitleSetting::updateOrCreate(
            ['distributor_code' => $scope->distributor_code, 'category_key' => $scope->category_key],
            [
                'title_template'          => trim($this->tpl),
                'size_attribute_priority' => $sizes ?: null,
                'is_active'               => true,
            ]
        );"""
assert s.count(old) == 1, s.count(old)
new = """        // MARKER-DROP-SIZE-FIELD \u2014 size_attribute_priority is deliberately
        // NOT written here. The editor no longer exposes it ({attr:Name} in
        // the template does the same job visibly), and writing it from a
        // field that no longer exists would null the stored value on the
        // seeded rules the first time anyone saved them.
        CatalogTitleSetting::updateOrCreate(
            ['distributor_code' => $scope->distributor_code, 'category_key' => $scope->category_key],
            [
                'title_template' => trim($this->tpl),
                'is_active'      => true,
            ]
        );"""
s = s.replace(old, new)

# drop the property
old = """    public string $tpl = '';
    public string $sizeAttr = '';"""
assert s.count(old) == 1
new = """    public string $tpl = '';"""
s = s.replace(old, new)

# edit(): stop populating it
old = """        $this->sizeAttr = implode(', ', $rule?->size_attribute_priority ?? []);
"""
assert s.count(old) == 1
new = ""
s = s.replace(old, new)

# preview: no override, the stored setting stands
old = """        $composer = app(CatalogTitleComposer::class);
        $sizeOverride = array_values(array_filter(array_map('trim', explode(',', $this->sizeAttr))));
"""
assert s.count(old) == 1
new = """        $composer = app(CatalogTitleComposer::class);
"""
s = s.replace(old, new)

old = """                'now'   => $composer->renderTemplate(
                    $scope->distributor_code, $this->tpl, $parts,
                    $sizeOverride ?: null
                ),"""
assert s.count(old) == 1
new = """                // MARKER-DROP-SIZE-FIELD \u2014 no override; {size} resolves from
                // whatever the saved rule already says, and {attr:Name} is
                // the visible way to pick a size.
                'now'   => $composer->renderTemplate(
                    $scope->distributor_code, $this->tpl, $parts
                ),"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('page ok')
CTDS_0_EOF

# ------------------------------------------------------------------- view
python3 - <<'CTDS_1_EOF'
import io
p = 'resources/views/filament/pages/catalog-title-review.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """                <div>
                    <label class="block text-xs font-semibold mb-1.5">Size comes from</label>
                    <input wire:model.live.debounce.250ms="sizeAttr" placeholder="Labeled Size"
                        class="w-full font-mono text-xs rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2.5">
                    <p class="text-[11px] text-gray-400 mt-1.5">
                        Attribute names, comma separated, tried in order before any text matching.
                    </p>
                </div>

"""
assert s.count(old) == 1, s.count(old)
s = s.replace(old, "")

old = """                        <span wire:loading.delay wire:target="tpl,sizeAttr,addToken\""""
assert s.count(old) == 1
new = """                        <span wire:loading.delay wire:target="tpl,addToken\""""
s = s.replace(old, new)

old = """                         wire:loading.class="opacity-50" wire:target="tpl,sizeAttr,addToken\""""
assert s.count(old) == 1
new = """                         wire:loading.class="opacity-50" wire:target="tpl,addToken\""""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('view ok')
CTDS_1_EOF

php -l app/Filament/Pages/CatalogTitleReview.php

echo
echo "catalog-title-drop-size-field applied."
