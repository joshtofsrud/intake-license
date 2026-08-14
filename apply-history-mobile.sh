#!/bin/bash
# apply-history-mobile.sh
#
# MARKER-HIST-MOBILE — Transaction History on a phone. The page had NO
# @media rules at all: an eight-column table (Sale #, Status, Customer,
# Items, Total, Tx group, Date, Staff) squeezed into ~390px, so the first
# three columns ate the width, sale numbers wrapped to three lines, and
# everything from Items rightward — including Total and Date — was off
# screen. Josh: "can't see much detail, and scrolls forever."
#
#  1. BELOW 760px THE TABLE BECOMES CARDS. Each row is a block with its
#     values labelled from data-label, so all eight fields are readable
#     without horizontal squeeze. Desktop markup is untouched — same table,
#     same JS, same data attributes the sorting depends on.
#
#  2. RENDERS IN CHUNKS OF 25 with a "Show more" button. Sorting, search
#     and the filter pills are all CLIENT-SIDE over rows already in the DOM,
#     so paging on the server would silently break them (you would only be
#     sorting the page you had). Chunking the DISPLAY keeps every one of
#     those behaviours exact and still stops the endless scroll. The chunk
#     resets whenever the filter, search or sort changes.
#
#  3. A SORT CONTROL FOR MOBILE. The only way to sort is clicking column
#     headers, and the cards have no headers — so a select mirrors the same
#     five keys and drives the same currentSort state.
#
# KNOWN LIMIT, NOT FIXED HERE (needs its own decision): the controller
# loads ->limit(200) and every filter/search runs over those rows only. Past
# 200 transactions, older records are unreachable — search included, since
# it never reaches the server. Fixing that means moving filtering server-side,
# which is a rewrite of this page's interaction model, not a mobile tidy-up.
set -e

MARKER="MARKER-HIST-MOBILE"
H="resources/views/tenant/register/history.blade.php"

[ -f "$H" ] || { echo "ERROR: missing $H — run from the repo root"; exit 1; }
if grep -q "$MARKER" "$H" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io
p = 'resources/views/tenant/register/history.blade.php'
src = io.open(p, encoding='utf-8').read()

# ---------------------------------------------------------------
# 1. Styles: card rows, mobile toolbar, sort control, show-more
# ---------------------------------------------------------------
a = "  .h-count{font-size:13px;color:var(--ia-text-dim)}"
assert src.count(a) == 1, 'h-count anchor'
src = src.replace(a, a + """

  /* MARKER-HIST-MOBILE ------------------------------------------------ */
  .h-sortbar{display:none}
  .h-more{display:none;margin:14px 0 4px;width:100%;padding:12px;
    background:transparent;border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;
    font-family:inherit;cursor:pointer}
  .h-more:hover{background:rgba(127,127,127,.06)}
  .h-more.on{display:block}

  @media (max-width: 760px){
    /* count above a full-width search rather than squeezed beside it */
    .h-toolbar{flex-wrap:wrap;gap:8px}
    .h-count{flex:1 1 100%;order:-1}
    .h-search{flex:1 1 100%;max-width:none;min-width:0}

    /* sort lives in the header row on desktop; the cards have no header */
    .h-sortbar{display:flex;gap:8px;align-items:center;margin:0 0 12px}
    .h-sortbar select{flex:1;min-width:0;padding:9px 12px;font-size:13px;
      font-family:inherit;color:var(--ia-text);background:var(--ia-input-bg);
      border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md)}

    /* table -> cards. Desktop markup is unchanged; only the box model is. */
    .h-table-wrap{overflow:visible}
    .h-table, .h-table tbody, .h-table tr, .h-table td{display:block;width:auto}
    .h-table thead{display:none}
    .h-table tr{
      border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);
      padding:12px 14px;margin-bottom:10px;background:var(--ia-surface)
    }
    .h-table td{
      display:flex;justify-content:space-between;align-items:baseline;gap:14px;
      padding:3px 0;border:0;text-align:left;font-size:13px
    }
    .h-table td::before{
      content:attr(data-label);flex:0 0 auto;font-size:10.5px;font-weight:600;
      letter-spacing:.05em;text-transform:uppercase;color:var(--ia-text-dim)
    }
    /* the identifying pair reads as the card's heading */
    .h-table td[data-label="Sale #"]{padding-bottom:6px;font-size:15px;font-weight:600}
    .h-table td[data-label="Total"]{font-size:15px;font-weight:600}
    .h-table td .h-meta{text-align:right}
    .h-table td.h-empty-search{display:block;text-align:center}
    .h-table td.h-empty-search::before{content:none}
  }""", 1)

# ---------------------------------------------------------------
# 2. Sort control markup (rendered always, shown by CSS on mobile)
# ---------------------------------------------------------------
b = """  <div class="h-toolbar">"""
assert src.count(b) == 1, 'toolbar anchor'
src = src.replace(b, """  {{-- MARKER-HIST-MOBILE — the cards have no column headers to click --}}
  <div class="h-sortbar">
    <select id="hSortSelect" aria-label="Sort transactions">
      <option value="date:desc">Newest first</option>
      <option value="date:asc">Oldest first</option>
      <option value="total:desc">Largest total</option>
      <option value="total:asc">Smallest total</option>
      <option value="sale_number:asc">Sale #</option>
      <option value="customer:asc">Customer</option>
      <option value="status:asc">Status</option>
    </select>
  </div>

""" + b, 1)

# ---------------------------------------------------------------
# 3. data-label on every cell
# ---------------------------------------------------------------
cells = [
    ("""            <td>
              @if($r['sale_number'])""",
     """            <td data-label="Sale #">{{-- MARKER-HIST-MOBILE --}}
              @if($r['sale_number'])"""),
    ("""            <td>
              <span class="h-status {{ $r['payment_status'] }}">""",
     """            <td data-label="Status">
              <span class="h-status {{ $r['payment_status'] }}">"""),
    ("""            <td>
              {{ $r['customer'] ?? '—' }}""",
     """            <td data-label="Customer">
              {{ $r['customer'] ?? '—' }}"""),
    ("""            <td>
              {{ $r['item_count'] }}""",
     """            <td data-label="Items">
              {{ $r['item_count'] }}"""),
    ("""            <td class="h-total {{ $r['is_refund'] ? 'refund' : '' }}">""",
     """            <td data-label="Total" class="h-total {{ $r['is_refund'] ? 'refund' : '' }}">"""),
    ("""            <td>
              @if($r['transaction_id'])""",
     """            <td data-label="Tx group">
              @if($r['transaction_id'])"""),
    ("""            <td class="h-meta" title="{{ $dateObj?->format('M j, Y g:i A') ?? '' }}">""",
     """            <td data-label="Date" class="h-meta" title="{{ $dateObj?->format('M j, Y g:i A') ?? '' }}">"""),
    ("""            <td class="h-meta">{{ $r['started_by'] ?? '—' }}</td>""",
     """            <td data-label="Staff" class="h-meta">{{ $r['started_by'] ?? '—' }}</td>"""),
]
for old, new in cells:
    assert src.count(old) == 1, 'cell anchor: ' + old[:60]
    src = src.replace(old, new, 1)

# ---------------------------------------------------------------
# 4. Show-more button after the table
# ---------------------------------------------------------------
c = """    </table>
  </div>
@endif"""
assert src.count(c) == 1, 'table close anchor'
src = src.replace(c, """    </table>
  </div>

  {{-- MARKER-HIST-MOBILE --}}
  <button type="button" class="h-more" id="hShowMore"></button>
@endif""", 1)

# ---------------------------------------------------------------
# 5. Chunked rendering + sort select wiring
# ---------------------------------------------------------------
d = """let currentSort = { key: 'date', dir: 'desc' };"""
assert src.count(d) == 1
src = src.replace(d, """// MARKER-HIST-MOBILE — render in chunks. Filtering, search and sorting all
// run client-side over the rows already in the DOM, so this caps what is
// PAINTED, never what is searched: every behaviour stays exact.
const HIST_CHUNK = 25;
let shownLimit = HIST_CHUNK;

let currentSort = { key: 'date', dir: 'desc' };""", 1)

e = """  // Re-render
  allRows.forEach(r => r.remove());
  filtered.forEach(r => tbody.appendChild(r));"""
assert src.count(e) == 1, 'render anchor'
src = src.replace(e, """  // Re-render — MARKER-HIST-MOBILE: only the current chunk lands in the DOM.
  allRows.forEach(r => r.remove());
  const visible = filtered.slice(0, shownLimit);
  visible.forEach(r => tbody.appendChild(r));

  const moreBtn = document.getElementById('hShowMore');
  if (moreBtn) {
    const remaining = filtered.length - visible.length;
    moreBtn.classList.toggle('on', remaining > 0);
    if (remaining > 0) {
      moreBtn.textContent = 'Show ' + Math.min(HIST_CHUNK, remaining) + ' more · ' + remaining + ' not shown';
    }
  }""", 1)

f = """  if (shownCount) shownCount.textContent = filtered.length;
}"""
assert src.count(f) == 1
src = src.replace(f, """  // MARKER-HIST-MOBILE — the count reflects what is on screen, so it never
  // claims to be showing rows the chunk is holding back.
  if (shownCount) shownCount.textContent = Math.min(filtered.length, shownLimit);
}

// MARKER-HIST-MOBILE — any change to what matches starts the chunk over.
function resetChunk() { shownLimit = HIST_CHUNK; }

document.getElementById('hShowMore')?.addEventListener('click', () => {
  shownLimit += HIST_CHUNK;
  applyFilters();
});

document.getElementById('hSortSelect')?.addEventListener('change', (e) => {
  const [key, dir] = e.target.value.split(':');
  currentSort = { key, dir };
  resetChunk();
  applyFilters();
});""", 1)

# search + filter pills reset the chunk
g = """    currentSearch = e.target.value;
    applyFilters();"""
assert src.count(g) == 1
src = src.replace(g, """    currentSearch = e.target.value;
    resetChunk(); // MARKER-HIST-MOBILE
    applyFilters();""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: history mobile cards, chunking, sort control')
PY

# filter pills also reset the chunk — handled separately so the anchor stays tight
python3 - <<'PY'
import io, re
p = 'resources/views/tenant/register/history.blade.php'
src = io.open(p, encoding='utf-8').read()

# find the chip click handler's applyFilters() call
m = re.search(r"(document\.querySelectorAll\('\.h-chip'\)[\s\S]{0,1200}?)(\n\s*applyFilters\(\);)", src)
assert m, 'chip handler not found'
if 'resetChunk(); // MARKER-HIST-MOBILE (chips)' not in src:
    indent = re.match(r'\n(\s*)', m.group(2)).group(1)
    src = src[:m.start(2)] + '\n' + indent + 'resetChunk(); // MARKER-HIST-MOBILE (chips)' + m.group(2) + src[m.end(2):]
    io.open(p, 'w', encoding='utf-8').write(src)
    print('ok: filter pills reset the chunk')
PY

echo ""
echo "== history mobile applied =="
echo "Post-deploy: php artisan optimize:clear"
