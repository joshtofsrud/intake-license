<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantGiftCard;
use App\Models\Tenant\TenantGiftCardTransaction;
use App\Services\Tenant\GiftCardService;
use Illuminate\Http\Request;

// MARKER-GIFTCARDS-ADMIN — staff-side gift card manager.
class GiftCardController extends Controller
{
    public function __construct(protected GiftCardService $cards)
    {
    }

    public function index(Request $request)
    {
        $tenant = tenant();
        $q      = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $type   = $request->query('type');

        $rows = TenantGiftCard::query()
            ->where('tenant_id', $tenant->id)
            ->when($q !== '', function ($w) use ($q) {
                $norm = TenantGiftCard::normalizeCode($q);
                $w->where(function ($x) use ($q, $norm) {
                    if ($norm !== '') {
                        $x->orWhere('code', 'like', '%' . $norm . '%');
                    }
                    $x->orWhere('purchaser_name', 'like', '%' . $q . '%')
                      ->orWhere('recipient_name', 'like', '%' . $q . '%')
                      ->orWhere('recipient_email', 'like', '%' . $q . '%')
                      ->orWhereHas('purchaser', fn ($c) => $c->where('first_name', 'like', '%' . $q . '%')
                          ->orWhere('last_name', 'like', '%' . $q . '%')
                          ->orWhere('email', 'like', '%' . $q . '%'));
                });
            })
            ->when(in_array($status, ['active', 'used', 'deactivated', 'pending'], true), fn ($w) => $w->where('status', $status))
            ->when(in_array($type, ['physical', 'egift'], true), fn ($w) => $w->where('type', $type))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $since = now()->subDays(30);
        $stats = [
            'outstanding_cents' => (int) TenantGiftCard::where('tenant_id', $tenant->id)->where('status', 'active')->sum('balance_cents'),
            'active_count'      => (int) TenantGiftCard::where('tenant_id', $tenant->id)->where('status', 'active')->count(),
            'sold_cents'        => (int) TenantGiftCardTransaction::where('tenant_id', $tenant->id)->where('kind', 'issue')->where('created_at', '>=', $since)->sum('amount_cents'),
            'sold_count'        => (int) TenantGiftCardTransaction::where('tenant_id', $tenant->id)->where('kind', 'issue')->where('created_at', '>=', $since)->count(),
            'redeemed_cents'    => (int) abs(TenantGiftCardTransaction::where('tenant_id', $tenant->id)->where('kind', 'redeem')->where('created_at', '>=', $since)->sum('amount_cents')),
            'redeemed_count'    => (int) TenantGiftCardTransaction::where('tenant_id', $tenant->id)->where('kind', 'redeem')->where('created_at', '>=', $since)->count(),
            'used_count'        => (int) TenantGiftCard::where('tenant_id', $tenant->id)->where('status', 'used')->count(),
        ];

        return view('tenant.gift-cards.index', [
            'tenant'    => $tenant,
            'rows'      => $rows,
            'stats'     => $stats,
            'q'         => $q,
            'status'    => $status,
            'type'      => $type,
            'canManage' => (bool) auth('tenant')->user()?->can('giftcards.manage'),
        ]);
    }

    public function show(Request $request, string $cardId)
    {
        $tenant = tenant();
        $card = TenantGiftCard::where('tenant_id', $tenant->id)->findOrFail($cardId);
        $card->load('transactions');

        return view('tenant.gift-cards.show', [
            'tenant'    => $tenant,
            'card'      => $card,
            'canManage' => (bool) auth('tenant')->user()?->can('giftcards.manage'),
        ]);
    }

    /** Manual issue — a card sold or granted outside a register sale. */
    public function store(Request $request)
    {
        abort_unless(auth('tenant')->user()?->can('giftcards.manage'), 403);
        $tenant = tenant();

        $data = $request->validate([
            'type'            => 'required|in:physical,egift',
            'amount'          => 'required|numeric|min:1|max:10000',
            'code'            => 'nullable|string|max:40|required_if:type,physical',
            'recipient_email' => 'nullable|email|max:160|required_if:type,egift',
            'recipient_name'  => 'nullable|string|max:120',
            'note'            => 'nullable|string|max:200',
        ]);

        $amount = (int) round(((float) $data['amount']) * 100);
        $code = $data['type'] === 'physical'
            ? TenantGiftCard::normalizeCode((string) $data['code'])
            : $this->cards->generateCode($tenant->id);

        if ($data['type'] === 'physical') {
            if ($code === '') {
                return back()->with('error', 'Card code is required for a physical card.');
            }
            if (TenantGiftCard::where('tenant_id', $tenant->id)->where('code', $code)->exists()) {
                return back()->with('error', "Code {$code} is already in use.");
            }
        }

        $card = \Illuminate\Support\Facades\DB::transaction(function () use ($tenant, $data, $amount, $code) {
            $card = TenantGiftCard::create([
                'tenant_id'         => $tenant->id,
                'code'              => $code,
                'type'              => $data['type'],
                'status'            => 'active',
                'original_cents'    => $amount,
                'balance_cents'     => $amount,
                'recipient_name'    => $data['recipient_name'] ?? null,
                'recipient_email'   => $data['recipient_email'] ?? null,
                'issued_by_user_id' => auth('tenant')->id(),
            ]);
            TenantGiftCardTransaction::create([
                'tenant_id'           => $tenant->id,
                'gift_card_id'        => $card->id,
                'kind'                => 'issue',
                'amount_cents'        => $amount,
                'balance_after_cents' => $amount,
                'note'                => 'Issued manually' . (filled($data['note'] ?? null) ? ' — ' . $data['note'] : ''),
                'user_id'             => auth('tenant')->id(),
            ]);
            return $card;
        });

        if ($card->type === 'egift') {
            \App\Jobs\DeliverGiftCardJob::dispatch($card->id)->afterCommit();
        }

        return redirect()->route('tenant.gift-cards.show', ['cardId' => $card->id])
            ->with('success', 'Gift card issued — ' . $card->code);
    }

    public function adjust(Request $request, string $cardId)
    {
        abort_unless(auth('tenant')->user()?->can('giftcards.manage'), 403);
        $card = TenantGiftCard::where('tenant_id', tenant()->id)->findOrFail($cardId);

        $data = $request->validate([
            'direction' => 'required|in:credit,debit',
            'amount'    => 'required|numeric|min:0.01|max:10000',
            'reason'    => 'required|string|max:200',
        ]);

        $delta = (int) round(((float) $data['amount']) * 100);
        if ($data['direction'] === 'debit') {
            $delta = -$delta;
        }

        try {
            $this->cards->adjust($card, $delta, $data['reason'], auth('tenant')->id());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $verb = $delta > 0 ? '+' : '−';
        return back()->with('success', sprintf('Balance adjusted %s$%s — %s.', $verb, number_format(abs($delta) / 100, 2), $data['reason']));
    }

    public function deactivate(Request $request, string $cardId)
    {
        abort_unless(auth('tenant')->user()?->can('giftcards.manage'), 403);
        $card = TenantGiftCard::where('tenant_id', tenant()->id)->findOrFail($cardId);

        $data = $request->validate(['reason' => 'required|string|max:200']);
        $this->cards->deactivate($card, $data['reason'], auth('tenant')->id());

        return back()->with('success', 'Card deactivated — ' . $data['reason']);
    }
}
