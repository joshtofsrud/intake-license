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
    {{-- MARKER-OLD-SCHOOL-PHOTO — enctype is required or the files are
         silently dropped and the note saves without them. --}}
    <form method="POST" action="{{ route('tenant.notes.store') }}" class="pad-new"
          enctype="multipart/form-data">
      @csrf
      <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
      <textarea name="body" rows="3" placeholder="Write it down…" required></textarea>
      {{-- MARKER-PAD-CUSTOMER — the hidden field is the single source of
           truth for who the note is about. The chip and the search only ever
           write to it. --}}
      <input type="hidden" name="customer_id" data-pad-cid value="{{ $padCustomer->id ?? '' }}">

      <div class="pad-pick" data-pad-pick @if($padCustomer) hidden @endif>
        <input type="text" class="pad-search" data-pad-search autocomplete="off"
               placeholder="Attach a customer (optional)…">
        <div class="pad-results" data-pad-results hidden></div>
      </div>

      <div class="pad-new-foot">
        <span class="pad-chip" data-pad-chip @unless($padCustomer) hidden @endunless>
          <span data-pad-chip-name>{{ $padCustomer ? $padCustomer->first_name . ' ' . $padCustomer->last_name : '' }}</span>
          <button type="button" data-pad-chip-clear aria-label="Detach">×</button>
        </span>
        <span class="pad-hint" data-pad-hint @if($padCustomer) hidden @endif>no customer</span>
        {{-- MARKER-OLD-SCHOOL-PHOTO — capture="environment" makes a phone
             open the rear camera rather than the photo library. --}}
        <label class="pad-cam" title="Add a photo">
          <input type="file" name="photos[]" accept="image/*" capture="environment" multiple hidden data-pad-photos>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 7h3l2-2h8l2 2h3v12H3z"/><circle cx="12" cy="13" r="3.5"/>
          </svg>
          <span data-pad-photo-count hidden></span>
        </label>
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

{{-- MARKER-PAD-MOBILE — markup renders per instance; these do not. --}}
@once
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
  /* MARKER-PAD-CUSTOMER */
  .pad-pick { position:relative; margin-top:8px; }
  .pad-search { width:100%; border:1px solid #D9CDB0; border-radius:8px; padding:7px 10px;
                font-family:inherit; font-size:12.5px; background:#FBF7EC; color:#2A2419; }
  .pad-search:focus { outline:none; border-color:#B8860B; }
  .pad-results { position:absolute; left:0; right:0; top:100%; margin-top:4px; z-index:5;
                 background:#FBF7EC; border:1px solid #D9CDB0; border-radius:8px; overflow:hidden;
                 max-height:190px; overflow-y:auto; box-shadow:0 6px 18px rgba(0,0,0,.22); }
  .pad-res { display:block; width:100%; text-align:left; background:none; border:0; cursor:pointer;
             padding:7px 10px; font-family:inherit; font-size:12.5px; color:#2A2419;
             border-bottom:1px solid #EDE3CC; }
  .pad-res:last-child { border-bottom:0; }
  .pad-res:hover, .pad-res.on { background:#EDE3CC; }
  .pad-res small { display:block; color:#7A7159; font-size:10.5px; margin-top:1px; }
  .pad-none { padding:9px 10px; font-size:12px; color:#7A7159; }
  .pad-chip button { background:none; border:0; color:#33452C; cursor:pointer; font-size:13px;
                     line-height:1; padding:0 0 0 5px; opacity:.6; }
  .pad-chip button:hover { opacity:1; }
  /* MARKER-OLD-SCHOOL-PHOTO */
  .pad-cam { margin-left:auto; display:inline-flex; align-items:center; gap:5px; cursor:pointer;
             border:1px solid #D9CDB0; border-radius:8px; padding:6px 9px; color:#5A5343;
             background:#FBF7EC; font-size:11.5px; }
  .pad-cam:hover { background:#EDE3CC; }
  .pad-add {  background:#B8860B; color:#fff; border:none; border-radius:8px;
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

  /* MARKER-PAD-MOBILE-POS — below 1024px the mobile header is the one on
     screen (the desktop attention row is hidden), so this only ever restyles
     the visible instance. Matched to .ia-mobile-header-bell: 38x38, 10px
     radius, 20px icon, and the same absolute anchoring from the right edge —
     that header lays its icons out by offset, not by flow. */
  @media (max-width: 1023px) {
    .pad {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      display: flex;
      align-items: center;
    }
    .pad-btn {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      opacity: 1;
      color: rgba(255, 255, 255, .9);
    }
    body.ia-theme-b .pad-btn { color: rgba(0, 0, 0, .78); }
    .pad-btn:active { background: rgba(127, 127, 127, .12); }
    .pad-btn svg { width: 20px; height: 20px; }
    /* Same 18px circle the bell badge uses, so two adjacent counts sit level. */
    .pad-badge {
      min-width: 18px;
      height: 18px;
      top: 2px;
      right: 2px;
      font-size: 10.5px;
    }
  }
</style>

<script>
/* MARKER-PAD-MOBILE — the partial renders in BOTH headers, so there are two
   instances in the DOM and CSS decides which is visible. querySelector bound
   only the first, which left the mobile button dead while a hidden desktop
   panel held all the wiring. Bind each one. */
( function () {
  /* MARKER-PAD-READY — wait for the document.

     @once emits this block at the FIRST include (the mobile header), and the
     desktop attention row is further down the page. Running immediately meant
     querySelectorAll found only the instance that had already been parsed,
     leaving the desktop button with no handler at all. */
  function initPads() {
    document.querySelectorAll( '[data-pad]' ).forEach( function ( wrap ) {
      // Idempotent: a second pass must not stack a second set of listeners
      // on a button that already has them.
      if ( wrap.hasAttribute( 'data-pad-bound' ) ) { return; }
      wrap.setAttribute( 'data-pad-bound', '' );
      bindPad( wrap );
    } );
  }

  if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', initPads );
  } else {
    initPads();
  }

  function bindPad( wrap ) {
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

  /* MARKER-OLD-SCHOOL-PHOTO — a hidden file input gives no feedback that
     anything was picked, so say how many. */
  var photos = panel.querySelector( '[data-pad-photos]' );
  var pcount = panel.querySelector( '[data-pad-photo-count]' );
  if ( photos && pcount ) {
    photos.addEventListener( 'change', function () {
      var n = photos.files ? photos.files.length : 0;
      if ( n > 0 ) {
        pcount.textContent = n;
        pcount.removeAttribute( 'hidden' );
      } else {
        pcount.setAttribute( 'hidden', '' );
      }
    } );
  }

  /* MARKER-PAD-CUSTOMER — a small picker against the same endpoint the
     shared component uses. Kept inside this IIFE so nothing it declares can
     collide with another script on the page. */
  var cid     = panel.querySelector( '[data-pad-cid]' );
  var pick    = panel.querySelector( '[data-pad-pick]' );
  var search  = panel.querySelector( '[data-pad-search]' );
  var results = panel.querySelector( '[data-pad-results]' );
  var chip    = panel.querySelector( '[data-pad-chip]' );
  var chipNm  = panel.querySelector( '[data-pad-chip-name]' );
  var chipX   = panel.querySelector( '[data-pad-chip-clear]' );
  var hint    = panel.querySelector( '[data-pad-hint]' );

  if ( cid && pick && search && results && chip && chipNm && chipX && hint ) {
    var searchUrl = @json(route('tenant.customers.search', []));
    var timer = null;

    function attach( id, name ) {
      cid.value = id;
      chipNm.textContent = name;
      chip.removeAttribute( 'hidden' );
      hint.setAttribute( 'hidden', '' );
      pick.setAttribute( 'hidden', '' );
      results.setAttribute( 'hidden', '' );
      search.value = '';
    }

    function detach() {
      cid.value = '';
      chip.setAttribute( 'hidden', '' );
      hint.removeAttribute( 'hidden' );
      pick.removeAttribute( 'hidden' );
      search.focus();
    }

    chipX.addEventListener( 'click', detach );

    /* MARKER-PAD-PICKER-SHAPE — the endpoint wraps its rows in a key
       ("customers" today). Hunting for the first array rather than naming
       the key means a rename upstream cannot silently turn every search into
       "No match", which is exactly what happened. */
    function rowsOf( d ) {
      if ( Array.isArray( d ) ) { return d; }
      if ( !d || typeof d !== 'object' ) { return []; }
      for ( var k in d ) {
        if ( Object.prototype.hasOwnProperty.call( d, k ) && Array.isArray( d[ k ] ) ) {
          return d[ k ];
        }
      }
      return [];
    }

    function render( rows ) {
      results.innerHTML = '';
      if ( !rows.length ) {
        var none = document.createElement( 'div' );
        none.className = 'pad-none';
        none.textContent = 'No match.';
        results.appendChild( none );
        results.removeAttribute( 'hidden' );
        return;
      }
      rows.slice( 0, 8 ).forEach( function ( c ) {
        var b = document.createElement( 'button' );
        b.type = 'button';
        b.className = 'pad-res';
        // textContent throughout — a customer name is user data and must
        // never be written as markup.
        b.textContent = c.name || ( ( c.first_name || '' ) + ' ' + ( c.last_name || '' ) ).trim();
        var sub = c.email || c.phone || '';
        if ( sub ) {
          var s2 = document.createElement( 'small' );
          s2.textContent = sub;
          b.appendChild( s2 );
        }
        b.addEventListener( 'click', function () {
          attach( c.id, b.firstChild ? b.firstChild.textContent : 'customer' );
        } );
        results.appendChild( b );
      } );
      results.removeAttribute( 'hidden' );
    }

    search.addEventListener( 'input', function () {
      var q = search.value.trim();
      if ( timer ) { clearTimeout( timer ); }
      if ( q.length < 2 ) { results.setAttribute( 'hidden', '' ); return; }
      timer = setTimeout( function () {
        fetch( searchUrl + '?q=' + encodeURIComponent( q ), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
          } )
          .then( function ( r ) { return r.ok ? r.json() : []; } )
          .then( function ( d ) { render( rowsOf( d ) ); } )
          .catch( function () { results.setAttribute( 'hidden', '' ); } );
      }, 250 );
    } );

    search.addEventListener( 'keydown', function ( e ) {
      if ( e.key === 'Escape' ) { results.setAttribute( 'hidden', '' ); e.stopPropagation(); }
    } );
  }
  }
}() );
</script>
@endonce
