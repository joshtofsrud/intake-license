<?php
// MARKER-LIVE-IDENTIFY

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\GuardsRetailAccess;
use App\Models\Tenant\TenantInventoryItem;
use App\Support\Barcode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /inventory/identify?field=&value=&except= — asked by the Add and Edit
 * item forms the moment someone tabs out of SKU, barcode, EAN or part number.
 *
 *   - any barcode-looking value, in any of those boxes, is checked against
 *     the whole barcode pool (UPC, EAN, barcode-SKU, merged-away aliases);
 *   - a SKU is checked exactly (case-insensitive);
 *   - a part number is checked ignoring case and punctuation, and reported
 *     as "possibly" — without a barcode it is weaker evidence.
 */
class ItemIdentifyController extends Controller
{
    use GuardsRetailAccess;

    public function check(Request $request): JsonResponse
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);

        $field  = (string) $request->query('field', '');
        $value  = trim((string) $request->query('value', ''));
        $except = $request->query('except') ?: null;
        if ($value === '' || ! in_array($field, ['sku', 'catalog_upc', 'catalog_ean', 'catalog_mpn'], true)) {
            return response()->json(['match' => null]);
        }

        $q = fn () => TenantInventoryItem::where('tenant_id', $tenant->id)
            ->when($except, fn ($w) => $w->where('id', '!=', $except));

        $item = null;
        $how = null;
        $strength = 'same';

        if ($keys = Barcode::keys($value)) {
            $hits = Barcode::itemsFor($tenant->id, $keys, $except);
            if ($hits) {
                $item = reset($hits);
                $how = 'barcode ' . key($hits);
            }
        }

        if (! $item && $field === 'sku') {
            $item = $q()->whereRaw('LOWER(sku) = ?', [mb_strtolower($value)])->first();
            $how = $item ? 'SKU ' . $item->sku : null;
        }

        if (! $item && $field === 'catalog_mpn') {
            $norm = preg_replace('/[^A-Z0-9]/', '', strtoupper($value));
            if (strlen($norm) >= 2) {
                $item = $q()->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(REPLACE(catalog_mpn, '-', ''), ' ', ''), '.', ''), '/', '')) = ?", [$norm])->first();
                if ($item) {
                    $how = 'part number ' . $item->catalog_mpn;
                    $strength = 'possible';
                }
            }
        }

        if (! $item) {
            return response()->json(['match' => null]);
        }

        return response()->json(['match' => [
            'id'         => $item->id,
            'name'       => $item->name,
            'price'      => $item->effectiveSellPriceCents(),
            'stock'      => (int) ($item->computed_stock_count ?? 0),
            'how'        => $how,
            'strength'   => $strength,
            'url'        => route('tenant.inventory.show', $item->id),
            'adjust_url' => route('tenant.inventory.show', $item->id) . '?adjust=1',
        ]]);
    }
}
