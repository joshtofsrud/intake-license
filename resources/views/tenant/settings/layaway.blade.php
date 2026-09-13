@extends('layouts.tenant.app')
@php $pageTitle = 'Layaway'; @endphp

@section('content')
{{-- MARKER-LAYAWAY — the shop's policy. Every number here is snapshotted onto
     a plan the day it opens, so changing this never rewrites an existing
     agreement. --}}
<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Layaway</h1>
    <p class="ia-page-sub">Your terms. Intake writes them into each agreement on the day it opens.</p>
  </div>
</div>

<form method="POST" action="{{ route('tenant.settings.layaway.save') }}">
  @csrf
  <div class="ia-card">
    <div class="ia-card-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;max-width:820px">

        <div class="ia-form-group">
          <label class="ia-label">Minimum first payment</label>
          <div style="display:flex;align-items:center;gap:8px">
            <input class="ia-input" type="number" name="min_first_pct" min="0" max="100" value="{{ old('min_first_pct', $settings['min_first_pct']) }}" style="width:100px">
            <span style="color:var(--ia-text-dim)">% of the total, to open</span>
          </div>
        </div>

        <div class="ia-form-group">
          <label class="ia-label">Maximum term</label>
          <div style="display:flex;align-items:center;gap:8px">
            <input class="ia-input" type="number" name="term_days" min="7" max="365" value="{{ old('term_days', $settings['term_days']) }}" style="width:100px">
            <span style="color:var(--ia-text-dim)">days to collect</span>
          </div>
        </div>

        <div class="ia-form-group">
          <label class="ia-label">Payment frequency</label>
          <select class="ia-input" name="frequency">
            @foreach(['weekly' => 'Every week', 'biweekly' => 'Every 2 weeks', 'monthly' => 'Monthly', 'none' => 'No schedule — pay any time'] as $v => $l)
              <option value="{{ $v }}" @selected(old('frequency', $settings['frequency']) === $v)>{{ $l }}</option>
            @endforeach
          </select>
        </div>

        <div class="ia-form-group">
          <label class="ia-label">Grace after a missed payment</label>
          <div style="display:flex;align-items:center;gap:8px">
            <input class="ia-input" type="number" name="grace_days" min="0" max="60" value="{{ old('grace_days', $settings['grace_days']) }}" style="width:100px">
            <span style="color:var(--ia-text-dim)">days before it shows as overdue</span>
          </div>
        </div>

        <div class="ia-form-group">
          <label class="ia-label">On cancellation, refund</label>
          <select class="ia-input" name="cancel_refund">
            @foreach(['less_fee' => 'Everything paid, less a restocking fee', 'full' => 'Everything paid, in full', 'store_credit' => 'Store credit for everything paid'] as $v => $l)
              <option value="{{ $v }}" @selected(old('cancel_refund', $settings['cancel_refund']) === $v)>{{ $l }}</option>
            @endforeach
          </select>
        </div>

        <div class="ia-form-group">
          <label class="ia-label">Restocking fee</label>
          <div style="display:flex;align-items:center;gap:8px">
            <input class="ia-input" type="number" name="restock_fee_pct" min="0" max="100" value="{{ old('restock_fee_pct', $settings['restock_fee_pct']) }}" style="width:100px">
            <span style="color:var(--ia-text-dim)">% of the value of items that were <strong>held</strong></span>
          </div>
        </div>

        <div class="ia-form-group">
          <label class="ia-label">Out-of-stock lines</label>
          <select class="ia-input" name="out_of_stock">
            <option value="special_order" @selected(old('out_of_stock', $settings['out_of_stock']) === 'special_order')>Raise a special order automatically</option>
            <option value="ask" @selected(old('out_of_stock', $settings['out_of_stock']) === 'ask')>Ask each time</option>
          </select>
        </div>

        <div class="ia-form-group">
          <label class="ia-label">When a special order arrives</label>
          <select class="ia-input" name="arrival_notify">
            <option value="both" @selected(old('arrival_notify', $settings['arrival_notify']) === 'both')>Text and email the customer</option>
            <option value="email" @selected(old('arrival_notify', $settings['arrival_notify']) === 'email')>Email only</option>
            <option value="none" @selected(old('arrival_notify', $settings['arrival_notify']) === 'none')>Don't notify — staff will call</option>
          </select>
        </div>
      </div>

      <div style="margin-top:16px;padding:11px 14px;border-radius:8px;background:rgba(245,196,81,.07);border:0.5px solid rgba(245,196,81,.32);font-size:12.5px;line-height:1.5;max-width:820px">
        <strong>Your policy, your responsibility.</strong> What you may keep when a customer cancels is
        governed by state law. Intake fills the agreement from these numbers and does not check them
        against your state. Have your own counsel confirm them.
      </div>

      <div style="margin-top:16px">
        <button type="submit" class="ia-btn ia-btn--primary">Save policy</button>
      </div>
    </div>
  </div>
</form>
@endsection
