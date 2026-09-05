@extends('layouts.tenant.app')
@php $pageTitle = 'Discounts'; @endphp

{{-- MARKER-DISCOUNTS-ADMIN --}}
@push('styles')
<style>
  .dc-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
  .dc-card{background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:16px 18px}
  .dc-num{font-size:23px;font-weight:740;letter-spacing:-.02em;color:var(--ia-text)}
  .dc-lbl{font-size:11.5px;color:var(--ia-text-dim);margin-top:3px}
  .dc-box{background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:18px;margin-bottom:20px}
  .dc-box h2{font-size:13px;font-weight:640;margin:0 0 12px;color:var(--ia-text)}
  .dc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
  .dc-f label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-dim);margin-bottom:5px}
  .dc-f input,.dc-f select{width:100%;box-sizing:border-box;background:var(--ia-surface-2);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);padding:9px 12px;font-size:13px;font-family:inherit}
  .dc-f input:focus,.dc-f select:focus{outline:none;border-color:var(--ia-accent)}
  .dc-hint{font-size:11.5px;color:var(--ia-text-dim);margin-top:5px;line-height:1.5}
  .dc-row{display:grid;grid-template-columns:1.4fr 1.2fr 1fr 1fr auto;gap:10px;align-items:center;padding:12px 4px;border-bottom:.5px solid var(--ia-border);font-size:13px}
  .dc-row.head{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);padding-bottom:8px}
  .dc-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:640;color:var(--ia-text)}
  .dc-pill{display:inline-block;font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;border-radius:100px;padding:2px 8px;border:.5px solid var(--ia-border);color:var(--ia-text-dim)}
  .dc-pill.on{border-color:var(--ia-accent);color:var(--ia-accent)}
  .dc-flash{border:.5px solid rgba(120,200,120,.45);border-radius:var(--ia-r-md);padding:10px 14px;margin-bottom:16px;font-size:13px;color:var(--ia-text-dim)}
  .dc-flash.bad{border-color:rgba(220,120,120,.5)}
  /* MARKER-PROMO-TAGS */
  .dc-tags{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}
  .dc-tag{font-size:10.5px;border:.5px solid var(--ia-border);border-radius:100px;padding:1px 7px;color:var(--ia-text-dim)}
  .dc-tagform{display:none;grid-column:1/-1;padding:8px 4px 4px}
  .dc-tagform.on{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .dc-tagform input{flex:1;min-width:220px;background:var(--ia-surface-2);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);padding:7px 10px;font-size:12.5px;font-family:inherit}
  .dc-tagform input:focus{outline:none;border-color:var(--ia-accent)}
  .dc-anon{font-size:11px;color:var(--ia-text-dim)}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Discounts</h1>
    <p class="ia-page-subtitle">Codes your customers can use at the register and online.</p>
  </div>
</div>

@if(session('success'))<div class="dc-flash">{{ session('success') }}</div>@endif
@if(session('error'))<div class="dc-flash bad">{{ session('error') }}</div>@endif
@if($errors->any())<div class="dc-flash bad">{{ $errors->first() }}</div>@endif

<div class="dc-cards">
  <div class="dc-card"><div class="dc-num">{{ $discounts->where('is_active', true)->count() }}</div><div class="dc-lbl">Active codes</div></div>
  <div class="dc-card"><div class="dc-num">${{ number_format(($monthTotals->cents ?? 0) / 100, 2) }}</div><div class="dc-lbl">Given away this month · {{ number_format($monthTotals->n ?? 0) }} uses</div></div>
  <div class="dc-card"><div class="dc-num">${{ number_format(($totals->cents ?? 0) / 100, 2) }}</div><div class="dc-lbl">Given away all time · {{ number_format($totals->n ?? 0) }} uses</div></div>
</div>

{{-- New code --}}
<div class="dc-box">
  <h2>New discount code</h2>
  <form method="POST" action="{{ route('tenant.discounts.store') }}">
    @csrf
    <div class="dc-grid">
      <div class="dc-f">
        <label>Code</label>
        <input type="text" name="code" placeholder="SPRING20" required autocapitalize="characters">
      </div>
      <div class="dc-f">
        <label>Type</label>
        <select name="type">
          <option value="percent">Percent off</option>
          <option value="fixed">Amount off</option>
        </select>
      </div>
      <div class="dc-f">
        <label>Value</label>
        <input type="text" name="value_input" placeholder="20" inputmode="decimal" required>
        <div class="dc-hint">Percent: 20 = 20% off. Amount: 15 = $15 off.</div>
      </div>
      <div class="dc-f">
        <label>Label (internal)</label>
        <input type="text" name="label" placeholder="Spring tune-up promo">
      </div>
    </div>

    <div class="dc-grid" style="margin-top:14px">
      <div class="dc-f">
        <label>Minimum spend</label>
        <input type="text" name="min_subtotal" placeholder="0.00" inputmode="decimal">
      </div>
      <div class="dc-f">
        <label>Max discount</label>
        <input type="text" name="max_discount" placeholder="0.00" inputmode="decimal">
        <div class="dc-hint">Caps a percent code. Leave blank for none.</div>
      </div>
      <div class="dc-f">
        <label>Starts</label>
        <input type="date" name="starts_at">
      </div>
      <div class="dc-f">
        <label>Ends</label>
        <input type="date" name="ends_at">
      </div>
      <div class="dc-f">
        <label>Total uses</label>
        <input type="number" name="max_redemptions" placeholder="0" min="0">
        <div class="dc-hint">0 = unlimited.</div>
      </div>
      <div class="dc-f">
        <label>Uses per customer</label>
        <input type="number" name="max_per_customer" placeholder="0" min="0">
        <div class="dc-hint">Needs a customer on the sale.</div>
      </div>
      <div class="dc-f" style="grid-column:1/-1">
        {{-- MARKER-PROMO-TAGS --}}
        <label>Tag customers who use this</label>
        <input type="text" name="tags" placeholder="spring20, promo customer" maxlength="600">
        <div class="dc-hint">Comma-separated. New tags are created. Everyone who redeems this code gets them on their record, so a campaign can reach them later — a shared tag like <b>promo customer</b> on every code reaches everyone who's ever used one.</div>
      </div>
    </div>

    <div style="margin-top:16px">
      <button type="submit" class="ia-btn ia-btn--primary">Create code</button>
    </div>
  </form>
</div>

{{-- Existing codes --}}
<div class="dc-box">
  <h2>Your codes</h2>
  @if($discounts->isEmpty())
    <div style="font-size:13px;color:var(--ia-text-dim)">No codes yet. Create one above and it's usable at the register straight away.</div>
  @else
    <div class="dc-row head">
      <div>Code</div><div>Discount</div><div>Used</div><div>Given away</div><div></div>
    </div>
    @foreach($discounts as $d)
      @php
        $g = $given[$d->id] ?? null;
        $reason = $d->inactiveReason();
      @endphp
      <div class="dc-row">
        <div>
          <span class="dc-code">{{ $d->code }}</span>
          @if($d->label)<div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:2px">{{ $d->label }}</div>@endif
          {{-- MARKER-PROMO-TAGS --}}
          @php $tn = $tagNames[$d->id] ?? []; $anon = (int) ($anonByCode[$d->id] ?? 0); @endphp
          <div class="dc-tags">
            @foreach($tn as $name)<span class="dc-tag">{{ $name }}</span>@endforeach
            <button type="button" class="dc-tag" style="cursor:pointer" onclick="document.getElementById('dc-tf-{{ $d->id }}').classList.toggle('on')">{{ $tn ? 'edit tags' : '+ tag customers' }}</button>
          </div>
          @if($anon > 0 && $tn)<div class="dc-anon">{{ $anon }} {{ Str::plural('use', $anon) }} had no customer on the sale — can't be tagged.</div>@endif
        </div>
        <div>
          {{ $d->summary() }}
          <div style="margin-top:3px">
            @if($reason)
              <span class="dc-pill">{{ $reason }}</span>
            @else
              <span class="dc-pill on">Usable now</span>
            @endif
          </div>
        </div>
        <div>
          {{ number_format($g->n ?? 0) }}@if($d->max_redemptions > 0) <span style="color:var(--ia-text-dim)">/ {{ number_format($d->max_redemptions) }}</span>@endif
        </div>
        <div>${{ number_format(($g->cents ?? 0) / 100, 2) }}</div>
        <div style="display:flex;gap:6px;justify-content:flex-end">
          {{-- MARKER-PROMO-TAGS — only when there's a tag to target and someone to reach --}}
          @if($tn && (($g->n ?? 0) - $anon) > 0)
            <form method="POST" action="{{ route('tenant.discounts.campaign', $d->id) }}">
              @csrf
              <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm">Email these customers</button>
            </form>
          @endif
          <form method="POST" action="{{ route('tenant.discounts.toggle', $d->id) }}">
            @csrf
            <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm">{{ $d->is_active ? 'Turn off' : 'Turn on' }}</button>
          </form>
          @if(($g->n ?? 0) === 0)
            <form method="POST" action="{{ route('tenant.discounts.destroy', $d->id) }}">
              @csrf
              @method('DELETE')
              <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm">Delete</button>
            </form>
          @endif
        </div>
        {{-- MARKER-PROMO-TAGS — inline tag editor for an existing code. Its own form, a sibling of the buttons, never nested. --}}
        <form method="POST" action="{{ route('tenant.discounts.tags', $d->id) }}" class="dc-tagform" id="dc-tf-{{ $d->id }}">
          @csrf
          <input type="text" name="tags" value="{{ implode(', ', $tn) }}" placeholder="spring20, promo customer" maxlength="600">
          <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save tags</button>
          <span class="dc-anon">Adding a tag also tags everyone who already used the code.</span>
        </form>
      </div>
    @endforeach
    <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:12px;line-height:1.5">
      A code that has been used can't be deleted — it's the record of money given away. Turning it off stops new uses and leaves history intact.
    </div>
  @endif
</div>

{{-- Recent redemptions --}}
<div class="dc-box">
  <h2>Recent uses</h2>
  @if($recent->isEmpty())
    <div style="font-size:13px;color:var(--ia-text-dim)">No codes have been used yet.</div>
  @else
    <div class="dc-row head" style="grid-template-columns:1fr 1fr 1fr 1fr">
      <div>Code</div><div>Amount off</div><div>Sale subtotal</div><div>When</div>
    </div>
    @foreach($recent as $r)
      <div class="dc-row" style="grid-template-columns:1fr 1fr 1fr 1fr">
        <div class="dc-code">{{ $r->code }}</div>
        <div>${{ number_format($r->amount_cents / 100, 2) }}</div>
        <div>${{ number_format($r->subtotal_cents / 100, 2) }}</div>
        <div style="color:var(--ia-text-dim)">{{ $r->created_at->format('M j, g:ia') }}</div>
      </div>
    @endforeach
  @endif
</div>

@endsection
