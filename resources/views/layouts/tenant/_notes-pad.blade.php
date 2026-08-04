{{-- MARKER-OLD-SCHOOL — the pad button and its panel.

     Adding and ticking are plain form posts that return to the current page,
     so capture works with no JavaScript at all. The only script here opens
     and closes the panel. --}}
@php
  $padNotes = \App\Models\Tenant\TenantNote::where('tenant_id', tenant()->id)
      ->whereNull('completed_at')
      ->with('customer')
      ->orderBy('created_at')
      ->limit(8)
      ->get();
  $padOpen = \App\Http\Controllers\Tenant\NoteController::openCount();
  // Pages may pre-attach a customer by setting $noteCustomer. That is the
  // whole friction saving — the note already knows who it is about.
  $padCustomer = $noteCustomer ?? null;
@endphp

<div class="pad" data-pad>
  <button type="button" class="pad-btn" data-pad-toggle aria-label="Notes" title="Notes">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <path d="M4 4h11l5 5v11H4z"/><path d="M8 10h8M8 14h5"/>
    </svg>
    @if($padOpen > 0)<span class="pad-badge">{{ $padOpen > 99 ? '99+' : $padOpen }}</span>@endif
  </button>

  <div class="pad-panel" data-pad-panel hidden>
    <form method="POST" action="{{ route('tenant.notes.store') }}" class="pad-new">
      @csrf
      <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
      @if($padCustomer)
        <input type="hidden" name="customer_id" value="{{ $padCustomer->id }}">
      @endif
      <textarea name="body" rows="3" placeholder="Write it down…" required></textarea>
      <div class="pad-new-foot">
        @if($padCustomer)
          <span class="pad-chip">{{ $padCustomer->first_name }} {{ $padCustomer->last_name }}</span>
        @else
          <span class="pad-hint">no customer</span>
        @endif
        <button type="submit" class="pad-add">Add note</button>
      </div>
    </form>

    <div class="pad-list">
      @forelse($padNotes as $n)
        <div class="pad-note">
          <form method="POST" action="{{ route('tenant.notes.toggle', $n->id) }}">
            @csrf
            <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
            <button type="submit" class="pad-box" aria-label="Cross off"></button>
          </form>
          <div class="pad-body">
            <div class="pad-text">{{ $n->body }}</div>
            <div class="pad-meta">
              @if($n->customer)
                <span class="pad-who">{{ $n->customer->first_name }} {{ $n->customer->last_name }}</span>
              @endif
              <span @class(['pad-age' => $n->ageInDays() >= 7])>
                {{ $n->created_at?->diffForHumans() }}
              </span>
            </div>
          </div>
        </div>
      @empty
        <div class="pad-empty">Nothing on the pad.</div>
      @endforelse
    </div>

    <a href="{{ route('tenant.notes.index') }}" class="pad-foot">
      Open the pad
      @if($padOpen > count($padNotes)) · {{ $padOpen }} total @endif
    </a>
  </div>
</div>

<style>
  .pad { position:relative; }
  .pad-btn { position:relative; width:36px; height:36px; border-radius:10px; display:flex; align-items:center;
             justify-content:center; background:transparent; border:none; cursor:pointer; color:var(--ia-text);
             opacity:.55; transition:opacity .15s ease, background .15s ease; }
  .pad-btn:hover { opacity:1; background:rgba(127,127,127,.09); }
  .pad-btn:focus { outline:none; }
  .pad-badge { position:absolute; top:-4px; right:-4px; min-width:16px; height:16px; padding:0 4px;
               border-radius:999px; background:#B8860B; color:#fff; font-size:10px; font-weight:700;
               display:flex; align-items:center; justify-content:center; }

  /* MARKER-OLD-SCHOOL-BANNER — 9990: above page furniture, below the 9999
     that real modals use, so a dialog still covers the pad. The number alone
     is not enough; see the reparent in the script below. */
  .pad-panel { position:fixed; width:320px; z-index:9990; border-radius:12px; overflow:hidden;
               background:#F4ECD8; color:#2A2419;
               box-shadow:0 8px 30px rgba(0,0,0,.28), inset 0 0 0 .5px rgba(0,0,0,.12); }

  .pad-new { padding:12px 12px 10px; border-bottom:1px solid #D9CDB0; }
  .pad-new textarea { width:100%; border:1px solid #D9CDB0; border-radius:8px; padding:9px 10px;
                      font-family:inherit; font-size:13px; line-height:1.5; resize:vertical;
                      background:#FBF7EC; color:#2A2419; }
  .pad-new textarea:focus { outline:none; border-color:#B8860B; }
  .pad-new-foot { display:flex; align-items:center; gap:8px; margin-top:8px; }
  .pad-chip { background:#DBE6D5; color:#33452C; border-radius:5px; padding:2px 8px; font-size:11px; font-weight:600; }
  .pad-hint { font-size:11px; color:#7A7159; }
  .pad-add { margin-left:auto; background:#B8860B; color:#fff; border:none; border-radius:8px;
             padding:7px 13px; font-size:12px; font-weight:650; cursor:pointer; font-family:inherit; }

  .pad-list { max-height:320px; overflow-y:auto; }
  .pad-note { display:flex; gap:10px; align-items:flex-start; padding:10px 12px; border-bottom:1px solid #D9CDB0; }
  .pad-box { width:18px; height:18px; border:1.6px solid #8D8267; border-radius:4px; background:#FBF7EC;
             cursor:pointer; flex:none; margin-top:1px; padding:0; }
  .pad-box:hover { background:#8D8267; }
  .pad-body { flex:1; min-width:0; }
  .pad-text { font-size:13px; line-height:1.45; word-break:break-word; }
  .pad-meta { display:flex; gap:7px; align-items:center; margin-top:4px; font-size:10.5px; color:#7A7159; }
  .pad-who { background:#E3D8BD; color:#4A4231; border-radius:4px; padding:1px 6px; font-weight:600; }
  .pad-age { color:#A8622A; font-weight:600; }
  .pad-empty { padding:18px 12px; font-size:12.5px; color:#7A7159; text-align:center; }
  .pad-foot { display:block; padding:10px 12px; font-size:12px; color:#5A5343; text-decoration:none;
              border-top:1px solid #D9CDB0; background:#EDE3CC; }
  .pad-foot:hover { background:#E5DAC0; }
</style>

<script>
( function () {
  var wrap = document.querySelector( '[data-pad]' );
  if ( !wrap ) { return; }
  var btn   = wrap.querySelector( '[data-pad-toggle]' );
  var panel = wrap.querySelector( '[data-pad-panel]' );
  if ( !btn || !panel ) { return; }

  function place() {
    var r = btn.getBoundingClientRect();
    panel.style.top = ( r.bottom + 8 ) + 'px';
    // Keep it on screen on narrow viewports rather than hanging off the edge.
    var left = Math.min( r.right - 320, window.innerWidth - 330 );
    panel.style.left = Math.max( 10, left ) + 'px';
  }

  // MARKER-OLD-SCHOOL-BANNER — move the panel to <body> before it is ever
  // shown. A fixed element inside an ancestor that has position + z-index is
  // trapped in that ancestor's stacking context, so it can be painted behind
  // page content that declares no z-index at all. Reparenting escapes every
  // such context; raising the number would only appear to fix it.
  var moved = false;
  function liftOut() {
    if ( moved ) { return; }
    document.body.appendChild( panel );
    moved = true;
  }

  btn.addEventListener( 'click', function ( e ) {
    e.stopPropagation();
    var showing = !panel.hasAttribute( 'hidden' );
    if ( showing ) { panel.setAttribute( 'hidden', '' ); return; }
    liftOut();
    place();
    panel.removeAttribute( 'hidden' );
    var ta = panel.querySelector( 'textarea' );
    if ( ta ) { ta.focus(); }
  } );

  document.addEventListener( 'click', function ( e ) {
    // panel.contains is required as well as wrap.contains: after liftOut()
    // the panel is a child of <body>, so a click inside it is no longer
    // inside .pad and would otherwise close the thing being typed into.
    if ( !panel.hasAttribute( 'hidden' )
         && !wrap.contains( e.target )
         && !panel.contains( e.target ) ) {
      panel.setAttribute( 'hidden', '' );
    }
  } );

  document.addEventListener( 'keydown', function ( e ) {
    if ( e.key === 'Escape' ) { panel.setAttribute( 'hidden', '' ); }
  } );

  window.addEventListener( 'resize', function () {
    if ( !panel.hasAttribute( 'hidden' ) ) { place(); }
  } );
}() );
</script>
