<?php

namespace Database\Seeders;

use App\Models\PlatformCampaign;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Sample draft campaigns for master admin › Communication › Campaigns.
 *
 * Drafts only: no audience, never scheduled, so nothing can send until you
 * pick an audience and press Send. Figures in [brackets] are placeholders to
 * replace with real ones. The screenshots are real Intake screens, copied
 * into public storage from database/seeders/assets.
 *
 * Safe to run twice. A sample you haven't edited is refreshed to this
 * version; one you've edited is kept, except that still-empty screenshot
 * blocks get their picture.
 *
 *   php artisan db:seed --class=PlatformCampaignSamplesSeeder --force
 */
class PlatformCampaignSamplesSeeder extends Seeder
{
    public function run(): void
    {
        $wo     = $this->asset('work-order.png');            // staff work order
        $portal = $this->asset('customer-work-order.png');   // the customer's own page

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
                    ['type' => 'feat_tray', 'data' => ['url' => $wo, 'tab' => 'Work order', 'caption' => 'Prices come from the catalog, not the keyboard.']],
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
                    ['type' => 'feat_tray', 'data' => ['url' => $wo, 'tab' => 'Work order', 'caption' => '']],
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
                    ['type' => 'feat_annotated', 'data' => ['url' => $wo, 'markers' => [
                        ['x' => 71, 'y' => 53, 'title' => 'Prices that keep themselves.', 'note' => "Each distributor's catalog is priced your way and updated as it changes."],
                        ['x' => 41, 'y' => 79, 'title' => 'One item, not three.', 'note' => 'The same part from three distributors is merged into one.'],
                        ['x' => 80, 'y' => 88, 'title' => 'The total, as you go.', 'note' => 'Every service and part adds up on the ticket while you work, not at month end.'],
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
                    ['type' => 'feat_annotated', 'data' => ['url' => $portal, 'markers' => [
                        ['x' => 60, 'y' => 19, 'title' => "Where it's at.", 'note' => 'Status and a ready-by date, updated as you move the job along.'],
                        ['x' => 25, 'y' => 58, 'title' => 'Every part, priced.', 'note' => 'Each service and part with its price, so the bill is never a surprise.'],
                        ['x' => 96, 'y' => 14, 'title' => 'Message about this.', 'note' => 'Questions arrive in your inbox tied to the job.'],
                    ]]],
                    $demo,
                    $sign,
                ],
            ],

            // E: investor update, November 2026 (real numbers, pulled Nov 16)
            [
                'name'      => 'Sample E · Investor update · November',
                'subject'   => 'Intake, November: $78k through two shops',
                'preheader' => '713 sales this year, and our founding shop doubled in October.',
                'theme'     => 'light',
                'blocks'    => [
                    ['type' => 'feat_hero', 'data' => ['label' => 'Investor update · November 2026', 'text' => '$78,098 through Intake this year.']],
                    ['type' => 'letter_text', 'data' => ['text' => "Hi {first_name},\n\nA quick look at the month. Oakridge Bike Shop, our founding shop, started ringing up sales on Intake in September. In October it rang up 326, more than twice September's dollars."]],
                    ['type' => 'feat_tiles', 'data' => ['tiles' => [
                        ['label' => 'Processed in 2026', 'value' => '$78,098', 'note' => '713 sales at two shops'],
                        ['label' => 'Oakridge, October', 'value' => '$32,733', 'note' => 'up from $15,808 in September'],
                        ['label' => 'Customers on file', 'value' => '1,761', 'note' => 'across both shops'],
                        ['label' => 'Items sold', 'value' => '1,304', 'note' => 'rung up through the register'],
                    ]]],
                    ['type' => 'letter_numbers', 'data' => [
                        'headers' => ['', 'September', 'October', 'Change'],
                        'rows'    => [
                            ['Oakridge sales', '200', '326', '+63%'],
                            ['Oakridge dollars', '$15,808', '$32,733', '+107%'],
                            ['Ground Control dollars', '$1,353', '$3,943', '+191%'],
                        ],
                    ]],
                    ['type' => 'feat_divider', 'data' => []],
                    ['type' => 'feat_section', 'data' => ['style' => 'square', 'num' => '01', 'text' => 'What shipped']],
                    ['type' => 'feat_headline', 'data' => ['text' => "Customers can see their own work order."]],
                    ['type' => 'feat_lede', 'data' => ['text' => "Every customer now has a page for their job: status, each part and its price, what's paid and what's owed, and a button to message the shop about it. Fewer \"is it ready?\" calls for the shop, and a reason for customers to come back to Intake."]],
                    ['type' => 'feat_annotated', 'data' => ['url' => $portal, 'markers' => [
                        ['x' => 60, 'y' => 19, 'title' => 'Live status.', 'note' => 'The job moves along as the shop works.'],
                        ['x' => 25, 'y' => 58, 'title' => 'Every part, priced.', 'note' => 'No surprises at pickup.'],
                        ['x' => 96, 'y' => 14, 'title' => 'Message the shop.', 'note' => 'Lands in the shop inbox, tied to the job.'],
                    ]]],
                    ['type' => 'letter_text', 'data' => ['text' => "Also this month:\n\nRedesigned work-order pages for staff, faster and clearer on a phone.\nEmail campaigns from Intake itself, with the layouts in this email.\nOutreach at scale: a list of over 100,000 prospect shops, emailed one at a time at a steady pace so it reads like a person, not a blast. About 3,000 shops have heard from us so far."]],
                    ['type' => 'letter_ask', 'data' => ['title' => 'What would help', 'items' => "Intros to shop owners who'd try Intake\n[Raise: where the round stands and what's left]"]],
                    ['type' => 'letter_signature', 'data' => ['text' => "Thanks for backing this.\n— Josh", 'small' => 'Josh Tofsrud · Founder, Intake · josh@intake.works']],
                ],
            ],
        ];

        foreach ($campaigns as $c) {
            $have = PlatformCampaign::where('name', $c['name'])->first();
            if (! $have) {
                PlatformCampaign::create($c + ['status' => 'draft']);
                continue;
            }
            // never touched since it was seeded: refresh it to this version
            if ($have->isEditable() && $have->updated_at?->equalTo($have->created_at)) {
                $have->update($c);
                $have->forceFill(['updated_at' => $have->created_at])->saveQuietly();
                continue;
            }
            // edited since: only give still-empty screenshots their picture
            $blocks = (array) $have->blocks;
            foreach ($blocks as $i => $b) {
                if (in_array($b['type'] ?? '', ['feat_tray', 'feat_annotated'], true) && ($b['data']['url'] ?? '') === '') {
                    $match = collect($c['blocks'])->first(fn ($n) => ($n['type'] ?? '') === $b['type']);
                    if ($match) $blocks[$i]['data']['url'] = $match['data']['url'];
                }
            }
            if ($have->isEditable()) $have->update(['blocks' => $blocks]);
        }
    }

    /** Copy a sample screenshot into public storage; returns its absolute URL. */
    private function asset(string $file): string
    {
        $to = 'platform-letters/samples/' . $file;
        if (! Storage::disk('public')->exists($to)) {
            Storage::disk('public')->put($to, file_get_contents(database_path('seeders/assets/platform-letters/' . $file)));
        }
        return url('/storage/' . $to);
    }
}
