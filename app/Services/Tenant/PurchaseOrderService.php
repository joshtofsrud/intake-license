<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemVendor;
use App\Models\Tenant\TenantPurchaseOrder as PO;
use App\Models\Tenant\TenantPurchaseOrderCounter;
use App\Models\Tenant\TenantPurchaseOrderEvent;
use App\Models\Tenant\TenantPurchaseOrderLine as Line;
use App\Models\Tenant\TenantSpecialOrder as SO;
use App\Models\Tenant\TenantVendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-PO: purchase orders.
 *
 * Every special order that is ordered rides on a PO line. A PO line can carry
 * special orders and stock together: qty_ordered ≥ the units of its open
 * special orders, and the rest is stock. "Still owed" (ordered − received −
 * cancelled) is what counts as on order.
 *
 * Exits are all defined here:
 *  - draft line removed / draft deleted → its special orders stay Needed, off the PO
 *  - vendor can't supply / close short / cancel → owed qty cancelled; special
 *    orders still waiting go back to Needed with a note and staff are alerted
 *  - special order cancelled → off a draft line (qty reduced); on a placed PO
 *    the units keep coming as stock
 */
class PurchaseOrderService
{
    // ────────────────────────────────────────────────────────
    //  Settings and numbering
    // ────────────────────────────────────────────────────────

    public static function settings(Tenant $tenant): array
    {
        $s = (array) (($tenant->settings ?? [])['purchasing'] ?? []);

        return [
            'po_prefix'          => (string) ($s['po_prefix'] ?? 'PO-'),
            'freight_method'     => in_array($s['freight_method'] ?? null, ['value', 'units', 'off'], true) ? $s['freight_method'] : 'value',
            'invoice_tolerance'  => (int) ($s['invoice_tolerance_cents'] ?? 100),
            'notify_after_receive' => (bool) ($s['notify_after_receive'] ?? true),
        ];
    }

    public function nextNumber(Tenant $tenant): string
    {
        $prefix = self::settings($tenant)['po_prefix'];

        return DB::transaction(function () use ($tenant, $prefix) {
            $c = TenantPurchaseOrderCounter::where('tenant_id', $tenant->id)->lockForUpdate()->first()
                ?? TenantPurchaseOrderCounter::create(['tenant_id' => $tenant->id, 'next_number' => 1]);
            do {
                $n = $c->next_number;
                $c->next_number = $n + 1;
                $number = $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            } while (PO::withTrashed()->where('tenant_id', $tenant->id)->where('po_number', $number)->exists());
            $c->save();

            return $number;
        });
    }

    /** "PO-0061" once numbered; what the next one will be. */
    public static function peekNextNumber(Tenant $tenant): string
    {
        $n = (int) (TenantPurchaseOrderCounter::where('tenant_id', $tenant->id)->value('next_number') ?? 1);

        return self::settings($tenant)['po_prefix'] . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    public function event(PO $po, string $body, ?string $userId = null): void
    {
        TenantPurchaseOrderEvent::create([
            'tenant_id' => $po->tenant_id, 'purchase_order_id' => $po->id,
            'user_id' => $userId ?? Auth::guard('tenant')->id(), 'body' => $body, 'created_at' => now(),
        ]);
    }

    private function soNote(SO $so, string $body): void
    {
        app(SpecialOrderService::class)->addNote($so->id, null, $body, true);
    }

    // ────────────────────────────────────────────────────────
    //  Drafts
    // ────────────────────────────────────────────────────────

    public function createDraft(Tenant $tenant, string $vendorId, string $locationId): PO
    {
        $vendor = TenantVendor::where('tenant_id', $tenant->id)->where('is_active', true)->find($vendorId);
        if (! $vendor) {
            throw new PurchaseOrderException('Pick one of your active vendors.');
        }
        if (! \App\Models\Tenant\TenantLocation::where('tenant_id', $tenant->id)->where('is_active', true)->whereKey($locationId)->exists()) {
            throw new PurchaseOrderException('Pick one of your active locations to ship to.');
        }
        $po = PO::create([
            'tenant_id' => $tenant->id, 'po_number' => $this->nextNumber($tenant),
            'vendor_id' => $vendorId, 'location_id' => $locationId, 'status' => PO::DRAFT,
            'created_by_user_id' => Auth::guard('tenant')->id(),
        ]);
        $this->event($po, 'Draft started.');

        return $po;
    }

    /** The newest draft for this vendor + location, or a new one. */
    public function draftFor(Tenant $tenant, string $vendorId, string $locationId): PO
    {
        return PO::where('tenant_id', $tenant->id)->where('vendor_id', $vendorId)->where('location_id', $locationId)
            ->where('status', PO::DRAFT)->orderByDesc('created_at')->first()
            ?? $this->createDraft($tenant, $vendorId, $locationId);
    }

    /** This vendor's part number and cost for an item. */
    public static function vendorTerms(string $vendorId, ?string $itemId): array
    {
        if (! $itemId) {
            return ['sku' => null, 'cost' => null];
        }
        $pivot = TenantInventoryItemVendor::where('inventory_item_id', $itemId)->where('vendor_id', $vendorId)->first();
        $vendor = TenantVendor::withTrashed()->find($vendorId);
        $list = $pivot ? ($pivot->live_cost_cents ?? $pivot->unit_cost_cents) : null;
        $cost = $list !== null && $vendor ? $vendor->netCostCents((int) $list) : $list;
        if ($cost === null) {
            $cost = TenantInventoryItem::find($itemId)?->effectiveCostCents();
        }

        return ['sku' => $pivot?->vendor_sku, 'cost' => $cost];
    }

    /**
     * What else is happening with this item before it's added: a line on this
     * PO already, or still owed on another open PO.
     */
    public function checkItem(PO $po, string $itemId): array
    {
        $here = $po->lines()->where('inventory_item_id', $itemId)->first();
        $elsewhere = Line::where('tenant_id', $po->tenant_id)->where('inventory_item_id', $itemId)
            ->where('purchase_order_id', '!=', $po->id)
            ->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', array_merge(PO::OPEN, [PO::DRAFT])))
            ->with('purchaseOrder')->get()
            ->filter(fn ($l) => $l->purchaseOrder->isDraft() ? $l->qty_ordered > 0 : $l->owed() > 0)
            ->map(fn ($l) => [
                'po_id' => $l->purchase_order_id, 'po_number' => $l->purchaseOrder->po_number,
                'status' => $l->purchaseOrder->statusLabel(),
                'qty' => $l->purchaseOrder->isDraft() ? $l->qty_ordered : $l->owed(),
                'expected' => ($l->expected_date ?? $l->purchaseOrder->expected_date)?->format('M j'),
            ])->values()->all();

        return ['here' => $here, 'elsewhere' => $elsewhere];
    }

    public function addLine(PO $po, ?string $itemId, ?string $name, int $qty, ?int $costCents, bool $afterPlaced = false): Line
    {
        if (! $po->isDraft() && ! $afterPlaced) {
            throw new PurchaseOrderException("{$po->po_number} has been placed. Use \"Add a line\" so the change is tracked.");
        }
        if (in_array($po->status, [PO::RECEIVED, PO::CLOSED_SHORT, PO::CANCELLED], true)) {
            throw new PurchaseOrderException("{$po->po_number} is {$po->statusLabel()}; start a new PO.");
        }
        if ($qty < 1) {
            throw new PurchaseOrderException('Quantity must be at least 1.');
        }
        $item = $itemId ? TenantInventoryItem::where('tenant_id', $po->tenant_id)->find($itemId) : null;
        if ($itemId && ! $item) {
            throw new PurchaseOrderException('That item isn\'t in your catalog.');
        }
        if (! $item && trim((string) $name) === '') {
            throw new PurchaseOrderException('Name the item.');
        }
        $terms = self::vendorTerms($po->vendor_id, $item?->id);

        return DB::transaction(function () use ($po, $item, $name, $qty, $costCents, $terms, $afterPlaced) {
            $line = Line::create([
                'tenant_id' => $po->tenant_id, 'purchase_order_id' => $po->id,
                'inventory_item_id' => $item?->id, 'name_snapshot' => $item?->name ?? trim((string) $name),
                'vendor_sku' => $terms['sku'], 'qty_ordered' => $qty,
                'unit_cost_cents' => $costCents ?? $terms['cost'],
                'expected_date' => $afterPlaced ? ($po->expected_date ?? null) : null,
                'added_after_placed' => $afterPlaced,
                'sort' => (int) $po->lines()->max('sort') + 1,
            ]);
            if ($afterPlaced) {
                $this->markChanged($po, "{$qty} × {$line->name_snapshot} added.");
                $this->event($po, "{$qty} × {$line->name_snapshot} added after placing.");
            }

            return $line;
        });
    }

    public function updateLine(Line $line, ?int $qty, ?int $costCents, bool $costGiven): Line
    {
        $po = $line->parentPo();
        $line->load('specialOrders');
        if ($qty !== null) {
            if (! $po->isDraft()) {
                throw new PurchaseOrderException('Quantities can\'t change after placing. Add a line, or use "Can\'t supply…" to cancel what\'s owed.');
            }
            $min = max(1, $line->specialOrderUnits());
            if ($qty < $min) {
                throw new PurchaseOrderException($line->specialOrderUnits() > 0
                    ? "{$line->specialOrderUnits()} on this line are special orders. Remove the line to take them off."
                    : 'Quantity must be at least 1.');
            }
            $line->qty_ordered = $qty;
        }
        if ($costGiven) {
            $line->unit_cost_cents = $costCents;
        }
        $line->save();

        return $line;
    }

    /** Draft only. Its special orders stay Needed, off the PO. */
    public function removeLine(Line $line): int
    {
        $po = $line->parentPo();
        if (! $po->isDraft()) {
            throw new PurchaseOrderException("{$po->po_number} has been placed. Use \"Can't supply…\" to cancel what's owed.");
        }

        return DB::transaction(function () use ($line, $po) {
            $n = 0;
            foreach ($line->specialOrders()->whereIn('status', [SO::STATUS_NEEDED])->get() as $so) {
                $so->forceFill(['purchase_order_line_id' => null])->save();
                $this->soNote($so, "Taken off draft {$po->po_number}. Still needed.");
                $n++;
            }
            $line->delete();

            return $n;
        });
    }

    public function deleteDraft(PO $po): int
    {
        if (! $po->isDraft()) {
            throw new PurchaseOrderException("{$po->po_number} has been placed; close it short or cancel it instead.");
        }

        return DB::transaction(function () use ($po) {
            $n = 0;
            foreach ($po->lines()->get() as $line) {
                $n += $this->removeLine($line->setRelation('purchaseOrder', $po));
            }
            $po->delete();

            return $n;
        });
    }

    /**
     * Special orders board → PO. Each order goes on its vendor's draft for its
     * pickup location (a PO ships to one place), on the line for its item.
     * Returns [po_id => ['po' => PO, 'count' => n]].
     */
    public function addSpecialOrders(Tenant $tenant, array $ids): array
    {
        $orders = SO::where('tenant_id', $tenant->id)->whereIn('id', $ids)->orderBy('created_at')->get();
        $out = [];
        $skipped = [];

        DB::transaction(function () use ($tenant, $orders, &$out, &$skipped) {
            foreach ($orders as $so) {
                if ($so->status !== SO::STATUS_NEEDED) { $skipped[] = "{$so->so_number} is {$so->statusLabel()}"; continue; }
                if ($so->purchase_order_line_id) { $skipped[] = "{$so->so_number} is already on a PO"; continue; }
                if (! $so->vendor_id) { $skipped[] = "{$so->so_number} has no vendor"; continue; }
                $loc = $so->location_id ?: SpecialOrderService::defaultLocationId($tenant->id);
                if (! $loc) { $skipped[] = "{$so->so_number} has no pickup location"; continue; }

                $po = $this->draftFor($tenant, $so->vendor_id, $loc);
                $line = $so->inventory_item_id
                    ? $po->lines()->where('inventory_item_id', $so->inventory_item_id)->first()
                    : $po->lines()->whereNull('inventory_item_id')->where('name_snapshot', $so->item_name_snapshot)->first();
                if ($line) {
                    $line->increment('qty_ordered', (int) $so->quantity);
                } else {
                    $terms = self::vendorTerms($po->vendor_id, $so->inventory_item_id);
                    $line = Line::create([
                        'tenant_id' => $tenant->id, 'purchase_order_id' => $po->id,
                        'inventory_item_id' => $so->inventory_item_id, 'name_snapshot' => $so->item_name_snapshot,
                        'vendor_sku' => $terms['sku'], 'qty_ordered' => (int) $so->quantity,
                        'unit_cost_cents' => $terms['cost'] ?? $so->unit_cost_cents_estimated,
                        'sort' => (int) $po->lines()->max('sort') + 1,
                    ]);
                }
                $so->forceFill(['purchase_order_line_id' => $line->id])->save();
                $this->soNote($so, "Added to draft {$po->po_number}. Still needed until it's placed.");
                $out[$po->id] ??= ['po' => $po, 'count' => 0];
                $out[$po->id]['count']++;
            }
        });

        return ['pos' => $out, 'skipped' => $skipped];
    }

    // ────────────────────────────────────────────────────────
    //  Placing
    // ────────────────────────────────────────────────────────

    /** Today + the vendor's lead time (learned once there are 3+ POs, if chosen). */
    public static function defaultExpected(TenantVendor $vendor, ?PO $po = null): ?string
    {
        $days = null;
        if ($vendor->lead_time_mode === 'learned' && (int) $vendor->learned_po_count >= 3 && $vendor->learned_lead_days !== null) {
            $days = (int) round((float) $vendor->learned_lead_days);
        } elseif ($vendor->lead_time_days !== null) {
            $days = (int) $vendor->lead_time_days;
        } elseif ($po) {
            $days = TenantInventoryItemVendor::where('vendor_id', $vendor->id)
                ->whereIn('inventory_item_id', $po->lines()->pluck('inventory_item_id')->filter())
                ->max('lead_time_days');
            $days = $days !== null ? (int) $days : null;
        }

        return $days !== null ? now()->addDays($days)->toDateString() : null;
    }

    public static function leadTimeNote(TenantVendor $vendor): string
    {
        if ($vendor->lead_time_mode === 'learned' && (int) $vendor->learned_po_count >= 3 && $vendor->learned_lead_days !== null) {
            return "Today plus {$vendor->name}'s learned lead time (" . rtrim(rtrim(number_format((float) $vendor->learned_lead_days, 1), '0'), '.') . ' days).';
        }
        if ($vendor->lead_time_days !== null) {
            return "Today plus {$vendor->name}'s lead time ({$vendor->lead_time_days} days).";
        }

        return "No lead time set for {$vendor->name}. Set one on the vendor to fill this in.";
    }

    public function place(PO $po, string $via, ?string $email, ?string $vendorRef, string $expected): array
    {
        if (! $po->isDraft()) {
            throw new PurchaseOrderException("{$po->po_number} is already {$po->statusLabel()}.");
        }
        $po->load('lines.specialOrders', 'vendor');
        if ($po->lines->isEmpty()) { // (loaded just above)
            throw new PurchaseOrderException('Add at least one line first.');
        }
        if (! array_key_exists($via, PO::VIA)) {
            throw new PurchaseOrderException('Say how it was sent.');
        }
        if ($via === 'email' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PurchaseOrderException('Enter the vendor\'s email address.');
        }
        if (! $po->vendor || ! $po->vendor->is_active) {
            throw new PurchaseOrderException('This vendor is no longer active.');
        }

        $ordered = 0;
        DB::transaction(function () use ($po, $via, $email, $vendorRef, $expected, &$ordered) {
            $po->forceFill([
                'status' => PO::PLACED, 'placed_at' => now(), 'placed_by_user_id' => Auth::guard('tenant')->id(),
                'placed_via' => $via, 'sent_to_email' => $via === 'email' ? $email : null,
                'vendor_reference' => $vendorRef ?: null, 'expected_date' => $expected,
            ])->save();
            foreach ($po->lines as $line) {
                $line->forceFill(['expected_date' => $line->expected_date ?? $expected])->save();
                foreach ($line->specialOrders->where('status', SO::STATUS_NEEDED) as $so) {
                    $so->forceFill([
                        'status' => SO::STATUS_ORDERED, 'vendor_id' => $po->vendor_id, 'po_number' => $po->po_number,
                        'vendor_reference' => $vendorRef ?: $so->vendor_reference,
                        'expected_arrival_date' => $line->expected_date, 'ordered_at' => now(),
                        'unit_cost_cents_estimated' => $line->unit_cost_cents ?? $so->unit_cost_cents_estimated,
                    ])->save();
                    $this->soNote($so, "Ordered on {$po->po_number} · expected " . $line->expected_date->format('M j') . '.');
                    $ordered++;
                }
                if ($line->inventory_item_id) {
                    TenantInventoryItemVendor::where('inventory_item_id', $line->inventory_item_id)->where('vendor_id', $po->vendor_id)
                        ->update(['last_ordered_at' => now()]);
                }
            }
            $this->event($po, 'Placed. ' . PO::VIA[$via] . ($via === 'email' ? " to {$email}" : '') . '. Expected ' . \Carbon\Carbon::parse($expected)->format('M j') . '.'
                . ($vendorRef ? " Their reference {$vendorRef}." : ''));
        });

        $sent = null;
        if ($via === 'email') {
            $sent = $this->emailPo($po->fresh(), $email, false);
        }

        return ['ordered' => $ordered, 'emailed' => $sent];
    }

    public function pdfBytes(PO $po, bool $revised = false): string
    {
        $po->loadMissing('lines', 'vendor', 'location');
        $tenant = Tenant::findOrFail($po->tenant_id);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('tenant.purchase-orders.pdf', [
            'po' => $po, 'tenant' => $tenant, 'revised' => $revised,
        ])->setPaper('letter')->output();
    }

    public function emailPo(PO $po, string $email, bool $revised): bool
    {
        $tenant = Tenant::findOrFail($po->tenant_id);
        $po->loadMissing('vendor', 'lines');
        $subject = ($revised ? 'Revised ' : '') . "Purchase order {$po->po_number} from {$tenant->name}";
        $mail = \App\Services\EmailService::forTenant($tenant);
        $html = $mail->renderHtml(view('tenant.purchase-orders.email', ['po' => $po, 'tenant' => $tenant, 'revised' => $revised])->render());
        $ok = $mail->sendRenderedWithPdf('purchase_order', $email, $subject, $html, $this->pdfBytes($po, $revised), "{$po->po_number}.pdf");
        $this->event($po, $ok ? ('Emailed' . ($revised ? ' (revised)' : '') . " to {$email}.") : "Email to {$email} failed. Nothing was sent; try again or send it another way.");

        return $ok;
    }

    // ────────────────────────────────────────────────────────
    //  After placing
    // ────────────────────────────────────────────────────────

    public function markChanged(PO $po, string $what): void
    {
        $po->forceFill([
            'changed_since_placed' => true,
            'changes_note' => trim(($po->changed_since_placed ? (string) $po->changes_note . ' ' : '') . $what),
        ])->save();
    }

    public function markVendorTold(PO $po): void
    {
        $po->forceFill(['changed_since_placed' => false, 'changes_note' => null])->save();
        $this->event($po, 'Marked the vendor told about the changes.');
    }

    /** Change a line's expected date. Returns the special orders waiting on it. */
    public function setLineDate(Line $line, string $date): Collection
    {
        $po = $line->parentPo();
        if (! $po->isOpen()) {
            throw new PurchaseOrderException("{$po->po_number} is {$po->statusLabel()}.");
        }
        $old = ($line->expected_date ?? $po->expected_date)?->format('M j');
        $line->forceFill(['expected_date' => $date])->save();
        $new = \Carbon\Carbon::parse($date)->format('M j');
        $this->event($po, "Expected date on {$line->name_snapshot} moved" . ($old ? " {$old} →" : ' to') . " {$new}.");
        $waiting = $line->specialOrders()->where('status', SO::STATUS_ORDERED)->get();
        foreach ($waiting as $so) {
            $so->forceFill(['expected_arrival_date' => $date])->save();
            $this->soNote($so, "Expected date moved to {$new} on {$po->po_number}.");
        }

        return $waiting;
    }

    /**
     * The vendor can't supply what's still owed on a line. Special orders
     * still waiting on it go back to Needed, off the PO, so they can be
     * ordered elsewhere; staff are alerted.
     */
    public function cancelRemaining(Line $line, ?string $reason, bool $markChanged = true): int
    {
        $po = $line->parentPo();
        $owed = $line->owed();
        if ($owed < 1) {
            return 0;
        }

        return DB::transaction(function () use ($line, $po, $owed, $reason, $markChanged) {
            $line->forceFill(['qty_cancelled' => (int) $line->qty_cancelled + $owed, 'cancel_reason' => $reason ?: $line->cancel_reason])->save();
            $back = $this->sendWaitingBack($line, "{$po->vendor?->name} couldn't supply it on {$po->po_number}" . ($reason ? " ({$reason})" : '') . '.');
            if ($markChanged) {
                $this->markChanged($po, "{$owed} × {$line->name_snapshot} cancelled.");
            }
            $this->event($po, "{$owed} × {$line->name_snapshot} cancelled" . ($reason ? ": {$reason}" : '') . '.'
                . ($back->isNotEmpty() ? ' ' . $back->pluck('so_number')->implode(', ') . ' back to Needed.' : ''));
            $this->recompute($po->fresh());

            return $owed;
        });
    }

    /** Special orders still Ordered on a line go back to Needed, off the PO. */
    public function sendWaitingBack(Line $line, string $why): Collection
    {
        $back = $line->specialOrders()->where('status', SO::STATUS_ORDERED)->get();
        foreach ($back as $so) {
            $so->forceFill([
                'status' => SO::STATUS_NEEDED, 'purchase_order_line_id' => null, 'po_number' => null,
                'ordered_at' => null, 'expected_arrival_date' => null,
            ])->save();
            $this->soNote($so, "Back to Needed: {$why} Order it again.");
        }
        if ($back->isNotEmpty()) {
            $tenant = Tenant::find($line->tenant_id);
            if ($tenant) {
                app(StaffAlertService::class)->emit($tenant, 'special_order.back_to_needed', [
                    'title' => $back->count() === 1
                        ? "{$back->first()->so_number} needs ordering again"
                        : "{$back->count()} special orders need ordering again",
                    'body'  => $why,
                    'link'  => route('tenant.special-orders.index'),
                ]);
            }
        }

        return $back;
    }

    public function closeShort(PO $po, ?string $reason): int
    {
        if (! $po->isOpen()) {
            throw new PurchaseOrderException("{$po->po_number} is {$po->statusLabel()}.");
        }

        return DB::transaction(function () use ($po, $reason) {
            $n = 0;
            foreach ($po->lines()->get() as $line) {
                $n += $this->cancelRemaining($line->setRelation('purchaseOrder', $po), $reason ?: 'closed short', false);
            }
            $po->forceFill(['status' => PO::CLOSED_SHORT, 'closed_at' => now(), 'closed_reason' => $reason, 'changed_since_placed' => false, 'changes_note' => null])->save();
            $this->event($po, 'Closed short' . ($reason ? ": {$reason}" : '') . '. What was still owed is cancelled.');

            return $n;
        });
    }

    /** The vendor cancelled the whole order: only before anything is received. */
    public function cancelPo(PO $po, ?string $reason): void
    {
        if (! $po->isOpen()) {
            throw new PurchaseOrderException("{$po->po_number} is {$po->statusLabel()}.");
        }
        if ($po->lines()->sum('qty_received') > 0) {
            throw new PurchaseOrderException('Some of it has been received. Use Close short to stop waiting on the rest.');
        }
        DB::transaction(function () use ($po, $reason) {
            foreach ($po->lines()->get() as $line) {
                $this->cancelRemaining($line->setRelation('purchaseOrder', $po), $reason ?: 'PO cancelled', false);
            }
            $po->forceFill(['status' => PO::CANCELLED, 'closed_at' => now(), 'closed_reason' => $reason, 'changed_since_placed' => false, 'changes_note' => null])->save();
            $this->event($po, 'Cancelled' . ($reason ? ": {$reason}" : '') . '.');
        });
    }

    /** Placed / partly received / received from what the lines say. */
    public function recompute(PO $po): void
    {
        if (! $po->isOpen() && $po->status !== PO::RECEIVED) {
            return;
        }
        $po->load('lines');
        $owed = $po->owedUnits();
        $got = (int) $po->lines->sum('qty_received');
        $status = $owed === 0 ? ($got > 0 ? PO::RECEIVED : PO::CANCELLED) : ($got > 0 ? PO::PARTIAL : PO::PLACED);
        if ($status === $po->status) {
            return;
        }
        $was = $po->status;
        $po->forceFill(['status' => $status, 'received_at' => $status === PO::RECEIVED ? now() : null,
            'closed_at' => $status === PO::CANCELLED ? now() : $po->closed_at])->save();
        if ($status === PO::RECEIVED && $was !== PO::RECEIVED) {
            $this->event($po, 'Received in full.');
            $this->learnLeadTime($po);
        } elseif ($status === PO::CANCELLED) {
            $this->event($po, 'Nothing is owed and nothing was received, so it\'s cancelled.');
        }
    }

    /** Placed → received in full, averaged per vendor. */
    public function learnLeadTime(PO $po): void
    {
        if (! $po->placed_at) {
            return;
        }
        $vendor = TenantVendor::withTrashed()->find($po->vendor_id);
        if (! $vendor) {
            return;
        }
        $days = max(0, (int) $po->placed_at->copy()->startOfDay()->diffInDays(now()->startOfDay()));
        $n = (int) $vendor->learned_po_count;
        $avg = $vendor->learned_lead_days !== null ? (float) $vendor->learned_lead_days : 0.0;
        $vendor->forceFill([
            'learned_lead_days' => round((($avg * $n) + $days) / ($n + 1), 1),
            'learned_po_count'  => $n + 1,
        ])->save();
        $this->event($po, "Placed {$po->placed_at->format('M j')}, received in full " . now()->format('M j') . ": {$days} day" . ($days === 1 ? '' : 's')
            . ". {$vendor->name}'s average is now " . rtrim(rtrim(number_format((float) $vendor->learned_lead_days, 1), '0'), '.') . " days over " . ($n + 1) . ' PO' . ($n ? 's' : '') . '.');
    }

    // ────────────────────────────────────────────────────────
    //  Receiving
    // ────────────────────────────────────────────────────────

    /**
     * Units arrived against a line. Special orders on it are filled oldest
     * first, customers before stock; each filled one is marked arrived (and
     * held) at the location. Returns the ids of orders filled for someone.
     */
    public function receive(Line $line, int $accepted, ?int $unitCostCents, string $locationId, ?\App\Models\Tenant\TenantUser $user, ?string $invoiceNumber, ?string $invoiceDate): array
    {
        $filled = [];
        if ($accepted < 1) {
            return $filled;
        }
        $line->forceFill(['qty_received' => (int) $line->qty_received + $accepted])->save();

        $svc = app(SpecialOrderService::class);
        $waiting = $line->specialOrders()->where('status', SO::STATUS_ORDERED)->get()
            ->sortBy(fn ($so) => [($so->customer_id || $so->appointment_id) ? 0 : 1, $so->created_at?->timestamp ?? 0])->values();
        $left = $accepted;
        foreach ($waiting as $so) {
            if ($left < 1) {
                break;
            }
            $take = min($left, (int) $so->quantity);
            $svc->markArrived($so->id, $take, $unitCostCents ?? $line->unit_cost_cents, $invoiceNumber, $invoiceDate, false, $user, $locationId);
            if ($so->customer_id || $so->appointment_id) {
                $filled[] = $so->id;
            }
            $left -= $take;
        }

        return $filled;
    }

    /** Open lines a new shipment would fill, for one or more POs. */
    public static function openLines(Collection $pos): Collection
    {
        return $pos->flatMap(fn ($po) => $po->loadMissing('lines')->lines->filter(fn ($l) => $l->owed() > 0));
    }

    /** Open POs for a vendor + location (for receiving). */
    public static function openFor(string $tenantId, ?string $vendorId, string $locationId): Collection
    {
        if (! $vendorId) {
            return collect();
        }

        return PO::where('tenant_id', $tenantId)->where('vendor_id', $vendorId)->where('location_id', $locationId)
            ->whereIn('status', PO::OPEN)->with('lines')->orderBy('placed_at')->get()
            ->filter(fn ($po) => $po->owedUnits() > 0)->values();
    }

    // ────────────────────────────────────────────────────────
    //  Special order side
    // ────────────────────────────────────────────────────────

    /** A special order on a line was cancelled. */
    public function onSpecialOrderCancelled(SO $so): void
    {
        $line = $so->purchase_order_line_id ? Line::find($so->purchase_order_line_id) : null;
        if (! $line) {
            return;
        }
        $po = $line->parentPo();
        $so->forceFill(['purchase_order_line_id' => null])->save();
        if ($po && $po->isDraft()) {
            $left = (int) $line->qty_ordered - (int) $so->quantity;
            if ($left <= 0 && $line->specialOrders()->count() === 0) {
                $line->delete();
                $this->event($po, "{$line->name_snapshot} removed: {$so->so_number} was cancelled.");
            } else {
                $line->forceFill(['qty_ordered' => max(1, $left)])->save();
                $this->event($po, "{$line->name_snapshot} reduced by {$so->quantity}: {$so->so_number} was cancelled.");
            }
        } elseif ($po && $po->isOpen()) {
            $this->event($po, "{$so->so_number} was cancelled; its {$so->quantity} × {$line->name_snapshot} still come in as stock.");
        }
    }
}
