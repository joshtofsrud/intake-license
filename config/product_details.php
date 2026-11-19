<?php

/*
 * MARKER-PRODUCT-DETAILS: how a catalog product's specs are laid out on the
 * item page and the online store page (App\Support\ProductDetails).
 * Display rules only; the data itself comes from each distributor's field map.
 */
return [
    // Spec groups, first match wins. Needs at least min_for_groups specs to group at all.
    'min_for_groups' => 8,
    'groups' => [
        'Frame & fork'    => ['frame', 'fork', 'headset', 'rear shock', 'shock', 'suspension'],
        'Drivetrain'      => ['crank', 'chainring', 'bottom bracket', 'b/b', 'chain', 'cassette', 'derailleur', 'f/d', 'r/d', 'shifter', 'pedal', 'motor', 'battery', 'gear'],
        'Brakes'          => ['brake', 'rotor', 'caliper'],
        'Wheels & tires'  => ['rim', 'hub', 'spoke', 'tire', 'tyre', 'wheel', 'tube'],
        'Cockpit'         => ['handlebar', 'stem', 'seatpost', 'seat clamp', 'saddle', 'grip', 'bar tape', 'cockpit'],
    ],
    'other_group' => 'Other',

    // Geometry row names as a shop would say them.
    'geometry_labels' => [
        'st length' => 'Seat tube', 'tt length' => 'Top tube', 'reach' => 'Reach', 'stack' => 'Stack',
        'standover' => 'Standover', 'ht angle' => 'Head angle', 'ht length' => 'Head tube',
        'st angle' => 'Seat angle', 'cs length' => 'Chainstay', 'bb drop' => 'BB drop',
        'bb height' => 'BB height', 'wheelbase' => 'Wheelbase', 'front center' => 'Front center',
        'fork length' => 'Fork length', 'fork offset' => 'Fork offset',
    ],

    // Specs never shown to shoppers or on the item page
    'hide' => ['inner pack', 'master pack', 'legacy #', 'legacy', 'ean', 'unit of measure', 'shipping length (l)',
        'shipping width (w)', 'shipping height (h)', 'shipping weight', 'case quantity'],

    'size_order' => ['XXS', 'XS', 'S', 'SM', 'M', 'MD', 'L', 'LG', 'XL', 'XXL', 'XXXL'],
];
