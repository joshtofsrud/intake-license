{{--
  MARKER-PATCH-603 — booking marketing sections renderer.
  $bookingSections is ['before' => Collection, 'after' => Collection], split
  around the booking_embed pivot on the tenant's Booking page (slug "book",
  edited in the normal page builder). Rendered via the same public section
  partials as any builder page.

  Usage: @include('public.sections._booking_extras', ['slot' => 'before'])
--}}
@php $__list = ($bookingSections[$slot ?? 'before'] ?? collect()); @endphp

@foreach($__list as $section)
  @php $__partial = 'public.sections._' . $section->section_type; @endphp
  @if($section->section_type !== 'booking_embed' && view()->exists($__partial))
    @include($__partial, [
      'c'        => $section->content ?? [],
      'section'  => $section,
      'navItems' => $navItems ?? collect(),
      'catalog'  => $catalog ?? collect(),
      'tenant'   => $currentTenant ?? tenant(),
    ])
  @endif
@endforeach

