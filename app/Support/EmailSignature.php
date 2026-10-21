<?php

namespace App\Support;

/**
 * MARKER-UNIVERSAL-SIGNATURE: Intake's one email signature (logo, name, title,
 * phone, links), edited on Sales > Email templates > Signature. Added to
 * prospect emails, every investor email and Platform inbox replies. Not added
 * to tenant emails, password resets, team invites or billing notices.
 * Stored on platform_settings.email_signature so automatic sends (an investor
 * committing on the invest page) carry it with nobody signed in.
 */
class EmailSignature
{
    public static function html(): string
    {
        try {
            return \App\Services\Sales\ProspectOutreach::signatureHtml();
        } catch (\Throwable $e) {
            report($e);
            return '';
        }
    }

    /** Plain-text version, starting with a blank line; empty when off. */
    public static function textSuffix(): string
    {
        try {
            $t = \App\Services\Sales\ProspectOutreach::signatureText();
        } catch (\Throwable $e) {
            report($e);
            return '';
        }
        return $t === '' ? '' : "\n\n-- \n" . $t;
    }
}
