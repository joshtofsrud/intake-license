{{-- MARKER-INV-PAGER — one pager, rendered in three places (above the table,
     below it, and under the mobile card list). $qs, $pages and $pagerWhere
     come from the caller. $pagerWhere is 'top', 'bottom' or 'mobile' and only
     decides which extras show, so the three stay in step by construction. --}}
@php
  $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
  $to   = min($page * $perPage, $total);

  // A window around the current page: first and last are always reachable,
  // and 24 pages must not render 24 buttons on a phone.
  $window = $pagerWhere === 'mobile' ? 1 : 2;
  $nums   = collect(range(1, $pages))
      ->filter(fn ($p) => $p === 1 || $p === $pages || abs($p - $page) <= $window)
      ->values();
@endphp

<div class="inv-pager inv-pager--{{ $pagerWhere }}">

  <div class="inv-pager-count">
    Showing {{ number_format($from) }}–{{ number_format($to) }} of {{ number_format($total) }}
  </div>

  @if($pagerWhere !== 'mobile')
    <form method="get" action="{{ route('tenant.inventory.index') }}" class="inv-pager-size">
      <input type="hidden" name="s" value="{{ $search }}">
      <input type="hidden" name="category" value="{{ $category }}">
      <input type="hidden" name="brand" value="{{ $brand }}">
      <input type="hidden" name="distributor" value="{{ $distributor }}">
      <input type="hidden" name="stock" value="{{ $stock }}">
      <input type="hidden" name="sort" value="{{ $sort }}">
      <label for="inv-per-page-{{ $pagerWhere }}">Rows</label>
      <select name="perPage" id="inv-per-page-{{ $pagerWhere }}" onchange="this.form.submit()">
        @foreach($perPageAllowed as $opt)
          <option value="{{ $opt }}" @selected($opt === $perPage)>{{ $opt }}</option>
        @endforeach
      </select>
    </form>
  @endif

  @if($pages > 1)
    <div class="inv-pager-nav">
      @if($page > 1)
        <a href="?{{ $qs($page - 1) }}" class="ia-btn ia-btn--ghost">← Prev</a>
      @else
        <span class="ia-btn ia-btn--ghost is-off">← Prev</span>
      @endif

      @php $last = 0; @endphp
      @foreach($nums as $n)
        @if($last && $n - $last > 1)
          <span class="inv-pager-gap">…</span>
        @endif
        @if($n === $page)
          <span class="inv-pager-num is-here">{{ $n }}</span>
        @else
          <a href="?{{ $qs($n) }}" class="inv-pager-num">{{ $n }}</a>
        @endif
        @php $last = $n; @endphp
      @endforeach

      @if($page < $pages)
        <a href="?{{ $qs($page + 1) }}" class="ia-btn ia-btn--ghost">Next →</a>
      @else
        <span class="ia-btn ia-btn--ghost is-off">Next →</span>
      @endif
    </div>
  @endif

</div>
