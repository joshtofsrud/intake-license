<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * puts a Feature tiles section on intake.works/features, filled with the
 * tiles from its Custom HTML section, and hides (does not delete) that Custom HTML section.
 * The footer line drops "No credit card to start" — signup takes a card.
 */
class MarketingInstallFeatureTiles extends Command
{
    protected $signature   = 'marketing:install-feature-tiles {--slug=features}';
    protected $description = 'Add the Feature tiles section to the intake.works Features page and hide its old Custom HTML tiles';

    public function handle(): int
    {
        $t = Tenant::where('is_platform', true)->first();
        $page = $t ? TenantPage::where('tenant_id', $t->id)->where('slug', (string) $this->option('slug'))->first() : null;
        if (! $page) { $this->error('No intake.works page with slug "' . $this->option('slug') . '".'); return self::FAILURE; }
        if (TenantPageSection::where('page_id', $page->id)->where('section_type', 'feature_tiles')->exists()) {
            $this->error('That page already has a Feature tiles section. Nothing changed.'); return self::FAILURE;
        }
        $old = TenantPageSection::where('page_id', $page->id)->where('section_type', 'custom_html')->where('is_visible', true)->get()
            ->first(fn ($s) => str_contains((string) json_encode($s->content), 'Work orders'));

        $html = $old ? (string) json_encode($old->content) : '';
        $withHead = str_contains($html, 'booking to pickup');
        $signup = 'https://app.intake.works/signup';
        $T = fn ($icon, $title, $body, $chips, $flow, $wide, $k, $h, $b, $pts, $addr) => [
            'icon' => $icon, 'title' => $title, 'body' => $body, 'chips' => $chips, 'flow' => $flow, 'wide' => $wide,
            'd_kicker' => $k, 'd_heading' => $h, 'd_body' => $b, 'd_points' => $pts, 'd_image' => '', 'd_image_alt' => '', 'd_address' => $addr,
            'd_cta1_label' => 'See it in the demo', 'd_cta1_url' => '/demo', 'd_cta2_label' => 'Start free trial', 'd_cta2_url' => $signup,
        ];
        $tiles = [
            $T('calendar', 'Online booking that turns into work', 'Customers pick a service and a time on your own website. Every booking lands on your calendar and becomes a work order automatically, with no re-typing.', "Booked\nChecked in\nIn progress\nReady\nPicked up", true, true,
               'Online booking', 'Bookings that set up the job for you', 'Customers choose a service, a time and how they want to drop off. You get a confirmed slot and a ready-made work order.', "Booking form on your own site, with your services and prices\nCalendar with staff and resources, so slots never double up\nDeposits or full payment taken at booking\nEach booking becomes a work order with the customer attached", 'yourshop.com/book'),
            $T('clipboard', 'Work orders', 'Status, charges, parts and staff notes in one place for every job.', '', false, false,
               'Work orders', 'Every job, in view', 'From the moment something comes in to the moment it goes home, the whole team sees where it is.', "Status from booked to picked up\nParts and labour added as you go\nStaff notes on the job\nCustomers get a message when it is ready", 'app.intake.works/work-orders'),
            $T('register', 'Point of sale', 'Walk-in sales, quotes and returns. Stock updates the moment a sale is finished.', '', false, false,
               'Point of sale', 'The counter, without a second system', 'Ring up items, services and gift cards in the same place your bookings live.', "Sales, quotes, refunds and returns\nGift cards and discount codes\nReceipts by text or email\nStock updates as soon as a sale is done", 'app.intake.works/register'),
            $T('people', 'Customer records', 'Built from every booking and sale: history, spend and notes, without data entry.', '', false, false,
               'Customer records', 'Know who walks in', 'Every booking and sale adds to the customer’s record, so the history is there when they call.', "Visit and purchase history\nLifetime spend and last visit\nStaff notes on the customer\nBuilt automatically, no data entry", 'app.intake.works/customers'),
            $T('mail', 'Win-back emails', 'Spot customers who’ve drifted away and bring them back with campaigns.', '', false, false,
               'Win-back emails', 'Bring regulars back', 'Intake notices customers who have gone quiet and lets you invite them back with an email.', "Email campaigns to customers who opted in\nCustomers who have drifted away, found for you\nUnsubscribes handled for you\nSee who opened and who came back", 'app.intake.works/campaigns'),
            $T('globe', 'Your brand, your website', 'A full website builder on your own domain. Customers see your name and colours, not ours.', "Page builder\nOwn domain\nOnline booking\nGift cards", false, false,
               'Website', 'A site that books for you', 'Build your site with the same tool that runs the shop, so booking and gift cards are built in.', "Page builder with ready-made sections\nYour own domain on Branded and Scale\nBooking and gift cards on any page\nSearch-friendly pages and share previews", 'yourshop.com'),
            $T('chart', 'Grows with the shop', 'Add the pieces you need when you need them: rentals, an online store, staff scheduling and more.', "Rentals\nOnline store\nTeam & scheduling\nReports", false, true,
               'Add-ons', 'Turn on what you need', 'Start with the core and add the rest when the shop is ready for it.', "Rentals with a fleet and online booking\nAn online store from your inventory\nStaff scheduling and time clock\nReports on sales, jobs and customers", 'app.intake.works/settings'),
        ];
        $content = [
            'eyebrow' => '', 'heading' => $withHead ? 'Everything in your shop, from booking to pickup.' : '', 'accent_words' => $withHead ? 'from booking to pickup.' : '',
            'subheading' => $withHead ? 'Booking, work orders, point of sale and your customer records share one login and one set of data. Use all of it, or just the pieces you need.' : '',
            'tiles' => $tiles, 'footer_text' => 'Live in under 10 minutes.', 'footer_cta_label' => 'Start your free trial →', 'footer_cta_url' => $signup,
            'accent_color' => '', 'anchor_id' => '', 'custom_classes' => '', 'hide_on_mobile' => false, 'hide_on_desktop' => false,
        ];

        DB::transaction(function () use ($page, $t, $old, $content) {
            $at = $old ? (int) $old->sort_order : ((int) TenantPageSection::where('page_id', $page->id)->max('sort_order') + 10);
            TenantPageSection::where('page_id', $page->id)->where('sort_order', '>=', $at)->increment('sort_order');
            TenantPageSection::create([
                'page_id' => $page->id, 'tenant_id' => $t->id, 'section_type' => 'feature_tiles', 'content' => $content,
                'bg_color' => $old->bg_color ?? null, 'padding' => ($old->padding ?? null) ?: 'normal', 'is_visible' => true, 'sort_order' => $at,
            ]);
            if ($old) $old->update(['is_visible' => false]);
        });

        $this->info('Feature tiles added to /' . $page->slug . ' with 7 tiles and drawers' . ($content['heading'] ? ' (and the page heading).' : '.'));
        $this->line($old ? 'The old Custom HTML tiles section is hidden, not deleted — delete it in the builder once you are happy.' : 'No Custom HTML tiles section was found, so the new section went at the end of the page.');
        $this->line('Drawer screenshots are empty: add them per tile in the builder.');
        return self::SUCCESS;
    }
}
