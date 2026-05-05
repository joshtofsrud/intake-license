@extends('layouts.tenant.app')

@php $pageTitle = 'Refunds'; @endphp

@push('styles')
<style>
  .reg-tabs-bar{
    display:flex;gap:4px;margin:0 0 18px;border-bottom:0.5px solid var(--ia-border);
    flex-wrap:wrap
  }
  .reg-tab-link{
    padding:10px 18px;font-size:13px;font-weight:500;color:var(--ia-text-dim);
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-0.5px;
    transition:color var(--ia-t),border-color var(--ia-t)
  }
  .reg-tab-link:hover{color:var(--ia-text)}
  .reg-tab-link.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}

  .refunds-empty{
    padding:60px 20px;text-align:center;color:var(--ia-text-dim);
    border:0.5px dashed var(--ia-border);border-radius:var(--ia-r-lg);
    background:var(--ia-surface)
  }
  .refunds-empty h3{font-size:16px;color:var(--ia-text);margin-bottom:6px;font-weight:500}
  .refunds-empty p{font-size:13px;line-height:1.5;max-width:420px;margin:0 auto}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Refunds</h1>
    <p class="ia-page-subtitle">Process returns and refunds.</p>
  </div>
</div>

<div class="reg-tabs-bar">
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Sale</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
  <a href="{{ route('tenant.register.refunds.index') }}" class="reg-tab-link active">Refunds</a>
</div>

<div class="refunds-empty">
  <h3>Refunds coming soon</h3>
  <p>This page will let you process refunds against past sales. We'll wire up the full flow in a follow-up build.</p>
</div>

@endsection
