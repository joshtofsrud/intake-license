{{-- MARKER-SHOP-GA4 — the shop's own GA4 tag (Settings › Analytics), shared by
     the funnel tracker and the standalone rental pages so every public shop
     page reports. --}}
@php
  $shopGa4 = trim((string) (($currentTenant->settings['analytics_ga4_id'] ?? null) ?? ''));
  if (! preg_match('/^(G-|UA-)[A-Z0-9]{4,20}$/i', $shopGa4)) { $shopGa4 = ''; }
@endphp
@if($shopGa4 !== '')
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $shopGa4 }}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '{{ $shopGa4 }}', { 'anonymize_ip': true });
</script>
@endif
