@extends('public.account._shell')
@php $pageTitle = 'Passes'; @endphp
@push('styles')
  @include('public.account.portal._portal-css')
  @include('public.classes._online-css')
@endpush

@section('content')
{{-- MARKER-CLASSES-P3: the member's own passes, with Update card --}}
@include('public.account.portal._nav', ['active' => 'passes'])

@if(session('success'))<div class="ac-flash ac-flash--success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="ac-flash ac-flash--error">{{ session('error') }}</div>@endif
@if($errors->any())<div class="ac-flash ac-flash--error">{{ $errors->first() }}</div>@endif

@foreach($memberships->where('status', 'past_due') as $m)
  <div class="ac-banner ac-banner--overdue">
    <span>
      @if($m->billing_mode === 'card')
        Your <b>{{ $m->product?->name }}</b> renewal didn't go through.@if($m->next_retry_at) We'll try again {{ $m->next_retry_at->format('M j') }}.@endif
      @else
        Your <b>{{ $m->product?->name }}</b> renewal is due. Pay at the studio{{ $cardsOn ? ', or add a card' : '' }}.
      @endif
    </span>
    @if($cardsOn)<a href="#" onclick="mpCard('{{ $m->id }}');return false">{{ $m->billing_mode === 'card' ? 'Update card' : 'Add card' }}</a>@endif
  </div>
@endforeach

<div class="ac-section-title">Memberships</div>
<div class="ac-list">
  @forelse($memberships as $m)
    <div class="ac-list-row" style="flex-wrap:wrap">
      <div style="flex:1;min-width:0">
        <div class="ac-list-name">{{ $m->product?->name ?? 'Membership' }}</div>
        <div class="ac-list-meta">
          @if($m->product?->type === 'unlimited')
            Unlimited
          @else
            {{ max(0, (int) ($m->product?->monthly_limit ?? 0) - (int) $m->classes_used_this_period) }} of {{ $m->product?->monthly_limit }} left this month
          @endif
          @if($m->status === 'paused')
            · paused until {{ $m->pause_until?->format('M j') }}
          @elseif($m->status === 'past_due')
            · you can keep booking until {{ $m->current_period_end?->copy()->addDays(8)->format('M j') }}
          @elseif($m->cancel_at_period_end)
            · ends {{ $m->current_period_end?->format('M j') }}
          @elseif($m->billing_mode !== 'comp')
            · renews {{ $m->current_period_end?->copy()->addDay()->format('M j') }} · {{ format_money((int) $m->renewal_price_cents) }}
          @endif
        </div>
        <div class="ac-list-meta">
          @if($m->billing_mode === 'card')
            {{ ucfirst((string) $m->card_brand ?: 'Card') }} ending {{ $m->card_last4 }}
          @elseif($m->billing_mode === 'desk')
            Paid at the studio
          @else
            From the studio
          @endif
          @if($cardsOn && $m->billing_mode !== 'comp' && $m->status !== 'past_due')
            · <a href="#" class="ac-link" onclick="mpCard('{{ $m->id }}');return false">{{ $m->billing_mode === 'card' ? 'Change card' : 'Pay by card instead' }}</a>
          @endif
        </div>
      </div>
      <div class="ac-list-right">
        <span class="ac-pill ac-pill--{{ $m->status === 'past_due' ? 'refunded' : ($m->status === 'paused' ? 'due' : 'active') }}">
          {{ $m->status === 'past_due' ? ($m->billing_mode === 'card' ? 'Payment failed' : 'Due') : ($m->status === 'paused' ? 'Paused' : 'Active') }}
        </span>
      </div>
      <div id="mp-card-{{ $m->id }}" style="display:none;flex-basis:100%;margin-top:12px">
        <div class="co-pe mp-pe"></div>
        <div class="ac-flash ac-flash--error co-err"></div>
        <div style="display:flex;gap:8px">
          <button type="button" class="ac-btn ac-btn--primary" style="width:auto;padding:10px 18px" onclick="mpSave('{{ $m->id }}', this)">
            {{ $m->status === 'past_due' ? 'Save card and pay ' . format_money((int) $m->renewal_price_cents) : 'Save card' }}
          </button>
          <button type="button" class="ac-btn ac-btn--ghost" style="width:auto;padding:10px 18px" onclick="document.getElementById('mp-card-{{ $m->id }}').style.display='none'">Cancel</button>
        </div>
      </div>
    </div>
  @empty
    <div class="ac-empty">No memberships. <a class="ac-link" href="{{ route('tenant.customer.classes.passes') }}">See passes</a></div>
  @endforelse
</div>

<div class="ac-section-title">Class packs</div>
<div class="ac-list">
  @forelse($packs as $p)
    <div class="ac-list-row">
      <div>
        <div class="ac-list-name">{{ $p->product?->name ?? 'Class pack' }}</div>
        <div class="ac-list-meta">Use by {{ $p->expires_at?->format('M j, Y') }}</div>
      </div>
      <div class="ac-list-right"><span class="ac-pill ac-pill--active">{{ $p->credits_remaining }} left</span></div>
    </div>
  @empty
    <div class="ac-empty">No class packs</div>
  @endforelse
</div>

@if($recent->isNotEmpty())
  <div class="ac-section-title">Recent</div>
  <div class="ac-list">
    @foreach($recent as $r)
      <div class="ac-list-row">
        <div><div class="ac-list-name">{{ $r->note ?: ucfirst(str_replace('_', ' ', $r->reason)) }}</div>
          <div class="ac-list-meta">{{ tlocal($r->created_at, 'M j, g:i A') }}</div></div>
        @if($r->delta)<div class="ac-list-right" style="font-variant-numeric:tabular-nums">{{ $r->delta > 0 ? '+' . $r->delta : $r->delta }}</div>@endif
      </div>
    @endforeach
  </div>
@endif

<div class="legend">To pause or stop a membership, contact {{ $currentTenant->name }}. A new card is used for every renewal from then on, and a renewal that failed is charged to it straight away.</div>
@endsection

@if($cardsOn)
@push('scripts')
@include('public.classes._pay-js')
<script>
var mpOpen = null;
function mpCard(id) {
  var box = document.getElementById('mp-card-' + id);
  if (mpOpen && mpOpen !== id) { document.getElementById('mp-card-' + mpOpen).style.display = 'none'; }
  box.style.display = 'block';
  if (mpOpen !== id) {
    document.querySelectorAll('.mp-pe').forEach(function (e) { e.innerHTML = ''; e.removeAttribute('id'); });
    box.querySelector('.mp-pe').id = 'mp-pe-live';
    ClassPay.mount({ pk: @json($stripePk), el: '#mp-pe-live', mode: 'setup' });
    mpOpen = id;
  }
  box.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function mpSave(id, btn) {
  var box = document.getElementById('mp-card-' + id);
  ClassPay.pay(@json(url('/account/passes')) + '/' + encodeURIComponent(id) + '/card', {}, box.querySelector('.co-err'), btn);
}
</script>
@endpush
@endif
