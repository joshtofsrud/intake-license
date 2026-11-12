{{-- the shop's own GA4 tag (Settings › Analytics), shared by
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
  // MARKER-SHOP-GA4: add ?ga_debug=1 to any page address to watch events live in GA4 DebugView
  gtag('config', '{{ $shopGa4 }}', { 'anonymize_ip': true, 'debug_mode': /[?&]ga_debug=1/.test(location.search) });
  // shop events: pages call shopEv(); it does nothing unless the shop has events switched on
  window.shopEv = {!! \App\Support\ShopGa4::on($currentTenant)
      ? "function (n, p) { try { p = p || {}; if (!p.currency) { p.currency = " . json_encode(strtoupper((string) ($currentTenant->currency ?: 'USD'))) . "; } gtag('event', n, p); } catch (e) {} }"
      : 'function () {}' !!};
</script>
@else
<script>window.shopEv = function () {};</script>
@endif
