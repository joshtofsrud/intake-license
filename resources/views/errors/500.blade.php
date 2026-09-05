@extends('errors._shell')
@section('page_title', '500 — Something broke')
@section('eyebrow', '500 · Something broke on our end')
@section('eyebrow_tone', 'tone-red')
@section('title')
That's <span class="err-title-accent">on us</span>, not you.
@endsection
@section('body')
Something went sideways. We've been notified automatically and we'll dig into it. In the meantime, going back and trying again often works — most issues like this clear in seconds.
@endsection
@section('mini_links')
  <a href="{{ url('/status') }}">Status</a>
  <a href="{{ url('/docs') }}">Help</a>
@endsection
@section('actions')
  {{-- MARKER-ERR-HOME --}}
  <a href="{{ error_home_url() }}" class="btn btn-primary">← Back to dashboard</a>
  <a href="javascript:window.location.reload()" class="btn btn-secondary">Try this page again</a>
@endsection
@section('status_block')
<div class="err-status-block">
  <div class="err-status-row">
    <span>Reference ID</span>
    <span class="err-status-value">{{ $errorRefId ?? 'ERR-' . strtoupper(\Illuminate\Support\Str::random(8)) }}</span>
  </div>
  <div class="err-status-row">
    <span>Time</span>
    <span class="err-status-value">{{ now()->format('M j, Y · H:i') }} UTC</span>
  </div>
  <div class="err-status-row">
    <span>System status</span>
    <span class="err-status-pill ok"><span class="err-status-dot"></span> All systems normal</span>
  </div>
</div>
@endsection
@section('footer_text', 'Persistent issue? Email')
