<?php
// MARKER-ROADMAP-REWRITE — customer-facing outcomes for every PUBLISHED roadmap
// entry: no internal detail, file counts, incidents or time estimates.
// Hidden entries are not touched. Two entries are unpublished (not deleted).
require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$E = [
 // ---------- in progress ----------
 'a215888c-c789-4ab9-9a5a-eeed75d44353' => ['Online store', 'Sell products online from your own website — cart, checkout and orders that feed straight into your sales and customer records.', 'In progress'],
 'a215888c-c804-4d50-a8f0-46fded94add6' => ['Booking recovery', 'Follow up with customers who start a booking but don\'t finish, so fewer bookings slip away.', 'In progress'],
 'a1c06097-0317-4b5b-b801-675a330f4113' => ['Inventory on your phone', 'Inventory lists, receiving and stock levels redesigned for phones, to the same standard as Customers and Schedule.', 'In progress'],
 'a1ea2464-c84d-4ee4-aed5-cf069dd878a6' => ['Improved custom-domain reliability', 'Simplifying how custom domains are routed, making setup smoother and problems easier to troubleshoot.', 'In progress'],
 'a1ea2464-c94b-4960-a5b9-1210147dafdb' => ['Easier custom-domain setup', 'Every DNS record your domain needs, shown in one place with copy buttons — so connecting your own domain is a single, clear step.', 'In progress'],
 // ---------- next up ----------
 '05915485-465a-11f1-bba5-4a1d0792142d' => ['Manage add-ons yourself', 'Add or remove paid add-ons straight from your billing page — no need to contact us.', 'Next up'],
 'a1a3847e-7009-44b0-bc19-e9bb54c1a410' => ['Capacity page redesign', 'Clearer closed-day handling, capacity per staff member or station, and smarter defaults that match how shops actually run.', 'Next up'],
 'a1b7c393-7942-48ae-803a-4baab144c7db' => ['One-click refunds', 'Refund any paid sale straight from your transaction history, with the refund ready to confirm.', 'Next up'],
 '059156df-465a-11f1-bba5-4a1d0792142d' => ['Change your plan inside Intake', 'Compare plans, see the prorated cost and switch — without leaving your admin.', 'Next up'],
 '059157f7-465a-11f1-bba5-4a1d0792142d' => ['Self-service appointment changes', 'A "Manage appointment" link in every confirmation email so customers can cancel within your cancellation window — no login, no phone call.', 'Next up'],
 '05915934-465a-11f1-bba5-4a1d0792142d' => ['More dependable AI quick setup', 'Making AI-assisted setup more consistent, so your starting services and settings come out right first time.', 'Next up'],
 '05915a7a-465a-11f1-bba5-4a1d0792142d' => ['Dashboard wording that fits your shop', 'Labels that match how you work — "Awaiting drop-off" for drop-off shops, "Pending bookings" for appointment shops.', 'Next up'],
 '05915e3e-465a-11f1-bba5-4a1d0792142d' => ['Even more reliable updates', 'More automated checks on sign-up, booking and payments before every update ships.', 'Next up'],
 // ---------- exploring ----------
 'a1a3847e-78c5-43bd-bca2-2b21ef6165b2' => ['Lead inbox', 'One inbox for enquiries that come from outside the booking flow, alongside customers who started a booking but didn\'t finish.', 'Exploring'],
 'a1a3847e-7f41-4238-8756-521203f7e327' => ['Easier switching from other software', 'Bring your customers and history across from Acuity, Square, Mindbody, Vagaro or a spreadsheet — and preview everything before it\'s imported.', 'Exploring'],
 'a1b7c393-7d20-4902-ac69-086c7d602ab5' => ['Email quotes to customers', 'Send a polished quote straight to your customer with one click — ideal for custom builds, packages and higher-ticket work.', 'Exploring'],
 'a1b7c393-7ffc-420e-b8b3-831c716f17bf' => ['Industry packs', 'Defaults and features tailored to your kind of business — classes for studios, quotes for service shops, appointments for salons.', 'Exploring'],
 'abe68a38-a04f-4517-a812-20b224bf49c3' => ['Match services to staff and stations', 'Say who or what can do each service — "only Maya does colour" or "this service only at the back bench".', 'Exploring'],
 // ---------- shipped ----------
 '05915bbe-465a-11f1-bba5-4a1d0792142d' => ['Inventory', 'Track stock, receive orders and see what you have. Your own product details stay yours — catalog updates never overwrite them.', 'Shipped May 2026'],
 '529ae55e-520c-4231-9341-2d774c5df7e8' => ['Take payment at booking', 'Customers pay you directly when they book or when the job is done, and refunds work the same way.', 'Shipped'],
 'a2cbfc4e-54c0-4e72-8ec3-86622a68ee94' => ['Layaway', 'Layaway plans with payments against a hold, plus special orders with deposits.', 'Shipped'],
 '05914f52-465a-11f1-bba5-4a1d0792142d' => ['Customer payments through Stripe', 'Payments go straight to your own account, at booking or at completion, with refunds from the appointment view.', 'Shipped'],
 'a1b7c441-0eea-455c-927f-2c7f91827cbd' => ['Nightly automated backups', 'Your data is backed up every night to encrypted offsite storage and kept for 30 days.', 'Shipped'],
 '31d9c0ce-4658-11f1-bba5-4a1d0792142d' => ['Point of sale', 'Ring up sales by scanning, typing or browsing. Attach a customer, calculate tax, hold and resume sales, refund and void, and email or print receipts.', 'Shipped'],
 'a1a3847e-65d1-4d3c-8e7d-72b022db37f7' => ['Week and month calendar views', 'A week view with a lane for each staff member or station, and a month view that shows how busy each day is.', 'Shipped Apr 2026'],
 'a1a3847e-6d8c-4576-afb3-926f6e807874' => ['Drag to reschedule', 'Move an appointment by dragging it to a new time, with a warning if the slot is already busy.', 'Shipped'],
 'a1b7c393-6f3e-4d76-b738-5d6ba11de0ae' => ['Quotes dashboard', 'Your quote pipeline, ageing, conversion rate and weekly volume on one page.', 'Shipped May 2026'],
 'a1b7c393-72da-4429-a5b7-302a0e0dd4df' => ['Refunds and exchanges together', 'Refund and sell in one transaction, linked together in your history.', 'Shipped May 2026'],
 'a1b7c393-7688-4250-9fd5-c657c948ad0c' => ['Transaction history', 'Every sale, refund, draft and quote in one list you can filter.', 'Shipped May 2026'],
 'a1a3847e-687e-4156-98a1-ebe424e9cf7f' => ['No more double bookings', 'If two customers try to book the same time at the same moment, only one gets it — the other is told straight away.', 'Shipped Apr 2026'],
 'a1a3847e-7c31-4dba-b38e-c0424e4124f8' => ['One inbox for every conversation', 'Texts and emails with each customer together in one thread.', 'Shipped'],
 'a1a3847e-7263-4bf8-bffa-7c33b6593911' => ['Customer cancellation', 'Customers cancel from a link in their confirmation email — no login, one tap on a phone.', 'Shipped'],
 'a1a3847e-7558-46ee-81a5-b65a864f184a' => ['Reports', 'Revenue, repeat customers and how busy your staff and stations are — built around the questions shop owners actually ask.', 'Shipped'],
 'a1b858fb-04a9-4414-b237-530f1c5b37cb' => ['Memberships and class packs', 'Sell memberships and class packs, with automatic renewals, expiry and refunds on cancellation.', 'Shipped'],
 'a1b858fb-09d5-4627-8ecc-825cf9f39d9c' => ['Customer activity timeline', 'Every appointment, sale, class and membership for a customer in one timeline, grouped by month.', 'Shipped'],
 'a1b858fb-0da1-4493-9090-54e0ed85e7e1' => ['Class drop-ins at the register', 'Sell a class drop-in at the register and the customer is booked into the class automatically.', 'Shipped'],
];
$UNPUBLISH = [
 '05915d24-465a-11f1-bba5-4a1d0792142d', // "Real pricing: $29 / $79 / $199" — prices are live
 '059155e7-465a-11f1-bba5-4a1d0792142d', // master-admin billing dashboard — internal, not a customer outcome
];

$n = 0; $miss = [];
foreach ($E as $id => [$t, $b, $tf]) {
    $e = App\Models\RoadmapEntry::find($id);
    if (! $e) { $miss[] = $id; continue; }
    $e->update(['title' => $t, 'body' => $b, 'rough_timeframe' => $tf]);
    $n++;
}
foreach ($UNPUBLISH as $id) {
    $e = App\Models\RoadmapEntry::find($id);
    if ($e) { $e->update(['is_published' => false]); echo "Unpublished: {$e->title}\n"; } else { $miss[] = $id; }
}
echo "Rewrote {$n} entries.\n";
if ($miss) echo "Not found: " . implode(', ', $miss) . "\n";
