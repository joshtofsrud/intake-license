@extends('public.account._shell')
@php $pageTitle = 'Messages'; @endphp
{{-- MARKER-PORTAL-V2 --}}
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'messages'])

@if(session('success'))
  <div class="ac-flash ac-flash--success">{{ session('success') }}</div>
@endif
@if($errors->any())
  <div class="ac-flash ac-flash--error">{{ $errors->first() }}</div>
@endif

<div class="ac-section-title">{{ $currentTenant->name }}</div>
<div class="ac-msg-wrap">
  <div class="ac-msgs" id="ac-msgs">
    @forelse($messages as $m)
      @if($m->kind === 'transactional')
        <div class="ac-msg txn">{{ $m->body }}
          <div class="ac-msg-t">{{ tlocal_datetime($m->created_at, 'M j, g:i A') }}</div></div>
      @else
        <div class="ac-msg {{ $m->direction === 'in' ? 'me' : 'shop' }}">{{ $m->body }}
          <div class="ac-msg-t"><span class="ac-chan">{{ strtoupper($m->channel ?? 'web') }}</span>{{ tlocal_datetime($m->created_at, 'M j, g:i A') }}</div></div>
      @endif
    @empty
      <div class="ac-empty" style="background:transparent">No messages yet &mdash; say hi below.</div>
    @endforelse
  </div>
  <form method="POST" action="{{ route('tenant.customer.portal.messages.send') }}" class="ac-compose">
    @csrf
    <textarea name="body" rows="2" maxlength="1200" required placeholder="Message {{ $currentTenant->name }}&hellip;"></textarea>
    <button type="submit" aria-label="Send">&uarr;</button>
  </form>
</div>
<div style="font-size:12px;opacity:.45">Replies land here and by text or email &mdash; same conversation either way.</div>

<script>
  (function () { var m = document.getElementById('ac-msgs'); if (m) { m.scrollTop = m.scrollHeight; } })();
</script>
@endsection
