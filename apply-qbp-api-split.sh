#!/usr/bin/env bash
# apply-qbp-api-split.sh
# MARKER-QBP-API-SPLIT — which API owns what, written down where it is used.
#
# Confirmed against a live product detail response, not the guide:
#
#   API1 (free, one key, X-QBPAPI-KEY) carries EVERYTHING transactional —
#   sku, manufacturerPartNumber, modelCode, barcodes, brand, productCategory,
#   dimensions, freight, and the whole price ladder: basePrice, dealerPrice,
#   mapPrice, msrp. Stock too: warehouse, quantityAvailable,
#   stockLevelStatus and estimatedArrivalDate. bulletPoints and
#   classifications are on API1 as well.
#
#   That settles the open question. CLS documents that it excludes "Your
#   Price"; API1 returns dealerPrice, so cost does not need CLS at all.
#
#   API3 / CLS (licensed, separate agreement) carries the IMAGE FILES. API1
#   returns image file NAMES only — a name is not an image, so a storefront
#   showing product photos needs the licence and nothing else does.
#
# WHICH KEY, WHICH TIER. dealerPrice is the authenticated account's own
# negotiated price. The platform catalog is shared by every tenant, so a
# platform key's dealerPrice must never land in it. The existing two-tier
# design already handles this — syncIdentity sets cost_cents to null and cost
# arrives per tenant on the pivot — so QBP needs no special case, only the
# discipline not to break it. Stated here because the next person to touch
# this will be looking at a payload that has dealerPrice sitting right next
# to the identity fields.
set -e

python3 <<'PY'
import io

p = 'app/Services/Distributors/QbpClient.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-QBP-API-SPLIT' not in s, 'already applied'

old = """ *   - Images require a separate CLS subscription; product detail carries
 *     file names only.
 *   - Dealer cost is NOT documented as present on product detail. CLS
 *     explicitly excludes "Your Price". Confirm with the probe before
 *     designing anything that depends on cost arriving here."""
assert s.count(old) == 1, 'S1 docblock anchor'
s = s.replace(old, """ *   - Images: API1 returns file NAMES; the files themselves need CLS.
 *
 * MARKER-QBP-API-SPLIT — settled against a live response:
 *
 *   COST IS ON API1. dealerPrice comes back on product detail alongside
 *   basePrice, mapPrice and msrp. CLS's exclusion of "Your Price" turned out
 *   not to matter, because CLS is not where cost lives.
 *
 *   Everything transactional is API1 and free: identity, model grouping,
 *   barcodes, categories, dimensions, freight, the price ladder, warehouse
 *   stock with estimatedArrivalDate, plus bulletPoints and classifications.
 *
 *   CLS is needed for ONE thing: the image files. Anything that only needs
 *   to know an image exists can read the file name from API1.
 *
 *   TIER DISCIPLINE. dealerPrice is the authenticated ACCOUNT's price, so it
 *   belongs on the per-tenant sync, never in platform_distributor_catalogs,
 *   which every tenant reads. syncIdentity already nulls cost_cents; keep it
 *   that way. The hazard is that in QBP's payload dealerPrice sits inline
 *   with the identity fields, so it is easy to map by accident.""")

old = """    public function images(array $skus): array        { throw $this->pending('images'); }"""
assert s.count(old) == 1, 'S2 images anchor'
s = s.replace(old, """    /**
     * MARKER-QBP-API-SPLIT — the one method that is not API1.
     *
     * Product detail gives a file NAME. Retrieving the file needs a Content
     * License Service subscription: an active QBP account with order
     * history, a signed licensing agreement, and QBP's intake process.
     *
     * This throws with that explanation rather than returning an empty list,
     * because "no images" and "not licensed for images" are different
     * problems with different fixes, and only one of them is solved by code.
     */
    public function images(array $skus): array
    {
        throw new RuntimeException(
            'QBP images require a Content License Service (API3) subscription. API1 returns image '
            . 'file names only. Product content, attributes, stock and dealer cost all come from '
            . 'API1 and need no licence.'
        );
    }""")

# Field paths, recorded where the map will be written.
old = """    /**
     * MARKER-QBP-XML — XML body to a plain array."""
assert s.count(old) == 1, 'S3 xml helper anchor'
s = s.replace(old, """    /**
     * MARKER-QBP-API-SPLIT — element paths confirmed on a live response, so
     * the field map is written against real names rather than the guide.
     *
     *   TIER 1, shared catalog (no cost):
     *     sku                      distributor_variant_no
     *     modelCode                distributor_product_no
     *     manufacturerPartNumber   manufacturer_sku
     *     barcodes.barcode         upc / ean
     *     brand.name               manufacturer
     *     productCategory.id       resolve via the category tree
     *     Length/Width/Height      dimensions
     *     Weight                   weight
     *     bulletPoints             description material
     *     classifications          attributes
     *     blocked / discontinued / hazmat / ormd / markets
     *                              gate whether an item is offerable at all
     *
     *   TIER 2, per tenant:
     *     dealerPrice.value        cost_cents      ← the account's own price
     *     mapPrice.value           map_cents
     *     msrp.value               msrp_cents
     *     basePrice.value          list, for reference
     *     stockLevel.quantityAvailable / .stockLevelStatus / .warehouse
     *     stockLevel.estimatedArrivalDate
     *                              nothing else we carry has an ETA; it is
     *                              the difference between "we will call you"
     *                              and "it lands Thursday"
     *
     * Prices nest as {currencyIso, value, formattedValue, priceType} — read
     * `value`, never `formattedValue`, which is a display string.
     */

    /**
     * MARKER-QBP-XML — XML body to a plain array.""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- the split is stated where it gets used ---"
grep -c "MARKER-QBP-API-SPLIT" app/Services/Distributors/QbpClient.php

echo
echo "--- images explains CLS instead of returning empty ---"
grep -n "Content License Service (API3)" app/Services/Distributors/QbpClient.php

echo
echo "--- every interface method still implemented ---"
python3 - <<'PY'
import io, re
iface = io.open('app/Services/Distributors/DistributorAdapter.php', encoding='utf-8').read()
impl  = io.open('app/Services/Distributors/QbpClient.php', encoding='utf-8').read()
need = set(re.findall(r'public function (\w+)\(', iface))
have = set(re.findall(r'public function (\w+)\(', impl))
print('  missing:', sorted(need - have) or 'none')
assert not (need - have)
PY

echo
echo "--- shared catalog still refuses cost ---"
grep -n "cost_cents'\]      = null" app/Services/Distributors/DistributorCatalogSyncService.php

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Services/Distributors/QbpClient.php', encoding='utf-8').read()
i, n, d, par, brk = 0, len(s), 0, 0, 0
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
        elif c == '[': brk += 1
        elif c == ']': brk -= 1
        i += 1
print('QbpClient braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-qbp-api-split: OK"
