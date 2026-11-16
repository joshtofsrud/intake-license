<?php

namespace Database\Seeders;

use App\Models\PlatformCampaign;
use Illuminate\Database\Seeder;

/**
 * Sample draft campaigns for master admin › Communication › Campaigns.
 *
 * Drafts only: no audience, never scheduled, so nothing can send until you
 * pick an audience and press Send. Figures in [brackets] are placeholders to
 * replace with real ones. Screenshot blocks are left empty: choose an image
 * in the composer (the annotated one keeps its three markers).
 *
 * Safe to run twice: a campaign with the same name is left alone.
 *
 *   php artisan db:seed --class=PlatformCampaignSamplesSeeder --force
 */
class PlatformCampaignSamplesSeeder extends Seeder
{
    public function run(): void
    {
        $sign = ['type' => 'letter_signature', 'data' => ['text' => '— Josh', 'small' => 'Josh Tofsrud · Founder, Intake · josh@intake.works']];
        $demo = ['type' => 'feat_button', 'data' => ['label' => 'See it in the demo', 'url' => 'https://intake.works/demo']];

        $campaigns = [
            // A: lime hero + receipt
            [
                'name'      => 'Sample A · Margin · Receipt',
                'subject'   => 'Finding the margin you already have',
                'preheader' => 'One shop, one month, zero prices typed by hand.',
                'theme'     => 'light',
                'blocks'    => [
                    ['type' => 'feat_hero', 'data' => ['label' => 'Work order · Nov 2026', 'text' => 'Finding the margin you already have.']],
                    ['type' => 'letter_text', 'data' => ['text' => "Hi {first_name},\n\nHolding a 41% margin is close to impossible when prices are typed in by hand, or a season out of date. Here's what changed at one shop in a month."]],
                    ['type' => 'feat_receipt', 'data' => ['title' => 'One shop · 30 days', 'rows' => [['Duplicates merged', '[1,184]'], ['Prices updated', '[6,920]'], ['Typed by hand', '0']], 'total_label' => 'Margin', 'total_value' => '[41.6%]']],
                    ['type' => 'feat_divider', 'data' => []],
                    ['type' => 'feat_section', 'data' => ['style' => 'square', 'num' => '03', 'text' => 'How Intake fixes it']],
                    ['type' => 'feat_headline', 'data' => ['text' => 'Let the catalog keep itself.']],
                    ['type' => 'feat_lede', 'data' => ['text' => "Each distributor's catalog is priced the way you choose and kept current. The same part from three distributors becomes one item, and the register, the service desk and the web store all read the same list."]],
                    ['type' => 'feat_tray', 'data' => ['url' => '', 'tab' => 'Register', 'caption' => 'The register at our founding shop.']],
                    $demo,
                    $sign,
                ],
            ],
            // B: dashboard colour tiles, dark
            [
                'name'      => 'Sample B · Margin · Tiles',
                'subject'   => 'Finding the margin you already have',
                'preheader' => 'One shop\'s month, in the tiles they see every morning.',
                'theme'     => 'dark',
                'blocks'    => [
                    ['type' => 'feat_display', 'data' => ['text' => 'Finding the margin you already have.']],
                    ['type' => 'feat_lede', 'data' => ['text' => "Holding a 41% margin is close to impossible when prices are typed in by hand. Here's one shop's month, in the same tiles they see every morning."]],
                    ['type' => 'feat_tiles', 'data' => ['tiles' => [
                        ['label' => 'Duplicates merged', 'value' => '[1,184]', 'note' => 'across three distributors'],
                        ['label' => 'Margin', 'value' => '[41.6%]', 'note' => 'up from [37.9%]'],
                        ['label' => 'Prices kept current', 'value' => '[6,920]', 'note' => 'none typed by hand'],
                        ['label' => 'Catalog', 'value' => 'One list.', 'note' => 'register, service desk and web store'],
                    ]]],
                    ['type' => 'feat_headline', 'data' => ['text' => 'Let the catalog keep itself.']],
                    ['type' => 'feat_lede', 'data' => ['text' => "Each distributor's catalog is priced the way you choose and kept current, and the same part from three places becomes one item."]],
                    ['type' => 'feat_tray', 'data' => ['url' => '', 'tab' => 'Work order', 'caption' => '']],
                    $demo,
                    $sign,
                ],
            ],
            // C: one annotated screen
            [
                'name'      => 'Sample C · Margin · Annotated',
                'subject'   => 'Three things that keep your margin from slipping',
                'preheader' => 'All on one screen.',
                'theme'     => 'light',
                'blocks'    => [
                    ['type' => 'feat_display', 'data' => ['text' => 'Finding the margin you already have.']],
                    ['type' => 'feat_lede', 'data' => ['text' => 'Hi {first_name}, three things on one screen that keep a 41% margin from slipping.']],
                    ['type' => 'feat_annotated', 'data' => ['url' => '', 'markers' => [
                        ['x' => 28, 'y' => 17, 'title' => 'Prices that keep themselves.', 'note' => "Each distributor's catalog is priced your way and updated as it changes."],
                        ['x' => 52, 'y' => 55, 'title' => 'One item, not three.', 'note' => 'The same part from three distributors is merged into one.'],
                        ['x' => 87, 'y' => 82, 'title' => 'The margin, on every ticket.', 'note' => 'Totals and margin show as the sale is rung up, not at month end.'],
                    ]]],
                    ['type' => 'feat_button', 'data' => ['label' => 'Take the tour in the demo', 'url' => 'https://intake.works/demo']],
                    $sign,
                ],
            ],
            // D: the customer portal, receipt style
            [
                'name'      => 'Sample D · Customer portal · "Is it ready?"',
                'subject'   => 'The "is it ready?" call, gone',
                'preheader' => 'Customers check their own work order. You keep working.',
                'theme'     => 'light',
                'blocks'    => [
                    ['type' => 'feat_hero', 'data' => ['label' => 'Work order · Dec 2026', 'text' => 'Stop answering the phone to say "not yet."']],
                    ['type' => 'letter_text', 'data' => ['text' => "Hi {first_name},\n\nEvery shop knows the call. It comes mid-job, the answer is \"tomorrow,\" and it costs ten minutes you don't get back. Here's what changed when customers could check for themselves."]],
                    ['type' => 'feat_receipt', 'data' => ['title' => 'One shop · 30 days', 'rows' => [['"Is it ready?" calls', '[42] → [9]'], ['Parts approved by the customer', '[31]'], ['Receipts reprinted by staff', '0']], 'total_label' => 'Time back', 'total_value' => '[6 hrs]']],
                    ['type' => 'feat_divider', 'data' => []],
                    ['type' => 'feat_section', 'data' => ['style' => 'square', 'num' => '04', 'text' => 'How it works']],
                    ['type' => 'feat_headline', 'data' => ['text' => 'The work order, in their pocket.']],
                    ['type' => 'feat_lede', 'data' => ['text' => "Every customer gets a page for their job: status, what's being done, each part and its price, and what's still owed. When you add a part, they approve it there. When they have a question, it lands in your inbox attached to that work order."]],
                    ['type' => 'feat_annotated', 'data' => ['url' => '', 'markers' => [
                        ['x' => 22, 'y' => 15, 'title' => "Where it's at.", 'note' => 'Status and a ready-by date, updated as you move the job along.'],
                        ['x' => 50, 'y' => 52, 'title' => 'Yes, go ahead.', 'note' => "New parts wait for the customer's OK, with the price shown."],
                        ['x' => 80, 'y' => 80, 'title' => 'Message about this.', 'note' => 'Questions arrive tied to the job.'],
                    ]]],
                    $demo,
                    $sign,
                ],
            ],
        ];

        foreach ($campaigns as $c) {
            PlatformCampaign::firstOrCreate(['name' => $c['name']], $c + ['status' => 'draft']);
        }
    }
}
