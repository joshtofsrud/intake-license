<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The choices made in the Print & Send composer at the moment of printing —
 * format, which assets, which notes, prices, ledger, and where it's going.
 *
 * MARKER-PATCH-333
 */
class DocumentOptions
{
    public function __construct(
        public string $type = 'receipt',          // receipt | tag | invoice | slip
        public string $format = 't80',            // t80 | t58 | full | invoice
        public array  $assetIds = [],             // empty = all assets
        public bool   $splitPerAsset = false,     // one document per asset
        public bool   $includeCustomerNotes = true,
        public bool   $includeStaffNotes = false,
        public bool   $includePrices = true,
        public bool   $includeQr = false,
        public bool   $includeLedger = false,     // payment history + running balance
        public string $action = 'print',          // print | pdf | email
        public ?string $emailTo = null,
    ) {
    }

    public static function fromRequest(Request $r): self
    {
        $assets = (array) $r->input('assets', []);
        $assets = array_values(array_filter($assets, fn ($a) => $a !== null && $a !== ''));

        return new self(
            type: (string) $r->input('type', 'receipt'),
            format: (string) $r->input('format', 't80'),
            assetIds: $assets,
            splitPerAsset: $r->boolean('split'),
            includeCustomerNotes: $r->boolean('notes_customer', true),
            includeStaffNotes: $r->boolean('notes_staff', false),
            includePrices: $r->boolean('prices', true),
            includeQr: $r->boolean('qr', false),
            includeLedger: $r->boolean('ledger', false),
            action: (string) $r->input('action', 'print'),
            emailTo: $r->input('email') ?: null,
        );
    }

    public function isThermal(): bool
    {
        return in_array($this->format, ['t80', 't58'], true);
    }
}
