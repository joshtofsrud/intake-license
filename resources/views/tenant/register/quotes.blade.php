@extends('layouts.tenant.app')

@php $pageTitle = 'Quotes'; @endphp

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

  .quotes-empty{
    padding:60px 20px;text-align:center;color:var(--ia-text-dim);
    border:0.5px dashed var(--ia-border);border-radius:var(--ia-r-lg);
    background:var(--ia-surface)
  }
  .quotes-empty h3{font-size:16px;color:var(--ia-text);margin-bottom:6px;font-weight:500}
  .quotes-empty p{font-size:13px;line-height:1.5;max-width:420px;margin:0 auto}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Quotes</h1>
    <p class="ia-page-subtitle">Saved carts customers can come back to.</p>
  </div>
</div>

<div class="reg-tabs-bar">
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Sale</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link active">Quotes</a>
  <a href="{{ route('tenant.register.refunds.index') }}" class="reg-tab-link">Refunds</a>
</div>

<div class="quotes-empty">
  <h3>No quotes yet</h3>
  <p>Save a cart as a quote from the register and it'll appear here. Quotes stay live until you discard them.</p>
</div>

@endsection
