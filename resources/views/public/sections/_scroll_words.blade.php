{{-- MARKER-SCROLL-WORDS --}}
@include('partials.scroll-words', ['c' => $c ?? ($section->content ?? []), 'section' => $section])
