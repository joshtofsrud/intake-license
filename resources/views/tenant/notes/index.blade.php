@extends('layouts.tenant.app')
@php $pageTitle = 'Notes'; @endphp

@section('content')

{{-- MARKER-OLD-SCHOOL --}}
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">The pad</h1>
    <p class="ia-page-subtitle">
      Write it down, cross it off. Notes here are a scratch pad — they never go onto a customer's record.
    </p>
  </div>
</div>

@if($oldest && $oldest->ageInDays() >= 7)
  <div class="np-stale">
    Oldest note has been sitting for <b>{{ $oldest->ageInDays() }} days</b> — a pad nobody clears stops
    getting read.
  </div>
@endif

<div class="np-tabs">
  <a href="{{ route('tenant.notes.index') }}"
     class="np-tab {{ $tab === 'open' ? 'on' : '' }}">Open {{ $openCount }}</a>
  <a href="{{ route('tenant.notes.index', ['tab' => 'done']) }}"
     class="np-tab {{ $tab === 'done' ? 'on' : '' }}">Crossed off {{ $doneCount }}</a>
  {{-- MARKER-OLD-SCHOOL-REPORT --}}
  <a href="{{ route('tenant.notes.index', ['tab' => 'report']) }}"
     class="np-tab {{ $tab === 'report' ? 'on' : '' }}">How it's going</a>
</div>

<div class="np-list">
  @forelse($notes as $n)
    <div class="np-note {{ $n->completed_at ? 'done' : '' }}">
      <form method="POST" action="{{ route('tenant.notes.toggle', $n->id) }}">
        @csrf
        <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
        <button type="submit" class="np-box {{ $n->completed_at ? 'on' : '' }}"
                aria-label="{{ $n->completed_at ? 'Put back on the pad' : 'Cross off' }}"></button>
      </form>

      <div class="np-body">
        <div class="np-text">{{ $n->body }}</div>
        {{-- MARKER-OLD-SCHOOL-PHOTO --}}
        @if($n->photos)
          <div class="np-shots">
            @foreach($n->photoUrls() as $u)
              <a href="{{ $u }}" target="_blank" rel="noopener"><img src="{{ $u }}" alt="" loading="lazy"></a>
            @endforeach
          </div>
        @endif
        <div class="np-meta">
          @if($n->customer)
            <a class="np-who" href="{{ route('tenant.customers.show', $n->customer->id) }}">
              {{ $n->customer->first_name }} {{ $n->customer->last_name }}
            </a>
          @endif
          <span>{{ $n->author?->name ?? 'someone' }} · {{ $n->created_at?->diffForHumans() }}</span>
          @if($n->completed_at)
            <span class="np-done-by">crossed off by {{ $n->completer?->name ?? 'someone' }}
              {{ $n->completed_at->diffForHumans() }}</span>
          @elseif($n->ageInDays() >= 7)
            <span class="np-age">{{ $n->ageInDays() }} days old</span>
          @endif
        </div>
      </div>

      <form method="POST" action="{{ route('tenant.notes.destroy', $n->id) }}"
            onsubmit="return confirm('Delete this note? It is not kept anywhere else.')">
        @csrf
        @method('DELETE')
        <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
        <button type="submit" class="np-del" aria-label="Delete">×</button>
      </form>
    </div>
  @empty
    <div class="np-empty">
      {{ $tab === 'done' ? 'Nothing crossed off yet.' : 'Nothing on the pad. Use the notes button up top.' }}
    </div>
  @endforelse
</div>

<div style="margin-top:14px">{{ $notes->withQueryString()->links() }}</div>

<style>
  .np-stale { background:rgba(184,134,11,.10); border:.5px solid rgba(184,134,11,.35); border-radius:9px;
              padding:10px 13px; font-size:12.5px; margin-bottom:14px; }
  .np-tabs { display:flex; gap:6px; margin-bottom:14px; }
  .np-tab { padding:6px 13px; border-radius:999px; font-size:12.5px; text-decoration:none;
            border:.5px solid var(--ia-border); color:var(--ia-text-dim); }
  .np-tab.on { background:#B8860B; border-color:#B8860B; color:#fff; font-weight:650; }

  .np-list { display:flex; flex-direction:column; gap:8px; }
  .np-note { display:flex; gap:11px; align-items:flex-start; background:#F4ECD8; color:#2A2419;
             border-radius:10px; padding:12px 13px; }
  .np-note.done { opacity:.62; }
  .np-box { width:19px; height:19px; border:1.6px solid #8D8267; border-radius:4px; background:#FBF7EC;
            cursor:pointer; flex:none; margin-top:1px; padding:0; position:relative; }
  .np-box.on { background:#8D8267; }
  .np-box.on:after { content:''; position:absolute; left:5px; top:1px; width:5px; height:10px;
                     border:solid #FBF7EC; border-width:0 2px 2px 0; transform:rotate(43deg); }
  .np-body { flex:1; min-width:0; }
  .np-text { font-size:13.5px; line-height:1.5; word-break:break-word; }
  .np-note.done .np-text { text-decoration:line-through; text-decoration-color:#6B6250; }
  .np-meta { display:flex; gap:9px; align-items:center; flex-wrap:wrap; margin-top:6px;
             font-size:10.5px; color:#7A7159; }
  .np-who { background:#DBE6D5; color:#33452C; border-radius:4px; padding:1px 7px; font-weight:600;
            text-decoration:none; }
  .np-age { color:#A8622A; font-weight:600; }
  .np-done-by { color:#5F7A55; }
  /* MARKER-OLD-SCHOOL-PHOTO */
  .np-shots { display:flex; gap:6px; margin-top:7px; flex-wrap:wrap; }
  .np-shots img { width:64px; height:64px; object-fit:cover; border-radius:6px; display:block;
                  border:1px solid #D9CDB0; }
  .np-del { background:none; border:none; color:#8D8267; font-size:17px; line-height:1; cursor:pointer;
            padding:0 2px; }
  .np-del:hover { color:#A8622A; }
  .np-empty { padding:28px; text-align:center; font-size:13px; color:var(--ia-text-dim);
              border:.5px dashed var(--ia-border); border-radius:10px; }
</style>

@endsection
