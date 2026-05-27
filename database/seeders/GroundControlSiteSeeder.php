<?php
// MARKER-PATCH-158-G13

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;
use App\Models\Tenant\TenantNavItem;
use Illuminate\Database\Seeder;

/**
 * GroundControlSiteSeeder
 *
 * Direct-import seeder for the Ground Control marketing site. Translates
 * the static HTML brand kit (grndctrl-v2) into tenant_pages +
 * tenant_page_sections rows using the existing page-builder section types.
 *
 * Use:
 *   php artisan db:seed --class=GroundControlSiteSeeder --force \
 *     --tenant-id=<tenant-uuid>
 *
 * Or invoke programmatically:
 *   (new GroundControlSiteSeeder($tenantId))->run();
 *
 * Idempotent — re-running wipes sections on each page and reseeds them.
 * Tenant nav items are wiped + reseeded too.
 *
 * Trade-offs (read this before running):
 *   - The home hero's "live route" side card is dropped (no matching
 *     section type). Hero shows headline + CTAs only.
 *   - Service cards (Standard/Full/Complete) are seeded as a feature_grid
 *     with prices in the body text, NOT as the live `services` section
 *     that pulls from the tenant's services catalog. This keeps the
 *     pricing static and matches the original brand kit. To pull live
 *     services instead, swap feature_grid for `services` after seeding.
 *   - Suspension brand row (RockShox, Fox, etc.) is seeded as a separate
 *     text-based feature_grid since logo_bar expects images.
 */
class GroundControlSiteSeeder extends Seeder
{
    protected ?string $tenantId = null;

    public function __construct(?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
    }

    public function run(): void
    {
        // Allow tenant_id via CLI option, env var, or constructor arg
        $tenantId = $this->tenantId
            ?? $this->command?->option('tenant-id')
            ?? env('SEED_TENANT_ID');

        if (! $tenantId) {
            $this->command?->error('Missing tenant id. Pass --tenant-id=<uuid> or set SEED_TENANT_ID.');
            return;
        }

        $tenant = Tenant::where('id', $tenantId)->first();
        if (! $tenant) {
            $this->command?->error("No tenant found with id {$tenantId}.");
            return;
        }

        $this->command?->info("Seeding Ground Control marketing site for tenant: {$tenant->name} ({$tenant->subdomain})");

        // --- Nav items (wipe + reseed) ---
        TenantNavItem::where('tenant_id', $tenant->id)->delete();
        $navLinks = [
            ['Home', '/', 10],
            ['How it works', '/how-it-works', 20],
            ['Book', '/book', 30],
            ['FAQ', '/faq', 40],
            ['Contact', '/contact', 50],
        ];
        foreach ($navLinks as [$label, $url, $order]) {
            TenantNavItem::create([
                'tenant_id'       => $tenant->id,
                'label'           => $label,
                'url'             => $url,
                'is_external'     => false,
                'open_in_new_tab' => false,
                'sort_order'      => $order,
            ]);
        }
        $this->command?->info('  Nav: 5 items seeded.');

        // --- Home page ---
        $this->seedPage($tenant, 'home', 'Ground Control', 'Mobile bike service · Spokane, WA', true, [

            ['type' => 'nav', 'content' => [
                'show_logo'  => true,
                'cta_label'  => 'Schedule pickup',
                'cta_url'    => '/book',
                'bg_style'   => 'solid',
            ]],

            ['type' => 'hero', 'content' => [
                'eyebrow'             => 'Now booking weekly pickups',
                'headline'            => "Skip the shop visit.\nWe come to you.",
                'accent_words'        => 'to you',
                'subheading'          => "Ground Control is a fully mobile bike service in Spokane. Full tune-ups, overhauls, and the suspension work we've always been known for. We pick up from your driveway and bring it back ready to ride.",
                'bg_type'             => 'color',
                'bg_color'            => '#0a0a0a',
                'text_color'          => '#ffffff',
                'cta_primary_label'   => 'Schedule pickup',
                'cta_primary_url'     => '/book',
                'cta_secondary_label' => 'How it works',
                'cta_secondary_url'   => '/how-it-works',
                'height'              => 'large',
                'text_align'          => 'left',
                'note'                => 'Free local pickup · 24h typical turnaround',
            ]],

            ['type' => 'feature_grid', 'content' => [
                'eyebrow'  => '',
                'heading'  => '',
                'subheading' => '',
                'columns'  => 4,
                'features' => [
                    ['icon' => '→',  'title' => 'Pickup & return',  'body' => 'Free in Spokane & surrounding'],
                    ['icon' => '24', 'title' => '24h turnaround',   'body' => 'Most jobs back the next day'],
                    ['icon' => '$',  'title' => 'Quote first',      'body' => 'No surprise charges, ever'],
                    ['icon' => '◫',  'title' => 'Photo report',     'body' => 'Every job documented'],
                ],
            ]],

            ['type' => 'feature_grid', 'content' => [
                'eyebrow'    => 'Service menu',
                'heading'    => 'Full-service workshop, on wheels.',
                'subheading' => 'From a quick tune to a complete teardown. Pricing starts at the figures below — final quote always confirmed after inspection.',
                'columns'    => 3,
                'features' => [
                    ['icon' => '01', 'title' => 'Standard tune-up · $95',
                     'body' => 'Full safety inspection · Drivetrain clean & lube · Shift & brake adjustment · Wheel true & tire pressure · Bolt torque check. 30-day warranty on labor.'],
                    ['icon' => '02', 'title' => 'Full tune-up · $165',
                     'body' => 'Everything in Standard, plus: Wheels off, hubs inspected · Bottom bracket & headset check · Ultrasonic drivetrain clean · Frame wash & finish. 60-day warranty on labor.'],
                    ['icon' => '03', 'title' => 'Complete overhaul · $295',
                     'body' => 'Stripped & deep cleaned · Bearings serviced or replaced · Drivetrain wear measured · Cable & housing replacement · Reassembled & test ridden. 60-day warranty on labor.'],
                ],
                'cta_label' => 'Schedule a pickup',
                'cta_url'   => '/book',
            ]],

            ['type' => 'feature_grid', 'content' => [
                'eyebrow'    => 'Suspension specialty',
                'heading'    => 'Forks, shocks & dropper posts.',
                'subheading' => "Out-of-area suspension shipments welcome — we'll send you a label.",
                'columns'    => 3,
                'features'   => [
                    ['icon' => '✓', 'title' => 'Lower service · $90 & up',  'body' => 'Legs/sleeve off, cleaned, new wipers & oil.'],
                    ['icon' => '✓', 'title' => 'Full rebuild · $150 & up',  'body' => 'Teardown, damper rebuild, air spring service.'],
                    ['icon' => '✓', 'title' => 'Dropper post · $120 & up', 'body' => 'Teardown, all new o-rings, seals, & oil.'],
                ],
            ]],

            ['type' => 'step_timeline', 'content' => [
                'eyebrow'    => 'How it works',
                'heading'    => 'Four steps, door to door.',
                'subheading' => 'No parking, no waiting room, no carrying your bike across town.',
                'steps'      => [
                    ['title' => 'Book online', 'desc' => 'Pick services and a pickup day.', 'done' => true],
                    ['title' => 'We pick up',  'desc' => 'We come to you in Spokane.',      'done' => true],
                    ['title' => 'We service',  'desc' => 'Most jobs turn around within 24 hours.', 'done' => true],
                    ['title' => 'We return',   'desc' => 'Back to your door, ready to ride.', 'done' => false],
                ],
            ]],

            ['type' => 'feature_grid', 'content' => [
                'eyebrow'  => 'Suspension service from',
                'heading'  => '',
                'columns'  => 5,
                'features' => [
                    ['icon' => '', 'title' => 'RockShox',   'body' => ''],
                    ['icon' => '', 'title' => 'Fox',        'body' => ''],
                    ['icon' => '', 'title' => 'Marzocchi',  'body' => ''],
                    ['icon' => '', 'title' => 'Öhlins',     'body' => ''],
                    ['icon' => '', 'title' => 'DVO',        'body' => ''],
                ],
            ]],

            ['type' => 'cta_banner', 'content' => [
                'headline'   => 'Ready to ride a dialed bike?',
                'subheading' => 'Free 24-hour service in Spokane. We pick up, we service, we return.',
                'cta_label'  => 'Schedule pickup',
                'cta_url'    => '/book',
                'bg_color'   => '#0a0a0a',
                'text_color' => '#ffffff',
            ]],

            ['type' => 'footer', 'content' => [
                'show_logo'      => true,
                'show_copyright' => true,
                'copyright_text' => '© 2026 Ground Control · Spokane, WA · Built & maintained in Spokane',
            ]],
        ]);
        $this->command?->info('  Page: home seeded.');

        // --- How it works ---
        $this->seedPage($tenant, 'how-it-works', 'How it works', 'How Ground Control works · Spokane mobile bike service', false, [

            ['type' => 'nav', 'content' => ['show_logo'=>true,'cta_label'=>'Schedule pickup','cta_url'=>'/book','bg_style'=>'solid']],

            ['type' => 'hero', 'content' => [
                'eyebrow'           => 'How it works',
                'headline'          => "Service that comes to you.\nFour steps, door to door.",
                'accent_words'      => 'to you',
                'subheading'        => 'You book online. We pick up, service in the workshop, and return your bike ready to ride. Most jobs back within 24 hours.',
                'bg_type'           => 'color',
                'bg_color'          => '#0a0a0a',
                'cta_primary_label' => 'Schedule pickup',
                'cta_primary_url'   => '/book',
                'height'            => 'medium',
                'text_align'        => 'left',
            ]],

            ['type' => 'step_timeline', 'content' => [
                'eyebrow'    => 'The process',
                'heading'    => 'Door to door in 24 hours.',
                'subheading' => 'No shop trip required.',
                'steps'      => [
                    ['title' => 'Book online',  'desc' => 'Pick services and a pickup day. Most jobs $95–$295. Quote always confirmed after inspection.', 'done' => true],
                    ['title' => 'We pick up',   'desc' => 'We come to your door anywhere in Spokane. Free in our service zone. Quick visual check on pickup.', 'done' => true],
                    ['title' => 'We service',   'desc' => 'Fully equipped workshop. Most jobs turn around in 24 hours. You get a photo report when complete.', 'done' => true],
                    ['title' => 'We return',    'desc' => 'Back to your door, ready to ride. Pay on completion. 30–60 day warranty on labor.', 'done' => false],
                ],
            ]],

            ['type' => 'feature_grid', 'content' => [
                'eyebrow'    => 'Service area',
                'heading'    => 'Where we go.',
                'subheading' => 'Local zones get free pickup. Suspension work ships nationally.',
                'columns'    => 2,
                'features'   => [
                    ['icon' => '⟐', 'title' => 'Spokane & surrounding · Free pickup',
                     'body' => 'South Hill · North Side · Spokane Valley · Liberty Lake · Mead · Airway Heights. Door-to-door within 24h typically.'],
                    ['icon' => '✈', 'title' => 'National · Suspension only',
                     'body' => "Ship your fork, shock, or dropper to us — we'll send a label, service it, ship it back. Bike shops welcome to send customer work too."],
                ],
            ]],

            ['type' => 'cta_banner', 'content' => [
                'headline'   => 'Ready when you are.',
                'subheading' => "Book a pickup or send your suspension. We'll handle the rest.",
                'cta_label'  => 'Schedule pickup',
                'cta_url'    => '/book',
                'bg_color'   => '#0a0a0a',
            ]],

            ['type' => 'footer', 'content' => ['show_logo'=>true,'show_copyright'=>true,'copyright_text'=>'© 2026 Ground Control · Spokane, WA']],
        ]);
        $this->command?->info('  Page: how-it-works seeded.');

        // --- Book ---
        $this->seedPage($tenant, 'book', 'Book a pickup', 'Schedule mobile bike service · Ground Control', false, [

            ['type' => 'nav', 'content' => ['show_logo'=>true,'cta_label'=>'Schedule pickup','cta_url'=>'/book','bg_style'=>'solid']],

            ['type' => 'hero', 'content' => [
                'eyebrow'    => 'Online booking',
                'headline'   => 'Schedule a pickup.',
                'subheading' => "A few quick details — pick a service, your address, and a day that works. We'll confirm by text within an hour.",
                'bg_type'    => 'color',
                'bg_color'   => '#0a0a0a',
                'height'     => 'small',
                'text_align' => 'left',
            ]],

            ['type' => 'booking_embed', 'content' => [
                'heading' => 'Pick a service',
            ]],

            ['type' => 'feature_grid', 'content' => [
                'eyebrow'    => "What you'll need",
                'heading'    => 'A few quick details.',
                'subheading' => '',
                'columns'    => 4,
                'features'   => [
                    ['icon' => '①', 'title' => 'Your address',  'body' => 'For pickup & return.'],
                    ['icon' => '②', 'title' => 'Bike info',     'body' => 'Make, model, what brings it in.'],
                    ['icon' => '③', 'title' => 'Pickup window', 'body' => 'Pick a date that works.'],
                    ['icon' => '④', 'title' => 'Contact',       'body' => "We'll text to confirm."],
                ],
            ]],

            ['type' => 'cta_banner', 'content' => [
                'headline'   => 'Questions before you book?',
                'subheading' => "Email or call — we usually reply same day.",
                'cta_label'  => 'Ask a question',
                'cta_url'    => '/contact',
                'bg_color'   => '#0a0a0a',
            ]],

            ['type' => 'footer', 'content' => ['show_logo'=>true,'show_copyright'=>true,'copyright_text'=>'© 2026 Ground Control · Spokane, WA']],
        ]);
        $this->command?->info('  Page: book seeded.');

        // --- FAQ ---
        $this->seedPage($tenant, 'faq', 'FAQ', 'Frequently asked questions · Ground Control', false, [

            ['type' => 'nav', 'content' => ['show_logo'=>true,'cta_label'=>'Schedule pickup','cta_url'=>'/book','bg_style'=>'solid']],

            ['type' => 'hero', 'content' => [
                'eyebrow'    => 'Common questions',
                'headline'   => 'Answers, in plain English.',
                'subheading' => "Everything we get asked the most. Don't see your question? Send us a message and we'll add it.",
                'bg_type'    => 'color',
                'bg_color'   => '#0a0a0a',
                'height'     => 'small',
                'text_align' => 'left',
            ]],

            ['type' => 'faq_accordion', 'content' => [
                'eyebrow'    => '',
                'heading'    => 'Pickup & return',
                'subheading' => '',
                'items'      => [
                    ['q' => 'Where do you pick up?',        'a' => "Free pickup anywhere in Spokane and surrounding areas — South Hill, North Side, Valley, Liberty Lake, Mead, Airway Heights. Outside that radius, we may charge a small travel fee depending on distance."],
                    ['q' => 'What does pickup cost?',      'a' => "Free in our standard service zone. We'll let you know up front if your address falls outside."],
                    ['q' => 'How fast is turnaround?',     'a' => "Most jobs go out the next day. Tune-ups and small jobs are often same-day. Major work or special-order parts can take longer — we'll always quote a turnaround when you book."],
                    ['q' => 'What if my bike needs more work than expected?', 'a' => "We'll always quote first. If we find something on inspection — a worn cassette, a damaged tire — we'll text you with the additional cost before doing anything."],
                ],
            ]],

            ['type' => 'faq_accordion', 'content' => [
                'eyebrow'    => '',
                'heading'    => 'Service & pricing',
                'subheading' => '',
                'items'      => [
                    ['q' => 'How do you decide which tune-up I need?', 'a' => "Standard is great for a bike ridden regularly and in good shape. Full is the right call if it's been a year or more or if you ride hard. Complete is for end-of-season overhauls, neglected bikes, or anything you want stripped and brought back to like-new."],
                    ['q' => 'Do you sell parts?',                 'a' => "Yes — we keep common consumables (chains, cables, tubes, brake pads) in stock. Anything we don't have we'll order; turnaround depends on the part."],
                    ['q' => 'Do you work on e-bikes?',            'a' => "Yes, on mechanicals. Drive units and battery diagnostics we typically refer to the manufacturer's authorized service center."],
                    ['q' => 'Suspension service?',                'a' => "It's our specialty. Forks, rear shocks, and dropper posts. We service everything from RockShox and Fox to Öhlins, Marzocchi, and DVO."],
                ],
            ]],

            ['type' => 'faq_accordion', 'content' => [
                'eyebrow'    => '',
                'heading'    => 'Warranty & payment',
                'subheading' => '',
                'items'      => [
                    ['q' => 'How do I pay?',           'a' => "Card on completion, or contactless tap. Sometimes we take a deposit at booking for jobs over $200."],
                    ['q' => 'Is there a warranty?',    'a' => "30-day warranty on labor for Standard tune-ups. 60 days for Full tune-ups and Complete overhauls. Parts are covered by manufacturer warranty."],
                    ['q' => "What if I'm not happy?", 'a' => "Tell us — we'll make it right. Service businesses live and die on reputation; we'd rather fix it than have you leave unhappy."],
                ],
            ]],

            ['type' => 'cta_banner', 'content' => [
                'headline'   => "Didn't find your answer?",
                'subheading' => 'Send us a message — usually back the same day.',
                'cta_label'  => 'Ask a question',
                'cta_url'    => '/contact',
                'bg_color'   => '#0a0a0a',
            ]],

            ['type' => 'footer', 'content' => ['show_logo'=>true,'show_copyright'=>true,'copyright_text'=>'© 2026 Ground Control · Spokane, WA']],
        ]);
        $this->command?->info('  Page: faq seeded.');

        // --- Contact ---
        $this->seedPage($tenant, 'contact', 'Contact', 'Contact Ground Control · Spokane mobile bike service', false, [

            ['type' => 'nav', 'content' => ['show_logo'=>true,'cta_label'=>'Schedule pickup','cta_url'=>'/book','bg_style'=>'solid']],

            ['type' => 'hero', 'content' => [
                'eyebrow'    => 'Contact',
                'headline'   => 'Reach us directly.',
                'subheading' => "Phone, email, or the form below. Usual response: same day during business hours.",
                'bg_type'    => 'color',
                'bg_color'   => '#0a0a0a',
                'height'     => 'small',
                'text_align' => 'left',
            ]],

            ['type' => 'feature_grid', 'content' => [
                'eyebrow'  => 'Direct lines',
                'heading'  => '',
                'columns'  => 3,
                'features' => [
                    ['icon' => '☎', 'title' => 'Phone',   'body' => '(509) 262-4122 · Tue–Sat'],
                    ['icon' => '✉', 'title' => 'Email',   'body' => 'josh@grndctrl.co'],
                    ['icon' => '⌖', 'title' => 'Location','body' => 'Spokane, WA · By appointment'],
                ],
            ]],

            ['type' => 'contact_form', 'content' => [
                'eyebrow'      => '',
                'heading'      => 'Send a message',
                'subheading'   => 'What can we help with?',
                'show_phone'   => true,
                'show_message' => true,
            ]],

            ['type' => 'footer', 'content' => ['show_logo'=>true,'show_copyright'=>true,'copyright_text'=>'© 2026 Ground Control · Spokane, WA']],
        ]);
        $this->command?->info('  Page: contact seeded.');

        $this->command?->info("Done. Visit {$tenant->subdomain}.intake.works to preview.");
    }

    private function seedPage(Tenant $tenant, string $slug, string $title, string $metaTitle, bool $isHome, array $sections): void
    {
        $page = TenantPage::updateOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => $slug],
            [
                'title'        => $title,
                'meta_title'   => $metaTitle,
                'is_home'      => $isHome,
                'is_published' => true,
                'is_in_nav'    => ! $isHome,
                'nav_order'    => match ($slug) {
                    'home'         => 0,
                    'how-it-works' => 10,
                    'book'         => 20,
                    'faq'          => 30,
                    'contact'      => 40,
                    default        => 99,
                },
            ]
        );

        TenantPageSection::where('page_id', $page->id)->delete();

        foreach ($sections as $i => $sec) {
            TenantPageSection::create([
                'tenant_id'    => $tenant->id,
                'page_id'      => $page->id,
                'section_type' => $sec['type'],
                'content'      => $sec['content'],
                'sort_order'   => ($i + 1) * 10,
                'is_visible'   => true,
            ]);
        }
    }
}
