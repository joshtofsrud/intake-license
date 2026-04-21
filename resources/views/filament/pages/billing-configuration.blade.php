<x-filament-panels::page>

    @php
        $settings = \App\Models\BillingSettings::current();
        $isConfigured = $settings->isConfigured();
        $isLive = $settings->isLive();
    @endphp

    {{-- Status banner --}}
    <div style="padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;
                background: {{ $isConfigured ? ($isLive ? '#FCEBEB' : '#E1F5EE') : '#FAEEDA' }};
                border: 1px solid {{ $isConfigured ? ($isLive ? '#F7C1C1' : '#9FE1CB') : '#FAC775' }};
                color: {{ $isConfigured ? ($isLive ? '#791F1F' : '#085041') : '#633806' }};">
        <div style="font-weight: 600; font-size: 14px; margin-bottom: 4px;">
            @if($isConfigured && $isLive)
                ● Live mode active — real charges will be processed
            @elseif($isConfigured)
                ● Test mode active — no real charges
            @else
                ⚠ Not configured — Stripe keys required
            @endif
        </div>
        <div style="font-size: 12px;">
            @if($settings->last_verified_at)
                Last verified {{ $settings->last_verified_at->diffForHumans() }} —
                {{ $settings->last_verified_status === 'success' ? '✓ success' : '✗ ' . $settings->last_verified_message }}
            @else
                Never verified. Click "Save and test connection" after entering your keys.
            @endif
        </div>
    </div>

    {{-- Quick reference: what to get from Stripe --}}
    @unless($isConfigured)
    <div style="padding: 14px 18px; background: #F5F5F4; border-radius: 8px; margin-bottom: 16px; font-size: 13px; line-height: 1.6;">
        <div style="font-weight: 600; margin-bottom: 8px;">Setup checklist:</div>
        <ol style="margin: 0; padding-left: 20px;">
            <li>Sign in to <a href="https://dashboard.stripe.com" target="_blank" rel="noopener" style="color: #635BFF;">dashboard.stripe.com</a></li>
            <li>Make sure "Test mode" is toggled on (top-right)</li>
            <li>Developers → API keys → copy Publishable key + Secret key → paste below</li>
            <li>Products → add 3 products: Starter, Branded, Scale</li>
            <li>For each product, add 2 prices: monthly + annual (10× monthly)</li>
            <li>Paste all 6 price IDs below</li>
            <li>Developers → Webhooks → add endpoint: <code>https://intake.works/webhooks/stripe/subscriptions</code></li>
            <li>Copy webhook signing secret → paste below</li>
            <li>Click "Save and test connection"</li>
        </ol>
    </div>
    @endunless

    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 20px; display: flex; gap: 8px;">
            <x-filament::button type="submit">
                Save configuration
            </x-filament::button>

            <x-filament::button
                wire:click="testConnection"
                color="gray"
                icon="heroicon-o-signal">
                Save and test connection
            </x-filament::button>
        </div>
    </form>

</x-filament-panels::page>
