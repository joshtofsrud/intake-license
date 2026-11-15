{{-- MARKER-SHOP-THEME: the site's heading and body fonts, for standalone public pages that do not use the layout --}}
@php
  $__dt = \App\Support\DesignTokens::resolve($currentTenant ?? tenant());
  $__fq = implode('&family=', array_map(fn($f) => \App\Support\SiteLook::fontParam($f), array_unique(array_filter([$__dt['font_heading'] ?? null, $__dt['font_body'] ?? null]))));
@endphp
@if($__fq !== '')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family={{ $__fq }}&display=swap" rel="stylesheet">
@endif
