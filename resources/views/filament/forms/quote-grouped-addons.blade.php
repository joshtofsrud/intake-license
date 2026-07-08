{{-- MARKER-QUOTE-GROUPED — grouped add-ons + itemized quote summary.
     State binds to quote_addons; tier is entangled from the sibling field.
     All pricing resolved server-side here; grouping/totals live in Alpine. --}}
@php
    $plans = collect(config('intake.plan_prices', []))
        ->filter(fn ($cents) => (int) $cents > 0)
        ->map(fn ($cents, $key) => ['label' => ucfirst($key), 'price' => (int) round($cents / 100)])
        ->all();

    $addons = \Illuminate\Support\Facades\DB::table('addons')
        ->where('status', 'active')
        ->orderBy('sort_order')
        ->get(['code', 'name', 'price_cents', 'included_in_plans'])
        ->map(fn ($a) => [
            'code'     => $a->code,
            'name'     => $a->name,
            'price'    => (int) round($a->price_cents / 100),
            'included' => (array) json_decode($a->included_in_plans ?? '[]', true),
        ])->values()->all();

    // Rate: viewData wins (admin passes the reference const); otherwise the
    // signed-in rep's agency rate (rep panel), falling back to the const.
    $rate = $rate
        ?? (function () {
            $rep = \App\Models\SalesRep::with('agency')->where('user_id', auth()->id())->first();
            return $rep?->agency?->commission_year1 !== null
                ? (float) $rep->agency->commission_year1
                : \App\Models\SalesProspect::COMMISSION_YEAR1;
        })();
    $rateLabel = $rateLabel ?? 'yr-1 commission';

    $tierPath = str_replace('quote_addons', 'quote_tier', $getStatePath());
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
<div
    x-data="{
        state: $wire.$entangle('{{ $getStatePath() }}'),
        tier: $wire.$entangle('{{ $tierPath }}'),
        addons: @js($addons),
        plans: @js($plans),
        rate: {{ $rate }},
        sel() { return Array.isArray(this.state) ? this.state : []; },
        isIncluded(a) { return !! this.tier && a.included.includes(this.tier); },
        included() { return this.addons.filter(a => this.isIncluded(a)); },
        onQuote() { return this.addons.filter(a => ! this.isIncluded(a) && this.sel().includes(a.code)); },
        available() { return this.addons.filter(a => ! this.isIncluded(a) && ! this.sel().includes(a.code)); },
        toggle(code) {
            let s = this.sel().slice();
            let i = s.indexOf(code);
            if (i > -1) { s.splice(i, 1); } else { s.push(code); }
            this.state = s;
        },
        base() { return this.tier && this.plans[this.tier] ? this.plans[this.tier].price : null; },
        total() {
            if (this.base() === null) return null;
            return this.onQuote().reduce((s, a) => s + a.price, this.base());
        },
        money(n) { return '$' + n.toLocaleString(); },
    }"
    style="display:grid;gap:14px"
>
    <div>
        <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:rgb(52 211 153);margin-bottom:6px">
            Included in tier <span x-text="included().length" style="opacity:.7"></span>
        </div>
        <template x-if="included().length === 0">
            <div style="font-size:12px;font-style:italic;opacity:.5;padding:2px 1px" x-text="tier ? 'nothing included at this tier' : 'pick a tier first'"></div>
        </template>
        <template x-for="a in included()" :key="'i-' + a.code">
            <div style="display:flex;align-items:center;gap:10px;padding:8px 11px;border:1px solid rgb(52 211 153 / .35);background:rgb(52 211 153 / .08);border-radius:9px;margin-bottom:6px">
                <span style="color:rgb(52 211 153);font-size:13px">&check;</span>
                <span style="font-weight:600;font-size:13px" x-text="a.name"></span>
                <span style="margin-left:auto;font-size:12.5px;font-weight:700;color:rgb(52 211 153)">included</span>
            </div>
        </template>
    </div>

    <div>
        <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.55;margin-bottom:6px">
            On this quote <span x-text="onQuote().length" style="opacity:.7"></span>
        </div>
        <template x-if="onQuote().length === 0">
            <div style="font-size:12px;font-style:italic;opacity:.5;padding:2px 1px">&mdash;</div>
        </template>
        <template x-for="a in onQuote()" :key="'q-' + a.code">
            <label style="display:flex;align-items:center;gap:10px;padding:8px 11px;border:1px solid rgb(139 92 246 / .45);background:rgb(139 92 246 / .1);border-radius:9px;margin-bottom:6px;cursor:pointer">
                <input type="checkbox" checked @change="toggle(a.code)" style="accent-color:rgb(139 92 246)">
                <span style="font-weight:600;font-size:13px" x-text="a.name"></span>
                <span style="margin-left:auto;font-size:12.5px;font-weight:700;opacity:.8" x-text="'+' + money(a.price) + '/mo'"></span>
            </label>
        </template>
    </div>

    <div>
        <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.55;margin-bottom:6px">
            Available add-ons <span x-text="available().length" style="opacity:.7"></span>
        </div>
        <template x-if="available().length === 0">
            <div style="font-size:12px;font-style:italic;opacity:.5;padding:2px 1px">&mdash;</div>
        </template>
        <template x-for="a in available()" :key="'a-' + a.code">
            <label style="display:flex;align-items:center;gap:10px;padding:8px 11px;border:1px solid rgb(127 127 127 / .3);border-radius:9px;margin-bottom:6px;cursor:pointer">
                <input type="checkbox" @change="toggle(a.code)" style="accent-color:rgb(139 92 246)">
                <span style="font-weight:600;font-size:13px" x-text="a.name"></span>
                <span style="margin-left:auto;font-size:12.5px;font-weight:700;opacity:.6" x-text="'+' + money(a.price) + '/mo'"></span>
            </label>
        </template>
    </div>

    <div style="border-top:1px solid rgb(127 127 127 / .25);padding-top:12px">
        <template x-if="total() === null">
            <div style="display:flex;justify-content:space-between;font-size:13px;opacity:.6"><span>Proposed monthly</span><span>&mdash;</span></div>
        </template>
        <template x-if="total() !== null">
            <div>
                <div style="display:flex;justify-content:space-between;font-size:12.5px;opacity:.75;padding:2px 0">
                    <span x-text="plans[tier].label + ' base'"></span><span x-text="money(base())"></span>
                </div>
                <template x-for="a in included()" :key="'si-' + a.code">
                    <div style="display:flex;justify-content:space-between;font-size:12.5px;padding:2px 0;color:rgb(52 211 153)">
                        <span x-text="a.name"></span><span>included</span>
                    </div>
                </template>
                <template x-for="a in onQuote()" :key="'sq-' + a.code">
                    <div style="display:flex;justify-content:space-between;font-size:12.5px;opacity:.75;padding:2px 0">
                        <span x-text="a.name"></span><span x-text="'+' + money(a.price)"></span>
                    </div>
                </template>
                <div style="display:flex;justify-content:space-between;font-weight:800;font-size:16px;border-top:1px solid rgb(127 127 127 / .25);margin-top:8px;padding-top:9px">
                    <span>Proposed monthly</span>
                    <span style="color:rgb(196 177 255)" x-text="money(total()) + '/mo'"></span>
                </div>
                <div style="text-align:right;font-size:11.5px;opacity:.6;margin-top:3px">
                    {{ $rateLabel }} @ {{ rtrim(rtrim(number_format($rate * 100, 2), '0'), '.') }}%
                    &approx; <span style="color:rgb(52 211 153);font-weight:700" x-text="'$' + (total() * rate).toFixed(2) + '/mo'"></span>
                </div>
            </div>
        </template>
    </div>
</div>
</x-dynamic-component>
