@extends('public.account._shell')
@php
  // MARKER-PORTAL-RENTALS
  $pageTitle = 'Rental ' . ($rental->rental_number ?? '');
  $sym   = tenant()->currency_symbol ?: '$';
  $money = fn ($c) => ((int) $c < 0 ? '-' : '') . $sym . number_format(abs((int) $c) / 100, 2);
  $unitLines  = $rental->lines->where('kind', 'unit')->values();
  $addonLines = $rental->lines->where('kind', '!=', 'unit')->values();
  $title = $rental->lines->first()?->name_snapshot ?? 'Rental';
  if ($rental->lines->count() > 1) { $title .= ' + ' . ($rental->lines->count() - 1) . ' more'; }
  $overdue = $rental->isOverdue();
  [$pillCls, $pillTxt] = match (true) {
    $overdue                        => ['refunded', 'Overdue'],
    $rental->status === 'out'       => ['due', 'Out'],
    $rental->status === 'reserved'  => ['pending', 'Reserved'],
    $rental->status === 'returned'  => ['returned', 'Returned'],
    $rental->status === 'cancelled' => ['cancelled', 'Cancelled'],
    default                         => ['pending', ucfirst((string) $rental->status)],
  };
  $stepAt = ['reserved' => 0, 'out' => 1, 'returned' => 2][$rental->status] ?? null;
  $steps = [
    ['Reserved', tlocal($rental->created_at, 'M j')],
    ['Out',      $rental->checked_out_at ? tlocal($rental->checked_out_at, 'M j') : tlocal($rental->starts_at, 'M j')],
    ['Returned', $rental->returned_at ? tlocal($rental->returned_at, 'M j') : null],
  ];
  $modeTxt = ['hourly' => 'hour', 'daily' => 'day', 'weekend' => 'weekend', 'seasonal' => 'season'];
  $depositTxt = ['authorized' => 'Held on your card — not charged', 'released' => 'Released', 'captured' => 'Charged'];
  $balance = max(0, (int) $rental->total_cents - (int) $rental->paid_cents);
  $photoUrl = function ($u) {
      if (! $u) { return null; }
      $mp = array_values(array_filter((array) ($u->model?->photos ?? [])));
      return $u->photo_url ?: ($u->model?->image_url ?: ($mp[0] ?? null));
  };
@endphp
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'rentals'])

<a href="{{ route('tenant.customer.portal.rentals') }}" class="ac-back">&larr; All rentals</a>

@if(session('success'))
  <div class="ac-flash ac-flash--success">{{ session('success') }}</div>
@endif
@if(session('error'))
  <div class="ac-flash ac-flash--error">{{ session('error') }}</div>
@endif
@if($errors->any())
  <div class="ac-flash ac-flash--error">{{ $errors->first() }}</div>
@endif

<div class="ac-dhead">
  <div>
    <div class="ac-dtitle">{{ $title }}</div>
    <div class="ac-dsub">@if($rental->rental_number) {{ $rental->rental_number }} &middot; @endif<span class="ac-pill ac-pill--{{ $pillCls }}">{{ $pillTxt }}</span></div>
  </div>
  <div class="ac-dact">
    <a href="#" onclick="window.print();return false;">Print</a>
    <a href="#conversation">Message</a>
  </div>
</div>

@if($rental->status === 'cancelled')
  <div class="ac-banner ac-banner--overdue"><span><b>This rental was cancelled.</b> Questions? Send us a message below.</span></div>
@elseif($stepAt !== null)
  <div class="ac-steps">
    @foreach($steps as $i => [$label, $when])
      <div class="ac-step {{ $i < $stepAt ? 'done' : '' }} {{ $i === $stepAt ? 'now' : '' }}">
        {{ $i < $stepAt ? '✓ ' : '' }}{{ $label }}
        @if($when && $i <= $stepAt)<small>{{ $when }}</small>@endif
      </div>
    @endforeach
  </div>
@endif

@if($overdue)
  <div class="ac-banner ac-banner--overdue"><span><b>Overdue</b> — this was due back {{ tlocal_datetime($rental->due_at, 'D, M j \a\t g:i A') }}.</span></div>
@endif

@if($extend)
  <div style="margin-bottom:18px" id="extend">
    @if(isset($extend['open']))
      <a href="{{ route('tenant.rentals.extension.show', $extend['open']->token) }}" class="ac-btn ac-btn--primary" style="display:block;text-align:center">
        Keep it longer — extend to {{ tlocal_datetime($extend['open']->extend_to, 'g:i A') }} for {{ format_money($extend['open']->total_cents) }}
      </a>
    @else
      <form method="POST" action="{{ route('tenant.customer.portal.rentals.extend', $rental->id) }}">
        @csrf
        <button class="ac-btn ac-btn--primary">Keep it longer — extend to {{ tlocal_datetime($extend['elig']['extend_to'], 'g:i A') }} for {{ format_money($extend['elig']['total_cents']) }}</button>
      </form>
    @endif
  </div>
@endif

<dl class="ac-kv">
  <dt>Pick up</dt><dd>{{ tlocal_datetime($rental->starts_at, 'D, M j \a\t g:i A') }}</dd>
  <dt>Due back</dt><dd>{{ tlocal_datetime($rental->due_at, 'D, M j \a\t g:i A') }}@if($rental->original_due_at && $rental->due_at && ! $rental->original_due_at->equalTo($rental->due_at)) <span style="opacity:.5">(extended from {{ tlocal($rental->original_due_at, 'D g:i A') }})</span>@endif</dd>
  @if($rental->checked_out_at)<dt>Picked up</dt><dd>{{ tlocal_datetime($rental->checked_out_at, 'D, M j \a\t g:i A') }}</dd>@endif
  @if($rental->returned_at)<dt>Returned</dt><dd>{{ tlocal_datetime($rental->returned_at, 'D, M j \a\t g:i A') }}</dd>@endif
  @if($rental->location)<dt>Location</dt><dd>{{ $rental->location->name }}</dd>@endif
  <dt>Agreement</dt>
  <dd>
    @if($rental->agreement_signed_at)
      Signed {{ tlocal_date($rental->agreement_signed_at, 'M j, Y') }}@if($rental->agreement_signer_name) by {{ $rental->agreement_signer_name }}@endif
      @if($rental->agreement_pdf_path) &middot; <a class="ac-link" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($rental->agreement_pdf_path) }}" target="_blank" rel="noopener">View PDF</a>@endif
    @else
      Not signed yet
    @endif
  </dd>
  @if((int) $rental->deposit_hold_cents > 0)
    <dt>Deposit</dt><dd>{{ $money($rental->deposit_hold_cents) }}@if($rental->deposit_status && $rental->deposit_status !== 'none') &middot; {{ $depositTxt[$rental->deposit_status] ?? ucfirst(str_replace('_', ' ', $rental->deposit_status)) }}@endif</dd>
  @endif
</dl>

<div class="ac-section-title">What you're renting</div>
<div class="ac-list">
  @foreach($unitLines as $ln)
    @php $ph = $photoUrl($ln->unit); @endphp
    <div class="ac-line ac-line--photo">
      @if($ph)<a href="{{ $ph }}" target="_blank" rel="noopener" class="ac-thumb" style="background-image:url('{{ e($ph) }}')" aria-label="Photo of {{ $ln->name_snapshot }}"></a>@else<span class="ac-thumb ac-thumb--none"></span>@endif
      <div style="flex:1;min-width:0">
        <div>{{ $ln->name_snapshot }}</div>
        <div class="ac-line-m">
          @if($ln->duration_units) {{ $ln->rateLabel($money) }} @endif
          @if($ln->unit?->size) &middot; size {{ $ln->unit->size }}@endif
        </div>
      </div>
      <div class="ac-line-r">{{ $money($ln->line_total_cents) }}</div>
    </div>
  @endforeach
  @foreach($addonLines as $ln)
    <div class="ac-line">
      <div><div>{{ $ln->name_snapshot }}</div>
        <div class="ac-line-m">Add-on @if((int) $ln->quantity > 1) &middot; {{ $ln->quantity }} &times; {{ $money($ln->rate_cents_snapshot) }}@endif</div></div>
      <div class="ac-line-r">{{ $money($ln->line_total_cents) }}</div>
    </div>
  @endforeach
  @if($rental->lines->isEmpty())<div class="ac-empty">Nothing on this rental yet</div>@endif
</div>

<div class="ac-list">
  <div class="ac-tot"><span>Subtotal</span><span>{{ $money($rental->subtotal_cents) }}</span></div>
  @if((int) $rental->discount_cents > 0)
    <div class="ac-tot"><span>Discount @if($rental->discount_code)({{ $rental->discount_code }})@endif</span><span>-{{ $money($rental->discount_cents) }}</span></div>
  @endif
  @if((int) $rental->tax_cents > 0)<div class="ac-tot"><span>Tax</span><span>{{ $money($rental->tax_cents) }}</span></div>@endif
  <div class="ac-tot ac-tot--grand"><span>Total</span><span>{{ $money($rental->total_cents) }}</span></div>
  @if((int) $rental->paid_cents !== 0)<div class="ac-tot"><span>Paid</span><span>{{ $money($rental->paid_cents) }}</span></div>@endif
  @if($balance > 0 && $rental->status !== 'cancelled')
    <div class="ac-tot ac-tot--due"><span>Balance due</span><span>{{ $money($balance) }}</span></div>
  @endif
</div>

@if($checks->isNotEmpty())
  <div class="ac-section-title">Condition checks</div>
  @foreach($unitLines as $ln)
    @continue(! $ln->unit_id || ! isset($checks[$ln->unit_id]))
    <div class="ac-list">
      <div class="ac-grp">{{ $ln->name_snapshot }}</div>
      @foreach($checks[$ln->unit_id] as $c)
        @php
          $map   = $labels[$ln->unit_id] ?? [];
          $flags = collect((array) $c->results)->filter(fn ($v) => $v === 'flag')->keys()
                     ->map(fn ($k) => $map[$k] ?? ucfirst(str_replace('_', ' ', (string) $k)));
          $pics  = array_values(array_filter((array) $c->photos));
        @endphp
        <div class="ac-note">
          <div style="display:flex;justify-content:space-between;gap:10px">
            <b>{{ $c->phase === 'check_out' ? 'At pick-up' : 'At return' }}</b>
            <span class="ac-pill ac-pill--{{ $c->flagged ? 'due' : 'paid' }}">{{ $c->flagged ? $flags->count() . ' flagged' : 'All OK' }}</span>
          </div>
          @if($flags->isNotEmpty())<div style="margin-top:4px">Flagged: {{ $flags->implode(', ') }}</div>@endif
          @if($c->notes_customer_visible && trim((string) $c->notes) !== '')<div class="ac-note-txt" style="margin-top:4px">{{ $c->notes }}</div>@endif
          @if(count($pics))
            <div class="ac-photos">
              @foreach($pics as $pi => $p)
                @php $pu = \Illuminate\Support\Facades\Storage::disk('public')->url($p); @endphp
                <a href="{{ $pu }}" target="_blank" rel="noopener" style="background-image:url('{{ e($pu) }}')" aria-label="Photo {{ $pi + 1 }}"></a>
              @endforeach
            </div>
          @endif
          <div class="ac-note-m">{{ $c->performed_at ? tlocal_datetime($c->performed_at, 'M j, Y g:i A') : '' }}</div>
        </div>
      @endforeach
    </div>
  @endforeach
@endif

@if($payments->isNotEmpty())
  <div class="ac-section-title">Payments</div>
  <div class="ac-list">
    @foreach($payments as $p)
      <div class="ac-line">
        <div><div>{{ $p->isRefund() ? 'Refund' : 'Payment' }} &middot; {{ $p->methodLabel() }}</div>
          <div class="ac-line-m">{{ $p->recorded_at ? tlocal_datetime($p->recorded_at, 'M j, Y g:i A') : '' }}</div></div>
        <div class="ac-line-r">{{ $money($p->amount_cents) }}</div>
      </div>
    @endforeach
  </div>
@endif

@if($receipts->isNotEmpty())
  <div class="ac-section-title">Receipts</div>
  <div class="ac-list">
    @foreach($receipts as $s)
      <a class="ac-list-row" href="{{ route('tenant.customer.portal.sale', $s->id) }}">
        <div><div class="ac-list-name">{{ $s->isRefund() ? 'Refund' : 'Receipt' }} {{ $s->sale_number ? '#' . $s->sale_number : '' }}</div>
          <div class="ac-list-meta">{{ tlocal_date($s->paid_at ?? $s->created_at) }}</div></div>
        <div class="ac-list-right"><span style="font-weight:700">{{ $money($s->total_cents) }}</span><span class="ac-go">&rsaquo;</span></div>
      </a>
    @endforeach
  </div>
@endif

@include('public.account.portal._record-conversation', [
  'conversation' => $conversation,
  'action'       => route('tenant.customer.portal.rental.message', $rental->id),
  'aboutLabel'   => 'this rental',
])
@endsection
