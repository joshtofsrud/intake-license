<?php

namespace App\Support\Launch;

/**
 * MARKER-LAUNCH-CHECKLIST: the checklists Intake starts with. Seeded once by the
 * migration; after that master admin owns the wording, order and level.
 */
class LaunchDefaults
{
    public const MODULES = ['ecom' => 'E-commerce', 'rent' => 'Rentals'];

    public static function behaviours(): array
    {
        $base = [
            'hold_go_live'     => true,   // required steps hold the Go live button
            'grandfather'      => 'live', // shops already public when this shipped: live | checklist
            'new_req_for_live' => false,  // a new required step reaches shops already live
            'email_owner'      => true,   // a live required step stops being true
            'add_issue'        => true,
            'email_intake'     => true,   // the platform alert address (master admin's alert setting)
            'remind_days'      => 14,
            'nudge'            => true,
        ];
        return ['ecom' => $base, 'rent' => $base];
    }

    /** check = a LaunchChecks key, or 'confirm' for a step the shop ticks. */
    public static function steps(): array
    {
        return [
            'ecom' => [
                ['key' => 'stripe', 'check' => 'stripe', 'group' => 'Payments and tax', 'level' => 'req', 'pause' => true, 'help' => 'payments',
                 'title' => 'Connect payments', 'explain' => 'Customers pay by card through your Stripe account.',
                 'how' => 'We check that Stripe is connected with live keys, so real cards can be charged. In test mode only test cards work.'],
                ['key' => 'tax', 'check' => 'tax', 'group' => 'Payments and tax', 'level' => 'req', 'help' => 'tax',
                 'title' => 'Set where you collect sales tax', 'explain' => 'Either one rate you charge on every order, or tax worked out from the shipping address.',
                 'how' => 'Passes when Stripe works out tax by address, or a tax rate is saved for your shop.'],
                ['key' => 'items', 'check' => 'items_online', 'group' => 'What you sell online', 'level' => 'req', 'help' => 'online-items',
                 'title' => 'Put items online', 'explain' => 'Turn on "for sale online" for the items you want customers to see.',
                 'how' => 'Passes with at least one active item for sale online.'],
                ['key' => 'quality', 'check' => 'item_quality', 'group' => 'What you sell online', 'level' => 'rec', 'help' => 'online-items',
                 'title' => 'Photos and prices on online items', 'explain' => 'Items without a photo sell far worse and are turned away by Google shopping.',
                 'how' => 'Counts online items missing a photo or a price.'],
                ['key' => 'weights', 'check' => 'weights', 'group' => 'What you sell online', 'level' => 'rec', 'help' => 'shipping',
                 'title' => 'Weights for items you ship', 'explain' => "Shipping prices by weight need each item's weight. Without it your default weight is used.",
                 'how' => 'Only matters when shipping is priced by a weight table or live carrier prices. Counts online items with no weight from you or the catalog.'],
                ['key' => 'fulfil', 'check' => 'fulfil', 'group' => 'Shipping and fulfilment', 'level' => 'req', 'help' => 'fulfillment',
                 'title' => 'Choose how customers get their orders', 'explain' => 'Pickup, local delivery, shipping, or any mix.',
                 'how' => 'Passes with at least one option on.'],
                ['key' => 'ship', 'check' => 'ship_prices', 'group' => 'Shipping and fulfilment', 'level' => 'req', 'help' => 'shipping',
                 'title' => 'Set your shipping prices', 'explain' => 'A flat price, a weight table, or live carrier prices.',
                 'how' => 'Passes when the chosen method is complete: a price, a table with a ship-from ZIP, or a carrier turned on with a ship-from ZIP. Not needed when you do not ship.'],
                ['key' => 'warehouses', 'check' => 'warehouses', 'group' => 'Shipping and fulfilment', 'level' => 'opt', 'help' => 'dropship',
                 'title' => 'Choose supplier warehouses', 'explain' => 'Only if you want suppliers to ship items you are out of.',
                 'how' => 'Shown only when Intake has supplier shipping available for your shop. Passes with at least one warehouse on.'],
                ['key' => 'returns', 'check' => 'returns_page', 'group' => 'Shipping and fulfilment', 'level' => 'req', 'help' => 'returns',
                 'title' => 'Publish your returns policy', 'explain' => 'Customers see a link to it at checkout and in their order email.',
                 'how' => 'Passes when a published page is chosen as your returns policy.'],
                ['key' => 'notify', 'check' => 'order_notify', 'group' => 'Messages', 'level' => 'req', 'help' => 'order-messages',
                 'title' => 'Choose who is told about new orders', 'explain' => 'These people get an email for each new order.',
                 'how' => 'Passes with at least one team member who has an email address.'],
                ['key' => 'emails', 'check' => 'confirm', 'group' => 'Messages', 'level' => 'rec', 'help' => 'order-messages',
                 'title' => 'Read your order emails', 'explain' => 'Open the confirmation and refund emails, send yourself a test of each, and check the wording.',
                 'how' => 'We cannot judge wording, so this is yours to confirm. Opening the templates does not tick it.'],
                ['key' => 'feed', 'check' => 'feed', 'group' => 'Be found and measured', 'level' => 'opt', 'help' => 'google-shopping', 'tier' => 'branded',
                 'title' => 'Google shopping feed', 'explain' => 'Lists your items on Google for free.',
                 'how' => 'Passes when the feed is on. Items Google would turn away are counted.'],
                ['key' => 'ga4', 'check' => 'ga4', 'group' => 'Be found and measured', 'level' => 'opt', 'help' => 'google-analytics', 'tier' => 'branded',
                 'title' => 'Analytics events', 'explain' => 'Sends item views, carts and purchases to Google Analytics.',
                 'how' => 'Passes when a measurement id is saved and shop events are on.'],
                ['key' => 'test', 'check' => 'test_order', 'group' => 'Try it', 'level' => 'req', 'help' => 'test-order',
                 'title' => 'Place a test order', 'explain' => 'Buy something from your own store, then refund it from the order page.',
                 'how' => 'Passes once one online order has been paid. Your team can place it while the store is in preview.'],
            ],
            'rent' => [
                ['key' => 'fleet', 'check' => 'fleet', 'group' => 'Your fleet', 'level' => 'req', 'help' => 'rental-fleet',
                 'title' => 'Add what you rent', 'explain' => 'At least one category, one model and one unit that is ready to rent.',
                 'how' => 'Passes with a unit that is available, for rent and bookable online.'],
                ['key' => 'ids', 'check' => 'unit_ids', 'group' => 'Your fleet', 'level' => 'rec', 'help' => 'rental-fleet',
                 'title' => 'Identifiers on every unit', 'explain' => 'Serial or tag numbers let the desk match the unit coming back.',
                 'how' => 'Counts units for rent with no identifier.'],
                ['key' => 'rates', 'check' => 'model_rates', 'group' => 'Rates and money', 'level' => 'req', 'help' => 'rental-rates',
                 'title' => 'Price every model', 'explain' => 'Online bookings are charged by the day, so each model needs a daily rate.',
                 'how' => 'Passes when no model with units bookable online is missing a daily rate.'],
                ['key' => 'deposit', 'check' => 'deposits', 'group' => 'Rates and money', 'level' => 'rec', 'help' => 'rental-rates',
                 'title' => 'Set your deposits', 'explain' => 'A deposit is held when a renter picks up. No deposit is allowed if that is right for you.',
                 'how' => 'Counts models that take no deposit.'],
                ['key' => 'paychoice', 'check' => 'rent_pay_choice', 'group' => 'Rates and money', 'level' => 'req', 'help' => 'rental-payments',
                 'title' => 'Choose how renters pay', 'explain' => 'Online when they book, at pickup, or let the renter choose.',
                 'how' => 'Passes once a choice is saved in Rentals settings.'],
                ['key' => 'stripe', 'check' => 'rent_payments', 'group' => 'Rates and money', 'level' => 'req', 'pause' => true, 'help' => 'payments',
                 'title' => 'Connect payments', 'explain' => 'Needed when renters pay online.',
                 'how' => 'Same check as the online store; connecting once covers both. Not needed when renters only pay at pickup.'],
                ['key' => 'agreement', 'check' => 'agreement', 'group' => 'Policies', 'level' => 'req', 'help' => 'rental-agreement',
                 'title' => 'Add your rental agreement', 'explain' => 'Renters sign it at pickup. The version they signed is kept with the rental.',
                 'how' => 'Passes when an agreement version is published.'],
                ['key' => 'late', 'check' => 'late_fees', 'group' => 'Policies', 'level' => 'rec', 'help' => 'rental-policies',
                 'title' => 'Set your late fees', 'explain' => 'Grace period, fee per hour and the cap.',
                 'how' => 'Passes once the late and overdue policy is saved, including no fee.'],
                ['key' => 'damage', 'check' => 'confirm', 'group' => 'Policies', 'level' => 'rec', 'help' => 'rental-policies',
                 'title' => 'Write your damage policy', 'explain' => 'What damage and a lost item cost, in your rental agreement.',
                 'how' => 'We cannot read policy text for sense, so this is yours to confirm.'],
                ['key' => 'condition', 'check' => 'condition', 'group' => 'Policies', 'level' => 'rec', 'help' => 'rental-condition',
                 'title' => 'Set up a condition checklist', 'explain' => 'What the desk checks when a unit goes out and comes back.',
                 'how' => 'Passes with at least one condition checklist.'],
                ['key' => 'hours', 'check' => 'rent_hours', 'group' => 'Booking page', 'level' => 'req', 'help' => 'rental-hours',
                 'title' => 'Set pickup and return hours', 'explain' => 'When renters can collect and bring things back, and any days you are closed.',
                 'how' => 'Passes with at least one open day. Online bookings outside these hours are turned away.'],
                ['key' => 'page', 'check' => 'booking_page', 'group' => 'Booking page', 'level' => 'req', 'help' => 'rental-booking-page',
                 'title' => 'Turn on your booking page', 'explain' => 'The page renters use to choose dates and book.',
                 'how' => 'Passes when "Show rentals in my menu" is on in Rentals settings. Turning it off hides rentals everywhere.'],
                ['key' => 'emails', 'check' => 'confirm', 'group' => 'Booking page', 'level' => 'rec', 'help' => 'rental-messages',
                 'title' => 'Read your rental emails', 'explain' => 'Confirmation, reminder and return receipt.',
                 'how' => 'Yours to confirm.'],
                ['key' => 'test', 'check' => 'test_booking', 'group' => 'Try it', 'level' => 'req', 'help' => 'test-booking',
                 'title' => 'Make a test booking and return it', 'explain' => 'Book one unit from your booking page, check it out at the desk and return it.',
                 'how' => 'Passes once one rental has been returned. Your team can book while the page is in preview.'],
            ],
        ];
    }
}
