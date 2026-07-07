{{--
  MARKER-PATCH-602 — booking marketing sections renderer (real page sections).
  Renders one slot ('before' or 'after' the booking form) worth of marketing
  sections that live as real TenantPageSection rows on the tenant's hidden
  "__booking_extras" page. Split by content['booking_slot']; rendered through
  the SAME public.sections._{type} partials the page builder uses — so they
  look identical to page-editor sections and never diverge.

  Usage: @include('public.sections._booking_extras', ['slot' => 'before'])
  Expects $bookingSections (Collection of TenantPageSection) in scope.
--}}
@php
  $__slot = $slot ?? 'before';
  $__list = ($bookingSections ?? collect())->filter(function ($s) use ($__slot) {
      return $s->is_visible && (($s->content['booking_slot'] ?? 'before') === $__slot);
  });
@endphp

@foreach($__list as $section)
  @php $__partial = 'public.sections._' . $section->section_type; @endphp
  @if(view()->exists($__partial))
    @include($__partial, [
      'c'        => $section->content ?? [],
      'section'  => $section,
      'navItems' => $navItems ?? collect(),
      'catalog'  => $catalog ?? collect(),
      'tenant'   => $currentTenant ?? tenant(),
    ])
  @endif
@endforeach

