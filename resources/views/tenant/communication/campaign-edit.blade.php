@extends('layouts.tenant.app')
@php $pageTitle = 'Campaign'; @endphp

{{-- MARKER-CAMPAIGNS-CORE — campaign edit shell. The block builder, audience
     picker and send review land in the next patches and mount on this page. --}}
@push('styles')
<style>
  .ce-wrap{max-width:760px}
  .ce-crumb{color:var(--ia-text-3,#74747a);font-size:12.5px;margin-bottom:14px}
  .ce-crumb a{color:var(--ia-text-2,#a6a6ac);text-decoration:none}
  .ce-card{background:var(--ia-surface,#161619);border:1px solid var(--ia-border,#2a2a2e);border-radius:13px;padding:20px;margin-bottom:16px}
  .ce-lbl{display:block;font-size:11.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--ia-text-3,#74747a);margin:0 0 6px}
  .ce-in{width:100%;box-sizing:border-box;background:var(--ia-bg,#0f0f11);border:1px solid var(--ia-border,#2a2a2e);border-radius:9px;color:var(--ia-text,#f4f4f5);font:inherit;font-size:15px;padding:10px 12px;margin-bottom:16px}
  .ce-save{appearance:none;border:none;cursor:pointer;font:inherit;font-weight:640;background:var(--ia-accent,#e0a82e);color:#141414;border-radius:9px;padding:10px 18px}
  .ce-ghost{appearance:none;background:none;cursor:pointer;font:inherit;border:1px solid var(--ia-border,#2a2a2e);color:var(--ia-text-2,#a6a6ac);border-radius:9px;padding:10px 16px}
  .ce-note{background:var(--ia-surface,#161619);border:1px solid var(--ia-border,#2a2a2e);border-radius:11px;padding:10px 14px;color:var(--ia-text-2,#a6a6ac);font-size:12.5px;margin-bottom:16px}
</style>
@endpush

@section('content')
<div class="ce-wrap">
  <div class="ce-crumb"><a href="{{ route('tenant.communication.index') }}#campaigns">Communication → Campaigns</a> → {{ $campaign->name }}</div>

  @if(session('success'))<div class="ce-note" style="border-color:rgba(120,200,120,.4)">{{ session('success') }}</div>@endif
  @if(session('error'))<div class="ce-note" style="border-color:rgba(220,120,120,.5)">{{ session('error') }}</div>@endif

  <div class="ce-note">
    This step saves the campaign's name and subject. The email designer, the audience picker and sending are the next build steps — this campaign cannot send yet.
  </div>

  <form method="POST" action="{{ route('tenant.campaigns.update', $campaign->id) }}">
    @csrf
    @method('PATCH')
    <div class="ce-card">
      <label class="ce-lbl" for="ce-name">Campaign name — internal, customers never see it</label>
      <input class="ce-in" id="ce-name" name="name" value="{{ old('name', $campaign->name) }}" maxlength="160" {{ $campaign->isEditable() ? '' : 'disabled' }}>

      <label class="ce-lbl" for="ce-subject">Subject line</label>
      <input class="ce-in" id="ce-subject" name="subject" value="{{ old('subject', $campaign->subject) }}" maxlength="200" placeholder="e.g. Spring tune-up — $20 off through March" {{ $campaign->isEditable() ? '' : 'disabled' }} style="margin-bottom:4px">
    </div>

    @if($campaign->isEditable())
      <div style="display:flex;gap:10px;align-items:center">
        <button type="submit" class="ce-save">Save</button>
      </div>
    @endif
  </form>

  @if($campaign->isEditable())
    <form method="POST" action="{{ route('tenant.campaigns.destroy', $campaign->id) }}" style="margin-top:14px" onsubmit="return confirm('Delete this campaign? This can\'t be undone.')">
      @csrf
      @method('DELETE')
      <button type="submit" class="ce-ghost">Delete campaign</button>
    </form>
  @endif
</div>
@endsection
