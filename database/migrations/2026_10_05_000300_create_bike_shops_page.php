<?php

use Illuminate\Database\Migrations\Migration;

/**
 * creates a DRAFT "For bike shops" page on
 * intake.works (slug bike-shops, unpublished) built from existing sections:
 * split hero, what it does for a bike shop, a repair start to finish, beyond
 * the service desk, questions, and a closing call to action. Review and
 * publish it in Marketing pages. Never touches an existing bike-shops page.
 */
return new class extends Migration
{
    public function up(): void
    {
        $plat = \App\Models\Tenant::where('is_platform', true)->value('id');
        if (! $plat) return;
        if (\App\Models\Tenant\TenantPage::where('tenant_id', $plat)->where('slug', 'bike-shops')->exists()) return;

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
            'tenant_id' => $plat, 'title' => 'For bike shops', 'slug' => 'bike-shops', 'kind' => 'page',
            'meta_title' => 'Intake for bike shops — service, POS, inventory and your website in one',
            'meta_description' => 'Booking, work orders, point of sale, inventory with distributor catalogs, rentals and your website — one system built by a bike shop owner.',
            'is_published' => false,
        ]);
        $sections = [
            ['hero', [
                'eyebrow' => 'For bike shops',
                'headline' => "The service desk, the sales floor\nand your website — in one place.",
                'accent_words' => 'in one place.',
                'subheading' => 'Bookings turn into work orders, parts come off the shelf as you use them, and customers get told when their bike is ready. Built by a bike shop owner who got tired of juggling five tools.',
                'buttons' => [
                    ['label' => 'Start free trial', 'url' => 'https://app.intake.works/signup', 'style' => 'primary'],
                    ['label' => 'Explore the demo', 'url' => '/demo', 'style' => 'ghost'],
                    ['label' => 'Book a call', 'url' => '#book', 'style' => 'ghost'],
                ],
                'buttons_style' => 'pill', 'hero_layout' => 'split', 'text_align' => 'left',
            ]],
            ['feature_grid', [
                'eyebrow' => 'Built for the shop floor',
                'heading' => 'Everything a bike shop runs on.',
                'accent_words' => '',
                'subheading' => 'Service, retail and the stuff in between — sharing one set of customers, inventory and numbers.',
                'features' => [
                    $f('wrench', 'Service queue', 'Customers book a drop-off day online. Every booking becomes a work order with status, notes, parts and labor on one ticket.'),
                    $f('box', 'Inventory with distributor catalogs', 'Pull product details from supported distributors, receive shipments by scanning, and keep your own prices and notes safe from catalog updates.'),
                    $f('card', 'Point of sale', 'Sell parts and bikes at the register, take cards or send a payment link, and handle returns and exchanges in one transaction.'),
                    $f('tag', 'Serial numbers', 'Track serialized bikes and frames from receiving to sale, so you always know which one went where.'),
                    $f('users', 'Customers and their bikes', 'Every visit, sale and conversation on one customer timeline — with texts and emails in a single inbox.'),
                    $f('cal', 'Your team, scheduled', 'Staff schedules, time off and a time clock, with each mechanic\'s capacity built into what customers can book.'),
                ],
                'columns' => 3, 'card_style' => 'card', 'show_icons' => true, 'text_align' => 'left',
            ]],
            ['step_timeline', [
                'eyebrow' => 'A repair, start to finish',
                'heading' => 'From "can you fit me in?" to "it\'s ready."',
                'accent_words' => '',
                'subheading' => '',
                'steps' => [
                    ['title' => 'Booked online', 'desc' => 'The customer picks a drop-off day with room left on it and tells you what the bike needs.', 'icon' => ''],
                    ['title' => 'Checked in', 'desc' => 'The booking is already a work order. Add notes and the bike is on the customer\'s record.', 'icon' => ''],
                    ['title' => 'In the stand', 'desc' => 'Add parts straight from inventory, or order what\'s missing as a special order tied to the job.', 'icon' => ''],
                    ['title' => 'Ready', 'desc' => 'Mark it ready and the customer is told — no phone tag.', 'icon' => ''],
                    ['title' => 'Picked up and paid', 'desc' => 'The ticket rings up at the register, or they pay ahead from a link on their phone.', 'icon' => ''],
                ],
            ]],
            ['feature_grid', [
                'eyebrow' => 'Beyond the service desk',
                'heading' => 'The rest of the shop, covered.',
                'accent_words' => '',
                'subheading' => '',
                'features' => [
                    $f('key', 'Rentals and demo fleet', 'Rent bikes by the day with availability checks, and let customers extend from a text message.'),
                    $f('truck', 'Pickup and delivery', 'Offer pickup and drop-off windows for customers who can\'t get to you.'),
                    $f('clip', 'Special orders and layaway', 'Order for a customer, take a deposit, and hold items on layaway without selling them twice.'),
                    $f('gift', 'Gift cards and discount codes', 'Sell and redeem gift cards in store and online, with codes that work across the register and your site.'),
                    $f('mail', 'Win-back emails', 'Spot regulars who haven\'t been in for a while and invite them back with an email campaign.'),
                    $f('globe', 'Your website, your domain', 'Build your site with Intake\'s page builder, take bookings on it, and run it on your own domain.'),
                ],
                'columns' => 3, 'card_style' => 'minimal', 'show_icons' => true, 'text_align' => 'left',
            ]],
            ['faq_accordion', [
                'eyebrow' => 'Questions',
                'heading' => 'What bike shops ask us.',
                'accent_words' => '',
                'subheading' => '',
                'items' => [
                    $q('Can I bring my customers and inventory across?', 'Yes. Import customers and inventory from a spreadsheet, map the columns, review any conflicts, and undo an import if something looks wrong.'),
                    $q('Does it work with my distributors?', 'Intake pulls catalog data from supported distributors so you\'re not typing product details by hand. Get in touch and we\'ll confirm yours.'),
                    $q('Which plan does a bike shop need?', 'Point of sale and inventory start on Branded, which includes up to 120 inventory items and three team members. Most full-service shops choose Scale for unlimited inventory and up to ten team members.'),
                    $q('Can customers book online?', 'Yes — on your own site. Drop-off shops let customers pick a day with room left on it; appointment shops offer times.'),
                    $q('Can I try it first?', 'Start a free trial and set up your own shop, or walk around the demo shop with no sign-up at all.'),
                ],
            ]],
            ['cta_banner', [
                'eyebrow' => '',
                'headline' => 'See it running in a real shop.',
                'accent_words' => 'real shop.',
                'subheading' => 'Walk around the demo, start your own free trial, or book a call and we\'ll show you around.',
                'buttons' => [
                    ['label' => 'Start free trial', 'url' => 'https://app.intake.works/signup', 'style' => 'primary'],
                    ['label' => 'Explore the demo', 'url' => '/demo', 'style' => 'ghost'],
                    ['label' => 'Book a call', 'url' => '#book', 'style' => 'ghost'],
                ],
                'buttons_style' => 'pill', 'text_align' => 'center',
            ]],
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
