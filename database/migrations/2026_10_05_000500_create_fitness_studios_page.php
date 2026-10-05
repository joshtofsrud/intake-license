<?php

use Illuminate\Database\Migrations\Migration;

/**
 * MARKER-PAGE-FITNESS — creates a DRAFT "For fitness studios" page on intake.works (slug fitness-studios,
 * unpublished). Review and publish it in Marketing pages. Never touches an
 * existing page with that address.
 */
return new class extends Migration
{
    public function up(): void
    {
        $plat = \App\Models\Tenant::where('is_platform', true)->value('id');
        if (! $plat) return;
        if (\App\Models\Tenant\TenantPage::where('tenant_id', $plat)->where('slug', 'fitness-studios')->exists()) return;

        $ic = [
            'wrench'  => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
            'box'     => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
            'tag'     => '<path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
            'card'    => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M6 15h4"/>',
            'users'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6 6 0 0 0-4-5.6"/>',
            'cal'     => '<rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/>',
            'truck'   => '<path d="M2 6h11v10H2zM13 10h4l4 4v2h-8"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
            'key'     => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3"/>',
            'clip'    => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/>',
            'gift'    => '<rect x="3" y="8" width="18" height="13" rx="1.5"/><path d="M3 12h18M12 8v13M12 8S10 3 7.5 4.5 9 8 12 8zm0 0s2-5 4.5-3.5S15 8 12 8z"/>',
            'mail'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
            'globe'   => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        ];
        $f = fn ($icon, $title, $body) => ['icon' => $ic[$icon], 'title' => $title, 'price' => '', 'body' => $body, 'cta_label' => '', 'cta_url' => ''];
        $q = fn ($question, $answer) => ['question' => $question, 'answer' => $answer, 'q' => $question, 'a' => $answer, 'open_default' => false];

        $page = \App\Models\Tenant\TenantPage::create([
            'tenant_id' => $plat, 'title' => 'For fitness studios', 'slug' => 'fitness-studios', 'kind' => 'page',
            'meta_title' => 'Intake for fitness studios — classes, memberships, waitlists and your website',
            'meta_description' => 'Class scheduling, memberships and packs, rosters and check-in, automatic waitlists and online booking — with private sessions alongside. One system for your studio.',
            'is_published' => false,
        ]);
        $sections = [
            ['hero', ['eyebrow' => 'For fitness studios', 'headline' => 'Classes, memberships
and your website — in one place.', 'accent_words' => 'in one place.', 'subheading' => 'Schedule classes, sell memberships and packs, manage rosters and waitlists, and let members book and pay online — all from the same system as your private sessions.', 'buttons' => [['label' => 'Start free trial', 'url' => 'https://app.intake.works/signup', 'style' => 'primary'], ['label' => 'Explore the demo', 'url' => '/demo', 'style' => 'ghost'], ['label' => 'Book a call', 'url' => '#book', 'style' => 'ghost']], 'buttons_style' => 'pill', 'hero_layout' => 'split', 'text_align' => 'left']],
            ['feature_grid', ['eyebrow' => 'Built for the studio', 'heading' => 'Everything a studio runs on.', 'accent_words' => '', 'subheading' => 'Classes, members and money in one place — with private sessions alongside.', 'features' => [$f('cal', 'Class scheduling', 'Create classes once, then schedule sessions one-off, weekly or across a date range — each with its own capacity and price.'), $f('users', 'Rosters and check-in', 'See who\'s coming, check members in, mark no-shows, and manage waitlists from the session.'), $f('tag', 'Memberships and class packs', 'Sell monthly memberships and packs with expiry dates. Credits come back automatically when a member cancels.'), $f('key', 'Waitlists that fill themselves', 'When a spot opens, the next person on the waitlist is moved in automatically.'), $f('card', 'Book and pay online', 'Members pay with their membership, a pack credit or per class — and see their bookings and credits in their own account.'), $f('clip', 'Private sessions too', 'Run one-on-one appointments alongside classes, on the same calendar and the same customer records.')], 'columns' => 3, 'card_style' => 'card', 'show_icons' => true, 'text_align' => 'left']],
            ['step_timeline', ['eyebrow' => 'A class, start to finish', 'heading' => 'From "is there space?" to "see you next week."', 'accent_words' => '', 'subheading' => '', 'steps' => [['title' => 'They find a class', 'desc' => 'Members browse your schedule with live spaces left, right on your website.', 'icon' => ''], ['title' => 'They book', 'desc' => 'Their membership or pack is picked automatically, or they pay for the class.', 'icon' => ''], ['title' => 'They show up', 'desc' => 'Check them in from the roster; no-shows are marked in a tap.', 'icon' => ''], ['title' => 'They come back', 'desc' => 'Reports show who\'s at risk of lapsing, and an email brings them back.', 'icon' => '']]]],
            ['feature_grid', ['eyebrow' => 'Beyond the schedule', 'heading' => 'The rest of the studio, covered.', 'accent_words' => '', 'subheading' => '', 'features' => [$f('gift', 'Class reports', 'Spot drop-in regulars, at-risk members, used-up packs and lapsed memberships — and export any list.'), $f('card', 'Drop-ins at the desk', 'Take a drop-in payment at the register and the member is booked into the class automatically.'), $f('mail', 'Email marketing, built in', 'Send campaigns to members who\'ve opted in — no Mailchimp or Constant Contact needed. You only pay per send.'), $f('globe', 'Your website, your domain', 'Build your site with ready-made sections, show your timetable and run it on your own domain.'), $f('cal', 'Your team, scheduled', 'Instructor schedules, time off and a time clock in the same place.'), $f('box', 'Retail at the front desk', 'Sell water, apparel and gear at the register, with inventory that keeps itself up to date.')], 'columns' => 3, 'card_style' => 'minimal', 'show_icons' => true, 'text_align' => 'left']],
            ['faq_accordion', ['eyebrow' => 'Questions', 'heading' => 'What studios ask us.', 'accent_words' => '', 'subheading' => '', 'items' => [$q('Which plan does a studio need?', 'Classes, memberships and packs are included from Branded, which also covers the front-desk register and up to three team members. Larger studios choose Scale for up to ten team members and unlimited retail inventory.'), $q('Can members manage their own bookings?', 'Yes. Members see their upcoming classes, history, membership and pack credits in their own account, and can cancel within your rules.'), $q('Can I run private sessions as well?', 'Yes — appointments and classes live side by side on the same calendar and customer records.'), $q('Can I bring my members across?', 'Yes. Import them from a spreadsheet, review anything that conflicts, and undo the import if something looks wrong.'), $q('Can I try it first?', 'Start a free trial and set up your own studio, or walk around the demo with no sign-up at all.')]]],
            ['cta_banner', ['eyebrow' => '', 'headline' => 'Spend your time teaching, not chasing bookings.', 'accent_words' => 'not chasing bookings.', 'subheading' => 'Start a free trial and have your timetable live today, or walk around the demo first.', 'buttons' => [['label' => 'Start free trial', 'url' => 'https://app.intake.works/signup', 'style' => 'primary'], ['label' => 'Explore the demo', 'url' => '/demo', 'style' => 'ghost'], ['label' => 'Book a call', 'url' => '#book', 'style' => 'ghost']], 'buttons_style' => 'pill', 'text_align' => 'center']],
        ];
        foreach ($sections as $i => [$type, $content]) {
            \App\Models\Tenant\TenantPageSection::create([
                'page_id' => $page->id, 'tenant_id' => $plat, 'section_type' => $type,
                'content' => $content, 'padding' => 'normal', 'is_visible' => true, 'sort_order' => $i + 1,
            ]);
        }
    }

    public function down(): void {}
};
