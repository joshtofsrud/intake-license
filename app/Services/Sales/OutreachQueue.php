<?php

namespace App\Services\Sales;

use App\Models\PlatformSettings;
use App\Models\SalesProspect;
use Illuminate\Database\Eloquent\Builder;

/**
 * MARKER-OUTREACH-AUTO: the outreach queue, shared by the Email outreach page
 * (one shop at a time) and auto-send (OutreachAutoSend), so both always agree
 * on who's next.
 *
 * Filters: who (new | again | all), industryId, state, stage, sort
 * (score | emailed | new) and mail (any | business | free). "Free" is an
 * address at a free email provider (Gmail, Outlook…); the list of those
 * domains is editable on the page and kept on platform_settings.
 */
class OutreachQueue
{
    /** Used until the list is edited on the page. */
    public const FREE_DEFAULT = [
        'gmail.com', 'googlemail.com',
        'hotmail.com', 'hotmail.co.uk', 'hotmail.ca', 'outlook.com', 'live.com', 'live.ca', 'msn.com', 'passport.com',
        'yahoo.com', 'yahoo.ca', 'yahoo.co.uk', 'ymail.com', 'rocketmail.com',
        'aol.com', 'icloud.com', 'me.com', 'mac.com',
        'comcast.net', 'att.net', 'sbcglobal.net', 'verizon.net', 'charter.net', 'cox.net', 'earthlink.net',
        'protonmail.com', 'proton.me', 'gmx.com', 'mail.com', 'zoho.com',
    ];

    public const MAIL = ['any' => 'Any email', 'business' => 'Business addresses', 'free' => 'Free email (Gmail, Outlook…)'];

    /** The free-email domains, lower case, one per entry. */
    public static function freeDomains(): array
    {
        try {
            $list = json_decode((string) PlatformSettings::current()->getAttribute('outreach_free_domains'), true);
        } catch (\Throwable $e) {
            $list = null;
        }

        return is_array($list) ? $list : self::FREE_DEFAULT;
    }

    /** Cleans what was typed (commas, spaces or new lines between domains) and saves it. Returns the list. */
    public static function saveFreeDomains(string $typed): array
    {
        $list = collect(preg_split('/[\s,;]+/', mb_strtolower($typed)))
            ->map(fn ($d) => ltrim(trim($d), '@'))
            ->filter(fn ($d) => preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/', $d))
            ->unique()->sort()->values()->all();
        $ps = PlatformSettings::current();
        $ps->forceFill(['outreach_free_domains' => json_encode($list)])->save();
        PlatformSettings::forget();

        return $list;
    }

    public static function isFree(?string $email): bool
    {
        $domain = mb_strtolower(trim(substr((string) $email, strrpos((string) $email, '@') + 1)));

        return in_array($domain, self::freeDomains(), true);
    }

    /** Open prospects with an email address that can be emailed now, filtered. */
    public static function filtered(array $f): Builder
    {
        $who = $f['who'] ?? 'new';
        $q = SalesProspect::query()
            ->whereNotNull('email')->where('email', 'like', '%@%')
            // MARKER-OUTREACH-RECONTACT: shops already emailed can be emailed
            // again; only a shop emailed in the last hour stays out, so a send
            // doesn't come straight back as the next shop (MARKER-RECONTACT-HOUR).
            ->when($who === 'new', fn ($q) => $q->whereNull('emailed_at'))
            ->when($who === 'again', fn ($q) => $q->whereNotNull('emailed_at')->where('emailed_at', '<', now()->subHour()))
            ->when($who === 'all', fn ($q) => $q->where(fn ($w) => $w->whereNull('emailed_at')->orWhere('emailed_at', '<', now()->subHour())))
            ->whereNotIn('stage', ['won', 'lost'])
            ->whereRaw('LOWER(email) NOT IN (SELECT email FROM platform_email_optouts)')
            // MARKER-OUTREACH-ONE-PER-ADDRESS: an address that had an outreach
            // email recently is out of the queue whichever listing it was
            // sent as; "new" means the ADDRESS has never had one.
            ->whereRaw('LOWER(TRIM(email)) NOT IN (SELECT LOWER(email) FROM sales_outreach_sends WHERE created_at >= ?)',
                [now()->subHours(ProspectOutreach::ADDRESS_GAP_HOURS)])
            ->when($who === 'new', fn ($q) => $q->whereRaw('LOWER(TRIM(email)) NOT IN (SELECT LOWER(email) FROM sales_outreach_sends)'));

        if (! empty($f['_base'])) return $q; // the counts per industry tab use the unfiltered set

        $mail = $f['mail'] ?? 'any';
        if (in_array($mail, ['business', 'free'], true)) {
            $free = self::freeDomains() ?: ['-'];
            $expr = "SUBSTRING_INDEX(LOWER(TRIM(email)), '@', -1)";
            $marks = implode(',', array_fill(0, count($free), '?'));
            $q->whereRaw($expr . ($mail === 'free' ? ' IN ' : ' NOT IN ') . '(' . $marks . ')', $free);
        }

        return $q
            ->when(($f['industryId'] ?? '') !== '', fn ($q) => $q->where('channel_id', $f['industryId']))
            ->when(($f['state'] ?? '') !== '', fn ($q) => $q->where('state', $f['state']))
            ->when(($f['stage'] ?? '') !== '', fn ($q) => $q->where('stage', $f['stage']));
    }

    /** Queue order: skipped ones at the back, then the chosen sort. */
    public static function ordered(array $f): Builder
    {
        $q = self::filtered($f)->orderByRaw('email_skipped_at IS NOT NULL')->orderBy('email_skipped_at');
        $sort = $f['sort'] ?? 'score';
        if ($sort === 'emailed') return $q->orderByRaw('emailed_at IS NOT NULL')->orderBy('emailed_at')->orderByDesc('lead_score');
        // MARKER-OUTREACH-FAST: ties break on id (in the index) instead of shop name (not in it)
        return $sort === 'new' ? $q->orderByDesc('created_at')->orderBy('id') : $q->orderByDesc('lead_score')->orderBy('id');
    }
}
