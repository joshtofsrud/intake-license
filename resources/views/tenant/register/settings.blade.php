@extends('layouts.tenant.app')

{{-- MARKER-REG-SETTINGS -- register settings tab --}}

@php $pageTitle = 'Register settings'; @endphp

@push('styles')
<style>
  .reg-tabs-bar{
    display:flex;gap:4px;margin:0 0 18px;border-bottom:0.5px solid var(--ia-border);
    flex-wrap:wrap
  }
  /* MARKER-REG-MOBILE ------------------------------------------------- */
  /* display:contents keeps the links as direct flex children of the bar on
     desktop, so nothing about the existing layout changes. */
  .reg-tabs-scroll{display:contents}

  @media (max-width: 760px){
    .ia-page-subtitle{display:none}

    .reg-tabs-bar{display:block;flex-wrap:nowrap}
    .reg-tabs-scroll{
      display:flex;gap:4px;overflow-x:auto;scrollbar-width:none;
      -webkit-overflow-scrolling:touch
    }
    .reg-tabs-scroll::-webkit-scrollbar{display:none}
    .reg-tab-link{white-space:nowrap;flex:0 0 auto;padding:10px 14px}
  }

  .reg-tab-link{
    padding:10px 18px;font-size:13px;font-weight:500;color:var(--ia-text-dim);
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-0.5px;
    transition:color var(--ia-t),border-color var(--ia-t)
  }
  .reg-tab-link:hover{color:var(--ia-text)}
  .reg-tab-link.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}

  .rs-card{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:20px 24px;margin-bottom:16px;max-width:720px}
  .rs-card h2{font-size:13px;font-weight:500;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}
  .rs-card .rs-desc{font-size:13px;color:var(--ia-text-dim);margin-bottom:14px;line-height:1.55}
  .rs-row{display:flex;align-items:center;gap:12px}
  .rs-row label{font-size:13px;color:var(--ia-text-muted);min-width:110px}
  .rs-links{display:flex;flex-direction:column;gap:8px}
  .rs-links a{font-size:13px;color:var(--ia-text-muted);transition:color var(--ia-t)}
  .rs-links a:hover{color:var(--ia-text)}
  /* MARKER-GC-SETTINGS */
  .gc-presets{display:grid;grid-template-columns:repeat(4,90px);gap:8px}
  .gc-presets input{text-align:center}
  .gc-inline{display:flex;align-items:center;gap:8px}
  .gc-sm{width:110px}
  .gc-hint{font-size:11.5px;color:var(--ia-text-dim);margin-top:6px;line-height:1.5;max-width:460px}
  .gc-check{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ia-text-muted);min-width:0;margin-bottom:6px}
  .gc-check input[type=checkbox]{width:15px;height:15px;accent-color:var(--ia-accent)}
</style>
@endpush

@section('content')

<div class="reg-tabs-bar">
  <div class="reg-tabs-scroll">{{-- MARKER-REG-MOBILE --}}
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
  <a href="{{ route('tenant.register.registers') }}" class="reg-tab-link">Registers</a>
  <a href="{{ route('tenant.register.settings') }}" class="reg-tab-link active">Settings</a>
  </div>
</div>

@if (session('status'))
  <div class="ia-flash ia-flash--success" style="max-width:720px">{{ session('status') }}</div>
@endif

<form method="POST" action="{{ route('tenant.register.settings.save') }}">
  @csrf

  <div class="rs-card">
    <h2>Draft transactions</h2>
    <div class="rs-desc">
      Drafts are unfinished carts saved at the register. Old drafts with no payments
      are discarded automatically past this age &mdash; the same as pressing Discard,
      so any un-placed special orders they requested are retracted too. Drafts parked
      on an appointment are never touched.
    </div>
    <div class="rs-row">
      <label for="rs-draft">Keep drafts</label>
      {{-- MARKER-SSEL-BATCH2 --}}
      <div style="min-width:180px;max-width:220px">
        <x-tenant.searchable-select name="register_draft_retention_days" :searchable="false"
          :options="['0' => 'Forever', '7' => '7 days', '14' => '14 days', '30' => '30 days', '90' => '90 days']"
          :selected="(string) $draftRetention" any="" noun="options" />{{-- MARKER-SSEL-NODUPE — the default is a real option; an "any" row duplicates it --}}
      </div>
    </div>
  </div>

  <div class="rs-card">
    <h2>Quotes</h2>
    <div class="rs-desc">
      Quotes are estimates you hand a customer to think over. If you set an age here,
      quotes older than it are discarded the same way. Leave it on Forever if you
      follow up on old quotes.
    </div>
    <div class="rs-row">
      <label for="rs-quote">Keep quotes</label>
      {{-- MARKER-SSEL-BATCH2 --}}
      <div style="min-width:180px;max-width:220px">
        <x-tenant.searchable-select name="register_quote_retention_days" :searchable="false"
          :options="['0' => 'Forever', '30' => '30 days', '90' => '90 days', '180' => '180 days', '365' => '1 year']"
          :selected="(string) $quoteRetention" any="" noun="options" />{{-- MARKER-SSEL-NODUPE — the default is a real option; an "any" row duplicates it --}}
      </div>
    </div>
  </div>

  {{-- MARKER-GC-SETTINGS --}}
  @if($tenant->gift_cards_visible)
  <div class="rs-card">
    <h2>Gift cards</h2>
    <div class="rs-desc">
      What staff and customers see when buying a card. Amounts already sold are
      never affected by changes here &mdash; outstanding balances stay redeemable
      whatever you set.
    </div>

    <div class="rs-row" style="align-items:flex-start;margin-bottom:14px">
      <label>Preset amounts</label>
      <div>
        <div class="gc-presets">
          @for($i = 0; $i < 4; $i++)
            <input type="text" inputmode="decimal" class="ia-input"
                   name="gift_card_presets[]"
                   value="{{ isset($gift['presets'][$i]) ? number_format($gift['presets'][$i] / 100, 2, '.', '') : '' }}"
                   placeholder="&mdash;">
          @endfor
        </div>
        <div class="gc-hint">Leave a box empty to show fewer buttons. Staff and customers can always type a custom amount.</div>
      </div>
    </div>

    <div class="rs-row" style="margin-bottom:14px">
      <label>Amount limits</label>
      <div class="gc-inline">
        <span class="gc-hint">Min</span>
        <input type="text" inputmode="decimal" class="ia-input gc-sm" name="gift_card_min"
               value="{{ number_format($gift['min_cents'] / 100, 2, '.', '') }}">
        <span class="gc-hint">Max</span>
        <input type="text" inputmode="decimal" class="ia-input gc-sm" name="gift_card_max"
               value="{{ number_format($gift['max_cents'] / 100, 2, '.', '') }}">
      </div>
    </div>

    <div class="rs-row" style="align-items:flex-start;margin-bottom:14px">
      <label>Sell online</label>
      <div>
        <label class="gc-check">
          <input type="hidden" name="gift_card_online_egift" value="0">
          <input type="checkbox" name="gift_card_online_egift" value="1" @checked($gift['online_egift'])>
          E-gift cards, emailed to the recipient
        </label>
        <label class="gc-check">
          <input type="hidden" name="gift_card_online_physical" value="0">
          <input type="checkbox" name="gift_card_online_physical" value="1" @checked($gift['online_physical'])>
          Physical cards, picked up in store
        </label>
        <div class="gc-hint">
          Turn both off to keep gift cards register-only &mdash; your public
          <code>/gift-cards</code> page then shows a call-us message instead of checkout.
          The balance check stays available either way.
        </div>
      </div>
    </div>

    <div class="rs-row" style="align-items:flex-start;margin-bottom:14px">
      <label>Refunds</label>
      <div>
        <label class="gc-check">
          <input type="hidden" name="gift_card_refund_to_card" value="0">
          <input type="checkbox" name="gift_card_refund_to_card" value="1" @checked($gift['refund_to_card'])>
          Allow refunding onto a gift card
        </label>
        <div class="gc-hint">Adds &ldquo;Gift card&rdquo; as a refund method: credits the customer&rsquo;s existing card, or issues a new one for the refund amount.</div>
      </div>
    </div>

    <div class="rs-row" style="margin-bottom:14px">
      <label for="rs-gc-pending">Abandoned online</label>
      <div>
        {{-- MARKER-SSEL-BATCH2 --}}
        <div style="min-width:180px;max-width:230px">
          <x-tenant.searchable-select name="gift_card_pending_retention_days" :searchable="false"
            :options="['0' => 'Keep forever', '1' => 'Purge after 1 day', '3' => 'Purge after 3 days', '7' => 'Purge after 7 days', '30' => 'Purge after 30 days']"
            :selected="(string) $gift['pending_days']" any="" noun="options" />
        </div>
        <div class="gc-hint">An online purchase that never finished payment leaves an unpaid card row. Only rows with no payment and no balance history are ever purged.</div>
      </div>
    </div>

    <div class="rs-row" style="align-items:flex-start;margin-bottom:14px">
      <label for="rs-gc-msg">Default message</label>
      <div style="flex:1">
        <input type="text" id="rs-gc-msg" class="ia-input" name="gift_card_default_message"
               maxlength="200" style="width:100%;max-width:420px"
               value="{{ $gift['default_message'] }}" placeholder="Optional — prefills the gift message box">
      </div>
    </div>

    <div class="rs-row" style="align-items:flex-start">
      <label for="rs-gc-policy">Terms line</label>
      <div style="flex:1">
        <input type="text" id="rs-gc-policy" class="ia-input" name="gift_card_policy_line"
               maxlength="160" style="width:100%;max-width:420px"
               value="{{ $gift['policy_line'] }}">
        <div class="gc-hint">Shown on your buy page, the balance check and the e-gift email. Check your state&rsquo;s rules before promising an expiry.</div>
      </div>
    </div>
  </div>
  @endif

  <div style="max-width:720px;margin-bottom:24px">
    <button type="submit" class="ia-btn ia-btn--primary">Save settings</button>
  </div>
</form>

<div class="rs-card">
  <h2>More register settings</h2>
  <div class="rs-desc">These live in the main settings area:</div>
  <div class="rs-links">
    <a href="{{ route('tenant.settings.index') }}#payments">Payment methods, manual tenders &amp; card payments (Stripe) &rarr;</a>
    <a href="{{ route('tenant.settings.index') }}#tags">Receipt footer &amp; print identity &rarr;</a>
  </div>
</div>

@endsection
