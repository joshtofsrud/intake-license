<?php

/*
|--------------------------------------------------------------------------
| Industry packs
|--------------------------------------------------------------------------
| Stub data for the /for/{slug} marketing landing pages. When you build
| the real onboarding pack system, move this to the database (likely a
| new `industry_packs` table with related `industry_pack_services`, etc.)
| and swap the lookup in MasterPageController.
|
| For now: this array drives both the landing pages and the
| "Industry pack showcase" section on the homepage.
*/

return [

    // --- Repair / drop-off ---

    'bike-shops' => [
        'name'     => 'Bike Shops',
        'slug'     => 'bike-shops',
        'category' => 'repair',
        'icon'     => '🚴',
        'tagline'  => 'Take tune-up bookings online, track every bike through your shop.',
        'services_blurb' => 'Tune-ups, brake service, wheel truing, overhauls — pre-loaded with realistic pricing tiers.',
        'workflow_blurb' => 'Drop-off, diagnosis, repair, pickup — the statuses match how a bike shop actually runs.',
    ],

    'auto-detailing' => [
        'name'     => 'Auto Detailing',
        'slug'     => 'auto-detailing',
        'category' => 'repair',
        'icon'     => '🚗',
        'tagline'  => 'From express wash to ceramic coating — booked online, tracked through completion.',
        'services_blurb' => 'Wash, interior, paint correction, ceramic coating — all pre-configured.',
        'workflow_blurb' => 'Intake, in-progress, ready-for-pickup, closed — clean pipeline for every vehicle.',
    ],

    'electronics-repair' => [
        'name'     => 'Electronics Repair',
        'slug'     => 'electronics-repair',
        'category' => 'repair',
        'icon'     => '📱',
        'tagline'  => 'Phone, laptop, game console — quoted, repaired, returned.',
        'services_blurb' => 'Screen replacements, battery swaps, data recovery — preset with typical pricing.',
        'workflow_blurb' => 'Diagnosis → quote → approval → repair → pickup. Customers track status online.',
    ],

    'tailor-alterations' => [
        'name'     => 'Tailors & Alterations',
        'slug'     => 'tailor-alterations',
        'category' => 'repair',
        'icon'     => '✂',
        'tagline'  => 'Hemming, resizing, custom fittings — your whole shop online.',
        'services_blurb' => 'Standard alterations, custom work, wedding rush — priced by turnaround.',
        'workflow_blurb' => 'Fitting, in-progress, ready — each garment tracked from drop-off to pickup.',
    ],

    'shoe-repair' => [
        'name'     => 'Shoe Repair',
        'slug'     => 'shoe-repair',
        'category' => 'repair',
        'icon'     => '👞',
        'tagline'  => 'Resoling, stretching, dyeing — customers drop off online.',
        'services_blurb' => 'Resole, heel replacement, stretching, cleaning & dyeing — by shoe type.',
        'workflow_blurb' => 'Drop-off, in-progress, ready — customers see status without calling.',
    ],

    'jewelry' => [
        'name'     => 'Jewelry',
        'slug'     => 'jewelry',
        'category' => 'repair',
        'icon'     => '💍',
        'tagline'  => 'Cleaning, resizing, stone-setting, engraving — tracked from drop-off to pickup.',
        'services_blurb' => 'Cleaning, sizing, stone setting, engraving, appraisal — preset.',
        'workflow_blurb' => 'Secure intake, in-progress, quality check, pickup — each piece photographed.',
    ],

    'musical-instruments' => [
        'name'     => 'Musical Instruments',
        'slug'     => 'musical-instruments',
        'category' => 'repair',
        'icon'     => '🎸',
        'tagline'  => 'Guitar setups, amp repair, restringing — booked and tracked online.',
        'services_blurb' => 'Setups, restringing, electronics, structural repair — preset by instrument type.',
        'workflow_blurb' => 'Drop-off, diagnosis, repair, pickup — with approval checkpoints.',
    ],

    'small-engine-lawn' => [
        'name'     => 'Small Engine & Lawn',
        'slug'     => 'small-engine-lawn',
        'category' => 'repair',
        'icon'     => '🌿',
        'tagline'  => 'Mower tune-ups, blade sharpening, winterization — seasonal scheduling made easy.',
        'services_blurb' => 'Tune-up, sharpening, winterization, repair — preset with seasonal options.',
        'workflow_blurb' => 'Seasonal capacity rules built in — handle the spring rush without double-booking.',
    ],

    // --- Wellness / appointment-based ---

    'personal-trainer' => [
        'name'     => 'Personal Trainers',
        'slug'     => 'personal-trainer',
        'category' => 'wellness',
        'icon'     => '💪',
        'tagline'  => 'Sessions, packages, progress — client bookings and tracking in one place.',
        'services_blurb' => '1:1 sessions, packages, consultations — preset with common session lengths.',
        'workflow_blurb' => 'Book, confirm, train, review. Clients see their upcoming sessions online.',
    ],

    'yoga-studio' => [
        'name'     => 'Yoga Studios',
        'slug'     => 'yoga-studio',
        'category' => 'wellness',
        'icon'     => '🧘',
        'tagline'  => 'Class booking, private sessions, memberships — all from one studio site.',
        'services_blurb' => 'Group classes, private sessions, workshops — preset with capacity limits.',
        'workflow_blurb' => 'Members book online, get reminders, and check in automatically.',
    ],

    'massage-therapy' => [
        'name'     => 'Massage Therapy',
        'slug'     => 'massage-therapy',
        'category' => 'wellness',
        'icon'     => '💆',
        'tagline'  => 'Session booking, intake forms, reminders — your whole practice online.',
        'services_blurb' => 'Swedish, deep tissue, sports, prenatal — preset with session lengths and pricing.',
        'workflow_blurb' => 'Client intake → appointment → follow-up. HIPAA-conscious forms included.',
    ],

    'salon-barber' => [
        'name'     => 'Salons & Barbers',
        'slug'     => 'salon-barber',
        'category' => 'wellness',
        'icon'     => '💇',
        'tagline'  => 'Cuts, color, treatments — booking that fits how you actually run the chair.',
        'services_blurb' => 'Cuts, color, treatments, packages — preset with realistic timing.',
        'workflow_blurb' => 'Per-stylist capacity, service buffers, no-show deposits — all built in.',
    ],

    // Keep this list in sync with the industry pack showcase and the
    // actual onboarding packs when those are built.
];
