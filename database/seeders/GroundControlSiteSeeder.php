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

        // ----------------------------------------------------------------
        // Section types used: nav, hero, services, text_image, cta_banner,
        // contact_form, booking_embed, footer. These are the types with
        // public partials at resources/views/public/sections/_*.blade.php.
        // Some content is represented via text_image with multi-line body
        // (white-space: pre-line on the body keeps line breaks).
        // ----------------------------------------------------------------

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

            ['type' => 'text_image', 'content' => [
                'eyebrow'        => 'Why mobile',
                'heading'        => 'Service without the trip.',
                'body'           => "→ Pickup & return — Free in Spokane & surrounding\n24h turnaround — Most jobs back the next day\n$ Quote first — No surprise charges, ever\n◫ Photo report — Every job documented",
                'image_url'      => '',
                'image_position' => 'right',
            ]],

            ['type' => 'services', 'content' => [
                'heading'     => 'Full-service workshop, on wheels.',
                'show_prices' => true,
                'columns'     => 3,
            ]],

            ['type' => 'text_image', 'content' => [
                'eyebrow'        => 'Suspension specialty',
                'heading'        => 'Forks, shocks & dropper posts.',
                'body'           => "Out-of-area suspension shipments welcome — we'll send you a label.\n\n• Lower service — \$90 & up. Legs/sleeve off, cleaned, new wipers & oil.\n• Full rebuild — \$150 & up. Teardown, damper rebuild, air spring service.\n• Dropper post — \$120 & up. Teardown, all new o-rings, seals, & oil.\n\nSuspension service from RockShox, Fox, Marzocchi, Öhlins, and DVO.",
                'image_position' => 'left',
                'cta_label'      => 'Schedule a pickup',
                'cta_url'        => '/book',
            ]],

            ['type' => 'text_image', 'content' => [
                'eyebrow'        => 'How it works',
                'heading'        => 'Four steps, door to door.',
                'body'           => "No parking, no waiting room, no carrying your bike across town.\n\n01 — Book online. Pick services and a pickup day.\n02 — We pick up. We come to you in Spokane.\n03 — We service. Most jobs turn around within 24 hours.\n04 — We return. Back to your door, ready to ride.",
                'image_position' => 'right',
                'cta_label'      => 'See the full breakdown',
                'cta_url'        => '/how-it-works',
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
                'text_color'        => '#ffffff',
                'cta_primary_label' => 'Schedule pickup',
                'cta_primary_url'   => '/book',
                'height'            => 'medium',
                'text_align'        => 'left',
            ]],

            ['type' => 'text_image', 'content' => [
                'eyebrow'        => 'The process',
                'heading'        => 'Door to door in 24 hours.',
                'body'           => "01 — Book online\nPick services and a pickup day. Most jobs \$95–\$295. Quote always confirmed after inspection.\n\n02 — We pick up\nWe come to your door anywhere in Spokane. Free in our service zone. Quick visual check on pickup.\n\n03 — We service\nFully equipped workshop. Most jobs turn around in 24 hours. You get a photo report when complete.\n\n04 — We return\nBack to your door, ready to ride. Pay on completion. 30–60 day warranty on labor.",
                'image_position' => 'right',
            ]],

            ['type' => 'text_image', 'content' => [
                'eyebrow'        => 'Service area',
                'heading'        => 'Where we go.',
                'body'           => "⟐ Spokane & surrounding — Free pickup\nSouth Hill · North Side · Spokane Valley · Liberty Lake · Mead · Airway Heights. Door-to-door within 24h typically.\n\n✈ National — Suspension only\nShip your fork, shock, or dropper to us — we'll send a label, service it, ship it back. Bike shops welcome to send customer work too.",
                'image_position' => 'left',
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
                'text_color' => '#ffffff',
                'height'     => 'small',
                'text_align' => 'left',
            ]],

            ['type' => 'booking_embed', 'content' => [
                'heading' => 'Pick a service',
            ]],

            ['type' => 'text_image', 'content' => [
                'eyebrow'        => "What you'll need",
                'heading'        => 'A few quick details.',
                'body'           => "① Your address — For pickup & return.\n② Bike info — Make, model, what brings it in.\n③ Pickup window — Pick a date that works.\n④ Contact — We'll text to confirm.",
                'image_position' => 'right',
            ]],

            ['type' => 'cta_banner', 'content' => [
                'headline'   => 'Questions before you book?',
                'subheading' => 'Email or call — we usually reply same day.',
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
                'text_color' => '#ffffff',
                'height'     => 'small',
                'text_align' => 'left',
            ]],

            ['type' => 'text_image', 'content' => [
                'eyebrow'        => '',
                'heading'        => 'Pickup & return',
                'body'           => "Q: Where do you pick up?\nFree pickup anywhere in Spokane and surrounding areas — South Hill, North Side, Valley, Liberty Lake, Mead, Airway Heights. Outside that radius, we may charge a small travel fee.\n\nQ: What does pickup cost?\nFree in our standard service zone. We'll let you know up front if your address falls outside.\n\nQ: How fast is turnaround?\nMost jobs go out the next day. Tune-ups and small jobs are often same-day. Major work or special-order parts can take longer.\n\nQ: What if my bike needs more work than expected?\nWe'll always quote first. If we find something on inspection, we'll text you with the additional cost before doing anything.",
                'image_position' => 'right',
            ]],

            ['type' => 'text_image', 'content' => [
                'eyebrow'        => '',
                'heading'        => 'Service & pricing',
                'body'           => "Q: How do you decide which tune-up I need?\nStandard is great for a bike ridden regularly and in good shape. Full is right if it's been a year or more or if you ride hard. Complete is for end-of-season overhauls, neglected bikes, or anything you want stripped and brought back to like-new.\n\nQ: Do you sell parts?\nYes — common consumables (chains, cables, tubes, brake pads) in stock. Anything else we'll order.\n\nQ: Do you work on e-bikes?\nYes, on mechanicals. Drive units and battery diagnostics we typically refer to the manufacturer.\n\nQ: Suspension service?\nIt's our specialty. Forks, rear shocks, dropper posts. RockShox, Fox, Öhlins, Marzocchi, DVO.",
                'image_position' => 'left',
            ]],

            ['type' => 'text_image', 'content' => [
                'eyebrow'        => '',
                'heading'        => 'Warranty & payment',
                'body'           => "Q: How do I pay?\nCard on completion, or contactless tap. Deposits sometimes at booking for jobs over \$200.\n\nQ: Is there a warranty?\n30-day warranty on labor for Standard tune-ups. 60 days for Full and Complete. Parts under manufacturer warranty.\n\nQ: What if I'm not happy?\nTell us — we'll make it right.",
                'image_position' => 'right',
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
                'text_color' => '#ffffff',
                'height'     => 'small',
                'text_align' => 'left',
            ]],

            ['type' => 'text_image', 'content' => [
                'eyebrow'        => 'Direct lines',
                'heading'        => 'Get in touch.',
                'body'           => "☎ Phone\n(509) 262-4122 · Tue–Sat\n\n✉ Email\njosh@grndctrl.co\n\n⌖ Location\nSpokane, WA · By appointment",
                'image_position' => 'right',
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
