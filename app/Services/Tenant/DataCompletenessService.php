<?php
// MARKER-DATA-COMPLETENESS

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantInventoryItem;
use App\Support\ImportFieldRegistry;
use Illuminate\Support\Facades\DB;

/**
 * How complete is the data, field by field.
 *
 * Counts only — never loads the records — so this stays cheap on a big
 * catalogue. "Missing" means NULL or empty string; a zero is a real value
 * and is NOT treated as missing (a $0 item is a decision, not a gap).
 */
class DataCompletenessService
{
    public function __construct(private Tenant $tenant) {}

    /** What a missing value actually costs, for the fields where it matters. */
    private const CONSEQUENCE = [
        'email'                 => "Can't be emailed at all — no receipts, no campaigns",
        'phone'                 => "Can't be texted",
        'first_name'            => 'Shows as a blank name on receipts and lists',
        'sku'                   => "Can't be scanned or matched to a distributor",
        'name'                  => 'Shows as a blank line on receipts',
        'shop_sell_price_cents' => "Can't be rung up without typing a price",
        'shop_cost_cents'       => 'Margin and reorder reporting will be wrong',
        'category'              => "Won't appear under any category online",
        'upc'                   => "Can't be scanned at the register",
    ];

    /** Fields that are structural rather than descriptive — flagged first. */
    private const CRITICAL = ['email', 'sku', 'name', 'shop_sell_price_cents'];

    public function customers(): array
    {
        return $this->report('customers', TenantCustomer::class, [
            'first_name', 'last_name', 'email', 'phone',
            'address_line1', 'city', 'state', 'postcode',
        ]);
    }

    public function inventory(): array
    {
        return $this->report('inventory', TenantInventoryItem::class, [
            'sku', 'name', 'description', 'shop_cost_cents', 'shop_sell_price_cents',
            'shop_bin_location', 'upc', 'color', 'size',
        ]);
    }

    /**
     * One aggregate query for all fields rather than one per field: on a
     * 40k-item catalogue that is the difference between instant and slow.
     */
    private function report(string $type, string $model, array $fields): array
    {
        $registry = ImportFieldRegistry::for($type);
        $table    = (new $model)->getTable();

        $total = DB::table($table)->where('tenant_id', $this->tenant->id)->count();

        if ($total === 0) {
            return ['total' => 0, 'fields' => []];
        }

        $selects = [];
        foreach ($fields as $f) {
            // Guard against a field in the registry that isn't a real column.
            if (! \Illuminate\Support\Facades\Schema::hasColumn($table, $f)) continue;
            $selects[] = "SUM(CASE WHEN `{$f}` IS NULL OR `{$f}` = '' THEN 1 ELSE 0 END) AS `miss_{$f}`";
        }

        if (! $selects) {
            return ['total' => $total, 'fields' => []];
        }

        $row = DB::table($table)
            ->where('tenant_id', $this->tenant->id)
            ->selectRaw(implode(', ', $selects))
            ->first();

        $out = [];
        foreach ($fields as $f) {
            $key = 'miss_' . $f;
            if (! isset($row->$key)) continue;

            $missing = (int) $row->$key;

            $out[] = [
                'field'       => $f,
                'label'       => $registry[$f]['label'] ?? ucfirst(str_replace('_', ' ', $f)),
                'missing'     => $missing,
                'present'     => $total - $missing,
                'percent'     => $total > 0 ? round($missing / $total * 100, 1) : 0.0,
                'critical'    => in_array($f, self::CRITICAL, true),
                'consequence' => self::CONSEQUENCE[$f] ?? null,
            ];
        }

        // Worst first, but always critical fields above cosmetic ones.
        usort($out, function ($a, $b) {
            if ($a['critical'] !== $b['critical']) return $a['critical'] ? -1 : 1;
            return $b['missing'] <=> $a['missing'];
        });

        return ['total' => $total, 'fields' => $out];
    }

    /** Marketing-consent split — the most common post-import surprise. */
    public function consent(): array
    {
        $base = TenantCustomer::where('tenant_id', $this->tenant->id)
            ->whereNotNull('email')->where('email', '!=', '');

        return [
            'mailable'     => (clone $base)->whereNotNull('email_marketing_consent_at')
                                ->whereNull('email_marketing_opt_out_at')->count(),
            'unconfirmed'  => (clone $base)->whereNull('email_marketing_consent_at')
                                ->whereNull('email_marketing_opt_out_at')->count(),
            'unsubscribed' => (clone $base)->whereNotNull('email_marketing_opt_out_at')->count(),
        ];
    }

    /**
     * Stream the records missing one field as CSV rows. Chunked, so a large
     * export never holds the whole table in memory.
     */
    public function exportMissing(string $type, string $field, callable $emit): void
    {
        $model = $type === 'inventory' ? TenantInventoryItem::class : TenantCustomer::class;
        $table = (new $model)->getTable();

        if (! \Illuminate\Support\Facades\Schema::hasColumn($table, $field)) {
            return;
        }

        $cols = $type === 'inventory'
            ? ['id', 'sku', 'name', 'shop_sell_price_cents']
            : ['id', 'first_name', 'last_name', 'email', 'phone'];

        $model::where('tenant_id', $this->tenant->id)
            ->where(fn ($q) => $q->whereNull($field)->orWhere($field, ''))
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($emit, $cols) {
                foreach ($rows as $r) {
                    $emit(array_map(fn ($c) => (string) ($r->$c ?? ''), $cols));
                }
            });
    }

    public static function exportHeader(string $type): array
    {
        return $type === 'inventory'
            ? ['id', 'sku', 'name', 'sell_price_cents']
            : ['id', 'first_name', 'last_name', 'email', 'phone'];
    }
}
