<?php

use Illuminate\Database\Migrations\Migration;

/**
 * creates a DRAFT "For solo practitioners" page on intake.works (slug solo-practitioners,
 * unpublished). Review and publish it in Marketing pages. Never touches an
 * existing page with that address.
 */
return new class extends Migration
{
    public function up(): void
    {
        $plat = \App\Models\Tenant::where('is_platform', true)->value('id');
        if (! $plat) return;
        if (\App\Models\Tenant\TenantPage::where('tenant_id', $plat)->where('slug', 'solo-practitioners')->exists()) return;

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
            'tenant_id' => $plat, 'title' => 'For solo practitioners', 'slug' => 'solo-practitioners', 'kind' => 'page',
            'meta_title' => 'Intake for solo practitioners — booking, payments, clients and your website',
            'meta_description' => 'Online booking with payment, client records, a website that takes bookings and built-in email marketing — made for one-person businesses. From $29 a month.',
            'is_published' => false,
        ]);
        $sections = [
            ['hero', ['eyebrow' => 'For solo practitioners', 'headline' => 'Your bookings, your clients
and your website — handled.', 'accent_words' => 'handled.', 'subheading' => 'One simple system for the one-person business: clients book and pay online, every job and visit is on their record, and your evenings stay yours.', 'buttons' => [['label' => 'Start free trial', 'url' => 'https://app.intake.works/signup', 'style' => 'primary'], ['label' => 'Explore the demo', 'url' => '/demo', 'style' => 'ghost'], ['label' => 'Book a call', 'url' => '#book', 'style' => 'ghost']], 'buttons_style' => 'pill', 'hero_layout' => 'split', 'text_align' => 'left']],
            ['feature_grid', ['eyebrow' => 'Built for one', 'heading' => 'Everything you need. Nothing you don’t.', 'accent_words' => '', 'subheading' => 'No team features to wade through — just the tools a one-person business actually uses.', 'features' => [$f('cal', 'Online booking', 'Clients pick a time — or a day, if you take drop-offs — from the hours you set, any time of day.'), $f('card', 'Get paid when they book', 'Take a deposit or full payment at booking, or when the job is done. The money goes straight to your account.'), $f('clip', 'Jobs and visits on record', 'Every booking becomes a job with notes and charges, so you always know what you did last time.'), $f('users', 'Client records', 'Contact details, history, spend and notes in one place — with a client\'s own answers from the booking form.'), $f('globe', 'A website that books', 'Build a simple site with ready-made sections and take bookings right on it.'), $f('mail', 'Email marketing, built in', 'Send campaigns to clients who\'ve opted in — no Mailchimp or Constant Contact needed. You only pay per send.')], 'columns' => 3, 'card_style' => 'card', 'show_icons' => true, 'text_align' => 'left']],
            ['step_timeline', ['eyebrow' => 'A booking, start to finish', 'heading' => 'Less admin between you and the work.', 'accent_words' => '', 'subheading' => '', 'steps' => [['title' => 'They book', 'desc' => 'Your client picks a time that suits you both and answers your questions up front.', 'icon' => ''], ['title' => 'They pay', 'desc' => 'Take a deposit or the full amount, so no-shows cost them, not you.', 'icon' => ''], ['title' => 'You do the work', 'desc' => 'Their history and notes are on the job, so you\'re never starting from scratch.', 'icon' => ''], ['title' => 'They come back', 'desc' => 'Their record builds with every visit, and a campaign brings regulars back when it\'s been a while.', 'icon' => '']]]],
            ['faq_accordion', ['eyebrow' => 'Questions', 'heading' => 'What solo practitioners ask us.', 'accent_words' => '', 'subheading' => '', 'items' => [$q('What does it cost?', 'Starter is $29 a month for a one-person business, with booking, jobs, client records and your website included. Email campaigns are paid per send.'), $q('Can I use it from my phone?', 'Yes — your schedule, clients and bookings all work on your phone.'), $q('Can I bring my existing clients across?', 'Yes. Import them from a spreadsheet, review anything that conflicts, and undo the import if something looks wrong.'), $q('Can I use my own domain?', 'Starter runs on your own intake.works address. When you want your own domain and to remove Intake branding, move up to Branded.'), $q('What if I hire someone later?', 'Move up to Branded and add up to three team members — your clients and history come with you.')]]],
            ['cta_banner', ['eyebrow' => '', 'headline' => 'Run your business, not your admin.', 'accent_words' => 'not your admin.', 'subheading' => 'Start a free trial and set up in minutes, or walk around the demo first.', 'buttons' => [['label' => 'Start free trial', 'url' => 'https://app.intake.works/signup', 'style' => 'primary'], ['label' => 'Explore the demo', 'url' => '/demo', 'style' => 'ghost'], ['label' => 'Book a call', 'url' => '#book', 'style' => 'ghost']], 'buttons_style' => 'pill', 'text_align' => 'center']],
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
