{{-- MARKER-PATCH-364 — themed Intake paginator (replaces unstyled default). --}}
@if ($paginator->hasPages())
  <style>
    .ia-pager{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:6px}
    .ia-pager .ia-pg{min-width:34px;height:34px;padding:0 7px;display:inline-flex;align-items:center;justify-content:center;
      border:1px solid var(--ia-border);border-radius:8px;font-size:13px;font-weight:600;color:var(--ia-text-3);
      background:rgba(255,255,255,.02);text-decoration:none;font-family:inherit}
    .ia-pager a.ia-pg:hover{color:var(--ia-text);border-color:var(--ia-border-2)}
    .ia-pager .ia-pg-cur{background:rgba(232,154,79,.12);border-color:rgba(232,154,79,.34);color:var(--ia-accent)}
    .ia-pager .ia-pg-dis{opacity:.35}
    .ia-pager .ia-pg-dots{border:none;background:none;min-width:auto}
  </style>
  <nav class="ia-pager" role="navigation" aria-label="Pagination">
    @if ($paginator->onFirstPage())
      <span class="ia-pg ia-pg-dis" aria-disabled="true" aria-label="Previous">&lsaquo;</span>
    @else
      <a class="ia-pg" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous">&lsaquo;</a>
    @endif

    @foreach ($elements as $element)
      @if (is_string($element))
        <span class="ia-pg ia-pg-dots" aria-disabled="true">{{ $element }}</span>
      @endif
      @if (is_array($element))
        @foreach ($element as $page => $url)
          @if ($page == $paginator->currentPage())
            <span class="ia-pg ia-pg-cur" aria-current="page">{{ $page }}</span>
          @else
            <a class="ia-pg" href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
          @endif
        @endforeach
      @endif
    @endforeach

    @if ($paginator->hasMorePages())
      <a class="ia-pg" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next">&rsaquo;</a>
    @else
      <span class="ia-pg ia-pg-dis" aria-disabled="true" aria-label="Next">&rsaquo;</span>
    @endif
  </nav>
@endif
