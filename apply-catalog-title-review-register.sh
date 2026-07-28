#!/bin/bash
# catalog-title-review-register — registers the review page with the panel.
#   AdminPanelProvider lists its pages explicitly and does NOT auto-discover
#   (there is a comment on the PlatformEmail line saying exactly that). The
#   new CatalogTitleReview page was never added, so Filament had no reason to
#   know it existed — no nav entry, no route. Nothing to do with the
#   component cache.
# NO MIGRATION. Server: optimize:clear && php artisan filament:cache-components
set -e
if grep -q "CatalogTitleReview" app/Providers/Filament/AdminPanelProvider.php; then
  echo "catalog-title-review-register already applied — aborting."; exit 1
fi
if [ ! -f app/Filament/Pages/CatalogTitleReview.php ]; then
  echo "catalog-title-review-page must be applied first — aborting."; exit 1
fi

python3 - <<'CTRR_0_EOF'
import io
p = 'app/Providers/Filament/AdminPanelProvider.php'
s = io.open(p, encoding='utf-8').read()

old = """                \\App\\Filament\\Pages\\CatalogTitles::class, // MARKER-PATCH-HLCE2 title editor"""
assert s.count(old) == 1, s.count(old)
new = """                // MARKER-REVIEW-PAGE \u2014 list-first Catalog Titles page. This panel
                // lists pages explicitly, so a new page class is invisible until
                // it appears here, cache or no cache.
                \\App\\Filament\\Pages\\CatalogTitleReview::class,
                \\App\\Filament\\Pages\\CatalogTitles::class, // MARKER-PATCH-HLCE2 title editor"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('panel registration ok')
CTRR_0_EOF

php -l app/Providers/Filament/AdminPanelProvider.php

echo
echo "catalog-title-review-register applied."
