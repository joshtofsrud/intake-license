#!/bin/bash
# apply-gift-card-settings.sh
#
# MARKER-GC-SETTINGS — gift card configuration on the register Settings tab
# (the tab MARKER-REG-SETTINGS already ships). Requires the gift card
# patches 1-4.
#
# One source of truth: GiftCardService::config($tenant) normalizes the
# stored settings and every surface reads it — the register sell modal, the
# public buy page, and the server-side validation on both. Nothing reads
# raw settings keys, so a default only ever changes in one place.
#
# Stored (tenant->settings):
#   gift_card_presets[]          up to 4 preset amounts in cents; fewer
#                                entries = fewer buttons, empty = custom only
#   gift_card_min_cents          floor for any card (default $5)
#   gift_card_max_cents          ceiling for any card (default $2,000)
#   gift_card_online_egift       sell e-gifts on the public page
#   gift_card_online_physical    sell pickup-in-store cards on the public page
#   gift_card_refund_to_card     allow refunds onto a gift card (patch C wires
#                                the register side; stored here so both land
#                                together)
#   gift_card_pending_retention_days  abandoned online checkouts to purge
#   gift_card_default_message    prefills the gift message box
#   gift_card_policy_line        the expiry/terms line shown to customers
#
# NOT added: a purchaser-receipt on/off switch. That email is a Communication
# Center message (patch B), and its toggle lives there with every other
# customer email — two switches for one behavior is how a shop ends up
# certain it turned something off when it didn't.
set -e

MARKER="MARKER-GC-SETTINGS"
SVC="app/Services/Tenant/GiftCardService.php"

[ -f "$SVC" ] || { echo "ERROR: requires the gift card patches first"; exit 1; }
grep -q "MARKER-REG-SETTINGS" app/Http/Controllers/Tenant/RegisterController.php || { echo "ERROR: requires apply-register-settings-reaper.sh"; exit 1; }
grep -q "MARKER-GIFTCARDS-GATE" app/Models/Tenant.php || { echo "ERROR: requires apply-gift-cards-addon-gate.sh"; exit 1; }
if grep -q "$MARKER" "$SVC" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io

# ---------------------------------------------------------------
# 1. GiftCardService::config() — the single normalizer
# ---------------------------------------------------------------
p = 'app/Services/Tenant/GiftCardService.php'
src = io.open(p, encoding='utf-8').read()

a = "    public function generateCode(string $tenantId): string"
assert src.count(a) == 1
src = src.replace(a, """    /**
     * MARKER-GC-SETTINGS -- normalized gift card configuration. Every surface
     * (register modal, public buy page, and the validation behind both) reads
     * this, so a default lives in exactly one place. Values are clamped here
     * rather than trusted: settings rows predate the validation that now
     * writes them, and a bad max would otherwise reject every sale.
     */
    public static function config(\\App\\Models\\Tenant $tenant): array
    {
        $s = (array) ($tenant->settings ?? []);

        $presets = array_values(array_filter(array_map(
            fn ($v) => (int) $v,
            (array) ($s['gift_card_presets'] ?? [2500, 5000, 10000, 15000])
        ), fn ($v) => $v > 0));
        $presets = array_slice($presets, 0, 4);

        $min = (int) ($s['gift_card_min_cents'] ?? 500);
        $max = (int) ($s['gift_card_max_cents'] ?? 200000);
        $min = max(100, $min);
        $max = max($min, $max);

        return [
            'presets'          => $presets,
            'min_cents'        => $min,
            'max_cents'        => $max,
            'online_egift'     => (bool) ($s['gift_card_online_egift'] ?? true),
            'online_physical'  => (bool) ($s['gift_card_online_physical'] ?? true),
            'refund_to_card'   => (bool) ($s['gift_card_refund_to_card'] ?? false),
            'pending_days'     => (int) ($s['gift_card_pending_retention_days'] ?? 7),
            'default_message'  => (string) ($s['gift_card_default_message'] ?? ''),
            'policy_line'      => (string) ($s['gift_card_policy_line'] ?? 'Never expires. Redeemable in store and online.'),
        ];
    }

    /** MARKER-GC-SETTINGS -- shared amount check for every sell path. */
    public static function assertAmountAllowed(\\App\\Models\\Tenant $tenant, int $cents): void
    {
        $cfg = self::config($tenant);
        if ($cents < $cfg['min_cents'] || $cents > $cfg['max_cents']) {
            throw new SaleValidationException(sprintf(
                'Gift card amounts must be between $%s and $%s.',
                number_format($cfg['min_cents'] / 100, 2),
                number_format($cfg['max_cents'] / 100, 2)
            ));
        }
    }

""" + a, 1)

# Enforce it where cards actually come into existence.
b = """            if (! \\App\\Models\\Tenant::find($sale->tenant_id)?->gift_cards_enabled) {
                throw new SaleValidationException('Gift cards are not enabled for this shop.');
            }
"""
assert src.count(b) == 1
src = src.replace(b, """            $sellTenant = \\App\\Models\\Tenant::find($sale->tenant_id);
            if (! $sellTenant?->gift_cards_enabled) {
                throw new SaleValidationException('Gift cards are not enabled for this shop.');
            }

            // MARKER-GC-SETTINGS -- the configured floor/ceiling, enforced at
            // activation so it also covers a draft rung up before the limits
            // changed rather than only the modal that created the line.
            self::assertAmountAllowed($sellTenant, (int) round($line->unit_price_cents * (float) $line->quantity));
""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: GiftCardService::config + amount guard')
PY

# ---------------------------------------------------------------
# 2. Controller: load + save
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Http/Controllers/Tenant/RegisterController.php'
src = io.open(p, encoding='utf-8').read()

a = """        return view('tenant.register.settings', [
            'tenant'         => $tenant,
            'draftRetention' => (int) ($cfg['register_draft_retention_days'] ?? 0),
            'quoteRetention' => (int) ($cfg['register_quote_retention_days'] ?? 0),
        ]);"""
assert src.count(a) == 1
src = src.replace(a, """        return view('tenant.register.settings', [
            'tenant'         => $tenant,
            'draftRetention' => (int) ($cfg['register_draft_retention_days'] ?? 0),
            'quoteRetention' => (int) ($cfg['register_quote_retention_days'] ?? 0),
            // MARKER-GC-SETTINGS
            'gift'           => \\App\\Services\\Tenant\\GiftCardService::config($tenant),
        ]);""", 1)

b = """        $data = $request->validate([
            'register_draft_retention_days' => 'required|integer|in:0,7,14,30,90',
            'register_quote_retention_days' => 'required|integer|in:0,30,90,180,365',
        ]);

        $settings = (array) ($tenant->settings ?? []);
        $settings['register_draft_retention_days'] = (int) $data['register_draft_retention_days'];
        $settings['register_quote_retention_days'] = (int) $data['register_quote_retention_days'];
        $tenant->settings = $settings;
        $tenant->save();"""
assert src.count(b) == 1
src = src.replace(b, """        $data = $request->validate([
            'register_draft_retention_days' => 'required|integer|in:0,7,14,30,90',
            'register_quote_retention_days' => 'required|integer|in:0,30,90,180,365',
            // MARKER-GC-SETTINGS -- dollars in the form, cents in storage.
            'gift_card_presets'                  => 'nullable|array|max:4',
            'gift_card_presets.*'                => 'nullable|numeric|min:0|max:20000',
            'gift_card_min'                      => 'nullable|numeric|min:1|max:20000',
            'gift_card_max'                      => 'nullable|numeric|min:1|max:20000',
            'gift_card_online_egift'             => 'nullable|boolean',
            'gift_card_online_physical'          => 'nullable|boolean',
            'gift_card_refund_to_card'           => 'nullable|boolean',
            'gift_card_pending_retention_days'   => 'nullable|integer|in:0,1,3,7,30',
            'gift_card_default_message'          => 'nullable|string|max:200',
            'gift_card_policy_line'              => 'nullable|string|max:160',
        ]);

        $settings = (array) ($tenant->settings ?? []);
        $settings['register_draft_retention_days'] = (int) $data['register_draft_retention_days'];
        $settings['register_quote_retention_days'] = (int) $data['register_quote_retention_days'];

        // MARKER-GC-SETTINGS -- only written when the gift card section was on
        // the page. A shop without the add-on never renders those inputs, and a
        // blind write would silently reset its presets to nothing.
        if ($tenant->gift_cards_visible) {
            $presets = array_values(array_filter(array_map(
                fn ($v) => (int) round(((float) $v) * 100),
                (array) ($data['gift_card_presets'] ?? [])
            ), fn ($v) => $v > 0));

            $min = (int) round(((float) ($data['gift_card_min'] ?? 5)) * 100);
            $max = (int) round(((float) ($data['gift_card_max'] ?? 2000)) * 100);
            if ($max < $min) {
                [$min, $max] = [$max, $min]; // reversed pair: swap rather than reject
            }

            $settings['gift_card_presets']   = $presets;
            $settings['gift_card_min_cents'] = max(100, $min);
            $settings['gift_card_max_cents'] = max(max(100, $min), $max);
            $settings['gift_card_online_egift']    = (bool) ($data['gift_card_online_egift'] ?? false);
            $settings['gift_card_online_physical'] = (bool) ($data['gift_card_online_physical'] ?? false);
            $settings['gift_card_refund_to_card']  = (bool) ($data['gift_card_refund_to_card'] ?? false);
            $settings['gift_card_pending_retention_days'] = (int) ($data['gift_card_pending_retention_days'] ?? 7);
            $settings['gift_card_default_message'] = trim((string) ($data['gift_card_default_message'] ?? ''));
            $settings['gift_card_policy_line']     = trim((string) ($data['gift_card_policy_line'] ?? ''));
        }

        $tenant->settings = $settings;
        $tenant->save();""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: settings load + save')
PY

# ---------------------------------------------------------------
# 3. Settings view — the Gift cards card
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'resources/views/tenant/register/settings.blade.php'
src = io.open(p, encoding='utf-8').read()

a = """  <div style="max-width:720px;margin-bottom:24px">
    <button type="submit" class="ia-btn ia-btn--primary">Save settings</button>
  </div>"""
assert src.count(a) == 1
src = src.replace(a, """  {{-- MARKER-GC-SETTINGS --}}
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
        <select id="rs-gc-pending" name="gift_card_pending_retention_days" class="ia-input" style="width:auto;min-width:180px">
          <option value="0"  @selected($gift['pending_days'] === 0)>Keep forever</option>
          <option value="1"  @selected($gift['pending_days'] === 1)>Purge after 1 day</option>
          <option value="3"  @selected($gift['pending_days'] === 3)>Purge after 3 days</option>
          <option value="7"  @selected($gift['pending_days'] === 7)>Purge after 7 days</option>
          <option value="30" @selected($gift['pending_days'] === 30)>Purge after 30 days</option>
        </select>
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

""" + a, 1)

# styles for the new controls
b = "  .rs-links a:hover{color:var(--ia-text)}"
assert src.count(b) == 1
src = src.replace(b, b + """
  /* MARKER-GC-SETTINGS */
  .gc-presets{display:grid;grid-template-columns:repeat(4,90px);gap:8px}
  .gc-presets input{text-align:center}
  .gc-inline{display:flex;align-items:center;gap:8px}
  .gc-sm{width:110px}
  .gc-hint{font-size:11.5px;color:var(--ia-text-dim);margin-top:6px;line-height:1.5;max-width:460px}
  .gc-check{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ia-text-muted);min-width:0;margin-bottom:6px}
  .gc-check input[type=checkbox]{width:15px;height:15px;accent-color:var(--ia-accent)}""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: settings view section')
PY

# ---------------------------------------------------------------
# 4. Register sell modal reads the config
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'resources/views/tenant/register/index.blade.php'
src = io.open(p, encoding='utf-8').read()

# MARKER-GC-SETTINGS -- define the config ONCE at the top: the refund tender
# button (patch C) reads it far earlier in the file than the sell modal does.
hoist_anchor = "@extends('layouts.tenant.app')"
assert src.count(hoist_anchor) == 1
src = src.replace(
    hoist_anchor,
    hoist_anchor + "\n\n@php $gcCfg = \\App\\Services\\Tenant\\GiftCardService::config(tenant()); @endphp {{-- MARKER-GC-SETTINGS --}}",
    1
)

a = """    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px" id="gcAmountGrid">
      <button type="button" class="reg-tender-btn" data-cents="2500" style="text-align:center;font-weight:600">$25</button>
      <button type="button" class="reg-tender-btn" data-cents="5000" style="text-align:center;font-weight:600">$50</button>
      <button type="button" class="reg-tender-btn" data-cents="10000" style="text-align:center;font-weight:600">$100</button>
      <button type="button" class="reg-tender-btn" data-cents="15000" style="text-align:center;font-weight:600">$150</button>
    </div>
    <input type="text" id="gcCustomAmount" placeholder="Custom amount" inputmode="decimal">"""
assert src.count(a) == 1
src = src.replace(a, """    {{-- MARKER-GC-SETTINGS -- presets come from register settings --}}
    @if(count($gcCfg['presets']))
    <div style="display:grid;grid-template-columns:repeat({{ count($gcCfg['presets']) }},1fr);gap:8px;margin-bottom:10px" id="gcAmountGrid">
      @foreach($gcCfg['presets'] as $gcAmt)
      <button type="button" class="reg-tender-btn" data-cents="{{ $gcAmt }}" style="text-align:center;font-weight:600">${{ rtrim(rtrim(number_format($gcAmt / 100, 2), '0'), '.') }}</button>
      @endforeach
    </div>
    @else
    <div id="gcAmountGrid" style="display:none"></div>
    @endif
    <input type="text" id="gcCustomAmount" placeholder="Custom amount" inputmode="decimal">""", 1)

# client-side limits + default message, mirroring the server guard
b = """  if (!cents || cents < 100) { gcSellError('Pick or enter an amount of at least $1.00.'); return; }"""
assert src.count(b) == 1
src = src.replace(b, """  // MARKER-GC-SETTINGS -- same floor/ceiling the server enforces at activation.
  if (!cents) { gcSellError('Pick or enter an amount.'); return; }
  if (cents < GC_CFG.min || cents > GC_CFG.max) {
    gcSellError('Gift card amounts must be between $' + (GC_CFG.min / 100).toFixed(2) + ' and $' + (GC_CFG.max / 100).toFixed(2) + '.');
    return;
  }""", 1)

c = """function gcSellError(msg) {"""
assert src.count(c) == 1
src = src.replace(c, """// MARKER-GC-SETTINGS -- limits + default message from register settings.
const GC_CFG = @json(['min' => $gcCfg['min_cents'], 'max' => $gcCfg['max_cents'], 'default_message' => $gcCfg['default_message']]);

function gcSellError(msg) {""", 1)

# prefill the message box when the modal is reset
d = """  ['gcCustomAmount','gcSellCode','gcSellEmail','gcSellMessage'].forEach(id => { document.getElementById(id).value = ''; });"""
assert src.count(d) == 1
src = src.replace(d, """  ['gcCustomAmount','gcSellCode','gcSellEmail','gcSellMessage'].forEach(id => { document.getElementById(id).value = ''; });
  document.getElementById('gcSellMessage').value = GC_CFG.default_message || ''; // MARKER-GC-SETTINGS""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: register modal wired to settings')
PY

# ---------------------------------------------------------------
# 5. Public buy page reads the same config
# ---------------------------------------------------------------
python3 - <<'PY'
import io

# 5a. controller: pass config, enforce type + limits
p = 'app/Http/Controllers/Tenant/GiftCardPublicController.php'
src = io.open(p, encoding='utf-8').read()

a = """        return \\App\\Services\\Tenant\\SiteChromeService::render($tenant, 'gift_shop', [
            'tenant'    => $tenant,
            'stripePk'  => $pk,
        ], ['title' => 'Gift cards', 'description' => 'Buy a ' . $tenant->name . ' gift card.']);"""
assert src.count(a) == 1
src = src.replace(a, """        return \\App\\Services\\Tenant\\SiteChromeService::render($tenant, 'gift_shop', [
            'tenant'    => $tenant,
            'stripePk'  => $pk,
            'gift'      => GiftCardService::config($tenant), // MARKER-GC-SETTINGS
        ], ['title' => 'Gift cards', 'description' => 'Buy a ' . $tenant->name . ' gift card.']);""", 1)

b = """        $amount = (int) round(((float) $data['amount']) * 100);"""
assert src.count(b) == 1
src = src.replace(b, """        // MARKER-GC-SETTINGS -- the shop's own limits and channel switches,
        // enforced server-side: the buy form is public, so its client checks
        // are a convenience, not a control.
        $cfg = GiftCardService::config($tenant);
        if ($data['type'] === 'egift' && ! $cfg['online_egift']) {
            return response()->json(['ok' => false, 'message' => 'E-gift cards aren\\'t available online right now.'], 422);
        }
        if ($data['type'] === 'physical' && ! $cfg['online_physical']) {
            return response()->json(['ok' => false, 'message' => 'Physical cards aren\\'t available online right now.'], 422);
        }

        $amount = (int) round(((float) $data['amount']) * 100);
        if ($amount < $cfg['min_cents'] || $amount > $cfg['max_cents']) {
            return response()->json(['ok' => false, 'message' => sprintf(
                'Gift card amounts must be between $%s and $%s.',
                number_format($cfg['min_cents'] / 100, 2),
                number_format($cfg['max_cents'] / 100, 2)
            )], 422);
        }""", 1)

# balance page shows the terms line too
c = """        return \\App\\Services\\Tenant\\SiteChromeService::render($tenant, 'gift_balance', [
            'tenant' => $tenant,
            'result' => null,
            'error'  => null,
        ], ['title' => 'Gift card balance']);"""
assert src.count(c) == 1
src = src.replace(c, """        return \\App\\Services\\Tenant\\SiteChromeService::render($tenant, 'gift_balance', [
            'tenant' => $tenant,
            'result' => null,
            'error'  => null,
            'gift'   => GiftCardService::config($tenant), // MARKER-GC-SETTINGS
        ], ['title' => 'Gift card balance']);""", 1)

d = """        return \\App\\Services\\Tenant\\SiteChromeService::render($tenant, 'gift_balance', [
            'tenant' => $tenant,
            'result' => $result,
            'error'  => $result ? null : 'No gift card found for that code.',
        ], ['title' => 'Gift card balance']);"""
assert src.count(d) == 1
src = src.replace(d, """        return \\App\\Services\\Tenant\\SiteChromeService::render($tenant, 'gift_balance', [
            'tenant' => $tenant,
            'result' => $result,
            'error'  => $result ? null : 'No gift card found for that code.',
            'gift'   => GiftCardService::config($tenant), // MARKER-GC-SETTINGS
        ], ['title' => 'Gift card balance']);""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: public controller enforces settings')

# 5b. buy page markup
p2 = 'resources/views/public/sections/_gift_shop.blade.php'
s2 = io.open(p2, encoding='utf-8').read()

e = """    <div class="sub">Good for anything — service, parts, rentals. Never expires. <a href="/gift-cards/balance" style="text-decoration:underline">Check a balance</a></div>"""
assert s2.count(e) == 1
s2 = s2.replace(e, """    {{-- MARKER-GC-SETTINGS --}}
    <div class="sub">Good for anything — service, parts, rentals.@if($gift['policy_line']) {{ $gift['policy_line'] }}@endif <a href="/gift-cards/balance" style="text-decoration:underline">Check a balance</a></div>""", 1)

f = """    @if(!$stripePk)
      <div class="panel" style="margin-top:24px;max-width:560px">
        Online gift card purchase isn't available right now — call or visit us in store to buy one.
      </div>
    @else"""
assert s2.count(f) == 1
s2 = s2.replace(f, """    {{-- MARKER-GC-SETTINGS -- both channels off is a deliberate "register only"
         setting, so say so plainly instead of showing a dead form. --}}
    @if(!$stripePk || (!$gift['online_egift'] && !$gift['online_physical']))
      <div class="panel" style="margin-top:24px;max-width:560px">
        Gift cards aren't available to buy online right now — call or visit us in store and we'll set one up.
      </div>
    @else""", 1)

g = """          <div class="ful" id="gp-type">
            <label class="on" data-type="egift"><b>E-gift card</b><span class="fee">Emailed instantly, or on a date you pick</span><input type="radio" name="gp_type" value="egift" checked></label>
            <label data-type="physical"><b>Physical card</b><span class="fee">Pick up in store</span><input type="radio" name="gp_type" value="physical"></label>
          </div>"""
assert s2.count(g) == 1
s2 = s2.replace(g, """          <div class="ful" id="gp-type">
            @if($gift['online_egift'])
            <label class="on" data-type="egift"><b>E-gift card</b><span class="fee">Emailed instantly, or on a date you pick</span><input type="radio" name="gp_type" value="egift" checked></label>
            @endif
            @if($gift['online_physical'])
            <label class="{{ $gift['online_egift'] ? '' : 'on' }}" data-type="physical"><b>Physical card</b><span class="fee">Pick up in store</span><input type="radio" name="gp_type" value="physical"></label>
            @endif
          </div>""", 1)

h = """          <div class="amounts" id="gp-amounts">
            <button type="button" class="amt" data-cents="2500">$25</button>
            <button type="button" class="amt on" data-cents="5000">$50</button>
            <button type="button" class="amt" data-cents="10000">$100</button>
            <button type="button" class="amt" data-cents="15000">$150</button>
            <button type="button" class="amt" data-cents="">Custom</button>
          </div>
          <div id="gp-custom-wrap" style="display:none;margin-top:10px">
            <input type="text" id="gp-custom" inputmode="decimal" placeholder="Amount in dollars ($5–$2,000)">
          </div>"""
assert s2.count(h) == 1
s2 = s2.replace(h, """          <div class="amounts" id="gp-amounts">
            @foreach($gift['presets'] as $gpI => $gpAmt)
            <button type="button" class="amt {{ $gpI === 1 || count($gift['presets']) === 1 ? 'on' : '' }}" data-cents="{{ $gpAmt }}">${{ rtrim(rtrim(number_format($gpAmt / 100, 2), '0'), '.') }}</button>
            @endforeach
            <button type="button" class="amt {{ count($gift['presets']) ? '' : 'on' }}" data-cents="">Custom</button>
          </div>
          <div id="gp-custom-wrap" style="{{ count($gift['presets']) ? 'display:none;' : '' }}margin-top:10px">
            <input type="text" id="gp-custom" inputmode="decimal" placeholder="Amount in dollars (${{ rtrim(rtrim(number_format($gift['min_cents'] / 100, 2), '0'), '.') }}–${{ number_format($gift['max_cents'] / 100) }})">
          </div>""", 1)

i = """  var state = { type: 'egift', cents: 5000 };"""
assert s2.count(i) == 1
s2 = s2.replace(i, """  // MARKER-GC-SETTINGS -- shop config drives the defaults and the client checks.
  var CFG = @json([
    'presets'         => $gift['presets'],
    'min'             => $gift['min_cents'],
    'max'             => $gift['max_cents'],
    'egift'           => $gift['online_egift'],
    'physical'        => $gift['online_physical'],
    'default_message' => $gift['default_message'],
  ]);
  var state = {
    type:  CFG.egift ? 'egift' : 'physical',
    cents: CFG.presets.length > 1 ? CFG.presets[1] : (CFG.presets[0] || null)
  };""", 1)

j = """    if (!cents || cents < 500) { err('Pick or enter an amount of at least $5.'); return; }"""
assert s2.count(j) == 1
s2 = s2.replace(j, """    if (!cents) { err('Pick or enter an amount.'); return; }
    if (cents < CFG.min || cents > CFG.max) {
      err('Gift card amounts must be between $' + (CFG.min / 100).toFixed(2) + ' and $' + (CFG.max / 100).toFixed(2) + '.');
      return;
    }""", 1)

# default message prefill + honest physical-only start state
k = """  sync();
})();"""
assert s2.count(k) == 1
s2 = s2.replace(k, """  if (CFG.default_message) {
    document.getElementById('gp-message').value = CFG.default_message;
    document.getElementById('gp-message-count').textContent = String(CFG.default_message.length);
  }
  if (!CFG.egift) {
    document.getElementById('gp-egift-fields').style.display = 'none';
    document.getElementById('gp-physical-note').style.display = '';
    document.getElementById('gp-send-title').textContent = '3 · Your details';
  }
  sync();
})();""", 1)

io.open(p2, 'w', encoding='utf-8').write(s2)
print('ok: public buy page wired to settings')

# 5c. balance page terms line
p3 = 'resources/views/public/sections/_gift_balance.blade.php'
s3 = io.open(p3, encoding='utf-8').read()
l = """    <div class="sub">Enter the code from your card or e-gift email.</div>"""
assert s3.count(l) == 1
s3 = s3.replace(l, """    <div class="sub">Enter the code from your card or e-gift email.@if(!empty($gift['policy_line'])) {{ $gift['policy_line'] }}@endif</div>""", 1)
io.open(p3, 'w', encoding='utf-8').write(s3)
print('ok: balance page terms line')
PY

echo ""
echo "== gift card settings applied =="
echo "Post-deploy: php artisan optimize:clear"
