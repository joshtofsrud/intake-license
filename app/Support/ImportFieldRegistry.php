<?php

namespace App\Support;

/**
 * MARKER-IMPORT1 — what a CSV is allowed to write.
 *
 * An ALLOW-LIST, not "every column on the table". Deliberately absent for
 * customers: password / remember_token / password_reset_* (credentials),
 * stripe_customer_id (owned by Stripe), email_verified_at, and
 * sms_opt_out_at / sms_consent_source — SMS consent has to be evidenced, not
 * assigned by a spreadsheet.
 *
 * type: text | email | phone | bool | choice | int
 */
class ImportFieldRegistry
{
    public static function for(string $importType): array
    {
        return match ($importType) {
            'customers' => self::customers(),
            'inventory' => self::inventory(),   // MARKER-IMPORT2
            default     => [],
        };
    }

    /**
     * MARKER-IMPORT2 — inventory fields.
     *
     * Absent on purpose: computed_stock_count and committed_count (maintained
     * by InventoryService under locks — stock arrives as a MOVEMENT, see the
     * 'stock' pseudo-field), every catalog_* column (owned by distributor sync
     * and clobbered on its next run), price_ack_at/by (an audit trail), and
     * distributor_catalog_id / default_vendor_id (set by linking, not typing).
     *
     * 'category' and 'vendor' are pseudo-fields: they resolve a NAME to a
     * record, creating it if needed, rather than writing a column directly.
     */
    public static function inventory(): array
    {
        return [
            'sku'          => ['label' => 'SKU',            'type' => 'text', 'max' => 100, 'match' => true],
            'name'         => ['label' => 'Item name',      'type' => 'text', 'max' => 255],
            'display_subtitle' => ['label' => 'Subtitle',   'type' => 'text', 'max' => 255],
            'description'  => ['label' => 'Description',    'type' => 'text', 'max' => 5000],
            'category'     => ['label' => 'Category (by name)', 'type' => 'resolve'],
            'vendor'       => ['label' => 'Vendor (by name)',   'type' => 'resolve'],
            'shop_cost_cents'        => ['label' => 'Shop cost',       'type' => 'money'],
            'shop_sell_price_cents'  => ['label' => 'Sell price',      'type' => 'money'],
            'shop_case_quantity'     => ['label' => 'Case quantity',   'type' => 'int'],
            'shop_reorder_threshold' => ['label' => 'Reorder at',      'type' => 'int'],
            'shop_reorder_quantity'  => ['label' => 'Reorder quantity','type' => 'int'],
            'shop_bin_location'      => ['label' => 'Bin location',    'type' => 'text', 'max' => 64],
            'stock'        => ['label' => 'Stock on hand',  'type' => 'int', 'stock' => true],
            'color'        => ['label' => 'Colour',         'type' => 'text', 'max' => 64],
            'size'         => ['label' => 'Size',           'type' => 'text', 'max' => 64],
            'upc'          => ['label' => 'UPC',            'type' => 'text', 'max' => 64],
            'tax_class_code' => ['label' => 'Tax class',    'type' => 'text', 'max' => 32],
            'is_active'    => ['label' => 'Active',         'type' => 'bool'],
            'is_stock_tracked' => ['label' => 'Track stock','type' => 'bool'],
            'show_online'  => ['label' => 'Show online',    'type' => 'bool'],
            'allow_oversell' => ['label' => 'Allow oversell','type' => 'bool'],
        ];
    }

    public static function customers(): array
    {
        return [
            'first_name'  => ['label' => 'First name',    'type' => 'text',  'max' => 80],
            'last_name'   => ['label' => 'Last name',     'type' => 'text',  'max' => 80],
            'email'       => ['label' => 'Email',         'type' => 'email', 'max' => 180, 'match' => true],
            'phone'       => ['label' => 'Phone',         'type' => 'phone', 'max' => 40],
            'address_line1' => ['label' => 'Address 1',   'type' => 'text',  'max' => 255],
            'address_line2' => ['label' => 'Address 2',   'type' => 'text',  'max' => 255],
            'city'        => ['label' => 'City',          'type' => 'text',  'max' => 128],
            'state'       => ['label' => 'State',         'type' => 'text',  'max' => 64],
            'postcode'    => ['label' => 'Postal code',   'type' => 'text',  'max' => 32],
            'country'     => ['label' => 'Country',       'type' => 'text',  'max' => 64],
            'notes'       => ['label' => 'Notes',         'type' => 'text',  'max' => 5000],
            'is_vip'      => ['label' => 'VIP',           'type' => 'bool'],
            'customer_type' => ['label' => 'Customer type', 'type' => 'choice',
                                'choices' => ['person', 'business']],
            'business_name' => ['label' => 'Business name', 'type' => 'text', 'max' => 255],
            'tax_exempt'  => ['label' => 'Tax exempt',    'type' => 'bool'],
            'tax_exempt_certificate' => ['label' => 'Tax exempt certificate', 'type' => 'text', 'max' => 128],
            'payment_terms' => ['label' => 'Payment terms', 'type' => 'text', 'max' => 64],
            'po_required' => ['label' => 'PO required',   'type' => 'bool'],
        ];
    }

    /** The field a row is matched on for this import type. */
    public static function matchField(string $importType): string
    {
        foreach (self::for($importType) as $key => $def) {
            if (! empty($def['match'])) {
                return $key;
            }
        }

        return 'email';
    }

    /**
     * Best-guess mapping from a header name. Deliberately conservative — a
     * wrong guess a person doesn't notice is worse than no guess.
     */
    public static function guess(string $importType, string $header): ?string
    {
        if ($importType === 'inventory') {          // MARKER-IMPORT2
            return self::guessInventory($header);
        }

        $norm = preg_replace('/[^a-z0-9]+/', '', strtolower($header));
        if ($norm === '') {
            return null;
        }

        $aliases = [
            'first_name' => ['firstname', 'fname', 'given', 'givenname', 'first'],
            'last_name'  => ['lastname', 'lname', 'surname', 'family', 'last'],
            'email'      => ['email', 'emailaddress', 'mail', 'e'],
            'phone'      => ['phone', 'phonenumber', 'mobile', 'cell', 'tel', 'telephone'],
            'address_line1' => ['address', 'address1', 'addressline1', 'street', 'street1'],
            'address_line2' => ['address2', 'addressline2', 'street2', 'unit', 'apt'],
            'city'       => ['city', 'town'],
            'state'      => ['state', 'province', 'region'],
            'postcode'   => ['postcode', 'postalcode', 'zip', 'zipcode'],
            'country'    => ['country'],
            'notes'      => ['notes', 'note', 'comment', 'comments'],
            'is_vip'     => ['vip', 'isvip'],
            'business_name' => ['business', 'businessname', 'company', 'companyname', 'organisation', 'organization'],
            'tax_exempt' => ['taxexempt', 'exempt'],
            'payment_terms' => ['terms', 'paymentterms'],
            'po_required'   => ['porequired', 'ponumberrequired'],
        ];

        foreach ($aliases as $field => $names) {
            if (in_array($norm, $names, true)) {
                return $field;
            }
        }

        return null;
    }

    /** MARKER-IMPORT2 — header guesses for inventory exports. */
    private static function guessInventory(string $header): ?string
    {
        $norm = preg_replace('/[^a-z0-9]+/', '', strtolower($header));
        if ($norm === '') {
            return null;
        }

        $aliases = [
            'sku'          => ['sku', 'itemcode', 'itemnumber', 'partnumber', 'partno', 'code', 'mpn'],
            'name'         => ['name', 'itemname', 'description', 'title', 'product'],
            'description'  => ['longdescription', 'longdesc', 'details', 'detail'],
            'category'     => ['category', 'dept', 'department', 'group', 'class'],
            'vendor'       => ['vendor', 'supplier', 'brand', 'manufacturer', 'distributor'],
            'shop_cost_cents'       => ['cost', 'unitcost', 'wholesale', 'buyprice'],
            'shop_sell_price_cents' => ['price', 'retail', 'retailprice', 'sellprice', 'msrp'],
            'shop_case_quantity'    => ['casequantity', 'caseqty', 'packsize'],
            'shop_reorder_threshold'=> ['reorderpoint', 'reorderat', 'minqty', 'min'],
            'shop_reorder_quantity' => ['reorderquantity', 'reorderqty'],
            'shop_bin_location'     => ['bin', 'binlocation', 'shelf', 'location'],
            'stock'        => ['qty', 'quantity', 'onhand', 'stock', 'stockonhand', 'qtyonhand'],
            'color'        => ['color', 'colour'],
            'size'         => ['size'],
            'upc'          => ['upc', 'barcode', 'ean', 'gtin'],
            'is_active'    => ['active', 'isactive', 'enabled'],
            'show_online'  => ['showonline', 'online', 'web', 'ecommerce'],
        ];

        foreach ($aliases as $field => $names) {
            if (in_array($norm, $names, true)) {
                return $field;
            }
        }

        return null;
    }
}
