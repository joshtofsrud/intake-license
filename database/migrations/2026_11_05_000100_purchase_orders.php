<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * MARKER-PO: purchase orders, and receiving against them.
 *
 *  - tenant_purchase_orders / _lines / _events / _counters (new)
 *  - a special order rides on a PO line (purchase_order_line_id)
 *  - a shipment line knows the PO line it fills, what wasn't accepted, what
 *    happens to the rest, and its share of freight; the shipment carries the
 *    vendor's invoice and how freight is spread
 *  - vendors get a lead time you set and one learned from your own POs
 *  - roles that can open Special orders can open Purchase orders, since
 *    ordering moves there (nobody loses access on the day)
 *  - live data: every ordered special order is put on a Placed PO, grouped by
 *    vendor + the PO number typed on it + pickup location. Nothing is lost.
 *
 * Additive throughout.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_purchase_orders')) {
            Schema::create('tenant_purchase_orders', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id')->index();
                $t->string('po_number', 32);
                $t->uuid('vendor_id');
                $t->uuid('location_id');
                // draft | placed | partial | received | closed_short | cancelled
                $t->string('status', 16)->default('draft');
                $t->timestamp('placed_at')->nullable();
                $t->uuid('placed_by_user_id')->nullable();
                $t->string('placed_via', 16)->nullable(); // email | website | phone | other
                $t->string('sent_to_email', 128)->nullable();
                $t->string('vendor_reference', 64)->nullable();
                $t->date('expected_date')->nullable();
                $t->text('notes_vendor')->nullable();
                $t->text('notes_internal')->nullable();
                $t->boolean('changed_since_placed')->default(false);
                $t->text('changes_note')->nullable();
                $t->timestamp('received_at')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->string('closed_reason', 255)->nullable();
                $t->uuid('created_by_user_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['tenant_id', 'po_number'], 'tpo_tenant_number_unique');
                $t->index(['tenant_id', 'status'], 'tpo_tenant_status_idx');
                $t->index(['tenant_id', 'vendor_id', 'location_id'], 'tpo_vendor_loc_idx');
            });
        }

        if (! Schema::hasTable('tenant_purchase_order_lines')) {
            Schema::create('tenant_purchase_order_lines', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id')->index();
                $t->uuid('purchase_order_id')->index();
                $t->uuid('inventory_item_id')->nullable()->index(); // null until cataloged
                $t->string('name_snapshot', 255);
                $t->string('vendor_sku', 64)->nullable();
                $t->unsignedInteger('qty_ordered')->default(0);
                $t->unsignedInteger('qty_received')->default(0);
                $t->unsignedInteger('qty_cancelled')->default(0);
                $t->integer('unit_cost_cents')->nullable();
                $t->date('expected_date')->nullable();
                $t->boolean('added_after_placed')->default(false);
                $t->string('cancel_reason', 255)->nullable();
                $t->unsignedInteger('sort')->default(0);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('tenant_purchase_order_events')) {
            Schema::create('tenant_purchase_order_events', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id');
                $t->uuid('purchase_order_id')->index();
                $t->uuid('user_id')->nullable();
                $t->text('body');
                $t->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('tenant_purchase_order_counters')) {
            Schema::create('tenant_purchase_order_counters', function (Blueprint $t) {
                $t->uuid('tenant_id')->primary();
                $t->unsignedInteger('next_number')->default(1);
                $t->timestamps();
            });
        }

        if (! Schema::hasColumn('tenant_special_orders', 'purchase_order_line_id')) {
            Schema::table('tenant_special_orders', function (Blueprint $t) {
                $t->uuid('purchase_order_line_id')->nullable()->after('po_number');
                $t->index('purchase_order_line_id', 'tso_po_line_idx');
            });
        }

        Schema::table('tenant_inventory_receive_shipments', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_inventory_receive_shipments', 'vendor_invoice_number')) {
                $t->string('vendor_invoice_number', 64)->nullable()->after('shipping_cost_cents');
                $t->date('vendor_invoice_date')->nullable()->after('vendor_invoice_number');
                $t->integer('invoice_total_cents')->nullable()->after('vendor_invoice_date');
                $t->integer('invoice_mismatch_cents')->nullable()->after('invoice_total_cents');
                $t->string('freight_method', 8)->nullable()->after('invoice_mismatch_cents'); // value | units | off
                $t->json('filled_special_order_ids')->nullable()->after('freight_method');
            }
        });

        Schema::table('tenant_inventory_receive_shipment_items', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_inventory_receive_shipment_items', 'purchase_order_line_id')) {
                $t->uuid('purchase_order_line_id')->nullable()->after('distributor_catalog_id');
                $t->index('purchase_order_line_id', 'trsi_po_line_idx');
                $t->unsignedInteger('rejected_quantity')->default(0)->after('received_quantity');
                $t->string('rejected_reason', 64)->nullable()->after('rejected_quantity');
                $t->boolean('rejected_replace')->default(true)->after('rejected_reason');
                $t->string('rest_action', 8)->nullable()->after('rejected_replace'); // owed | cancel
                $t->date('rest_expected_date')->nullable()->after('rest_action');
                $t->integer('freight_cents')->nullable()->after('total_cost_cents');
            }
        });

        Schema::table('tenant_vendors', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_vendors', 'lead_time_days')) {
                $t->unsignedSmallInteger('lead_time_days')->nullable()->after('free_freight_cents');
                $t->string('lead_time_mode', 8)->default('set')->after('lead_time_days'); // set | learned
                $t->decimal('learned_lead_days', 5, 1)->nullable()->after('lead_time_mode');
                $t->unsignedInteger('learned_po_count')->default(0)->after('learned_lead_days');
            }
        });

        $this->grantRoles();
        $this->placeLiveOrders();
    }

    /** Roles that list their areas and include special_orders get purchase_orders. */
    private function grantRoles(): void
    {
        if (! Schema::hasTable('tenant_roles')) {
            return;
        }
        DB::table('tenant_roles')->whereNotNull('sections')->orderBy('id')->chunk(500, function ($roles) {
            foreach ($roles as $r) {
                $s = json_decode($r->sections, true);
                if (! is_array($s) || ! in_array('special_orders', $s, true) || in_array('purchase_orders', $s, true)) {
                    continue;
                }
                $s[] = 'purchase_orders';
                DB::table('tenant_roles')->where('id', $r->id)->update(['sections' => json_encode(array_values($s))]);
            }
        });
    }

    /**
     * Every ordered special order goes on a Placed PO: one PO per vendor +
     * typed PO number + pickup location, one line per item (or per name when
     * not cataloged). Orders with no vendor can't be on a PO; they go back to
     * Needed with a note so they're ordered properly.
     */
    private function placeLiveOrders(): void
    {
        $now = now();
        $orders = DB::table('tenant_special_orders')
            ->where('status', 'ordered')
            ->whereNull('purchase_order_line_id')
            ->whereNull('deleted_at')
            ->orderBy('tenant_id')->orderBy('ordered_at')
            ->get();

        foreach ($orders->groupBy('tenant_id') as $tenantId => $rows) {
            $defaultLoc = DB::table('tenant_locations')->where('tenant_id', $tenantId)->whereNull('deleted_at')
                ->orderByDesc('is_default')->orderBy('sort_order')->value('id');
            $used = DB::table('tenant_purchase_orders')->where('tenant_id', $tenantId)->pluck('po_number')->flip()->all();
            $next = (int) (DB::table('tenant_purchase_order_counters')->where('tenant_id', $tenantId)->value('next_number') ?? 1);

            foreach ($rows as $so) {
                if (! $so->vendor_id) {
                    DB::table('tenant_special_orders')->where('id', $so->id)->update([
                        'status' => 'needed', 'ordered_at' => null, 'updated_at' => $now,
                    ]);
                    $this->note($tenantId, $so->id, 'Back to Needed: it was marked ordered with no vendor, so it couldn\'t go on a purchase order. Add it to a PO to order it.');
                }
            }
            $rows = $rows->filter(fn ($so) => $so->vendor_id);

            $groups = $rows->groupBy(fn ($so) => $so->vendor_id . '|' . trim((string) $so->po_number) . '|' . ($so->location_id ?: $defaultLoc));
            foreach ($groups as $key => $sos) {
                [$vendorId, $typed, $locId] = explode('|', $key);
                if (! $locId) {
                    continue; // a shop with no location can't receive; leave as is
                }
                $number = ($typed !== '' && ! isset($used[$typed]) && mb_strlen($typed) <= 32) ? $typed : null;
                if (! $number) {
                    do {
                        $number = 'PO-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
                        $next++;
                    } while (isset($used[$number]));
                }
                $used[$number] = true;

                $poId = (string) Str::uuid();
                $first = $sos->first();
                DB::table('tenant_purchase_orders')->insert([
                    'id' => $poId, 'tenant_id' => $tenantId, 'po_number' => $number,
                    'vendor_id' => $vendorId, 'location_id' => $locId, 'status' => 'placed',
                    'placed_at' => $sos->min('ordered_at') ?: $now, 'placed_via' => 'other',
                    'vendor_reference' => $sos->pluck('vendor_reference')->filter()->first(),
                    'expected_date' => $sos->pluck('expected_arrival_date')->filter()->min(),
                    'notes_internal' => 'Created from special orders already marked ordered when purchase orders arrived in Intake.',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->event($tenantId, $poId, 'Created from ' . $sos->count() . ' special order' . ($sos->count() === 1 ? '' : 's') . ' already marked ordered' . ($typed !== '' ? " (PO # typed on them: {$typed})" : '') . '.');

                $sort = 0;
                foreach ($sos->groupBy(fn ($so) => $so->inventory_item_id ?: ('name:' . mb_strtolower($so->item_name_snapshot))) as $lineSos) {
                    $s0 = $lineSos->first();
                    $lineId = (string) Str::uuid();
                    $sku = $s0->inventory_item_id
                        ? DB::table('tenant_inventory_item_vendors')->where('inventory_item_id', $s0->inventory_item_id)->where('vendor_id', $vendorId)->value('vendor_sku')
                        : null;
                    DB::table('tenant_purchase_order_lines')->insert([
                        'id' => $lineId, 'tenant_id' => $tenantId, 'purchase_order_id' => $poId,
                        'inventory_item_id' => $s0->inventory_item_id, 'name_snapshot' => $s0->item_name_snapshot,
                        'vendor_sku' => $sku, 'qty_ordered' => (int) $lineSos->sum('quantity'),
                        'unit_cost_cents' => $s0->unit_cost_cents_estimated,
                        'expected_date' => $lineSos->pluck('expected_arrival_date')->filter()->min(),
                        'sort' => $sort++, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    DB::table('tenant_special_orders')->whereIn('id', $lineSos->pluck('id'))->update([
                        'purchase_order_line_id' => $lineId, 'po_number' => $number, 'updated_at' => $now,
                    ]);
                    foreach ($lineSos as $so) {
                        $this->note($tenantId, $so->id, "On purchase order {$number}.");
                    }
                }
            }

            DB::table('tenant_purchase_order_counters')->updateOrInsert(
                ['tenant_id' => $tenantId],
                ['next_number' => $next, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    private function note(string $tenantId, string $soId, string $body): void
    {
        if (! Schema::hasTable('tenant_special_order_notes')) {
            return;
        }
        $cols = Schema::getColumnListing('tenant_special_order_notes');
        $row = ['id' => (string) Str::uuid(), 'special_order_id' => $soId, 'body' => $body, 'is_system' => true, 'created_at' => now()];
        if (in_array('tenant_id', $cols, true)) { $row['tenant_id'] = $tenantId; }
        if (in_array('updated_at', $cols, true)) { $row['updated_at'] = now(); }
        DB::table('tenant_special_order_notes')->insert($row);
    }

    private function event(string $tenantId, string $poId, string $body): void
    {
        DB::table('tenant_purchase_order_events')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'purchase_order_id' => $poId,
            'body' => $body, 'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('tenant_special_orders')->whereNotNull('purchase_order_line_id')->update(['purchase_order_line_id' => null]);
        foreach (['tenant_purchase_order_counters', 'tenant_purchase_order_events', 'tenant_purchase_order_lines', 'tenant_purchase_orders'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
        Schema::table('tenant_special_orders', function (Blueprint $t) {
            if (Schema::hasColumn('tenant_special_orders', 'purchase_order_line_id')) {
                $t->dropIndex('tso_po_line_idx');
                $t->dropColumn('purchase_order_line_id');
            }
        });
        Schema::table('tenant_inventory_receive_shipments', function (Blueprint $t) {
            foreach (['vendor_invoice_number', 'vendor_invoice_date', 'invoice_total_cents', 'invoice_mismatch_cents', 'freight_method', 'filled_special_order_ids'] as $c) {
                if (Schema::hasColumn('tenant_inventory_receive_shipments', $c)) { $t->dropColumn($c); }
            }
        });
        Schema::table('tenant_inventory_receive_shipment_items', function (Blueprint $t) {
            if (Schema::hasColumn('tenant_inventory_receive_shipment_items', 'purchase_order_line_id')) {
                $t->dropIndex('trsi_po_line_idx');
            }
            foreach (['purchase_order_line_id', 'rejected_quantity', 'rejected_reason', 'rejected_replace', 'rest_action', 'rest_expected_date', 'freight_cents'] as $c) {
                if (Schema::hasColumn('tenant_inventory_receive_shipment_items', $c)) { $t->dropColumn($c); }
            }
        });
        Schema::table('tenant_vendors', function (Blueprint $t) {
            foreach (['lead_time_days', 'lead_time_mode', 'learned_lead_days', 'learned_po_count'] as $c) {
                if (Schema::hasColumn('tenant_vendors', $c)) { $t->dropColumn($c); }
            }
        });
    }
};
