{{-- MARKER-OLD-SCHOOL-BANNER — open notes about one customer.

     Expects $bannerCustomer. Renders nothing when there is nothing open, so
     it can be included unconditionally.

     The note TEXT is here, not a count. A count sends someone looking at the
     moment they are least able to, which is the opposite of what the pad is
     for. --}}
@php
  $bnNotes = $bannerCustomer
      ? \App\Models\Tenant\TenantNote::where('tenant_id', tenant()->id)
          ->where('customer_id', $bannerCustomer->id)
          ->whereNull('completed_at')
          ->with('author')
          ->orderBy('created_at')
          ->get()
      : collect();
@endphp

@if($bnNotes->count())
  <div class="nb">
    <div class="nb-head">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M4 4h11l5 5v11H4z"/><path d="M8 10h8M8 14h5"/>
      </svg>
      Open notes on {{ $bannerCustomer->first_name }} {{ $bannerCustomer->last_name }}
      <span class="nb-n">{{ $bnNotes->count() }}</span>
    </div>

    @foreach($bnNotes as $bn)
      <div class="nb-line">
        <form method="POST" action="{{ route('tenant.notes.toggle', $bn->id) }}">
          @csrf
          <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
          <button type="submit" class="nb-box" aria-label="Cross off"></button>
        </form>
        <div class="nb-body">
          <div class="nb-text">{{ $bn->body }}</div>
          {{-- MARKER-OLD-SCHOOL-PHOTO --}}
          @if($bn->photos)
            <div class="np-shots">
              @foreach($bn->photoUrls() as $u)
                <a href="{{ $u }}" target="_blank" rel="noopener"><img src="{{ $u }}" alt="" loading="lazy"></a>
              @endforeach
            </div>
          @endif
          <div class="nb-meta">
            {{ $bn->author?->name ?? 'someone' }} · {{ $bn->created_at?->diffForHumans() }}
            @if($bn->ageInDays() >= 14)
              <span class="nb-age">still open after {{ $bn->ageInDays() }} days</span>
            @endif
          </div>
        </div>
      </div>
    @endforeach

    <div class="nb-foot">Tick one to cross it off — it disappears from here straight away.</div>
  </div>

  <style>
    .nb { background:#F4ECD8; color:#2A2419; border-radius:10px; padding:11px 13px; margin-bottom:16px;
          box-shadow:0 2px 0 rgba(0,0,0,.20); }
    .nb-head { display:flex; align-items:center; gap:8px; font-size:10.5px; font-weight:700;
               letter-spacing:.06em; text-transform:uppercase; color:#7A7159; margin-bottom:8px; }
    .nb-n { margin-left:auto; font-size:11.5px; font-weight:600; letter-spacing:0; text-transform:none; }
    .nb-line { display:flex; gap:11px; align-items:flex-start; padding:8px 0; border-top:1px solid #D9CDB0; }
    .nb-box { width:19px; height:19px; border:1.6px solid #8D8267; border-radius:4px; background:#FBF7EC;
              cursor:pointer; flex:none; margin-top:1px; padding:0; }
    .nb-box:hover { background:#8D8267; }
    .nb-body { flex:1; min-width:0; }
    .nb-text { font-size:13.5px; line-height:1.5; word-break:break-word; }
    .nb-meta { font-size:10.5px; color:#7A7159; margin-top:4px; }
    .nb-age { color:#A8622A; font-weight:600; margin-left:6px; }
    /* MARKER-OLD-SCHOOL-PHOTO */
    .np-shots { display:flex; gap:6px; margin-top:7px; flex-wrap:wrap; }
    .np-shots img { width:64px; height:64px; object-fit:cover; border-radius:6px; display:block;
                    border:1px solid #D9CDB0; }
    .nb-foot { border-top:1px solid #D9CDB0; margin-top:6px; padding-top:8px; font-size:11px; color:#7A7159; }
  </style>
@endif
