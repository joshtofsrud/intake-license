#!/usr/bin/env bash
# apply-drawer-token-chips.sh
# MARKER-DRAWER-TOKENS — the drawer keeps its own, shorter token list.
#
# There are two lists. CatalogTitles::TOKENS documents ten (now twenty), and
# CatalogTitleReview::getBaseTokensProperty() hard-codes eight of its own —
# which is the list you actually see, because the drawer on Catalog Titles is
# where the editing happens. Adding a token to one did nothing for the other.
#
# So the drawer now reads the same constant. One list, one place to add to.
#
# Two are deliberately left out of the clickable row:
#
#   {attr:NAME}  the drawer already offers something better — a chip per
#                attribute actually present on those items, so you click
#                {attr:Casing} rather than pasting a placeholder and editing it
#
#   {a|b|c}      a fallback chain is a shape, not a token; inserting it
#                literally would put nonsense in the template. It gets a line
#                of prose under the chips instead.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- page
p = 'app/Filament/Pages/CatalogTitleReview.php'
s = io.open(p, encoding='utf-8').read()

old = """    public function getBaseTokensProperty(): array
    {
        return ['{brand}', '{model}', '{size}', '{color}', '{unit}', '{type}', '{type0}', '{mpn}'];
    }"""
assert s.count(old) == 1, 'D1 baseTokens anchor'
s = s.replace(old, """    /**
     * MARKER-DRAWER-TOKENS — one list, shared with the Title templates page.
     *
     * This used to hard-code eight, so every token added to CatalogTitles::TOKENS
     * was invisible on the screen where rules are actually written.
     *
     * {attr:NAME} is excluded because the drawer offers something better below:
     * a chip per attribute genuinely present on these items. {a|b|c} is
     * excluded because a fallback chain is a shape rather than a token —
     * inserting it literally would just put nonsense in the template.
     */
    public function getBaseTokensProperty(): array
    {
        return collect(array_keys(\\App\\Filament\\Pages\\CatalogTitles::TOKENS))
            ->reject(fn ($t) => in_array($t, ['{attr:NAME}', '{a|b|c}'], true))
            ->values()->all();
    }""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- view
p = 'resources/views/filament/pages/catalog-title-review.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """                    @if (count($this->attrNames))"""
assert s.count(old) == 1, 'D2 attrNames anchor'
s = s.replace(old, """                    {{-- MARKER-DRAWER-TOKENS — chains have no chip, so say so once. --}}
                    <div class="text-[11px] opacity-60 mb-3 leading-relaxed">
                        Separate tokens with <span class="font-mono">|</span> to try them in order —
                        <span class="font-mono">{size|attr:Labeled Size|size_code}</span> uses the first
                        one that has a value.
                    </div>

                    @if (count($this->attrNames))""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- drawer now reads the shared constant ---"
grep -n "MARKER-DRAWER-TOKENS\|CatalogTitles::TOKENS" app/Filament/Pages/CatalogTitleReview.php

echo
echo "--- how many chips the drawer will show ---"
python3 - <<'PY'
import io, re
c = io.open('app/Filament/Pages/CatalogTitles.php', encoding='utf-8').read()
block = re.search(r"public const TOKENS = \[.*?\n    \];", c, re.S).group(0)
toks = re.findall(r"'(\{[^']+\})'", block)
shown = [t for t in toks if t not in ('{attr:NAME}', '{a|b|c}')]
print(len(shown), 'chips:', ' '.join(shown))
PY

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
f = 'resources/views/filament/pages/catalog-title-review.blade.php'
s = re.sub(r'\{\{--.*?--\}\}', '', io.open(f, encoding='utf-8').read(), flags=re.S)
print('glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|else|elseif|php|endphp)\b', s)))
for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp')]:
    o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
    print(' ', a, o, b, c, 'OK' if o == c else 'MISMATCH')
PY

echo "--- php balance ---"
python3 - <<'PY'
import io
s = io.open('app/Filament/Pages/CatalogTitleReview.php', encoding='utf-8').read()
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
print('CatalogTitleReview braces', d, 'parens', par)
PY

echo
echo "apply-drawer-token-chips: OK"
