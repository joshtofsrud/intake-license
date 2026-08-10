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
              {{-- MARKER-NOTEPHOTO — opens in the lightbox below, not a new tab --}}
              <button type="button" class="np-shot" data-full="{{ $u }}" aria-label="Open photo">
                <img src="{{ $u }}" alt="" loading="lazy">
              </button>
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
  /* MARKER-NOTEPHOTO */
  .np-shot { padding:0; border:0; background:none; cursor:zoom-in; line-height:0; border-radius:6px; }
  .np-shot:focus-visible { outline:2px solid #A8622A; outline-offset:2px; }
  .np-shots img { width:88px; height:88px; object-fit:cover; border-radius:6px; display:block;
                  border:1px solid #D9CDB0; transition:filter .12s; }
  .np-shot:hover img { filter:brightness(1.05); }

  #np-lb { position:fixed; inset:0; z-index:1400; background:rgba(24,20,12,.86);
           display:flex; align-items:center; justify-content:center; padding:32px; }
  #np-lb[hidden] { display:none; }
  #np-lb img { max-width:100%; max-height:100%; border-radius:8px; border:3px solid #F6F0E1;
               box-shadow:0 24px 70px rgba(0,0,0,.6); }
  .np-lb-btn { position:absolute; background:#F6F0E1; color:#3B3524; border:1px solid #D9CDB0;
               border-radius:50%; width:40px; height:40px; font-size:19px; line-height:1;
               cursor:pointer; display:flex; align-items:center; justify-content:center; }
  .np-lb-btn:hover { background:#fff; }
  .np-lb-x    { top:20px; right:20px; }
  .np-lb-prev { left:20px;  top:50%; transform:translateY(-50%); }
  .np-lb-next { right:20px; top:50%; transform:translateY(-50%); }
  .np-lb-btn[hidden] { display:none; }
  .np-lb-count { position:absolute; bottom:20px; left:50%; transform:translateX(-50%);
                 color:#E8DFC8; font-size:12px; letter-spacing:.04em; }
  .np-del { background:none; border:none; color:#8D8267; font-size:17px; line-height:1; cursor:pointer;
            padding:0 2px; }
  .np-del:hover { color:#A8622A; }
  .np-empty { padding:28px; text-align:center; font-size:13px; color:var(--ia-text-dim);
              border:.5px dashed var(--ia-border); border-radius:10px; }
</style>


{{-- MARKER-NOTEPHOTO — photo lightbox. Sits inside the content section: this
     view extends a layout, so anything after @endsection would be discarded. --}}
<div id="np-lb" hidden role="dialog" aria-modal="true" aria-label="Photo">
  <button type="button" class="np-lb-btn np-lb-x"    id="np-lb-x"    aria-label="Close">&times;</button>
  <button type="button" class="np-lb-btn np-lb-prev" id="np-lb-prev" aria-label="Previous photo">&#8249;</button>
  <img id="np-lb-img" src="" alt="">
  <button type="button" class="np-lb-btn np-lb-next" id="np-lb-next" aria-label="Next photo">&#8250;</button>
  <div class="np-lb-count" id="np-lb-count"></div>
</div>

<script>
(function () {
  var lb    = document.getElementById('np-lb');
  var img   = document.getElementById('np-lb-img');
  var prev  = document.getElementById('np-lb-prev');
  var next  = document.getElementById('np-lb-next');
  var count = document.getElementById('np-lb-count');
  if (!lb) { return; }

  var urls = [];
  var idx  = 0;

  function show(i) {
    if (!urls.length) { return; }
    idx = (i + urls.length) % urls.length;
    img.src = urls[idx];
    var many = urls.length > 1;
    prev.hidden = !many;
    next.hidden = !many;
    count.textContent = many ? (idx + 1) + ' of ' + urls.length : '';
  }

  function open(shot) {
    // Photos of THIS note only — arrows shouldn't wander into another note.
    var wrap = shot.closest('.np-shots');
    urls = Array.prototype.map.call(
      wrap ? wrap.querySelectorAll('.np-shot') : [shot],
      function (b) { return b.getAttribute('data-full'); }
    );
    show(urls.indexOf(shot.getAttribute('data-full')));
    lb.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function close() {
    lb.hidden = true;
    img.src = '';
    document.body.style.overflow = '';
  }

  document.addEventListener('click', function (e) {
    var shot = e.target.closest('.np-shot');
    if (shot) { open(shot); }
  });

  document.getElementById('np-lb-x').addEventListener('click', close);
  prev.addEventListener('click', function () { show(idx - 1); });
  next.addEventListener('click', function () { show(idx + 1); });

  // Backdrop only — clicking the photo itself shouldn't dismiss it.
  lb.addEventListener('click', function (e) { if (e.target === lb) { close(); } });

  document.addEventListener('keydown', function (e) {
    if (lb.hidden) { return; }
    if (e.key === 'Escape')     { close(); }
    if (e.key === 'ArrowLeft')  { show(idx - 1); }
    if (e.key === 'ArrowRight') { show(idx + 1); }
  });
})();
</script>

@endsection
