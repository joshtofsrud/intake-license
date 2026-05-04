<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantCustomer;
use App\Services\Tenant\SaleService;
use App\Services\Tenant\SaleValidationException;
use App\Services\Tenant\InventoryStockException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class RegisterController extends Controller
{
    public function __construct(protected SaleService $sales) {}

    public function index(Request $request)
    {
        $tenant = tenant();

        return view('tenant.register.index', [
            'tenant'     => $tenant,
            'taxRate'    => (float) ($tenant->default_tax_rate ?? 0),
            'taxLabel'   => $this->taxLabel($tenant),
            'tipsConfig' => [
                'enabled'      => (bool) $tenant->tips_enabled,
                'method'       => $tenant->tip_default_method,
                'options'      => is_array($tenant->tip_default_options)
                                    ? $tenant->tip_default_options
                                    : (json_decode($tenant->tip_default_options ?? '[]', true) ?: []),
                'allow_custom' => (bool) $tenant->tip_allow_custom,
                'attributable' => (bool) $tenant->tip_attributable,
            ],
            'surchargeConfig' => [
                'enabled' => (bool) $tenant->passthrough_card_fees,
                'percent' => (float) ($tenant->card_surcharge_percent ?? 0),
                'label'   => $tenant->card_surcharge_label ?? 'Card processing fee',
            ],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $tenant = tenant();
        $q = trim((string) $request->input('q', ''));
        $type = $request->input('type', 'all');

        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['products' => [], 'services' => [], 'customers' => []]);
        }

        $products = [];
        $services = [];
        $customers = [];

        if ($type === 'all' || $type === 'product') {
            $products = TenantInventoryItem::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                      ->orWhere('sku', 'like', "%{$q}%");
                })
                ->limit(15)
                ->get()
                ->map(fn ($p) => [
                    'id'              => $p->id,
                    'name'            => $p->name ?? '',
                    'sku'             => $p->sku ?? '',
                    'price_cents'     => (int) ($p->effectiveSellPriceCents() ?? 0),
                    'is_taxable'      => (($p->tax_class_code ?? null) !== 'exempt'),
                    'allow_oversell'  => (bool) $p->allow_oversell,
                ])
                ->toArray();
        }

        if ($type === 'all' || $type === 'service') {
            $services = TenantServiceItem::where('tenant_id', $tenant->id)
                ->where('is_active', 1)
                ->where('name', 'like', "%{$q}%")
                ->limit(15)
                ->get()
                ->map(fn ($s) => [
                    'id'               => $s->id,
                    'name'             => $s->name,
                    'price_cents'      => (int) ($s->price_cents ?? 0),
                    'duration_minutes' => (int) ($s->duration_minutes ?? 0),
                ])
                ->toArray();
        }

        if ($type === 'all' || $type === 'customer') {
            $customers = TenantCustomer::where('tenant_id', $tenant->id)
                ->where(function ($w) use ($q) {
                    $w->where('first_name', 'like', "%{$q}%")
                      ->orWhere('last_name', 'like', "%{$q}%")
                      ->orWhere('email', 'like', "%{$q}%")
                      ->orWhere('phone', 'like', "%{$q}%");
                })
                ->limit(10)
                ->get()
                ->map(fn ($c) => [
                    'id'    => $c->id,
                    'name'  => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                    'email' => $c->email ?? '',
                    'phone' => $c->phone ?? '',
                ])
                ->toArray();
        }

        return response()->json(compact('products', 'services', 'customers'));
    }

    public function storeSale(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');

        if (!$locationId) {
            return response()->json(['ok' => false, 'error' => 'No location selected.'], 409);
        }

        $validated = $request->validate([
            'customer_id'      => 'nullable|uuid',
            'notes'            => 'nullable|string',
            'tip_cents'        => 'nullable|integer|min:0',
            'discount_cents'   => 'nullable|integer|min:0',
            'payment_method'   => 'required|string|in:cash,card,check,store_credit,mark_paid,split',
            'payment_reference'=> 'nullable|string',
            'items'            => 'required|array|min:1',
            'items.*.type'             => 'required|string|in:service,product,open_item,gift_card',
            'items.*.service_id'       => 'nullable|uuid',
            'items.*.inventory_item_id'=> 'nullable|uuid',
            'items.*.name_snapshot'    => 'nullable|string|max:255',
            'items.*.unit_price_cents' => 'nullable|integer|min:0',
            'items.*.quantity'         => 'nullable|numeric|min:0.001',
            'items.*.discount_cents'   => 'nullable|integer|min:0',
            'items.*.is_taxable'       => 'nullable|boolean',
            'items.*.assigned_staff_id'=> 'nullable|uuid',
            'items.*.notes'            => 'nullable|string',
        ]);

        try {
            $sale = $this->sales->createSale([
                'tenant_id'          => $tenant->id,
                'rang_up_by_user_id' => auth('tenant')->id(),
                'location_id'        => $locationId,
                'customer_id'        => $validated['customer_id'] ?? null,
                'status'             => 'completed',
                'payment_status'     => 'paid',
                'payment_method'     => $validated['payment_method'],
                'payment_reference'  => $validated['payment_reference'] ?? null,
                'paid_at'            => Carbon::now(),
                'notes'              => $validated['notes'] ?? null,
                'tip_cents'          => (int) ($validated['tip_cents'] ?? 0),
                'discount_cents'     => (int) ($validated['discount_cents'] ?? 0),
                'items'              => $validated['items'],
            ]);

            if ($validated['payment_method'] === 'card' && $tenant->passthrough_card_fees) {
                $surcharge = (int) round($sale->subtotal_cents * (($tenant->card_surcharge_percent ?? 0) / 100));
                if ($surcharge > 0) {
                    $sale->update(['surcharge_cents' => $surcharge]);
                    $sale = $this->sales->recalculate($sale->fresh('items'));
                }
            }

            return response()->json([
                'ok'          => true,
                'sale_id'     => $sale->id,
                'sale_number' => $sale->sale_number,
                'total_cents' => $sale->total_cents,
                'redirect'    => route('tenant.register.index'),
            ]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (InventoryStockException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function refundIndex(Request $request)
    {
        $tenant = tenant();
        return view('tenant.register.refund', ['tenant' => $tenant]);
    }

    public function searchRefundables(Request $request): JsonResponse
    {
        $tenant = tenant();
        $q = trim((string) $request->input('q', ''));

        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['sales' => []]);
        }

        $sales = TenantSale::where('tenant_id', $tenant->id)
            ->whereIn('payment_status', ['paid', 'partial'])
            ->whereNull('refund_of_sale_id')
            ->where(function ($w) use ($q) {
                $w->where('sale_number', 'like', "%{$q}%")
                  ->orWhereHas('customer', function ($c) use ($q) {
                      $c->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                  });
            })
            ->orderByDesc('paid_at')
            ->limit(20)
            ->with(['customer', 'items'])
            ->get()
            ->map(function ($s) {
                return [
                    'id'          => $s->id,
                    'sale_number' => $s->sale_number,
                    'sale_date'   => $s->sale_date?->toDateString(),
                    'paid_at'     => $s->paid_at?->toDateTimeString(),
                    'total_cents' => $s->total_cents,
                    'tender'      => $s->payment_method,
                    'customer'    => $s->customer
                        ? trim(($s->customer->first_name ?? '') . ' ' . ($s->customer->last_name ?? ''))
                        : null,
                    'items'       => $s->items->map(fn ($i) => [
                        'id'               => $i->id,
                        'name'             => $i->name_snapshot,
                        'quantity'         => $i->quantity,
                        'line_total_cents' => $i->line_total_cents,
                        'type'             => $i->type,
                    ])->toArray(),
                ];
            });

        return response()->json(['sales' => $sales]);
    }

    public function storeRefund(Request $request): JsonResponse
    {
        $tenant = tenant();

        $validated = $request->validate([
            'original_sale_id' => 'required|uuid',
            'refund_method'    => 'required|string|in:cash,card,check,store_credit,mark_paid',
            'reason'           => 'nullable|string|max:500',
            'notes'            => 'nullable|string',
            'item_ids'         => 'required|array|min:1',
            'item_ids.*'       => 'uuid',
        ]);

        try {
            $refund = $this->sales->createRefund([
                'tenant_id'          => $tenant->id,
                'original_sale_id'   => $validated['original_sale_id'],
                'rang_up_by_user_id' => auth('tenant')->id(),
                'refund_method'      => $validated['refund_method'],
                'reason'             => $validated['reason'] ?? null,
                'notes'              => $validated['notes'] ?? null,
                'item_ids'           => $validated['item_ids'],
            ]);

            return response()->json([
                'ok'          => true,
                'refund_id'   => $refund->id,
                'sale_number' => $refund->sale_number,
                'total_cents' => $refund->total_cents,
            ]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (InventoryStockException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    protected function taxLabel($tenant): string
    {
        $rate = $tenant->default_tax_rate;
        if ($rate === null || (float) $rate === 0.0) {
            return 'No tax configured';
        }
        return 'Tax · ' . rtrim(rtrim(number_format((float) $rate, 3), '0'), '.') . '%';
    }
}
