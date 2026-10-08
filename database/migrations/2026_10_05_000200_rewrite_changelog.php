<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\ChangelogEntry as C;

/**
 * customer-facing changelog: seven highlights (one
 * from September), engineering detail rewritten as customer outcomes,
 * product-area categories, US spelling, the new intro, and a closing
 * call-to-action banner on the Changelog page. Hidden entries keep their text;
 * three internal entries are unpublished (not deleted). Runs once.
 */
return new class extends Migration
{
    public function up(): void
    {
        $miss = [];
        $set = function ($id, array $v) use (&$miss) { $e = C::find($id); if (! $e) { $miss[] = $id; return; } $e->update($v); };

        // 1 — highlights: off everywhere, then seven on (newest first: Sep, Aug, Aug, Jun, Jun, May, Apr)
        C::query()->update(['is_highlighted' => false]);
        foreach (['a2cbfc19-90d5-43dd-a59d-3bd4017b5137', 'a2cbfc19-7b9f-4c4a-817f-50de9ecff2b9', 'a2cbfc19-73b6-4333-ae48-6a52193e2da3',
                  'a21587cb-eb6b-432d-8625-5fefd0bed296', 'a21587cb-e505-4d42-a556-3a7de78f7653', 'a1ea1e31-2a62-4a05-bdcb-3bf08fe9fb04',
                  '05911ded-465a-11f1-bba5-4a1d0792142d'] as $id) $set($id, ['is_highlighted' => true]);

        // 2 — internal-only entries leave the public page
        foreach (['a1ea1e7e-2813-4831-a9dc-8068fa592c71', 'a1ea1e31-2e29-4256-b7eb-8f73ac226d95', '0591224b-465a-11f1-bba5-4a1d0792142d'] as $id) $set($id, ['is_published' => false]);

        // 3 — rewrites and categories [category, title|null, body|null]
        $E = [
         // September
         'a2cbfc19-8ede-4c08-ac38-259ab9efaa81' => ['Booking', null, null],
         'a2cbfc19-90d5-43dd-a59d-3bd4017b5137' => ['Booking', null, 'Shops that take a set number of jobs a day let customers pick a day — with how much room is left — instead of a clock time.'],
         'a2cbfc19-9257-4d14-9b91-189c8e16fba3' => ['Booking', null, null],
         'a2cbfc19-8756-499c-89b9-8d73d00a0c5e' => ['Imports', null, null],
         'a2cbfc19-8a1a-4ddb-b91f-52d7cc642958' => ['Inventory', 'Sort uncategorized items faster', null],
         'a2cbfc19-8b8e-45d6-84b6-1167ee286b3a' => ['Inventory', null, null],
         'a2cbfc19-8615-4365-9de4-6a3e51978923' => ['Help', null, null],
         'a2cbfc19-8443-4143-8247-fd4067c2563c' => ['Register', 'Inventory-aware layaway', 'Items placed on layaway are reserved automatically, keeping availability accurate and preventing accidental double sales.'],
         'a2cbfc19-830a-489d-93d2-e91621366497' => ['Billing', null, null],
         // August / July
         'a2cbfc19-819d-4aad-8e04-e5b26d9846c9' => ['Customers', null, null],
         'a2cbfc19-7eee-46e9-b94b-9fcfd399679d' => ['Customers', null, null],
         'a2cbfc19-80ab-4bfb-a116-f71b836022e0' => ['Register', null, null],
         'a2cbfc19-7e0d-4362-8e19-e72ab9bfd49c' => ['Dashboard', null, null],
         'a2cbfc19-7b9f-4c4a-817f-50de9ecff2b9' => ['Rentals', 'Automatic rental extensions', 'Customers extend a rental from a text message link and pay in one tap.'],
         'a2cbfc19-7824-4eef-9dfb-6a6f89706736' => ['Website', null, null],
         'a2cbfc19-73b6-4333-ae48-6a52193e2da3' => ['Register', null, null],
         'a2cbfc19-7174-447d-b625-f226d7a1a279' => ['Imports', null, null],
         'a2cbfc19-6d77-41a6-b7e0-6c95ca6a437e' => ['Customers', null, null],
         'a2cbfc19-6fbc-4417-b5e5-9181bce479cb' => ['Website', null, null],
         'a2cbfc19-6c6f-4f30-8a5a-067298672b45' => ['Customers', null, null],
         'a2cbfc19-67a6-4cf8-b745-343238e9766b' => ['Customers', null, null],
         'a2cbfc19-6a04-4f91-9b8b-9a18cff78fbc' => ['Inventory', null, null],
         'a2cbfc19-666d-4c40-8fb4-0b37670e5f3a' => ['Register', null, null],
         'a2cbfc19-63a7-490c-8a7c-ac671e8764e6' => ['Register', null, null],
         'a2cbfc19-6531-4121-a79d-1cd806001cc2' => ['Platform', null, null],
         'a2cbfc19-5f9f-43e5-9734-87405302399d' => ['Register', null, null],
         'a2cbfc19-5828-4237-b670-bedb94cba5be' => ['Payments', null, null],
         'a2cbfc19-5518-4cec-849e-f15e4b45ff03' => ['Team', null, null],
         'a2cbfc19-52d4-4969-ada1-18db6890f365' => ['Team', null, null],
         'a2cbfc19-4f38-4bf6-aea7-40e4681e7164' => ['Website', null, null],
         'a2cbfc19-4667-4fe5-b1ac-318b912dc8e1' => ['Team', null, null],
         'a2cbfc19-40e3-46d7-b761-9b9cc5cd9a50' => ['Customers', null, null],
         'a2cbfc19-4486-4261-b803-081521feeb9f' => ['Team', null, null],
         // June
         'a21587cb-eb6b-432d-8625-5fefd0bed296' => ['Customers', 'Unified inbox', 'Texts, emails and website messages from each customer land in one conversation. Every message shows how it arrived, and replies go back the same way by default.'],
         'a21587cb-ee78-4b66-b4b7-6bc09e63e366' => ['Customers', 'Reply to website and email leads by email', 'Replies to website and email inquiries go out as branded email from your shop\'s address.'],
         'a21587cb-f756-4138-8de2-94d9b89d4654' => ['Website', 'Contact form: phone field and spam protection', 'Choose whether the contact form asks for a phone number, and bots are filtered out quietly with no extra step for real customers.'],
         'a21587cb-e917-485c-b1c8-010c73e17360' => ['Payments', 'Reliable booking deposits', 'Appointments are now created only after payment succeeds, while the selected time remains safely reserved during checkout. Declined or abandoned payments no longer create incomplete bookings.'],
         'a21587cb-e9e9-4825-a976-73a3a332c18b' => ['Payments', 'One payment setup for everything', 'Booking and the register share one payment setup, so you connect Stripe once in Settings › Payments. Paid bookings are kept even if a customer closes the page during checkout.'],
         'a21587cb-eaae-40df-8749-a56864398a2e' => ['Customers', null, 'Messages from your website contact form open a conversation in the inbox, so nothing is missed.'],
         'a21587cb-e7e0-4f0d-913e-6517ab711788' => ['Inventory', 'Item pages with photo galleries', 'Product pages show a large photo with clickable thumbnails, so customers can see every angle.'],
         'a21587cb-e878-41ce-909f-e817e8d5e016' => ['Register', 'Payment links for walk-in sales', 'Payment links now work for walk-in sales that don\'t have a customer attached.'],
         'a21587cb-e751-4637-8bb2-0714b3271f20' => ['Website', 'Navigation and page editing fixes', 'Menu links save reliably and can be dragged into order, footer links go where they should, and the booking progress bar reads clearly on dark themes.'],
         'a21587cb-e505-4d42-a556-3a7de78f7653' => ['Website', 'Page builder', 'Build every page from ready-made sections, each with simple Content, Layout, Style and Advanced settings, plus a brand palette that keeps colors consistent.'],
         'a21587cb-e5a8-4273-a94a-8b7c03719b43' => ['Website', 'Media library', 'All your images in one place, sorted into folders. Archived images stay recoverable instead of disappearing.'],
         // May
         'a1ea1e7e-2228-4549-b145-5b9531b13ebc' => ['Payments', 'Card payments at the register', 'Take card payments at the register — typed-in cards, Apple Pay and Google Pay — with the card brand and last four digits saved on every sale.'],
         'a1ea1e7e-2333-4de2-a9b2-2710285de8d8' => ['Payments', 'Send a payment link', 'Text or email a payment link, or show a QR code. The customer pays from their phone and the register shows the receipt as soon as payment lands.'],
         'a1ea1e7e-247c-47d5-81c7-70f787d2d07d' => ['Payments', 'One-click card refunds', 'Refund a card sale from the sale itself — Intake records the refund and returns the money to the card in one step.'],
         'a1ea1e7e-256a-499f-8114-63e4a03f1f6d' => ['Payments', 'Refunds made in Stripe show up in Intake', 'If a refund is made directly in Stripe, the sale in Intake updates to refunded or partly refunded automatically.'],
         'a1ea1e7e-262a-4b6d-95da-2a01574f73b0' => ['Register', 'A nudge before charging', 'If a sale needs a customer attached, the register asks before the card is charged, not after.'],
         'a1ea1e7e-26cd-4f1a-a3d0-030ac6c19752' => ['Payments', null, 'If a sale can\'t be completed after a card is approved, the charge is refunded automatically, so a customer is never billed for a sale that didn\'t happen.'],
         'a1ea1e7e-2771-47ea-a938-0fbb465960a3' => ['Register', 'Faster card checkout', 'Choosing Card goes straight to payment — no reference number to type.'],
         'a1ea1e31-2a62-4a05-bdcb-3bf08fe9fb04' => ['Website', 'Custom domains', 'Connect a domain you already own, like yourshop.com. Customers see your brand, and secure HTTPS is set up and renewed automatically. Most domains go live within minutes of adding two DNS records.'],
         'a1ea1e31-2b7b-40e9-9a0f-7268c8e61d2a' => ['Website', null, 'Add more than one domain and choose your main one — the others redirect to it. Handy when moving from an old domain to a new one.'],
         'a1ea1e31-2c5f-45da-8f2c-a142aa9b9973' => ['Website', null, 'Add www.yourshop.com and yourshop.com works too, so customers reach you whichever they type.'],
         'a1ea1e31-2d44-4490-9289-9377c077de98' => ['Website', null, 'Every domain shows exactly where it is in setup, and what to fix if something needs attention — your Intake address keeps working meanwhile.'],
         'a1df3a0c-38d7-4079-80b2-334472123fc7' => ['Team', null, null],
         'a1df3a0c-3957-4032-956f-de80bac00c71' => ['Team', null, 'A cleaner Team page: each person\'s name, email, role and PIN status at a glance, with actions grouped and removal kept apart.'],
         'a1df3a0c-39b1-41d4-97e1-ecb040da3c6f' => ['Locations', null, 'Add locations with their own address, contact details and timezone, set your main one, and retire a location while keeping its history.'],
         'a1df3a0c-3629-4f64-ad8f-38d4195bff17' => ['Locations', 'Switch locations', 'Switch between locations from the sidebar and stay on the same page.'],
         'a1df3a0c-37d2-4f70-94b1-fa5501218bbd' => ['Team', null, null],
         'a1df3a0c-3868-45b9-8560-bab3a1ff2b17' => ['Team', 'Invited team members can sign in straight away', 'New team members are added to your main location automatically, so their first sign-in just works.'],
         'a1c06219-480b-479b-838e-571dca98547d' => ['Customers', null, null],
         'a1c06219-48d9-4067-94b7-b394af674eee' => ['Customers', null, null],
         'a1c06219-4961-479b-927e-70402ad527c8' => ['Customers', null, null],
         'a1c06219-49ed-49c0-ae88-d0b98ef1bdbc' => ['Calendar', null, null],
         'a1c06219-4abd-4394-8257-261884ea0136' => ['Mobile', null, null],
         'a1c06219-4bbe-493d-bd0e-4bc3377fba52' => ['Mobile', null, null],
         'a1c06219-4c75-4661-a687-37662edebf0e' => ['Mobile', null, null],
         'a1c06219-4eac-4af5-a3b0-6051dbffae81' => ['Mobile', 'Steadier navigation on iPhone', 'The bottom menu stays put when you scroll past the end of a page in Chrome on iPhone.'],
         'a1bd2077-2b56-4787-bb38-2d59a4b28e7a' => ['Register', null, 'Click any sale in your history or on a customer\'s timeline to see its items, totals and payment — and refund it from there.'],
         'a1bd2077-2be9-4bb3-a7aa-4a0023109212' => ['Settings', 'Settings in one place', 'Business, branding, communication, account, appearance and payments settings together on one page, with a clear Save bar.'],
         'a1bd2077-2c71-4463-b4be-134ee58b8154' => ['Settings', null, null],
         'a1bd2077-2e67-4e91-8907-2f34a65e9bea' => ['Settings', 'Easier-to-read dark themes', 'Dark themes have a little more contrast, so text is easier to read everywhere.'],
         'a1c06219-4f5c-48c2-8d62-d9439a51c608' => ['Calendar', null, 'Gaps between appointments on the phone schedule show the right length.'],
         'a1b858fb-11d5-4532-b859-689715689dc2' => ['Classes', null, null],
         'a1b858fb-15b3-42ae-8c99-87823353514f' => ['Classes', null, null],
         'a1b858fb-18ac-4461-802a-acf50f32b676' => ['Customers', null, null],
         'a1b858fb-1cf9-4dcd-b941-64e60e2ec8b8' => ['Classes', null, null],
         'a1b858fb-2282-4067-8241-33c1dfa1eae6' => ['Classes', 'Find customers quickly', 'Search by name, email or phone when adding someone to a class.'],
         'a1b858fb-2735-47cb-93a6-c03e80867b22' => ['Classes', null, null],
         'a1b858fb-2a33-4132-a709-56028cc34fad' => ['Classes', 'Clearer confirmations', 'Canceling, marking a no-show or removing someone from a waitlist now asks in a clear in-app dialog.'],
         'a1b7c393-55da-426d-95aa-e26953edce14' => ['Quotes', null, null],
         'a1b7c393-5e38-468e-a7a9-9c461f904c15' => ['Register', null, 'Handle a return and a new sale in one go: refund the item, add the replacement, and collect or refund the difference. Even swaps commit instantly.'],
         'a1b7c393-63e3-400d-8b20-7913d52dea83' => ['Register', null, 'Every sale, refund, draft and quote in one list you can filter, sort and search — with related transactions grouped together.'],
         'a1b7c393-6706-4510-8293-c14461328b8f' => ['Quotes', null, null],
         'a1b7c393-6ab6-4c4d-81c1-a9f34195481c' => ['Platform', 'Stay signed in through updates', 'Staff no longer get signed out when Intake is updated.'],
         '31d97da5-4658-11f1-bba5-4a1d0792142d' => ['Inventory', 'Receive shipments into inventory', 'Scan or type items as a shipment arrives, adjust quantities and costs, add new products on the spot, and commit — stock levels update instantly. Backorders and substitutes are tracked separately.'],
         // April
         '05911282-465a-11f1-bba5-4a1d0792142d' => ['Setup', 'AI-assisted setup', 'Describe your business in a couple of sentences and setup fills in your hours, services and booking style for you to review — or walk through each step yourself. Either way you\'re ready to take bookings in minutes.'],
         '05911a8b-465a-11f1-bba5-4a1d0792142d' => ['Reports', 'Reports', 'Revenue, bookings and cancellations, customer retention, popular services, staff utilization and busy times — all switchable between today, this week, this month or any date range.'],
         '05911cbb-465a-11f1-bba5-4a1d0792142d' => ['Dashboard', 'Attention cards you can click', 'Click any "Needs your attention" card to see the exact appointments behind it. Red means it\'s on you; amber means you\'re waiting on the customer.'],
         '05911ded-465a-11f1-bba5-4a1d0792142d' => ['Classes', 'Class booking', 'Run classes alongside appointments: schedule sessions, manage rosters, check-ins and waitlists, and sell memberships and class packs. Customers book online and manage everything from their account.'],
         '059120df-465a-11f1-bba5-4a1d0792142d' => ['Platform', 'Nightly backups', 'Your data is backed up every night to a separate region and kept for 30 days.'],
         '5d96ebc9-deba-4aeb-8eb1-5c62810f81a8' => ['Platform', 'Always the latest version', 'After each update you see the newest version straight away — no stale pages.'],
         '6b6185b8-678d-45e0-84a4-afb1bc0911d5' => ['Calendar', null, null],
         '941f98f4-58cb-4880-b2bb-73d5e282a999' => ['Booking', null, null],
         'dace9052-22dc-42ac-938b-3b5344410979' => ['Booking', null, 'Give each staff member or station its own daily limit. Together they set your shop\'s daily capacity, with an optional shop-wide cap for busy days.'],
         '11c2f521-f87d-4679-9d96-dd0cf817b9aa' => ['Booking', 'Booking page fixes', 'Dates and times on your booking page display correctly in every browser.'],
         '798a4175-9481-4e8e-a6c0-a5bb0daf0503' => ['Booking', 'Choose your drop-off methods', null],
         '7dbf620c-20db-4a85-8d79-b3defdc36430' => ['Booking', null, null],
         'aab8ce23-6449-4955-bfb3-66e87f369912' => ['Booking', null, null],
         '05912358-465a-11f1-bba5-4a1d0792142d' => ['Website', 'Public roadmap and changelog', 'See what we\'re building next and everything we\'ve shipped — here on intake.works and inside your Intake account.'],
         'a1a3847e-4138-469e-b4c5-92cd0678b365' => ['Calendar', null, null],
         'a1a3847e-480c-4e5d-bd05-fd9363ac53dd' => ['Calendar', null, null],
         'a1a3847e-4b39-478d-9d79-90931f861365' => ['Calendar', null, null],
         'a1a3847e-4ed1-4e2d-b502-38d3babbb166' => ['Calendar', null, null],
         'a1a3847e-520e-4c69-be8d-af7c51be5768' => ['Calendar', null, null],
         'a1a3847e-5529-453d-8d77-59439a8b9927' => ['Booking', null, null],
         'a1a3847e-5846-4c62-a686-819bee83e208' => ['Booking', null, null],
         'a1a3847e-5b34-458b-bf6d-c32e68525df2' => ['Settings', 'Your shop\'s timezone everywhere', 'Calendar, dashboard and capacity all use your shop\'s local time. Set it once in Settings.'],
         'a1a3847e-5ebb-4953-9973-8e3a545de6ba' => ['Booking', 'No double bookings', 'If two customers try to book the same time at the same moment, only one gets it — the other is told straight away.'],
         'a1a3847e-61f5-41e5-88cc-68ad7de97826' => ['Calendar', null, null],
        ];
        $n = 0;
        foreach ($E as $id => [$cat, $title, $body]) {
            $v = ['category' => $cat];
            if ($title !== null) $v['title'] = $title;
            if ($body !== null) $v['body'] = $body;
            $set($id, $v); $n++;
        }
        // US spelling anywhere left
        foreach (C::all() as $e) {
            $t = str_replace(['uncategorised', 'Uncategorised', 'colour', 'Colour', 'enquiries', 'enquiry', 'fulfilment', 'cancelled'], ['uncategorized', 'Uncategorized', 'color', 'Color', 'inquiries', 'inquiry', 'fulfillment', 'canceled'], (string) $e->title);
            $b = str_replace(['uncategorised', 'Uncategorised', 'colour', 'Colour', 'enquiries', 'enquiry', 'fulfilment', 'cancelled', 'Cancelling'], ['uncategorized', 'Uncategorized', 'color', 'Color', 'inquiries', 'inquiry', 'fulfillment', 'canceled', 'Canceling'], (string) $e->body);
            if ($t !== $e->title || $b !== $e->body) $e->update(['title' => $t, 'body' => $b]);
        }

        // 4 — intro + 5 — closing banner on the Changelog page
        $plat = App\Models\Tenant::where('is_platform', true)->value('id');
        $page = App\Models\Tenant\TenantPage::where('tenant_id', $plat)->where('slug', 'changelog')->first();
        $intro = 0; $cta = 'exists';
        if ($page) {
            foreach ($page->sections as $s) {
                $c = $s->content ?? []; $chg = false;
                foreach ($c as $k => $v) if (is_string($v) && stripos($v, 'Real updates') !== false) { $c[$k] = 'Everything we’ve improved. A running record of new features, refinements and fixes — newest first.'; $chg = true; }
                if ($chg) { $s->content = $c; $s->save(); $intro++; }
            }
            if (! $page->sections()->where('section_type', 'cta_banner')->exists()) {
                App\Models\Tenant\TenantPageSection::create([
                    'page_id' => $page->id, 'tenant_id' => $plat, 'section_type' => 'cta_banner', 'is_visible' => true, 'padding' => 'normal',
                    'sort_order' => (int) $page->sections()->max('sort_order') + 1,
                    'content' => [
                        'headline' => 'See what Intake can do for your shop.',
                        'subheading' => 'Walk around a real shop in the demo, or start a free trial and set up your own.',
                        'buttons' => [
                            ['label' => 'Start a free trial', 'url' => 'https://app.intake.works/signup', 'style' => 'primary'],
                            ['label' => 'Explore the demo', 'url' => '/demo', 'style' => 'ghost'],
                            ['label' => 'View the roadmap', 'url' => '/roadmap', 'style' => 'ghost'],
                        ],
                        'buttons_style' => 'pill', 'text_align' => 'center',
                    ],
                ]);
                $cta = 'added';
            }
        }
        echo "Rewrote {$n} entries; 7 highlights; 3 internal entries unpublished.\n";
        echo $intro ? "Intro updated.\n" : "Intro text not found — change it on the Changelog page in the builder.\n";
        echo "Closing banner: {$cta}.\n";
        if ($miss) echo "Not found: " . implode(', ', array_unique($miss)) . "\n";
    }

    public function down(): void {}
};
