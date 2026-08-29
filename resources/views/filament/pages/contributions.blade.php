{{-- MARKER-CONTRIBUTIONS --}}
<x-filament-panels::page>

<div class="rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden">
    <div style="padding:18px 22px;border-bottom:1px solid rgba(255,255,255,.08)">
        <div class="text-xs uppercase tracking-wide text-gray-500">Contributions</div>
        <p class="mt-1 text-sm text-gray-500">
            Money given to the project, buying nothing — no equity, no SAFE, no return. These are
            <b>not</b> investors and never appear in the round's totals or on a cap table.
        </p>
    </div>

    <div style="padding:20px 22px" class="flex flex-wrap gap-8">
        <div>
            <div class="text-2xl font-bold">${{ number_format($paidTotal / 100, 2) }}</div>
            <div class="text-xs uppercase tracking-wide text-gray-500 mt-1">Received</div>
        </div>
        <div>
            <div class="text-2xl font-bold">{{ $paidCount }}</div>
            <div class="text-xs uppercase tracking-wide text-gray-500 mt-1">Contributions</div>
        </div>
        <div>
            <div class="text-2xl font-bold text-gray-500">{{ $pendingCount }}</div>
            <div class="text-xs uppercase tracking-wide text-gray-500 mt-1">Started, not finished</div>
        </div>
    </div>

    @if ($contributions->isEmpty())
        <div style="padding:20px 22px;border-top:1px solid rgba(255,255,255,.08)"
             class="text-sm text-gray-500">Nothing yet.</div>
    @else
        <div class="overflow-x-auto" style="border-top:1px solid rgba(255,255,255,.08)">
            <table class="w-full text-sm">
                <thead class="text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="text-left p-3">Who</th>
                        <th class="text-left p-3">Amount</th>
                        <th class="text-left p-3">Status</th>
                        <th class="text-left p-3">When</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($contributions as $c)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="p-3">
                                {{ $c->name ?: '—' }}
                                <span class="block text-xs text-gray-500">{{ $c->email }}</span>
                            </td>
                            <td class="p-3">${{ number_format($c->amount_cents / 100, 2) }}</td>
                            <td class="p-3">
                                @if ($c->status === 'paid')
                                    <span class="text-success-600">Paid</span>
                                @elseif ($c->status === 'pending')
                                    <span class="text-gray-500">Started</span>
                                @else
                                    <span class="text-gray-500">{{ ucfirst($c->status) }}</span>
                                @endif
                            </td>
                            <td class="p-3 text-gray-500">
                                {{ ($c->paid_at ?: $c->created_at)?->diffForHumans() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p style="padding:14px 22px;border-top:1px solid rgba(255,255,255,.08)"
           class="text-xs text-gray-500">
            "Started" means a checkout was opened and not completed — normal, and not money owed.
            Only the verified Stripe webhook marks anything paid; nothing here is set by someone
            returning to the thank-you page.
        </p>
    @endif
</div>

</x-filament-panels::page>
