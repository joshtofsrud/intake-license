#!/bin/bash
# item-sourcing-placement — put Sourcing where the mockup had it, and fix images.
#
#   Two mistakes from the last patch.
#
#   1. The Sourcing card landed at the BOTTOM. I inserted it by searching
#      backwards for the nearest `<div` before the tab strip, and the tab
#      strip is the last thing on the page — so the card went after Pricing
#      and Stock by location rather than near the top. In Option A it sits
#      directly under the hero, above Specs: sourcing is the question the
#      page exists to answer, so it goes before the descriptive material.
#
#   2. No image on BTI items. The BTI field map writes `image_urls`, but the
#      column on platform_distributor_catalogs is `images`. DistributorMapResolver
#      drops canonical fields that aren't columns without complaining, so every
#      BTI row imported with no images at all and the page correctly said
#      "No image from the distributor catalog".
#
#      Re-seeding the map fixes new syncs; existing BTI rows need a re-sync to
#      pick images up, which is why the command is included below.
#
#   The image_base prefix is still a guess I could not verify — if images 404
#   after the re-sync, that host is the thing to check.
# NO MIGRATION. Server: view:clear, then re-seed and re-sync BTI:
#   php artisan db:seed --class=BtiFieldMapSeeder --force
#   php -d memory_limit=1G artisan distributors:sync-catalog BTI --page-size=50000
set -e
if grep -q "MARKER-SOURCING-PLACEMENT" resources/views/tenant/inventory/show.blade.php; then
  echo "item-sourcing-placement already applied — aborting."; exit 1
fi

# ------------------------------------------------------------- move the card
python3 - <<'ISP_0_EOF'
import io
p = 'resources/views/tenant/inventory/show.blade.php'
s = io.open(p, encoding='utf-8').read()

start = s.index("""  {{-- MARKER-ITEM-SOURCING — one table answering every vendor question:""")
end_marker = """  @endif

"""
end = s.index(end_marker, start) + len(end_marker)
card = s[start:end]
assert 'ia-card-title">Sourcing' in card, 'sourcing card not isolated'

# lift it out
s = s[:start] + s[end:]

# drop it in above Specs, indented to match the main column
anchor = """    {{-- Specs --}}"""
assert s.count(anchor) == 1, ('specs anchor', s.count(anchor))

card = card.replace('\n  ', '\n    ').replace('  {{-- MARKER-ITEM-SOURCING', '    {{-- MARKER-ITEM-SOURCING', 1)
card = ("""    {{-- MARKER-SOURCING-PLACEMENT — directly under the hero, above Specs.
         Sourcing is the question this page exists to answer, so it comes
         before the descriptive material rather than after it. --}}\n"""
        + card)

s = s.replace(anchor, card + anchor)

io.open(p, 'w', encoding='utf-8').write(s)
print('card moved above specs')
ISP_0_EOF

# ------------------------------------------------------------- images column
python3 - <<'ISP_1_EOF'
import io
p = 'database/seeders/BtiFieldMapSeeder.php'
s = io.open(p, encoding='utf-8').read()

old = """            ['image_urls', 'image_paths', 'split_pipe', ["""
assert s.count(old) == 1, ('image field', s.count(old))
new = """            // MARKER-SOURCING-PLACEMENT — the column is `images`, not
            // `image_urls`. The resolver silently drops canonical fields that
            // aren't columns, so every BTI row imported with no images.
            ['images', 'image_paths', 'split_pipe', ["""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('images field ok')
ISP_1_EOF

echo
echo "item-sourcing-placement applied."
