{{-- MARKER-WEB-TEMPLATES: the sample shop the Preview tab draws a template on (same sample the
     approved mockup used). Kept as files: inline, its HTML strings confuse Livewire's page parsing. --}}
@php $ssFonts = implode('&', array_map(fn ($f) => 'family=' . \App\Support\SiteLook::fontParam($f), \App\Support\SiteLook::FONTS)); @endphp
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?{{ $ssFonts }}&display=swap">
<link rel="stylesheet" href="{{ asset('css/admin/site-sample.css') }}?v={{ @filemtime(public_path('css/admin/site-sample.css')) }}">
<script src="{{ asset('js/admin/site-sample.js') }}?v={{ @filemtime(public_path('js/admin/site-sample.js')) }}" charset="utf-8"></script>
