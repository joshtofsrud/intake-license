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
        $a = fn (string $f) => $this->asset($f);

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

            // ---- Bike shop series (prospects). Every claim checked against the code, Nov 16.
            // 1: the service desk
            [
                'name'      => 'Bike shops 1 · Service desk',
                'subject'   => 'Two bikes, one ticket, zero phone tag',
                'preheader' => 'Work orders built the way a bike shop actually runs.',
                'theme'     => 'light',
                'sender'    => 'outreach',
                'blocks'    => [
                    ['type' => 'feat_hero', 'data' => ['label' => 'Service desk · 1 of 4', 'text' => 'The service desk, without the sticky notes.']],
                    ['type' => 'letter_text', 'data' => ['text' => "Hi {first_name},\n\nI've spent over twenty years in bike shops, and the service desk was always where the day fell apart: two bikes from one family on two tickets, a price typed from memory, a customer calling to ask if it's done. Here's the work order I built to fix that."]],
                    ['type' => 'feat_annotated', 'data' => ['url' => $a('sd-work-order.jpg'), 'markers' => [
                        ['x' => 27, 'y' => 24, 'title' => 'Every bike on one ticket.', 'note' => 'Each bike gets its own services, parts and serial number, and it all adds up to one bill.'],
                        ['x' => 48, 'y' => 43, 'title' => 'Your service menu, one tap away.', 'note' => 'Tune levels, bleeds, wheel work and e-bike jobs, with your prices and times.', 'shot' => $a('sd-service-menu.jpg')],
                        ['x' => 97, 'y' => 19, 'title' => 'The customer hears from you.', 'note' => 'A confirmation when they book, a reminder the day before, and an itemized receipt when you mark it done, with every part and service on it.', 'shot' => $a('sd-receipt-email.jpg')],
                        ['x' => 96, 'y' => 94, 'title' => 'Drop-off, pickup or delivery.', 'note' => 'When the bike is ready, the customer picks a delivery window from their phone, or asks you to call.', 'shot' => $a('sd-delivery-windows.jpg')],
                    ]]],
                    ['type' => 'letter_text', 'data' => ['text' => "Customers also get their own page for the job: where it's at, what's being done and what it costs, without calling you."]],
                    ['type' => 'feat_button', 'data' => ['label' => 'See the service desk', 'url' => 'https://intake.works/demo']],
                    ['type' => 'letter_signature', 'data' => ['text' => '— Josh', 'small' => 'Josh Tofsrud · Founder, Intake · josh@intake.works']],
                ],
            ],
            // 2: rentals, last-minute extension offers
            [
                'name'      => 'Bike shops 2 · Rental extensions',
                'subject'   => 'The rental that pays you to stay out longer',
                'preheader' => 'When nobody has the bike booked next, Intake offers the renter more time.',
                'theme'     => 'light',
                'sender'    => 'outreach',
                'blocks'    => [
                    ['type' => 'feat_hero', 'data' => ['label' => 'Rentals · 2 of 4', 'text' => 'Keep it longer?']],
                    ['type' => 'letter_text', 'data' => ['text' => "Hi {first_name},\n\nA rental e-bike coming back at 10 AM on a quiet Sunday just sits on the floor. Intake notices when nobody has that bike booked next and texts the renter before it's due: keep it longer, at a discount you set. They tap, pay, and the due time moves on its own."]],
                    ['type' => 'feat_receipt', 'data' => ['title' => 'One offer, start to finish', 'rows' => [['Due back', '10:00 AM'], ['Offer texted', '8:30 AM'], ['Nobody booked next', 'checked'], ['Extended to', '6:00 PM'], ['Your discount', '20%']], 'total_label' => 'Extra rental', 'total_value' => '$65.34']],
                    ['type' => 'feat_tray', 'data' => ['url' => $a('rt-offer.jpg'), 'tab' => 'What the renter sees', 'caption' => 'One tap to extend and pay. "No thanks" is right there too.']],
                    ['type' => 'feat_divider', 'data' => []],
                    ['type' => 'feat_section', 'data' => ['style' => 'square', 'num' => '02', 'text' => 'On your side']],
                    ['type' => 'feat_headline', 'data' => ['text' => 'Every offer, and what it earned.']],
                    ['type' => 'feat_annotated', 'data' => ['url' => $a('rt-offers.jpg'), 'markers' => [
                        ['x' => 75, 'y' => 34, 'title' => 'Money you would have left on the floor.', 'note' => 'Revenue from accepted offers over the last 30 days.'],
                        ['x' => 51, 'y' => 34, 'title' => 'How often they say yes.', 'note' => 'Accepted, declined and expired, side by side.'],
                        ['x' => 97, 'y' => 72, 'title' => 'Paid means done.', 'note' => 'No call, no re-booking: the rental moves itself.'],
                    ]]],
                    ['type' => 'letter_text', 'data' => ['text' => "You choose the discount, how long before return the offer goes out, and quiet hours when nothing gets sent."]],
                    ['type' => 'feat_button', 'data' => ['label' => 'See rentals in the demo', 'url' => 'https://intake.works/demo']],
                    ['type' => 'letter_signature', 'data' => ['text' => '— Josh', 'small' => 'Josh Tofsrud · Founder, Intake · josh@intake.works']],
                ],
            ],
            // 3: the online store
            [
                'name'      => 'Bike shops 3 · Online store',
                'subject'   => 'Your web store already knows what you have',
                'preheader' => 'One stock count for the counter, the service desk and the web.',
                'theme'     => 'dark',
                'sender'    => 'outreach',
                'blocks'    => [
                    ['type' => 'feat_display', 'data' => ['text' => 'One stock count. Counter, bench and web.']],
                    ['type' => 'feat_lede', 'data' => ['text' => "Hi {first_name}, most shop websites are a second inventory someone has to keep in sync. Intake's store sells from the same stock as your register and your work orders, so a tube sold at the counter is gone online too."]],
                    ['type' => 'feat_tiles', 'data' => ['tiles' => [
                        ['label' => 'Stock', 'value' => 'One count', 'note' => 'register, work orders and web'],
                        ['label' => 'Getting it to them', 'value' => '3 ways', 'note' => 'pickup, local delivery or shipping'],
                        ['label' => 'Photos', 'value' => 'Done for you', 'note' => "from your distributor's catalog"],
                        ['label' => 'Google', 'value' => 'Shopping feed', 'note' => 'your products, listed on Google'],
                    ]]],
                    ['type' => 'feat_annotated', 'data' => ['url' => $a('st-orders.jpg'), 'markers' => [
                        ['x' => 58.5, 'y' => 43, 'title' => 'Pickup, delivery or shipping.', 'note' => 'Each order says how it gets to them, and moves from paid to fulfilled in one place.', 'shot' => $a('st-order.jpg')],
                        ['x' => 32.5, 'y' => 73, 'title' => "It's the same shelf.", 'note' => 'Paid orders ring through your register, so stock and the day’s numbers stay right.', 'shot' => $a('st-inventory.jpg')],
                    ]]],
                    ['type' => 'feat_button', 'data' => ['label' => 'See the store in the demo', 'url' => 'https://intake.works/demo']],
                    ['type' => 'letter_signature', 'data' => ['text' => '— Josh', 'small' => 'Josh Tofsrud · Founder, Intake · josh@intake.works']],
                ],
            ],
            // 4: buying and reports
            [
                'name'      => 'Bike shops 4 · Buying and reports',
                'subject'   => 'Your customers already told you what to order',
                'preheader' => 'Searches that found nothing, at the register and online.',
                'theme'     => 'light',
                'sender'    => 'outreach',
                'blocks'    => [
                    ['type' => 'feat_hero', 'data' => ['label' => 'Buying · 4 of 4', 'text' => 'Order what people asked for.']],
                    ['type' => 'letter_text', 'data' => ['text' => "Hi {first_name},\n\nEvery time someone at your counter or on your website searches for something you don't have, that's a sale walking out. Intake keeps that list for you, next to what's actually selling and how many days of stock you have left."]],
                    ['type' => 'feat_receipt', 'data' => ['title' => 'Searched, not stocked · example', 'rows' => [['tubeless sealant (online)', '16'], ['12-speed quick link (register)', '12'], ['Vittoria Corsa 700x30 (register)', '10'], ['kids helmet (online)', '6']], 'total_label' => 'Customers who left without it', 'total_value' => '44']],
                    ['type' => 'feat_annotated', 'data' => ['url' => $a('by-moving.jpg'), 'markers' => [
                        ['x' => 18, 'y' => 12, 'title' => 'Searched, not stocked.', 'note' => 'Register and web searches that found nothing in stock, counted once even when typed letter by letter.', 'shot' => $a('by-searched.jpg')],
                        ['x' => 62, 'y' => 12, 'title' => 'What runs out first.', 'note' => 'Every item that sold, sorted from out of stock to overstocked.'],
                        ['x' => 56, 'y' => 60, 'title' => 'Days left, not just on hand.', 'note' => 'Stock divided by how fast it actually sells, with what is already on order.'],
                        ['x' => 82, 'y' => 60, 'title' => 'Straight to a PO.', 'note' => 'Adds the line to a draft for that vendor. Nothing is sent until you place it.'],
                    ]]],
                    ['type' => 'feat_divider', 'data' => []],
                    ['type' => 'feat_section', 'data' => ['style' => 'square', 'num' => '04', 'text' => 'Reports that answer something']],
                    ['type' => 'feat_tray', 'data' => ['url' => $a('by-search-report.jpg'), 'tab' => 'Shop search', 'caption' => 'What shoppers typed, what they clicked and what they added to the cart.']],
                    ['type' => 'feat_tray', 'data' => ['url' => $a('by-traffic.jpg'), 'tab' => 'Traffic', 'caption' => 'Visitors, and how many of them finished booking a repair.']],
                    ['type' => 'feat_button', 'data' => ['label' => 'See Buying in the demo', 'url' => 'https://intake.works/demo']],
                    ['type' => 'letter_signature', 'data' => ['text' => '— Josh', 'small' => 'Josh Tofsrud · Founder, Intake · josh@intake.works']],
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

            // F: investor update, two months of building (no shop names; numbers pulled Nov 16)
            [
                'name'      => 'Sample F · Investor update · Two months of building',
                'subject'   => 'Intake: $78k processed, and what shop owners are telling us',
                'preheader' => 'October sales more than doubled, and the shop calls keep running long.',
                'theme'     => 'light',
                'blocks'    => [
                    ['type' => 'feat_hero', 'data' => ['label' => 'Investor update · November 2026', 'text' => '$78,098 through Intake this year.']],
                    ['type' => 'letter_text', 'data' => ['text' => "Hi {first_name},\n\nTwo months of building, and a lot of time on calls with shop owners. Here's where things stand."]],
                    ['type' => 'feat_tiles', 'data' => ['tiles' => [
                        ['label' => 'Processed in 2026', 'value' => '$78,098', 'note' => '713 sales'],
                        ['label' => 'October', 'value' => '$36,676', 'note' => 'up 114% from $17,161 in September'],
                        ['label' => 'Customers on file', 'value' => '1,761', 'note' => 'across our live shops'],
                        ['label' => 'Items sold', 'value' => '1,304', 'note' => 'rung up through the register'],
                    ]]],
                    ['type' => 'feat_divider', 'data' => []],
                    ['type' => 'feat_section', 'data' => ['style' => 'square', 'num' => '01', 'text' => 'New since September']],
                    ['type' => 'feat_headline', 'data' => ['text' => 'A report builder with Claude built in.']],
                    ['type' => 'feat_lede', 'data' => ['text' => "Shops build their own reports on live data: sales, inventory, customers, staff, services and distributor stock, from templates or from scratch."]],
                    ['type' => 'letter_text', 'data' => ['text' => "They can save a report, have it emailed on a schedule, set alerts when a number crosses a line, and pin it to their dashboard. And because Claude is built in, an owner can ask a plain question about their own shop and get a detailed answer drawn from their own numbers."]],
                    ['type' => 'feat_headline', 'data' => ['text' => 'The Buying suite.']],
                    ['type' => 'letter_text', 'data' => ['text' => "It shows what's moving in the shop and what's moving at the distributor, gives the shop its open to buy, and sets target inventory levels down to the category and the item.\n\nIt also catches buying signals in the store: every search at the register or online that came up with nothing in stock is logged, so a shop sees what customers asked for without writing it down somewhere else.\n\nNext up: early buying alerts, which tell a dealer when an item they sell is about to run out at the distributor, before their own stock levels say it's time to reorder."]],
                    ['type' => 'feat_headline', 'data' => ['text' => 'Distributor sell-through history.']],
                    ['type' => 'letter_text', 'data' => ['text' => "Every night we record what's selling across the distributors' catalogs and keep 60 days of history, so a shop sees what's moving before it orders."]],
                    ['type' => 'letter_text', 'data' => ['text' => "Also new:\n\nOnline store: shipping with live carrier rates and sales tax, order emails and tracking, Google Merchant, GA4 and SEO, online store reports, and a launch checklist that walks a shop to go-live.\n\nDashboards: new views, set by role.\n\nWebsites: new templates, a rebuilt shop search, and SEO across every shop site.\n\nRegister: faster to ring up, with a duplicate finder that merges items.\n\nGrowth: our own sales engine, with tracking and a status for every prospect, plus email campaigns with full reporting."]],
                    ['type' => 'letter_text', 'data' => ['text' => "Sharpened: special orders, purchase orders and receiving, and staff scheduling all got a full pass."]],
                    ['type' => 'feat_divider', 'data' => []],
                    ['type' => 'feat_section', 'data' => ['style' => 'square', 'num' => '02', 'text' => 'What the shop calls are telling us']],
                    ['type' => 'feat_headline', 'data' => ['text' => 'The meetings keep running long.']],
                    ['type' => 'feat_lede', 'data' => ['text' => 'The scheduled calls with shop owners have been going great, and they often run past the time we booked.']],
                    ['type' => 'letter_text', 'data' => ['text' => "A lot of what we built came straight out of those calls: @mentions so someone at the bench can flag a teammate right on the work order, the Buying suite, and the report builder."]],
                    ['type' => 'feat_divider', 'data' => []],
                    ['type' => 'feat_section', 'data' => ['style' => 'square', 'num' => '03', 'text' => 'Smaller pieces, easier switch']],
                    ['type' => 'feat_headline', 'data' => ['text' => 'Start with one piece of Intake.']],
                    ['type' => 'letter_text', 'data' => ['text' => "The biggest thing we heard: moving everything at once is a hard commitment for a shop. So we're breaking Intake into smaller pieces: e-commerce, with the catalog behind it, and rentals.\n\nEach piece works on its own, next to the system a shop already uses. And each one is a first step: a shop can start with its online store or rentals, then move the rest over when it's ready."]],
                    ['type' => 'feat_divider', 'data' => []],
                    ['type' => 'feat_section', 'data' => ['style' => 'square', 'num' => '04', 'text' => 'The round']],
                    ['type' => 'feat_headline', 'data' => ['text' => '$77k left in the round.']],
                    ['type' => 'letter_text', 'data' => ['text' => "Once shops are on, the momentum keeps us rolling, and this round is what gets them on."]],
                    ['type' => 'letter_ask', 'data' => ['title' => 'What would help', 'items' => "Introductions to anyone looking for an opportunity like this\nIntros to shop owners who'd try one piece of Intake"]],
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
        // run as root, the folders it made would be root's and the web server
        // couldn't add annotated pictures next to them: hand them to the owner
        // of the storage folder
        $root  = Storage::disk('public')->path('');
        $owner = @fileowner($root); $group = @filegroup($root);
        foreach (['platform-letters', 'platform-letters/samples', $to] as $p) {
            $full = Storage::disk('public')->path($p);
            if ($owner !== false && function_exists('posix_getuid') && posix_getuid() === 0) {
                @chown($full, $owner); @chgrp($full, $group);
            }
        }
        return url('/storage/' . $to);
    }
}
