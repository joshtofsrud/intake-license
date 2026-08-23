@extends('layouts.tenant.app')
@php $pageTitle = 'Import'; @endphp
{{-- MARKER-IMPORT3 — the hub IS the landing: numbered sections, type cards
     that carry their own context, and history that shows outcomes. --}}

@section('content')
@include('tenant.imports._styles')

@if(session('error'))<div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ session('error') }}</div>@endif
@if(session('success'))<div class="ia-flash ia-flash--success" style="margin-bottom:14px">{{ session('success') }}</div>@endif

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Import</h1>
    <p class="ia-page-subtitle">Bring customers or inventory in from a spreadsheet. Nothing is written
      until you've seen a preview &mdash; and any import can be reversed.</p>
  </div>
</div>

{{-- 1 ---------------------------------------------------------------- --}}
<div class="imp-sec">
  <div class="imp-sec-h"><span class="imp-sec-n">1</span><span class="imp-sec-t">What are you importing?</span></div>
  <p class="imp-sec-s">Not sure your file is right? Download a starter CSV, paste your data into it, and import that.</p>

  <div class="imp-types">
    @php
      $impTypes = [
        'customers' => [
          'label'  => 'Customers',
          'fields' => 'Names · contact · address · notes · VIP · business name, tax exemption, terms, PO',
          'match'  => 'Matched on email',
          'extra'  => null,
          'noun'   => 'customers',
          'icon'   => '<circle cx="7" cy="5" r="3" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 12.5c0-2.5 2.5-4 5.5-4s5.5 1.5 5.5 4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>',
        ],
        'inventory' => [
          'label'  => 'Inventory',
          'fields' => 'SKU · name · cost & price · reorder points · bin · size & colour · category · vendor · stock',
          'match'  => 'Matched on SKU',
          'extra'  => 'Creates categories & vendors',
          'noun'   => 'items',
          'icon'   => '<path d="M2 4.5 7 2l5 2.5v5L7 12 2 9.5v-5Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M2 4.5 7 7l5-2.5M7 7v5" stroke="currentColor" stroke-width="1.2"/>',
        ],
      ];
    @endphp

    @foreach($impTypes as $impKey => $t)
      @php $n = $counts[$impKey] ?? 0; @endphp
      <div class="imp-type">
        <a href="{{ route('tenant.imports.create', ['type' => $impKey]) }}" class="imp-type-hit">
          <div class="imp-type-top">
            <span class="imp-type-ico"><svg width="15" height="15" viewBox="0 0 14 14" fill="none">{!! $t['icon'] !!}</svg></span>
            <h4>{{ $t['label'] }}</h4>
            <span class="imp-type-count">{{ $n > 0 ? number_format($n) . ' ' . $t['noun'] : 'none yet' }}</span>
          </div>
          <div class="imp-type-fields">{{ $t['fields'] }}</div>
          <div class="imp-type-meta">
            <span class="imp-tag key">{{ $t['match'] }}</span>
            <span class="imp-tag">{{ count(\App\Support\ImportFieldRegistry::for($impKey)) }} fields</span>
            @if($t['extra'])<span class="imp-tag">{{ $t['extra'] }}</span>@endif
          </div>
        </a>
        {{-- MARKER-IMPORT-CTA — explicit primary action; starter CSV is the aside. --}}
        <div class="imp-type-go">
          <a href="{{ route('tenant.imports.create', ['type' => $impKey]) }}" class="ia-btn ia-btn--primary ia-btn--sm">
            Import {{ strtolower($t['label']) }}
          </a>
          <a href="{{ route('tenant.imports.template', $impKey) }}" class="imp-type-alt">Download a starter CSV</a>
          <span class="imp-type-arrow" aria-hidden="true">&rarr;</span>
        </div>
      </div>
    @endforeach
  </div>
</div>

{{-- 2 ---------------------------------------------------------------- --}}
<div class="imp-sec">
  <div class="imp-sec-h"><span class="imp-sec-n">2</span><span class="imp-sec-t">Recent imports</span></div>
  <p class="imp-sec-s">Every import keeps its file, its mapping and its row-level outcome &mdash;
    so a bad run can be diagnosed, not guessed at.</p>

  <div class="ia-card" style="margin-bottom:8px">
    @if($imports->isEmpty())
      <div class="imp-empty">
        <b>Nothing imported yet</b>
        Once you run one, it'll be listed here with what it created, what it changed,
        and a button to reverse the whole thing.
      </div>
    @else
      <table class="imp">
        <thead><tr>
          <th style="width:112px">When</th><th>File</th>
          <th style="width:220px">Outcome</th><th style="width:100px">Status</th><th style="width:250px"></th>
        </tr></thead>
        <tbody>
          @foreach($imports as $imp)
            @php
              $rev  = $imp->totals['reversal'] ?? null;
              $rows = $imp->options['row_count'] ?? null;
              $who  = $actors[$imp->created_by_user_id] ?? null;
            @endphp
            <tr>
              <td class="imp-when">{{ tlocal_datetime($imp->created_at, 'g:i A') }}
                <span>{{ tlocal_date($imp->created_at, 'M j') }}</span></td>

              <td class="imp-file">{{ $imp->original_filename }}
                <span>{{ ucfirst($imp->type) }}@if($who) · {{ $who }}@endif
                  @if($rows) · {{ number_format($rows) }} rows @endif</span></td>

              <td>
                @if($imp->status === 'failed')
                  <span class="imp-hint">{{ Str::limit($imp->failure_reason, 60) }}</span>
                @elseif(in_array($imp->status, ['draft', 'previewed'], true))
                  <span class="imp-hint">Uploaded, not finished</span>
                @else
                  <div class="imp-nums">
                    <span class="imp-num ok"><b>{{ number_format($imp->total('created')) }}</b><i>created</i></span>
                    <span class="imp-num acc"><b>{{ number_format($imp->total('updated')) }}</b><i>updated</i></span>
                    <span class="imp-num {{ ($imp->total('errors') + $imp->total('unmatched')) > 0 ? 'bad' : '' }}">
                      <b>{{ number_format($imp->total('errors') + $imp->total('unmatched')) }}</b><i>skipped</i></span>
                  </div>
                @endif
              </td>

              <td><span class="chip chip--{{ $imp->status }}">{{ $imp->status }}</span></td>

              <td>
                <div class="imp-acts">
                  @if($imp->status === 'reversed' && ($rev['kept'] ?? 0) > 0)
                    <span class="imp-hint" style="align-self:center">{{ $rev['kept'] }} kept &mdash; used since</span>
                  @endif
                  @if($imp->error_path && $imp->status !== 'reversed')
                    <a href="{{ route('tenant.imports.errors', $imp->id) }}" class="ia-btn ia-btn--secondary ia-btn--sm">Error CSV</a>
                  @endif
                  @if(in_array($imp->status, ['draft', 'previewed'], true))
                    <a href="{{ route('tenant.imports.map', $imp->id) }}" class="ia-btn ia-btn--secondary ia-btn--sm">Resume</a>
                    <form method="POST" action="{{ route('tenant.imports.destroy', $imp->id) }}"
                          onsubmit="return confirm('Discard this upload? Nothing was written, so nothing is lost.')">
                      @csrf @method('DELETE')
                      <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm">Discard</button>
                    </form>
                  @else
                    <a href="{{ route('tenant.imports.show', $imp->id) }}" class="ia-btn ia-btn--ghost ia-btn--sm">View</a>
                  @endif
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  @if($total > $imports->count())
    <p class="imp-hint">Showing {{ $imports->count() }} of {{ number_format($total) }}.</p>
  @endif
</div>
@endsection
