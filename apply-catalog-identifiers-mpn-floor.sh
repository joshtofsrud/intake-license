#!/bin/bash
# catalog-identifiers-mpn-floor — stop dropping short but valid part numbers.
#
#   The first HLC index run produced no identifier for 6 of 14,469 rows. Five
#   have a blank manufacturer_sku and no barcode, and are not really products
#   — an OEM wheel placeholder, three "spare parts" assortments, a Pirelli
#   slatwall display. Correctly excluded.
#
#   The sixth is mine: Wheels Manufacturing / "BR-3" normalises to
#   WHEELSMANUFACTURING|BR3, whose part component is 3 characters, and the
#   4-character floor threw it away. That floor exists to stop a bare "100"
#   matching across manufacturers — but the brand is already carried in the
#   key, so WHEELSMANUFACTURING|BR3 cannot collide with anyone else's BR3.
#   The floor was solving a problem the brand qualifier had already solved.
#
#   So the floors split by type: barcodes keep 4 (a 3-digit barcode is
#   corrupt data), brand-qualified MPNs drop to 2. Single characters stay
#   out — a one-character part number is far more likely to be a data-entry
#   artefact than a real SKU, and it is the case most likely to collide
#   inside one brand.
#
#   Re-run the index after deploying; it is a full rebuild per distributor.
# NO MIGRATION. Server: optimize:clear, then php artisan catalog:index-identifiers
set -e
if grep -q "MARKER-MPN-FLOOR" app/Services/Distributors/CatalogIdentifierService.php; then
  echo "catalog-identifiers-mpn-floor already applied — aborting."; exit 1
fi

python3 - <<'CMF_0_EOF'
import io
p = 'app/Services/Distributors/CatalogIdentifierService.php'
s = io.open(p, encoding='utf-8').read()

old = """    /** Shorter than this after normalising and it isn't an identifier. */
    private const MIN_LENGTH = 4;"""
assert s.count(old) == 1, s.count(old)
new = """    /**
     * MARKER-MPN-FLOOR \u2014 floors differ by type, on purpose.
     *
     * A barcode shorter than 4 digits is corrupt data. A part number shorter
     * than 4 is routine \u2014 "BR-3" is a real Wheels Manufacturing SKU \u2014 and it
     * is already brand-qualified in the key, so WHEELSMANUFACTURING|BR3
     * cannot collide with another manufacturer's BR3. The original single
     * floor of 4 was guarding against a collision the brand prefix had
     * already ruled out, and it silently dropped valid rows.
     *
     * Single characters still stay out: within one brand they are far more
     * likely to be a data-entry artefact than a distinct part.
     */
    private const MIN_LENGTH = 4;        // barcodes
    private const MIN_MPN_LENGTH = 2;    // brand-qualified part numbers"""
s = s.replace(old, new)

old = """        if (strlen($s) < self::MIN_LENGTH || $this->isJunk($s)) {
            return null;
        }"""
assert s.count(old) == 1, s.count(old)
new = """        if (strlen($s) < self::MIN_MPN_LENGTH || $this->isJunk($s)) {
            return null;
        }"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('mpn floor ok')
CMF_0_EOF

php -l app/Services/Distributors/CatalogIdentifierService.php

echo
echo "catalog-identifiers-mpn-floor applied."
