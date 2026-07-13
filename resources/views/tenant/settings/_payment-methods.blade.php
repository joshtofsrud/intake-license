{{-- MARKER-PATCH-629 — unified payment methods list (Settings → Payments).
     Renders from tenant_payment_methods; every card is its own form.
     Expects: $paymentMethods (ordered collection). --}}

<style>
.pmx { border:.5px solid var(--ia-border); border-radius:12px; background:var(--ia-surface); margin-bottom:10px; }
.pmx-h { display:flex; align-items:center; gap:12px; padding:12px 15px; cursor:pointer; }
.pmx-ic { width:28px; height:28px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; flex:none; background:var(--ia-surface-2,#1a1a1a); color:var(--ia-text-muted); }
.pmx-h .nm { font-weight:700; font-size:13px; }
.pmx-h .k { font-size:10.5px; color:var(--ia-text-muted); }
.pmx-tag { font-size:9px; letter-spacing:.05em; text-transform:uppercase; border-radius:999px; padding:2px 7px; font-weight:700; background:rgba(96,165,250,.12); color:#60a5fa; margin-left:6px; }
.pmx-tog { position:relative; width:34px; height:19px; flex:none; margin-left:auto; }
.pmx-tog input { position:absolute; inset:0; opacity:0; margin:0; cursor:pointer; z-index:2; width:100%; height:100%; }
.pmx-tog i { position:absolute; inset:0; border-radius:10px; background:var(--ia-surface-2,#1a1a1a); border:1px solid var(--ia-border-2,rgba(255,255,255,.2)); }
.pmx-tog i::after { content:''; position:absolute; top:1.5px; left:2px; width:14px; height:14px; border-radius:50%; background:#888; transition:left .15s; }
.pmx-tog input:checked + i { background:var(--ia-accent); border-color:var(--ia-accent); }
.pmx-tog input:checked + i::after { left:16px; background:var(--ia-accent-text,#0a0a0a); }
.pmx-b { border-top:.5px solid var(--ia-border); padding:14px 15px; display:none; }
.pmx.open .pmx-b { display:block; }
.pmx-row { display:grid; grid-template-columns:140px 1fr; gap:10px; align-items:center; margin-bottom:10px; font-size:12px; }
.pmx-row > label { color:var(--ia-text-muted); }
.pmx-inp { background:var(--ia-surface-2,#1a1a1a); border:1px solid var(--ia-border); color:var(--ia-text); border-radius:7px; padding:8px 10px; font-size:12px; width:100%; }
.pmx-surf { display:flex; flex-direction:column; gap:7px; }
.pmx-surf .r { display:flex; gap:9px; align-items:center; }
.pmx-surf .r span { width:104px; font-size:11.5px; color:var(--ia-text-muted); }
.pmx-seg { display:flex; border:1px solid var(--ia-border-2,rgba(255,255,255,.2)); border-radius:8px; overflow:hidden; width:fit-content; }
.pmx-seg label { font-size:11.5px; font-weight:600; cursor:pointer; color:var(--ia-text-muted); display:block; }
.pmx-seg input { display:none; }
.pmx-seg label span { display:block; padding:7px 13px; }
.pmx-seg input:checked + span { background:var(--ia-accent); color:var(--ia-accent-text,#0a0a0a); }
.pmx-save { padding:8px 16px; border-radius:7px; font-size:12px; font-weight:600; cursor:pointer; border:none; background:var(--ia-accent); color:var(--ia-accent-text,#0a0a0a); }
.pmx-del { background:none; border:none; color:var(--ia-text-muted); font-size:11px; cursor:pointer; text-decoration:underline; }
.pmx-del:hover { color:#f87171; }
.pmx-add { border:1px dashed var(--ia-border-2,rgba(255,255,255,.2)); border-radius:12px; padding:13px 15px; margin-bottom:10px; }
.pmx-add summary { color:var(--ia-text-muted); font-size:12.5px; cursor:pointer; list-style:none; }
.pmx-add summary::-webkit-details-marker { display:none; }
</style>

<div style="font-size:13px;font-weight:700;margin:22px 0 4px">Payment methods</div>
<div style="font-size:11.5px;color:var(--ia-text-muted);margin-bottom:12px;line-height:1.5">One list governs register tenders and customer checkout. Toggle, then expand to set where each method shows and the hint text customers see — nothing is hardcoded.</div>

@foreach($paymentMethods as $pm)
  <div class="pmx" id="pm-{{ $pm->method_key }}">
    <div class="pmx-h" onclick="this.parentNode.classList.toggle('open')">
      <div class="pmx-ic">{{ mb_substr($pm->name, 0, 1) }}</div>
      <div>
        <div class="nm">{{ $pm->name }}@if($pm->is_custom)<span class="pmx-tag">custom</span>@endif</div>
        <div class="k">{{ $pm->kind === 'integrated' ? 'Integrated' : 'Manual' }}@if($pm->handle) · {{ $pm->handle }}@endif</div>
      </div>
      <span style="margin-left:auto;font-size:10.5px;color:{{ $pm->enabled ? 'var(--ia-accent)' : 'var(--ia-text-muted)' }}">{{ $pm->enabled ? 'On' : 'Off' }}</span>
      <span style="color:var(--ia-text-muted);font-size:11px">▾</span>
    </div>
    <div class="pmx-b">
      <form method="POST" action="{{ route('tenant.settings.payment-methods.update', $pm->id) }}">
        @csrf
        <div class="pmx-row"><label>Enabled</label>
          <label class="pmx-tog"><input type="checkbox" name="enabled" value="1" @checked($pm->enabled)><i></i></label>
        </div>

        @if($pm->is_custom)
          <div class="pmx-row"><label>Name</label><input class="pmx-inp" type="text" name="name" value="{{ $pm->name }}" maxlength="80"></div>
        @endif

        @if($pm->method_key === 'cash_app')
          <div class="pmx-row"><label>Processing</label>
            <div class="pmx-seg">
              <label><input type="radio" name="mode" value="manual" @checked(($pm->mode ?? 'manual') === 'manual')><span>Manual — $Cashtag</span></label>
              <label><input type="radio" name="mode" value="stripe" @checked($pm->mode === 'stripe')><span>Through Stripe</span></label>
            </div>
          </div>
          <div class="pmx-row"><label>&nbsp;</label><div style="font-size:10.5px;color:var(--ia-text-dim,rgba(255,255,255,.4));line-height:1.5">Manual: no fees, money lands in your Cash App balance, staff confirms. Through Stripe: instant confirmation like a card, standard Stripe rates, arrives with Stripe payouts.</div></div>
        @endif

        @if(in_array($pm->method_key, ['venmo', 'cash_app']) || $pm->is_custom)
          <div class="pmx-row"><label>{{ $pm->method_key === 'venmo' ? 'Venmo handle' : ($pm->method_key === 'cash_app' ? 'Cashtag' : 'Handle / account') }}</label>
            <input class="pmx-inp" type="text" name="handle" value="{{ $pm->handle }}" maxlength="120"
                   placeholder="{{ $pm->method_key === 'venmo' ? 'GroundControl-Bikes' : ($pm->method_key === 'cash_app' ? '$GroundControlBikes' : 'optional') }}"></div>
        @endif

        @if($pm->kind === 'manual' && $pm->method_key !== 'cash' && $pm->method_key !== 'store_credit')
          <div class="pmx-row"><label>Customer instructions</label>
            <input class="pmx-inp" type="text" name="instructions" value="{{ $pm->instructions }}" maxlength="300" placeholder="Shown to customers when they pick this method"></div>
        @endif

        <div class="pmx-row" style="align-items:start"><label style="padding-top:8px">Show at · hint text</label>
          <div class="pmx-surf">
            @foreach(['register' => 'Register', 'online' => 'Online checkout', 'booking' => 'Booking', 'rental' => 'Rentals'] as $sf => $label)
              <div class="r">
                <label class="pmx-tog" style="margin:0"><input type="checkbox" name="surface_{{ $sf }}" value="1" @checked((bool) data_get($pm->surfaces, "$sf.on"))><i></i></label>
                <span>{{ $label }}</span>
                <input class="pmx-inp" type="text" name="hint_{{ $sf }}" value="{{ data_get($pm->surfaces, "$sf.hint") }}" maxlength="60" placeholder="hint customers see" style="flex:1">
              </div>
            @endforeach
          </div>
        </div>

        @if(in_array($pm->method_key, ['venmo', 'cash_app']))
          <div class="pmx-row"><label>Payment link + QR</label>
            <label class="pmx-tog" style="margin:0"><input type="checkbox" name="link_qr" value="1" @checked($pm->link_qr)><i></i></label>
          </div>
        @endif

        {{-- MARKER-PATCH-636 — QB mapping: where this method's money deposits --}}
        <div class="pmx-row"><label>QB deposit account</label>
          <input class="pmx-inp" type="text" name="qb_deposit_account" maxlength="120"
                 value="{{ $pm->qb['deposit_account'] ?? '' }}"
                 placeholder="{{ $pm->method_key === 'card_stripe' ? 'Stripe Clearing' : 'Undeposited Funds' }} (default)"></div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px">
          <button class="pmx-save" type="submit">Save {{ $pm->name }}</button>
          <span></span>
        </div>
      </form>
      @if($pm->is_custom)
        <form method="POST" action="{{ route('tenant.settings.payment-methods.delete', $pm->id) }}" style="margin-top:8px;text-align:right"
              onsubmit="return confirm('Remove {{ $pm->name }}? Past sales keep their records.')">
          @csrf<button class="pmx-del" type="submit">Remove this method</button>
        </form>
      @endif
    </div>
  </div>
@endforeach

<details class="pmx-add">
  <summary>＋ Add payment method — name it, write the instructions, pick where it shows</summary>
  <form method="POST" action="{{ route('tenant.settings.payment-methods.store') }}" style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
    @csrf
    <input class="pmx-inp" type="text" name="name" required maxlength="80" placeholder="e.g. Zelle, House account" style="flex:1;min-width:160px">
    <input class="pmx-inp" type="text" name="instructions" maxlength="300" placeholder="customer instructions (optional)" style="flex:2;min-width:220px">
    <button class="pmx-save" type="submit">Add</button>
  </form>
</details>

{{-- MARKER-PATCH-636 — global QuickBooks accounts (the journal's credit side) --}}
<div class="pmx" style="margin-top:14px">
  <div class="pmx-h" onclick="this.parentNode.classList.toggle('open')">
    <div class="pmx-ic">QB</div>
    <div>
      <div class="nm">QuickBooks accounts</div>
      <div class="k">Journal export account names — deposit accounts are set per method above</div>
    </div>
    <span style="color:var(--ia-text-muted);font-size:11px;margin-left:auto">▾</span>
  </div>
  <div class="pmx-b">
    <form method="POST" action="{{ route('tenant.settings.payment-methods.qb') }}">
      @csrf
      @php $qs = tenant()->settings ?? []; @endphp
      <div class="pmx-row"><label>Sales income</label><input class="pmx-inp" type="text" name="qb_income_account" maxlength="120" value="{{ $qs['qb_income_account'] ?? '' }}" placeholder="Sales (default)"></div>
      <div class="pmx-row"><label>Sales tax payable</label><input class="pmx-inp" type="text" name="qb_tax_account" maxlength="120" value="{{ $qs['qb_tax_account'] ?? '' }}" placeholder="Sales Tax Payable (default)"></div>
      <div class="pmx-row"><label>Tips payable</label><input class="pmx-inp" type="text" name="qb_tips_account" maxlength="120" value="{{ $qs['qb_tips_account'] ?? '' }}" placeholder="Tips Payable (default)"></div>
      <div class="pmx-row"><label>&nbsp;</label><div style="font-size:10.5px;color:var(--ia-text-dim,rgba(255,255,255,.4));line-height:1.5">Names must match your QuickBooks chart of accounts exactly — the journal import matches by name. Tax and tips credit one account each; per-method splits happen on the debit side.</div></div>
      <div style="display:flex;justify-content:flex-end;margin-top:4px"><button class="pmx-save" type="submit">Save QB accounts</button></div>
    </form>
  </div>
</div>

