{{-- MARKER-RENTALS-STUDIO: stylesheets every staff rental page shares.
     @include this at the TOP LEVEL of a rental view (not inside a section). --}}
@push('styles')
  @foreach (['dashboard-simple', 'dashboard-studio', 'rentals-studio'] as $rsCss)
    <link rel="stylesheet" href="{{ asset('css/tenant/'.$rsCss.'.css') }}?v={{ filemtime(public_path('css/tenant/'.$rsCss.'.css')) }}">
  @endforeach
@endpush
