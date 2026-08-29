<?php

namespace App\Support;

use App\Models\InvestDocument;

/**
 * MARKER-INVEST-V2 — where the round's shared documents live.
 *
 * Both the gated page and the investor portal serve the same proposal PDFs,
 * so the mapping sits here rather than being copied into each controller.
 * Uploaded documents win over the shipped filenames.
 */
class InvestDocuments
{
    /** slug => relative path under storage/app */
    public const SHIPPED = [
        'proposal'       => 'invest/Intake-Investment-Opportunity.pdf',
        'proposal-light' => 'invest/Intake-Investment-Opportunity-Light.pdf',
        'summary'        => 'invest/Intake-One-Page-Summary.pdf',
        'summary-light'  => 'invest/Intake-One-Page-Summary-Light.pdf',
    ];

    /** Absolute path for a slug, or null when there is nothing to serve. */
    // MARKER-INVEST-CONTEXT — any slug the uploader created is servable, not
    // just the three shipped ones.
    public static function path(string $slug): ?string
    {
        $uploaded = InvestDocument::where('slug', $slug)->where('is_active', true)->first();

        $relative = $uploaded->path ?? (self::SHIPPED[$slug] ?? null);
        if (! $relative) { return null; }

        $absolute = storage_path('app/' . $relative);

        return is_file($absolute) ? $absolute : null;
    }

    /**
     * The documents to offer on screen. An entry only appears if the file is
     * actually there — a dead link on this page reads as carelessness at the
     * exact moment carelessness is most expensive.
     */
    /**
     * MARKER-INVEST-CONTEXT — everything uploaded and active, in the order set
     * in Raise setup.
     *
     * This used to look for three hardcoded slugs. The uploader derives a slug
     * from whatever label is typed, so "Full Proposal" became full-proposal and
     * matched nothing: the documents were there, active, and invisible. Never
     * whitelist against a field someone else can name freely.
     */
    public static function listed(): array
    {
        $out = [];

        foreach (InvestDocument::where('is_active', true)->orderBy('sort')->orderBy('id')->get() as $doc) {
            if (! self::path($doc->slug)) { continue; }   // active but the file is gone

            $out[] = [
                'slug'  => $doc->slug,
                'label' => $doc->label ?: $doc->slug,
                'meta'  => 'PDF',
            ];
        }

        if ($out) { return $out; }

        // Nothing uploaded yet — fall back to whatever shipped with the app.
        foreach ([
            ['proposal', 'Full proposal',    'PDF'],
            ['summary',  'One-page summary', '1 page · PDF'],
        ] as [$slug, $label, $meta]) {
            if (! self::path($slug)) { continue; }
            $out[] = ['slug' => $slug, 'label' => $label, 'meta' => $meta];
        }

        return $out;
    }
}
