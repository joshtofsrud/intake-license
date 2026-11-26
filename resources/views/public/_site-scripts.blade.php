{{-- MARKER-SITE-SCRIPTS: the shop's own scripts (page builder › Site scripts). $ssPart = head | body.
     Raw on purpose, like Custom HTML: only the shop's owners can set them. --}}
@php
  $ssTenant  = $currentTenant ?? (function_exists('tenant') ? tenant() : null);
  $ssPreview = ! empty($builderPreview) || \App\Support\SiteLook::previewing();
  $ssPageId  = isset($page) && is_object($page) && isset($page->id) ? (string) $page->id : null;
@endphp
{!! \App\Support\SiteScripts::render($ssPart ?? 'body', $ssTenant, $ssPageId, $ssPreview) !!}
