#!/bin/bash
# distributor-priority-order — position, not a number.
#
#   The priority picker was a select of 1,2,3,4,5,10,20,50 labelled "lower
#   wins". That asks a shop owner to decode a number into a rank, and the
#   gaps (5 then 10 then 20) imply a significance that doesn't exist.
#
#   With two or three distributors nobody is choosing a value, they're
#   putting sources in order. So the screen now says what the order MEANS —
#   "1st choice for product info" — and moves boxes with arrows. The integer
#   stays as storage and never appears.
#
#   Reordering swaps data_priority with the neighbour rather than
#   renumbering the list, so a distributor whose box is untouched keeps its
#   stored value and two shops can't end up with different numbers meaning
#   the same order.
#
#   The arrows are their own tiny form. Keeping them out of the credential
#   form matters: submitting that form with an empty credential is defined
#   as "keep what's saved", so a reorder must not travel through it and risk
#   being read as a credential change.
# NO MIGRATION. Server: optimize:clear
set -e
if grep -q "MARKER-PRIORITY-ORDER" app/Http/Controllers/Tenant/DistributorController.php; then
  echo "distributor-priority-order already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ route
python3 - <<'DPO_0_EOF'
import io
p = 'routes/web.php'
s = io.open(p, encoding='utf-8').read()

old = """                Route::post('/connection/key',    [TenantControllers\\DistributorController::class, 'saveKey'])->name('connection.key');"""
assert s.count(old) == 1, s.count(old)
new = old + """
                // MARKER-PRIORITY-ORDER — its own route so a reorder never
                // travels through the credential form, where a blank field
                // means "keep the saved key".
                Route::post('/connection/priority', [TenantControllers\\DistributorController::class, 'movePriority'])->name('connection.priority');"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('route ok')
DPO_0_EOF

# ------------------------------------------------------------------ controller
python3 - <<'DPO_1_EOF'
import io
p = 'app/Http/Controllers/Tenant/DistributorController.php'
s = io.open(p, encoding='utf-8').read()

# drop priority from the credential form's validation + write
old = """            'account_number'   => ['nullable', 'string', 'max:64'],
            'data_priority'    => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);"""
assert s.count(old) == 1, s.count(old)
new = """            'account_number'   => ['nullable', 'string', 'max:64'],
        ]);"""
s = s.replace(old, new)

old = """        $sub->account_number = $data['account_number'] ?? $sub->account_number;
        if (isset($data['data_priority'])) {
            $sub->data_priority = (int) $data['data_priority'];
        }
        $sub->save();"""
assert s.count(old) == 1, s.count(old)
new = """        $sub->account_number = $data['account_number'] ?? $sub->account_number;
        $sub->save();"""
s = s.replace(old, new)

# add the reorder action
old = """    public function testConnection(): RedirectResponse"""
assert s.count(old) == 1, s.count(old)
new = """    /**
     * MARKER-PRIORITY-ORDER \u2014 move a distributor up or down the data order.
     *
     * Swaps data_priority with the adjacent distributor rather than
     * renumbering everything. Renumbering would rewrite rows the shop never
     * touched, and it would let two shops hold different numbers that mean
     * the same order \u2014 harder to reason about later, for no benefit.
     *
     * If the two happen to hold the same stored value (both still on the
     * default), the mover is nudged one below its neighbour so the order is
     * still definite.
     */
    public function movePriority(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $request->validate([
            'distributor_code' => ['required', 'string', 'max:32'],
            'direction'        => ['required', 'in:up,down'],
        ]);

        $code = strtoupper($data['distributor_code']);

        $subs = TenantDistributorCatalogSubscription::where('tenant_id', tenant()->id)
            ->orderBy('data_priority')->orderBy('distributor_code')->get();

        $i = $subs->search(fn ($s) => $s->distributor_code === $code);
        if ($i === false) {
            return back();
        }

        $j = $data['direction'] === 'up' ? $i - 1 : $i + 1;
        if ($j < 0 || $j >= $subs->count()) {
            return back();          // already at the end
        }

        $me    = $subs[$i];
        $other = $subs[$j];

        $mine   = (int) $me->data_priority;
        $theirs = (int) $other->data_priority;

        if ($mine === $theirs) {
            $me->data_priority = $data['direction'] === 'up'
                ? max(1, $theirs - 1)
                : min(99, $theirs + 1);
        } else {
            $me->data_priority    = $theirs;
            $other->data_priority = $mine;
            $other->save();
        }
        $me->save();

        return back();
    }

    public function testConnection(): RedirectResponse"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('controller ok')
DPO_1_EOF

# ------------------------------------------------------------------ view
python3 - <<'DPO_2_EOF'
import io
p = 'resources/views/tenant/distributors/connection.blade.php'
s = io.open(p, encoding='utf-8').read()

# --- header: say what the position means ---------------------------------
old = """        <div style="font-size:12px;color:var(--ia-text-dim)">
          @if ($b['hasKey'])
            <span style="color:var(--ia-ok,#8FD14F)">connected</span> ·
          @endif
          {{ number_format($b['linked']) }} linked item{{ $b['linked'] === 1 ? '' : 's' }}
          @if ($i === 0 && $b['hasKey'])
            · <b>data source for shared items</b>
          @endif
        </div>"""
assert s.count(old) == 1, s.count(old)
new = """        <div style="font-size:12px;color:var(--ia-text-dim)">
          @if ($b['hasKey'])
            <span style="color:var(--ia-ok,#8FD14F)">connected</span> ·
          @endif
          {{ number_format($b['linked']) }} linked item{{ $b['linked'] === 1 ? '' : 's' }}
        </div>"""
s = s.replace(old, new)

# --- replace the number select with ordinal + arrows ---------------------
old = """          <div>
            <label class="dc-label">Priority</label>
            <select class="dc-input" name="data_priority">
              @foreach ([1,2,3,4,5,10,20,50] as $p)
                <option value="{{ $p }}" @selected($b['priority'] === $p)>{{ $p }}</option>
              @endforeach
            </select>
            <div style="font-size:11px;color:var(--ia-text-dim);margin-top:4px">Lower wins.</div>
          </div>"""
assert s.count(old) == 1, s.count(old)
s = s.replace(old, "")

old = """      <form method="POST" action="{{ route('tenant.distributors.connection.key') }}" style="margin-top:12px">"""
assert s.count(old) == 1, s.count(old)
new = """      {{-- MARKER-PRIORITY-ORDER — position, stated in words. The stored
           integer never appears; arrows swap with the neighbour. --}}
      <div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding:8px 10px;
                  background:var(--ia-surface-2);border-radius:var(--ia-r-md)">
        <span style="font-size:12.5px;font-weight:600">
          {{ $i === 0 ? '1st' : ($i === 1 ? '2nd' : ($i === 2 ? '3rd' : ($i + 1) . 'th') ) }} choice for product info
        </span>
        <span style="font-size:11px;color:var(--ia-text-dim)">
          @if ($i === 0)
            Its name, description and specs are used when more than one distributor carries an item.
          @else
            Used only where higher-placed distributors don't carry the item.
          @endif
        </span>
        <span style="flex:1"></span>
        <form method="POST" action="{{ route('tenant.distributors.connection.priority') }}" style="display:flex;gap:4px;margin:0">
          @csrf
          <input type="hidden" name="distributor_code" value="{{ $b['code'] }}">
          <button name="direction" value="up" class="ia-btn ia-btn--ghost" style="padding:3px 9px;font-size:12px"
                  @disabled($i === 0)>&uarr;</button>
          <button name="direction" value="down" class="ia-btn ia-btn--ghost" style="padding:3px 9px;font-size:12px"
                  @disabled($i === count($boxes) - 1)>&darr;</button>
        </form>
      </div>

      <form method="POST" action="{{ route('tenant.distributors.connection.key') }}" style="margin-top:12px">"""
s = s.replace(old, new)

# --- the intro note now describes order, not numbers ---------------------
old = """    <b>Priority</b> decides which distributor's product information wins when two of them carry the
    same item — the name, description and specs on your items. Lower number wins. It doesn't change
    who you buy from."""
assert s.count(old) == 1, s.count(old)
new = """    When two distributors carry the same item, the one placed higher supplies its product
    information — the name, description and specs on your items. Use the arrows to reorder.
    This doesn't change who you buy from."""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('view ok')
DPO_2_EOF

php -l app/Http/Controllers/Tenant/DistributorController.php
php -l routes/web.php

echo
echo "distributor-priority-order applied."
