#!/bin/bash
# item-modal-barcodes-and-empty — show the barcode some source actually has.
#
#   1. UPC WAS BLANK ON ITEMS THAT HAVE ONE.
#      The modal read $item->catalog_upc — a single field copied from the
#      primary source when the item was imported. HLC has 4,467 rows with no
#      UPC at all (they carry an EAN instead), so any item sourced from one
#      of those showed no barcode, even when the OTHER distributor supplying
#      the same product has a perfectly good UPC.
#
#      Now barcodes are collected from every linked source and shown as
#      UPC and EAN lines, deduplicated. That is the point of matching: one
#      feed fills a gap the other leaves.
#
#   2. "UNKNOWN" DIDN'T DISTINGUISH TWO DIFFERENT THINGS.
#      A vendor with no live figures reads the same whether the distributor
#      reported zero stock or nobody has ever synced that vendor for this
#      shop. BTI is in the second state — live_avail and live_cost_cents are
#      written per subscription by RunTenantDistributorSyncJob, and BTI has
#      no tenant credentials yet. The row now says "not synced" and the
#      footnote explains what to do, instead of implying BTI was asked and
#      didn't answer.
# NO MIGRATION. Server: optimize:clear && view:clear
set -e
if grep -q "MARKER-MODAL-BARCODES" app/Http/Controllers/Tenant/RegisterController.php; then
  echo "item-modal-barcodes-and-empty already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ controller
python3 - <<'MBE_0_EOF'
import io
p = 'app/Http/Controllers/Tenant/RegisterController.php'
s = io.open(p, encoding='utf-8').read()

# --- select the barcode columns on the source catalog rows --------------
old = """            ->get(['id', 'distributor_code', 'distributor_variant_no'])
            ->keyBy('id');"""
assert s.count(old) == 1, s.count(old)
new = """            ->get(['id', 'distributor_code', 'distributor_variant_no', 'upc', 'ean'])
            ->keyBy('id');"""
s = s.replace(old, new)

# --- mark a source that has never been synced ---------------------------
old = """                'checked_at'  => $checkedAt,"""
assert s.count(old) == 1, s.count(old)
new = """                'checked_at'  => $checkedAt,
                // MARKER-MODAL-BARCODES — never synced is not the same as
                // "reported nothing". live_* are written per subscription by
                // the tenant sync; a distributor with no tenant credentials
                // has simply never been asked.
                'synced'      => $src->live_checked_at !== null || $avail !== null,"""
s = s.replace(old, new)

# --- barcodes from every source ----------------------------------------
old = """        // Cheapest first; a source with no cost sorts last rather than as free."""
assert s.count(old) == 1, s.count(old)
new = """        // MARKER-MODAL-BARCODES — a barcode from whichever source has one.
        // $item->catalog_upc is a single value copied from the primary source
        // at import, and HLC has thousands of rows with no UPC (EAN only), so
        // it left items blank whose other distributor knows the number.
        $upcs = [];
        $eans = [];
        if (! empty($item->catalog_upc)) {
            $upcs[(string) $item->catalog_upc] = true;
        }
        foreach ($catalogRows as $row) {
            if (! empty($row->upc)) { $upcs[(string) $row->upc] = true; }
            if (! empty($row->ean)) { $eans[(string) $row->ean] = true; }
        }

        // Cheapest first; a source with no cost sorts last rather than as free."""
s = s.replace(old, new)

old = """            'upc'         => $item->catalog_upc,"""
assert s.count(old) == 1, s.count(old)
new = """            'upc'         => implode(' · ', array_keys($upcs)) ?: null,
            'ean'         => implode(' · ', array_keys($eans)) ?: null,"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('controller ok')
MBE_0_EOF

# ------------------------------------------------------------------ partial
python3 - <<'MBE_1_EOF'
import io
p = 'resources/views/tenant/_item-detail-modal.blade.php'
s = io.open(p, encoding='utf-8').read()

# --- availability wording ------------------------------------------------
old = """      var qty = ( v.avail === null || v.avail === undefined ) ? 'unknown' : v.avail;"""
assert s.count(old) == 1, s.count(old)
new = """      // MARKER-MODAL-BARCODES — distinguish "asked, got nothing" from
      // "never asked". A distributor with no tenant credentials has never
      // been synced for this shop and shouldn't read as unresponsive.
      var qty = ( v.avail === null || v.avail === undefined )
        ? ( v.synced ? 'unknown' : 'not synced' )
        : v.avail;"""
s = s.replace(old, new)

# --- footnote explains the unsynced case --------------------------------
old = """    var when = ago( newest );
    el( 'rim-vendor-asof' ).textContent = when
      ? 'Last checked ' + when + '. Distributor stock is a snapshot, not live.'
      : 'Distributor stock is a snapshot, not live.';"""
assert s.count(old) == 1, s.count(old)
new = """    var when = ago( newest );
    var note = when
      ? 'Last checked ' + when + '. Distributor stock is a snapshot, not live.'
      : 'Distributor stock is a snapshot, not live.';

    if ( vendor.some( function ( v ) { return !v.synced; } ) ) {
      note += ' A vendor showing “not synced” has no connection saved on the'
           +  ' Connection & sync page yet.';
    }
    el( 'rim-vendor-asof' ).textContent = note;"""
s = s.replace(old, new)

# --- show EAN alongside UPC ---------------------------------------------
old = """      if ( d.upc )      rows.push( '<td class="k">UPC</td><td class="n" style="font-family:ui-monospace,monospace;font-size:12px">' + esc( d.upc ) + '</td>' );"""
assert s.count(old) == 1, s.count(old)
new = """      if ( d.upc )      rows.push( '<td class="k">UPC</td><td class="n" style="font-family:ui-monospace,monospace;font-size:12px">' + esc( d.upc ) + '</td>' );
      // MARKER-MODAL-BARCODES — thousands of HLC rows carry an EAN and no
      // UPC, so hiding it left those items looking like they had no barcode.
      if ( d.ean )      rows.push( '<td class="k">EAN</td><td class="n" style="font-family:ui-monospace,monospace;font-size:12px">' + esc( d.ean ) + '</td>' );"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('partial ok')
MBE_1_EOF

echo
echo "item-modal-barcodes-and-empty applied."
