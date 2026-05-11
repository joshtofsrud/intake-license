{{-- ================================================================
     Renders a friendly notice on mobile that this page is best on
     desktop. Use at the top of pages too complex for phone editing.

     Usage:
       @include('layouts.tenant._desktop-only-notice', [
         'pageName' => 'Intake Form Editor',
       ])

     The actual page content can still render below — the notice is
     informational, not blocking. Pages may also choose to early-return
     a stripped-down read-only view on mobile.
     ================================================================ --}}
<div class="ia-desktop-only-notice">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <rect x="2" y="3" width="20" height="14" rx="2"/>
    <line x1="8" y1="21" x2="16" y2="21"/>
    <line x1="12" y1="17" x2="12" y2="21"/>
  </svg>
  <div>
    <strong>{{ $pageName ?? 'This page' }}</strong> works best on a larger screen.
    <span class="muted">Open this URL on your computer for the full editor.</span>
  </div>
</div>
