@extends('layouts.tenant.app')
@php $pageTitle = 'Data quality'; @endphp

{{-- MARKER-DATA-COMPLETENESS --}}
@include('tenant.reports._tab_styles')
@push('styles')
<style>
  .dq-wrap{max-width:940px}
  .dq-sec{background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:20px;margin-bottom:18px}
  .dq-sec h2{font-size:14px;font-weight:660;margin:0 0 4px;color:var(--ia-text)}
  .dq-sub{font-size:12.5px;color:var(--ia-text-dim);margin:0 0 16px;line-height:1.55}
  .dq-row{display:grid;grid-template-columns:1.5fr 2.2fr .8fr auto;gap:12px;align-items:center;padding:11px 4px;border-bottom:.5px solid var(--ia-border);font-size:13px}
  .dq-row.head{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);padding-bottom:7px}
  .dq-bar{height:6px;border-radius:3px;background:var(--ia-surface-2);overflow:hidden}
  .dq-bar span{display:block;height:100%;background:var(--ia-accent)}
  .dq-bar.crit span{background:#e07a7a}
  .dq-note{font-size:11.5px;color:var(--ia-text-dim);margin-top:3px;line-height:1.45}
  .dq-none{font-size:13px;color:var(--ia-text-dim)}
  .dq-chips{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:4px}
  .dq-chip{background:var(--ia-surface-2);border:.5px solid var(--ia-border);border-radius:10px;padding:10px 14px;min-width:130px}
  .dq-chip .n{font-size:19px;font-weight:740;color:var(--ia-text)}
  .dq-chip .k{font-size:11.5px;color:var(--ia-text-dim);margin-top:2px}
</style>
@endpush

@section('content')
<div class="dq-wrap">

  <div class="ia-page-head">
    <div class="ia-page-head-left">
      <h1 class="ia-page-title">Data quality</h1>
      <p class="ia-page-subtitle">What's missing from your records, and what it stops you doing.</p>
    </div>
  </div>

  @include('tenant.reports._tab_subnav', ['active' => 'data'])

  {{-- Consent first: the most common "why did nothing send" answer --}}
  <div class="dq-sec">
    <h2>Marketing permission</h2>
    <p class="dq-sub">
      Campaigns only reach the first group. Imported contacts start unconfirmed —
      that's deliberate, since permission has to be evidenced rather than assumed.
      Receipts and booking confirmations are unaffected by all of this.
    </p>
    <div class="dq-chips">
      <div class="dq-chip"><div class="n">{{ number_format($consent['mailable']) }}</div><div class="k">Can receive campaigns</div></div>
      <div class="dq-chip"><div class="n">{{ number_format($consent['unconfirmed']) }}</div><div class="k">Unconfirmed</div></div>
      <div class="dq-chip"><div class="n">{{ number_format($consent['unsubscribed']) }}</div><div class="k">Unsubscribed</div></div>
    </div>
    @if($consent['unconfirmed'] > 0)
      <p class="dq-note" style="margin-top:10px">
        <a href="{{ route('tenant.consent.index') }}">Confirm permission on Contacts &amp; consent</a>
        if your business has it for these contacts.
      </p>
    @endif
  </div>

  @foreach([['customers', 'Customers', $customers], ['inventory', 'Inventory', $inventory]] as [$type, $title, $data])
  <div class="dq-sec">
    <h2>{{ $title }}</h2>
    <p class="dq-sub">
      {{ number_format($data['total']) }} record(s). A blank counts as missing; a zero doesn't —
      a $0 price is a decision, not a gap.
    </p>

    @if($data['total'] === 0)
      <div class="dq-none">Nothing here yet.</div>
    @else
      <div class="dq-row head">
        <div>Field</div><div>Filled in</div><div>Missing</div><div></div>
      </div>
      @foreach($data['fields'] as $f)
        <div class="dq-row">
          <div>
            {{ $f['label'] }}
            @if($f['consequence'] && $f['missing'] > 0)
              <div class="dq-note">{{ $f['consequence'] }}</div>
            @endif
          </div>
          <div>
            <div class="dq-bar {{ $f['critical'] && $f['missing'] > 0 ? 'crit' : '' }}">
              <span style="width:{{ $data['total'] > 0 ? round($f['present'] / $data['total'] * 100) : 0 }}%"></span>
            </div>
            <div class="dq-note">{{ number_format($f['present']) }} of {{ number_format($data['total']) }}</div>
          </div>
          <div>
            @if($f['missing'] > 0)
              {{ number_format($f['missing']) }} <span style="color:var(--ia-text-dim)">({{ $f['percent'] }}%)</span>
            @else
              <span style="color:var(--ia-text-dim)">None</span>
            @endif
          </div>
          <div style="text-align:right">
            @if($f['missing'] > 0)
              <a class="ia-btn ia-btn--ghost ia-btn--sm" style="text-decoration:none"
                 href="{{ route('tenant.reports.data_quality.export', ['type' => $type, 'field' => $f['field']]) }}">CSV</a>
            @endif
          </div>
        </div>
      @endforeach

      <p class="dq-note" style="margin-top:12px">
        The CSV lists the affected records with their id. Fill in the blanks and bring it
        back through <a href="{{ route('tenant.imports.index') }}">Import</a> — matching is by
        {{ $type === 'inventory' ? 'SKU' : 'email' }}, so existing records update rather than duplicate.
      </p>
    @endif
  </div>
  @endforeach

</div>
@endsection
