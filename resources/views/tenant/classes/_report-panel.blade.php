{{--
  Reusable panel partial for the classes reports page. Renders a panel with
  header (title + tag + subtitle + export), then a list of customer rows
  (or an empty state).

  Required props:
    $rows        — Collection of row arrays from ClassReportsService
    $title       — Panel title (string)
    $tag         — Tag color: opportunity | risk | amber | info
    $tagLabel    — Short label for the tag (e.g. "Conversion")
    $subtitle    — Subtitle copy explaining the panel
    $exportSlug  — Slug for the CSV export route (matches controller match())
    $emptyText   — Copy when $rows is empty
    $sub         — Tenant subdomain (for route building)
--}}
@php
    /** Inline initials helper — first char of first two name words, uppercase. */
    $initials = function ($name) {
        $parts = preg_split('/\s+/', trim((string) $name));
        $i = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            if ($p !== '') $i .= strtoupper($p[0]);
        }
        return $i ?: '?';
    };
@endphp
<div class="rp-panel" data-rp-panel="{{ $exportSlug }}">
    <div class="rp-panel-head">
        <div class="rp-panel-title-wrap">
            <h2 class="rp-panel-title">{{ $title }} <span class="rp-panel-tag rp-tag-{{ $tag }}">{{ $tagLabel }}</span></h2>
            <p class="rp-panel-sub">{{ $subtitle }}</p>
        </div>
        <div class="rp-panel-actions">
            <a class="rp-export-btn" href="{{ route('tenant.classes.reports.export', ['panel' => $exportSlug]) }}">Export CSV</a>
        </div>
    </div>
    <div class="rp-row-list">
        @forelse($rows as $row)
            <a class="rp-row" href="{{ route('tenant.customers.show', ['id' => $row['customer_id']]) }}">
                <div class="rp-avatar {{ $row['severity'] }}">{{ $initials($row['name'] ?? '') }}</div>
                <div class="rp-row-main">
                    <div class="rp-row-name">{{ $row['name'] }}</div>
                    <div class="rp-row-fact">{{ $row['fact'] }}</div>
                </div>
                <div class="rp-row-meta">{{ $row['meta'] }}</div>
                <span class="rp-export-btn" style="cursor:default">{{ $row['cta'] }}</span>
            </a>
        @empty
            <div class="rp-empty">{{ $emptyText }}</div>
        @endforelse
    </div>
    <div class="rp-pager" data-rp-pager hidden>
        <button type="button" class="rp-pager-btn" data-rp-prev aria-label="Previous page">‹</button>
        <span class="rp-pager-status" data-rp-status>1–10 of —</span>
        <button type="button" class="rp-pager-btn" data-rp-next aria-label="Next page">›</button>
    </div>
</div>
