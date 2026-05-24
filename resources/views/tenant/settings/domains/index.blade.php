@extends('layouts.tenant.app')

@section('content')
<div style="padding: 24px 32px;">
  <div style="margin-bottom: 24px;">
    <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; color: #555; margin-bottom: 6px;">
      Settings → Domains
    </div>
    <div style="font-size: 22px; font-weight: 800; letter-spacing: -0.02em;">Domains</div>
    <div style="font-size: 13px; color: #888; margin-top: 4px;">
      Stub view — full UI coming in Patch 120 Part 2.
    </div>
  </div>

  <div style="padding: 18px; background: #131313; border: 1px solid #1f1f1f; border-radius: 12px;">
    <p style="margin-bottom: 12px;">You have {{ $usage }} domain(s).</p>
    @if($limit !== null)
      <p style="font-size: 12px; color: #888;">Limit: {{ $limit }} on your current plan.</p>
    @else
      <p style="font-size: 12px; color: #888;">No domain limit on your plan.</p>
    @endif

    @if(session('success'))
      <p style="margin-top: 12px; color: #BEF264;">{{ session('success') }}</p>
    @endif
    @if($errors->any())
      <p style="margin-top: 12px; color: #F87171;">{{ $errors->first() }}</p>
    @endif

    @foreach($domains as $d)
      <div style="margin-top: 12px; font-family: monospace;">
        {{ $d->hostname }} — <span style="color: #888;">{{ $d->status }}</span>
        @if($d->is_primary)
          <span style="font-size: 10px; color: #BEF264;">[primary]</span>
        @endif
      </div>
    @endforeach
  </div>
</div>
@endsection
