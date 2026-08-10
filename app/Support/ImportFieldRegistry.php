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
        return $importType === 'customers' ? self::customers() : [];
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
}
