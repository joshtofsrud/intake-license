{{-- GA4, Google Tag Manager and Plausible for intake.works,
     set in master admin › Site settings › Analytics. Loaded on every public
     intake.works page that carries the marketing tracker; never on booking
     management links or investor pages. Values are re-checked here so a bad
     entry can't inject anything. --}}
@php
  $mkSet  = \App\Models\SiteSettings::current();
  $mkGa4  = trim((string) ($mkSet->ga4_id ?? ''));
  $mkGtm  = trim((string) ($mkSet->gtm_id ?? ''));
  $mkPlau = trim((string) ($mkSet->plausible_domain ?? ''));
  if (! preg_match('/^G-[A-Z0-9]{4,20}$/i', $mkGa4))  { $mkGa4 = ''; }
  if (! preg_match('/^GTM-[A-Z0-9]{4,12}$/i', $mkGtm)) { $mkGtm = ''; }
  if (! preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/i', $mkPlau)) { $mkPlau = ''; }
  $mkSkip = request()->is('book/manage*') || request()->is('invest*');
@endphp
@if(! $mkSkip)
  @if($mkGa4 !== '')
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $mkGa4 }}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '{{ $mkGa4 }}', { 'anonymize_ip': true });
</script>
  @endif
  @if($mkGtm !== '')
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{{ $mkGtm }}');</script>
  @endif
  @if($mkPlau !== '')
<script defer data-domain="{{ $mkPlau }}" src="https://plausible.io/js/script.js"></script>
  @endif
@endif
