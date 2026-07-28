#!/bin/bash
# uncategorized-split-by-attribute — group a bucket by any attribute it carries.
#
#   Replaces SPLIT BY SIZE. That row assumed every bucket has a size and got
#   the size by scraping text, which is how Wheels ended up offering 12mm and
#   148mm (thru-axle and hub spacing) as wheel sizes. Values now come from the
#   distributor attributes on each row, and the attribute is chosen.
#
#   Ranking (this is the whole design, since nothing is remembered and the
#   default is all that stands between the user and a click every visit):
#     qualify  coverage >= 60% AND 5 <= distinct values <= 60
#     rank     coverage desc, ties broken toward MORE values
#     default  top qualifier, or no split when nothing qualifies
#
#   Measured against the real HLC data this gives:
#     Wheels (304)             -> Wheel Diameter/ISO  100% / 11 values
#     Chainrings (361)         -> Teeth               100% / 31 values
#     Fork Repair Parts (586)  -> no split (only "Misc", 46% / 164 values)
#
#   The floor of 5 is load-bearing: Position (100%/3), Rim Color (100%/4) and
#   Rim Material (99%/3) all tie or beat Wheel Diameter on coverage, and a
#   three-way split is not worth a chip row. The ceiling of 60 only catches
#   junk drawers — nothing real sits between 35 and 164 values.
#
#   Brand is offered in the dropdown but NEVER ranked, by decision: it is on
#   everything with few values, so ranking it would make it the default in
#   every bucket that has no usable attribute, which is precisely where the
#   honest answer is "nothing to split by".
#
#   Nothing is remembered. The choice is a query parameter and dies with the
#   page — a stale remembered split has to be undone before you can work a
#   different way, which costs more than the click it saves.
#
#   Units are no longer appended. The chip row hardcoded &Prime; after every
#   value, so 200mm rendered as 200mm" and 29'' (which carries its own marks
#   from HLC's text) came out 29"". Each value now prints as stored.
#
#   NOT in this patch: writing the chosen attribute onto tenant_inventory_items
#   .size. That is a write path and wants its own review.
# NO MIGRATION. Server: optimize:clear.
set -e
if grep -q "MARKER-SPLIT-BY" app/Http/Controllers/Tenant/InventoryController.php; then
  echo "uncategorized-split-by-attribute already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ controller
python3 - <<'USB_0_EOF'
import io
p = 'app/Http/Controllers/Tenant/InventoryController.php'
s = io.open(p, encoding='utf-8').read()

old = """        $bucket = trim((string) $request->query('bucket', ''));
        $size   = trim((string) $request->query('size', '')) ?: null;"""
assert s.count(old) == 1, s.count(old)
new = """        $bucket = trim((string) $request->query('bucket', ''));
        // MARKER-SPLIT-BY \u2014 attr '' means "not chosen, use the default";
        // 'none' means the user turned the row off. Neither is remembered.
        $attrKey = trim((string) $request->query('attr', ''));
        $attrVal = trim((string) $request->query('val', '')) ?: null;"""
s = s.replace(old, new)

# --- replace the whole size block ---------------------------------------
start = s.index("        // Size sub-groups (touch of A)")
end   = s.index("        // Category tree (nested by parent_id) with item counts.")
block = """        // MARKER-SPLIT-BY \u2014 tally every attribute across the bucket, then
        // rank them. Coverage alone is not enough: on Wheels, Position
        // (100%/3) and Rim Color (100%/4) beat Wheel Diameter on coverage
        // and would win a coverage-only race, giving a three-way split
        // nobody wants. Hence a floor on distinct values as well.
        $tally = [];
        foreach ($all as $it) {
            $cat = $it->distributorCatalog;
            if (! $cat) { continue; }

            $seen = [];
            foreach (($cat->attributes ?? []) as $a) {
                if (! is_array($a) || ! isset($a['Name'])) { continue; }
                $name = trim((string) $a['Name']);
                $val  = trim((string) ($a['Value'] ?? ''));
                if ($name === '' || $val === '' || isset($seen[$name])) { continue; }
                $seen[$name] = true;                       // one row counts once
                $tally[$name]['rows'] = ($tally[$name]['rows'] ?? 0) + 1;
                $tally[$name]['vals'][$val] = ($tally[$name]['vals'][$val] ?? 0) + 1;
            }

            // Brand is not an attribute. It is offered because it is often
            // the only usable grouping, and deliberately never ranked.
            $brand = trim((string) ($cat->manufacturer ?? ''));
            if ($brand !== '') {
                $tally['__brand']['rows'] = ($tally['__brand']['rows'] ?? 0) + 1;
                $tally['__brand']['vals'][$brand] = ($tally['__brand']['vals'][$brand] ?? 0) + 1;
            }
        }

        $denom = max(1, $bucketTotal);
        $attrOptions = [];
        foreach ($tally as $name => $t) {
            $isBrand = $name === '__brand';
            $cov  = (int) round((($t['rows'] ?? 0) / $denom) * 100);
            $vals = count($t['vals'] ?? []);

            $qualifies = ! $isBrand && $cov >= 60 && $vals >= 5 && $vals <= 60;
            $reason = '';
            if (! $qualifies) {
                if ($isBrand)          { $reason = 'not an attribute'; }
                elseif ($cov < 60)     { $reason = 'covers only ' . $cov . '%'; }
                elseif ($vals < 5)     { $reason = 'only ' . $vals . ' values'; }
                else                   { $reason = $vals . ' values \u2014 too many'; }
            }

            $attrOptions[] = [
                'key' => $name, 'label' => $isBrand ? 'Brand' : $name,
                'cov' => $cov, 'vals' => $vals,
                'qualifies' => $qualifies, 'reason' => $reason, 'brand' => $isBrand,
            ];
        }

        usort($attrOptions, function ($a, $b) {
            if ($a['qualifies'] !== $b['qualifies']) { return $b['qualifies'] <=> $a['qualifies']; }
            return ($b['cov'] <=> $a['cov']) ?: ($b['vals'] <=> $a['vals']);
        });

        // Default: the top qualifier, or nothing.
        if ($attrKey === '') {
            $top = null;
            foreach ($attrOptions as $o) { if ($o['qualifies']) { $top = $o['key']; break; } }
            $attrKey = $top ?? 'none';
        }
        $known = array_column($attrOptions, 'key');
        if ($attrKey !== 'none' && ! in_array($attrKey, $known, true)) { $attrKey = 'none'; }

        $activeAttr = $attrKey === 'none' ? null : $attrKey;
        $activeAttrLabel = 'Value';
        foreach ($attrOptions as $o) {
            if ($o['key'] === $activeAttr) { $activeAttrLabel = $o['label']; break; }
        }

        // Values for the chip row, biggest first. No unit is appended \u2014 the
        // value carries its own (622 has none, 12mm keeps its own).
        $valueCounts = [];
        if ($activeAttr !== null) {
            $valueCounts = $tally[$activeAttr]['vals'] ?? [];
            arsort($valueCounts);
        }

        foreach ($all as $it) {
            $it->_val = '';
            $cat = $it->distributorCatalog;
            if (! $cat || $activeAttr === null) { continue; }
            if ($activeAttr === '__brand') {
                $it->_val = trim((string) ($cat->manufacturer ?? ''));
                continue;
            }
            foreach (($cat->attributes ?? []) as $a) {
                if (is_array($a) && isset($a['Name'])
                    && trim((string) $a['Name']) === $activeAttr) {
                    $it->_val = trim((string) ($a['Value'] ?? ''));
                    break;
                }
            }
        }

        $items = $attrVal !== null
            ? $all->filter(fn ($it) => $it->_val === $attrVal)->values()
            : $all;

"""
s = s[:start] + block + s[end:]

old = """            'activeBucket' => $bucket, 'items' => $items, 'sizeCounts' => $sizeCounts,
            'activeSize' => $size, 'bucketTotal' => $bucketTotal,"""
assert s.count(old) == 1, s.count(old)
new = """            'activeBucket' => $bucket, 'items' => $items,
            // MARKER-SPLIT-BY
            'attrOptions' => $attrOptions, 'activeAttr' => $activeAttr,
            'activeAttrLabel' => $activeAttrLabel, 'valueCounts' => $valueCounts,
            'activeVal' => $attrVal, 'bucketTotal' => $bucketTotal,"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('controller ok')
USB_0_EOF

# ------------------------------------------------------------------ view
python3 - <<'USB_1_EOF'
import io
p = 'resources/views/tenant/inventory/uncategorized.blade.php'
s = io.open(p, encoding='utf-8').read()

# --- picker + chips replace the size chip row ---------------------------
start = s.index("    @if(count($sizeCounts))")
end   = s.index("    @endif", start) + len("    @endif\n")

block = """    {{-- MARKER-SPLIT-BY — pick the attribute to group by. Nothing is
         remembered; the default comes from the ranking every time. --}}
    @if(count($attrOptions))
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
        <span style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-mute);font-weight:600">Split by</span>
        <select onchange="window.location = this.value"
                style="background:var(--ia-input-bg);border:1px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);padding:7px 10px;font-size:12.5px;min-width:280px">
          <option value="{{ route('tenant.inventory.uncategorized', ['bucket' => $activeBucket, 'attr' => 'none']) }}"
            @selected(! $activeAttr)>— no split —</option>
          @foreach($attrOptions as $o)
            <option value="{{ route('tenant.inventory.uncategorized', ['bucket' => $activeBucket, 'attr' => $o['key']]) }}"
              @selected($activeAttr === $o['key'])>
              {{ $o['label'] }} — {{ $o['cov'] }}% · {{ $o['vals'] }} values{{ $o['qualifies'] ? '' : ' (' . $o['reason'] . ')' }}
            </option>
          @endforeach
        </select>
        @if($activeAttr)
          <span style="font-size:12px;color:var(--ia-text-dim)">grouping {{ $bucketTotal }} items</span>
        @else
          <span style="font-size:12px;color:var(--ia-text-dim)">nothing in this bucket groups usefully — pick one above if you disagree</span>
        @endif
      </div>

      @if($activeAttr && count($valueCounts))
        <div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
          @php $allOn = ! $activeVal; $shown = 0; @endphp
          <a href="{{ route('tenant.inventory.uncategorized', ['bucket' => $activeBucket, 'attr' => $activeAttr]) }}"
             style="padding:5px 11px;border-radius:20px;font-size:12.5px;font-weight:600;text-decoration:none;border:1px solid {{ $allOn ? 'var(--ia-text)' : 'var(--ia-border)' }};background:{{ $allOn ? 'var(--ia-text)' : 'var(--ia-surface-2)' }};color:{{ $allOn ? 'var(--ia-bg)' : 'var(--ia-text-dim)' }}">All <span style="font-family:var(--ia-mono)">{{ $bucketTotal }}</span></a>
          @foreach($valueCounts as $val => $cnt)
            @if($shown < 16)
              @php $on = $activeVal === (string) $val; $shown++; @endphp
              <a href="{{ route('tenant.inventory.uncategorized', ['bucket' => $activeBucket, 'attr' => $activeAttr, 'val' => $val]) }}"
                 style="padding:5px 11px;border-radius:20px;font-size:12.5px;font-weight:600;text-decoration:none;border:1px solid {{ $on ? 'var(--ia-text)' : 'var(--ia-border)' }};background:{{ $on ? 'var(--ia-text)' : 'var(--ia-surface-2)' }};color:{{ $on ? 'var(--ia-bg)' : 'var(--ia-text-dim)' }}">{{ $val }} <span style="font-family:var(--ia-mono);opacity:.7">{{ $cnt }}</span></a>
            @endif
          @endforeach
          @if(count($valueCounts) > 16)
            <span style="font-size:12px;color:var(--ia-text-mute)">+ {{ count($valueCounts) - 16 }} more</span>
          @endif
        </div>
      @endif
    @endif
"""
s = s[:start] + block + s[end:]

# --- column header follows the choice -----------------------------------
old = """<th style="padding:10px 14px">Item</th><th style="padding:10px 14px">Brand</th><th style="padding:10px 14px">Size</th>"""
assert s.count(old) == 1, s.count(old)
new = """<th style="padding:10px 14px">Item</th><th style="padding:10px 14px">Brand</th><th style="padding:10px 14px">{{ $activeAttrLabel }}</th>"""
s = s.replace(old, new)

# --- cell reads the chosen attribute ------------------------------------
old = """@if($it->_size)"""
assert s.count(old) == 1, s.count(old)
s = s.replace(old, """@if($it->_val)""")

old = """{{ $it->_size }}</span>"""
assert s.count(old) == 1, s.count(old)
s = s.replace(old, """{{ $it->_val }}</span>""")

io.open(p, 'w', encoding='utf-8').write(s)
print('view ok')
USB_1_EOF

php -l app/Http/Controllers/Tenant/InventoryController.php

echo
echo "uncategorized-split-by-attribute applied."
