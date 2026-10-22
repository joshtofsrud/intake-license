@extends('public.account._shell')
@php
  $pageTitle = 'Passes & pricing';
  // MARKER-CLASSES-P3
  $coversText = function ($p) use ($templateNames) {
      $ids = (array) ($p->covers ?? []);
      if (! $ids) return null;
      $names = collect($ids)->map(fn ($id) => $templateNames[$id] ?? null)->filter()->values();
      return $names->isEmpty() ? null : $names->join(', ', ' and ') . ' only';
  };
@endphp

@push('styles')
  @include('public.classes._online-css')
@endpush

@section('content')
<div class="ac-title">{{ $currentTenant->name }}</div>
<div class="ac-subtitle" style="margin-bottom:18px">Classes, passes and memberships</div>

@include('public.classes._tabs', ['active' => 'passes'])

@if($errors->any())
  <div class="ac-flash ac-flash--error">{{ $errors->first() }}</div>
@endif

@foreach($intro as $p)
  <a class="ps-intro" href="{{ $cardsOn ? route('tenant.customer.classes.buy', ['kind' => 'pack', 'productId' => $p->id]) : route('tenant.customer.classes') }}">
    <div>
      <div class="ps-intro-k">New here</div>
      <div class="ps-intro-n">{{ $p->name }}</div>
      <div class="ps-intro-s">{{ $p->credit_count }} {{ $p->credit_count === 1 ? 'class' : 'classes' }} in {{ $p->expiry_days }} days · first visit only{{ ($c = $coversText($p)) ? ' · ' . $c : '' }}</div>
    </div>
    <div class="ps-intro-p"><b>{{ format_money($p->price_cents) }}</b><span>{{ $cardsOn ? 'Buy now ›' : 'Ask at your first class' }}</span></div>
  </a>
@endforeach

@if($memberships->isNotEmpty())
  <div class="ps-group">
    <div class="ps-label">Memberships</div>
    @foreach($memberships as $p)
      <div class="ps-line">
        <div>
          <div class="ps-line-n">{{ $p->name }}</div>
          <div class="ps-line-s">
            {{ $p->isUnlimited() ? 'Unlimited classes' : $p->monthly_limit . ' classes a month' }}{{ ($c = $coversText($p)) ? ' · ' . $c : '' }}
          </div>
        </div>
        <div class="ps-line-r">
          @if(in_array($p->id, $owned, true))<span class="ps-have">You have this</span>@endif
          <div class="ps-line-p">{{ format_money($p->price_cents) }}<small>a month</small></div>
          @if($cardsOn && ! in_array($p->id, $owned, true))
            <a class="ps-buy" href="{{ route('tenant.customer.classes.buy', ['kind' => 'membership', 'productId' => $p->id]) }}">Buy</a>
          @elseif(! $cardsOn)
            <span class="ps-line-s">At the studio</span>
          @endif
        </div>
      </div>
    @endforeach
    <div class="ps-note">Renews monthly on the card you pay with. To pause or stop, contact the studio.</div>
  </div>
@endif

@if($packs->isNotEmpty())
  <div class="ps-group">
    <div class="ps-label">Class packs</div>
    @foreach($packs as $p)
      <div class="ps-line">
        <div>
          <div class="ps-line-n">{{ $p->name }}</div>
          <div class="ps-line-s">{{ $p->credit_count }} {{ $p->credit_count === 1 ? 'class' : 'classes' }} · use within {{ $p->expiry_days }} days{{ ($c = $coversText($p)) ? ' · ' . $c : '' }}</div>
        </div>
        <div class="ps-line-r">
          <div class="ps-line-p">{{ format_money($p->price_cents) }}@if($p->credit_count > 1)<small>{{ format_money((int) round($p->price_cents / $p->credit_count)) }} a class</small>@endif</div>
          @if($cardsOn)
            <a class="ps-buy" href="{{ route('tenant.customer.classes.buy', ['kind' => 'pack', 'productId' => $p->id]) }}">Buy</a>
          @else
            <span class="ps-line-s">At the studio</span>
          @endif
        </div>
      </div>
    @endforeach
  </div>
@endif

@if($intro->isEmpty() && $memberships->isEmpty() && $packs->isEmpty())
  <div class="ps-empty">Passes are sold at the studio. Ask at the desk.</div>
@endif

@if($cheapestDropin)
  <div class="ps-dropin"><span>Just one class? Drop in from the schedule.</span><b>from {{ format_money((int) $cheapestDropin) }}</b></div>
@endif
@endsection
