<?php

namespace App\Services\Demo\Industries;

class BikeShopData implements IndustryDataContract
{
    public function slug(): string { return 'bike-shops'; }
    public function label(): string { return 'Bike Shops'; }
    public function defaultShopName(): string { return 'Blue Ridge Cyclery'; }

    public function categories(): array
    {
        return [
            ['name' => 'Tune-ups',        'slug' => 'tune-ups',         'sort_order' => 10],
            ['name' => 'Drivetrain',      'slug' => 'drivetrain',       'sort_order' => 20],
            ['name' => 'Brakes',          'slug' => 'brakes',           'sort_order' => 30],
            ['name' => 'Wheels & Tires',  'slug' => 'wheels-and-tires', 'sort_order' => 40],
            ['name' => 'Suspension',      'slug' => 'suspension',       'sort_order' => 50],
            ['name' => 'Builds & Fits',   'slug' => 'builds-and-fits',  'sort_order' => 60],
        ];
    }

    public function servicesByCategory(): array
    {
        return [
            'tune-ups' => [
                ['name' => 'Basic Tune-Up',    'slug' => 'basic-tune-up',    'description' => 'Safety check, brake and shift adjustment, tire pressure, quick wipe-down. Get you back on the road.', 'price_cents' => 8500,  'duration_minutes' => 60,  'prep_before_minutes' => 5,  'cleanup_after_minutes' => 5,  'slot_weight' => 1],
                ['name' => 'Standard Tune-Up', 'slug' => 'standard-tune-up', 'description' => 'Full drivetrain clean, brake and shift adjustment, true wheels, bolt check, detail wipe.',               'price_cents' => 13500, 'duration_minutes' => 90,  'prep_before_minutes' => 10, 'cleanup_after_minutes' => 10, 'slot_weight' => 2],
                ['name' => 'Premium Tune-Up',  'slug' => 'premium-tune-up',  'description' => 'Everything in Standard plus bearing service, full degrease and relube, and test ride.',                        'price_cents' => 22500, 'duration_minutes' => 150, 'prep_before_minutes' => 15, 'cleanup_after_minutes' => 15, 'slot_weight' => 3],
            ],
            'drivetrain' => [
                ['name' => 'Chain Replacement',           'slug' => 'chain-replacement',           'description' => 'Remove old chain, install and size new chain. Parts additional.',                     'price_cents' => 3500, 'duration_minutes' => 30, 'prep_before_minutes' => 5,  'cleanup_after_minutes' => 5,  'slot_weight' => 1],
                ['name' => 'Drivetrain Deep Clean',       'slug' => 'drivetrain-deep-clean',       'description' => 'Remove drivetrain, degrease chain, cassette, chainrings. Full relube and reinstall.',  'price_cents' => 7500, 'duration_minutes' => 60, 'prep_before_minutes' => 10, 'cleanup_after_minutes' => 10, 'slot_weight' => 2],
                ['name' => 'Shifter / Derailleur Install','slug' => 'shifter-derailleur-install',  'description' => 'Install new shifter or derailleur, route cable, index. Parts additional.',           'price_cents' => 6500, 'duration_minutes' => 60, 'prep_before_minutes' => 5,  'cleanup_after_minutes' => 5,  'slot_weight' => 2],
            ],
            'brakes' => [
                ['name' => 'Brake Pad Replacement',  'slug' => 'brake-pad-replacement', 'description' => 'Replace pads, reset calipers, test. Parts additional.',                      'price_cents' => 4500, 'duration_minutes' => 30, 'prep_before_minutes' => 5,  'cleanup_after_minutes' => 5,  'slot_weight' => 1],
                ['name' => 'Hydraulic Brake Bleed',  'slug' => 'hydraulic-brake-bleed', 'description' => 'Full bleed of hydraulic brake system. Restores positive lever feel.',         'price_cents' => 6500, 'duration_minutes' => 45, 'prep_before_minutes' => 10, 'cleanup_after_minutes' => 10, 'slot_weight' => 2],
            ],
            'wheels-and-tires' => [
                ['name' => 'Flat Repair',        'slug' => 'flat-repair',         'description' => 'Diagnose, patch or replace tube, remount. Walk-in-friendly.',                    'price_cents' => 2500,  'duration_minutes' => 20,  'prep_before_minutes' => 5,  'cleanup_after_minutes' => 5,  'slot_weight' => 1],
                ['name' => 'Wheel True',         'slug' => 'wheel-true',          'description' => 'Tension check and true. For wheels that are not cracked or badly out.',         'price_cents' => 4000,  'duration_minutes' => 30,  'prep_before_minutes' => 5,  'cleanup_after_minutes' => 5,  'slot_weight' => 1],
                ['name' => 'Tubeless Setup',     'slug' => 'tubeless-setup',      'description' => 'Tape rim, install valves, mount tire, add sealant, seat bead. Per wheel.',     'price_cents' => 4500,  'duration_minutes' => 45,  'prep_before_minutes' => 5,  'cleanup_after_minutes' => 10, 'slot_weight' => 2],
                ['name' => 'Wheel Build (Hand)', 'slug' => 'wheel-build-hand',    'description' => 'Lace, tension, and true a new wheel from your hub, rim, spokes. Parts additional.', 'price_cents' => 11500, 'duration_minutes' => 120, 'prep_before_minutes' => 15, 'cleanup_after_minutes' => 15, 'slot_weight' => 3],
            ],
            'suspension' => [
                ['name' => 'Lower-Leg Service',  'slug' => 'lower-leg-service',  'description' => 'Clean seals, replace bath oil and foam rings. Recommended every 50 hrs.',    'price_cents' => 9500, 'duration_minutes' => 90, 'prep_before_minutes' => 10, 'cleanup_after_minutes' => 10, 'slot_weight' => 2],
                ['name' => 'Rear Shock Service', 'slug' => 'rear-shock-service', 'description' => 'Air can service on rear shock. Fox, RockShox, DVO, others.',                  'price_cents' => 9500, 'duration_minutes' => 90, 'prep_before_minutes' => 10, 'cleanup_after_minutes' => 10, 'slot_weight' => 2],
            ],
            'builds-and-fits' => [
                ['name' => 'New Bike Build',  'slug' => 'new-bike-build',  'description' => 'Assemble a boxed bike: install bars, wheels, pedals, adjust drivetrain and brakes, torque spec.', 'price_cents' => 17500, 'duration_minutes' => 150, 'prep_before_minutes' => 15, 'cleanup_after_minutes' => 15, 'slot_weight' => 3],
                ['name' => 'Basic Bike Fit',  'slug' => 'basic-bike-fit',  'description' => 'Saddle height, fore/aft, reach. One hour, no motion-capture.',                                    'price_cents' => 9500,  'duration_minutes' => 60,  'prep_before_minutes' => 5,  'cleanup_after_minutes' => 5,  'slot_weight' => 2],
            ],
        ];
    }

    public function addons(): array
    {
        return [
            ['name' => 'Replace Brake + Shift Cables',    'description' => 'Full cable and housing replacement on shifters and brakes.',                 'price_cents' => 3500, 'default_duration_minutes' => 30, 'applies_to' => ['basic-tune-up', 'standard-tune-up', 'premium-tune-up'],                      'overrides' => ['premium-tune-up' => ['price_cents' => 2500]]],
            ['name' => 'Install Chain',                   'description' => 'Add a new chain during service (parts included, single-speed or 11/12-speed).', 'price_cents' => 6500, 'default_duration_minutes' => 10, 'applies_to' => ['standard-tune-up', 'premium-tune-up', 'drivetrain-deep-clean'],                  'overrides' => []],
            ['name' => 'Install Tire + Tube',             'description' => 'Mount and install a new tire and tube. Per wheel. Parts additional.',        'price_cents' => 2000, 'default_duration_minutes' => 15, 'applies_to' => ['basic-tune-up', 'standard-tune-up', 'premium-tune-up', 'flat-repair'],          'overrides' => []],
            ['name' => 'Bleed Brakes',                    'description' => 'Add a hydraulic brake bleed to any service.',                                 'price_cents' => 5500, 'default_duration_minutes' => 30, 'applies_to' => ['standard-tune-up', 'premium-tune-up'],                                          'overrides' => []],
            ['name' => 'True Wheel (per wheel)',          'description' => 'Add a wheel true to any service. Per wheel.',                                'price_cents' => 2500, 'default_duration_minutes' => 15, 'applies_to' => ['basic-tune-up', 'standard-tune-up'],                                            'overrides' => []],
            ['name' => 'Tubeless Conversion (per wheel)', 'description' => 'Convert an existing wheel to tubeless. Tape, valve, sealant. Per wheel.',    'price_cents' => 4500, 'default_duration_minutes' => 30, 'applies_to' => ['standard-tune-up', 'premium-tune-up'],                                          'overrides' => []],
            ['name' => 'Bearing Service',                 'description' => 'Clean and repack headset, bottom bracket, and hub bearings where serviceable.', 'price_cents' => 7500, 'default_duration_minutes' => 60, 'applies_to' => ['standard-tune-up'],                                                             'overrides' => []],
            ['name' => 'Pack + Ship Return',              'description' => 'Professional pack-out and return shipping for mail-in service.',             'price_cents' => 4500, 'default_duration_minutes' => 20, 'applies_to' => ['basic-tune-up', 'standard-tune-up', 'premium-tune-up'],                         'overrides' => []],
        ];
    }

    public function receivingMethods(): array
    {
        return [
            ['name' => 'Drop-off at shop',       'slug' => 'dropoff',     'description' => 'Bring your bike in. Most service days use drop-off.',              'ask_for_time' => false, 'ask_for_tracking' => false],
            ['name' => 'Scheduled appointment',  'slug' => 'appointment', 'description' => 'Pick a time slot for a fit, assessment, or quick walk-in service.', 'ask_for_time' => true,  'ask_for_tracking' => false],
            ['name' => 'Mail-in',                'slug' => 'mail-in',     'description' => 'Ship us your bike or suspension. We service and ship back.',       'ask_for_time' => false, 'ask_for_tracking' => true],
        ];
    }

    public function industryFormFields(): array
    {
        return [
            ['key' => 'bike_make',         'label' => 'Bike Brand',         'type' => 'text',     'placeholder' => 'e.g. Specialized, Trek, Santa Cruz',     'help_text' => null,                       'is_required' => true,  'width' => 'half', 'options' => null],
            ['key' => 'bike_model',        'label' => 'Model',              'type' => 'text',     'placeholder' => 'e.g. Stumpjumper, Tallboy, Domane',      'help_text' => null,                       'is_required' => false, 'width' => 'half', 'options' => null],
            ['key' => 'bike_year',         'label' => 'Model Year',         'type' => 'text',     'placeholder' => 'e.g. 2022',                              'help_text' => 'Approximate is fine',      'is_required' => false, 'width' => 'half', 'options' => null],
            ['key' => 'issue_description', 'label' => 'Whats going on?',    'type' => 'textarea', 'placeholder' => 'Describe the issue or anything to check.', 'help_text' => null,                     'is_required' => false, 'width' => 'full', 'options' => null],
        ];
    }

    public function sampleResponses(): array
    {
        return [
            'bike_make' => ['Specialized', 'Trek', 'Santa Cruz', 'Giant', 'Cannondale', 'Scott', 'Canyon', 'Yeti', 'Kona', 'Salsa', 'Surly', 'Pivot', 'Ibis', 'Norco', 'Rocky Mountain', 'Orbea'],
            'bike_model' => ['Stumpjumper', 'Tallboy', 'Enduro', 'Tarmac', 'Domane', 'Fuel EX', 'Top Fuel', 'Hightower', 'Megatower', 'Spearfish', 'Timberjack', 'SB140', 'Process', 'Honzo', 'Krampus'],
            'bike_year' => function () { return (string) random_int(2015, 2026); },
            'issue_description' => [
                'Shifting is off in the higher gears.',
                'Brakes feel spongy, needs a bleed.',
                'Creaking from the bottom bracket when pedaling hard.',
                'Rear wheel is out of true after a rough ride.',
                'Just a seasonal tune-up before spring riding.',
                'Chain is skipping under load.',
                'Fork feels harsh, wants the lower legs serviced.',
                'Flat repair - picked up a goathead.',
                'New build arrived, needs assembly.',
                'Annual service, nothing specific.',
                'Rear derailleur hanger may be bent.',
                'Wants a basic fit adjustment after saddle change.',
            ],
        ];
    }

    public function firstNamePool(): array
    {
        return ['Aaron','Alex','Alison','Amy','Andrew','Anna','Ben','Brad','Brian','Caitlin','Cameron','Carlos','Chris','Claire','Connor','Dan','Dana','David','Derek','Diana','Drew','Elena','Eli','Emily','Emma','Eric','Erin','Ethan','Evan','Grace','Greg','Hannah','Ian','Isaac','Jack','Jake','James','Jamie','Jason','Jen','Jenna','Jeremy','Jess','John','Jordan','Julia','Justin','Kate','Katie','Kevin','Kim','Kyle','Laura','Leah','Lisa','Logan','Luke','Maddie','Marcus','Maria','Mark','Matt','Meg','Megan','Michael','Mike','Molly','Nate','Nick','Nina','Noah','Olivia','Owen','Patrick','Paul','Rachel','Ray','Rebecca','Rob','Ryan','Sam','Sarah','Sean','Shannon','Sophia','Steph','Steve','Tom','Tyler','Vanessa','Will','Zach','Zoe'];
    }

    public function lastNamePool(): array
    {
        return ['Anderson','Baker','Barnes','Bennett','Brooks','Brown','Bryant','Campbell','Carter','Chen','Clark','Coleman','Collins','Cook','Cooper','Davis','Dixon','Edwards','Ellis','Evans','Fisher','Flores','Foster','Garcia','Gomez','Graham','Gray','Green','Griffin','Hall','Harris','Hayes','Henderson','Hernandez','Hoffman','Howard','Hughes','Jackson','James','Jenkins','Johnson','Jones','Kelly','Kim','King','Lee','Lewis','Long','Lopez','Martinez','Mitchell','Moore','Morgan','Morris','Murphy','Nelson','Nguyen','Olson','Owens','Park','Parker','Patel','Peterson','Phillips','Powell','Price','Reed','Reyes','Richardson','Rivera','Roberts','Rodriguez','Rogers','Ross','Russell','Ryan','Sanders','Schmidt','Scott','Shaw','Simmons','Smith','Stewart','Sullivan','Taylor','Thomas','Thompson','Torres','Turner','Walker','Ward','Watson','White','Williams','Wilson','Wood','Wright','Young'];
    }

    public function workOrderFieldPresets(): array
    {
        return [
            [
                'label'               => 'Serial Number',
                'field_type'          => 'text',
                'help_text'           => 'Usually stamped under the bottom bracket or on the head tube.',
                'is_required'         => false,
                'is_identifier'       => true,
                'is_customer_visible' => true,
                'options'             => null,
            ],
            [
                'label'               => 'Model',
                'field_type'          => 'text',
                'help_text'           => 'e.g. Stumpjumper Expert, Tallboy CC, Domane SL 6',
                'is_required'         => false,
                'is_identifier'       => false,
                'is_customer_visible' => true,
                'options'             => null,
            ],
            [
                'label'               => 'Color',
                'field_type'          => 'text',
                'help_text'           => null,
                'is_required'         => false,
                'is_identifier'       => false,
                'is_customer_visible' => true,
                'options'             => null,
            ],
            [
                'label'               => 'Frame Size',
                'field_type'          => 'select',
                'help_text'           => null,
                'is_required'         => false,
                'is_identifier'       => false,
                'is_customer_visible' => true,
                'options'             => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
            ],
            [
                'label'               => 'Wheel Size',
                'field_type'          => 'select',
                'help_text'           => null,
                'is_required'         => false,
                'is_identifier'       => false,
                'is_customer_visible' => true,
                'options'             => ['26"', '27.5"', '29"', '700c', '650b', 'Other'],
            ],
        ];
    }

    public function additionalResources(): array
    {
        // Owner resource (Maya) auto-seeded by TenantUserObserver. These are
        // the additional mechanics. Caps demonstrate dual-cap drop-off math:
        // resource sum = Maya 8 + Dev 6 + Josh 4 = 18 bookings/day.
        return [
            ['name' => 'Dev Chen',     'subtitle' => 'Mechanic', 'color_hex' => '#3B82F6', 'max_appointments_per_day' => 6],
            ['name' => 'Josh Tofsrud', 'subtitle' => 'Owner',    'color_hex' => '#F472B6', 'max_appointments_per_day' => 4],
        ];
    }

    public function workOrderSampleValues(): array
    {
        return [
            'Serial Number' => function () {
                $letters = strtoupper(substr(bin2hex(random_bytes(2)), 0, 2));
                return $letters . random_int(10000000, 99999999);
            },
            'Model' => [
                'Stumpjumper Expert', 'Stumpjumper Comp', 'Tarmac SL7', 'Domane SL 6',
                'Fuel EX 8', 'Top Fuel 9.8', 'Hightower CC', 'Megatower C',
                'Spearfish', 'Timberjack', 'SB140', 'Process 153',
                'Honzo AL', 'Krampus', 'Checkpoint ALR', 'Cutthroat',
            ],
            'Color' => [
                'Matte Black', 'Gloss Black', 'White', 'Raw Aluminum',
                'Red', 'Blue', 'Green', 'Orange', 'Yellow',
                'Satin Grey', 'Teal', 'Purple', 'Bronze',
            ],
            'Frame Size' => ['XS', 'S', 'M', 'L', 'XL'],
            'Wheel Size' => ['27.5"', '29"', '700c'],
        ];
    }

    // MARKER-PATCH-112-BIKESHOP
    public function classTemplates(): array
    {
        return [
            [
                'name'             => 'Basic Bike Maintenance',
                'slug'             => 'basic-bike-maintenance',
                'description'      => 'Hands-on class covering flat repair, drivetrain cleaning, brake adjustment, and pre-ride safety checks. Bring your own bike. Tools provided.',
                'duration_minutes' => 90,
                'default_capacity' => 8,
                'price_cents'      => 4500,
                'instructor_index' => null, // owner teaches
                'schedule'         => [
                    ['dow' => 6, 'time' => '10:00'], // Saturday morning
                ],
            ],
        ];
    }

    public function inventoryCategories(): array
    {
        return [
            ['name' => 'Tubes & Tires',     'slug' => 'tubes-tires'],
            ['name' => 'Drivetrain',        'slug' => 'drivetrain'],
            ['name' => 'Brakes',            'slug' => 'brakes'],
            ['name' => 'Lubes & Cleaners',  'slug' => 'lubes-cleaners'],
            ['name' => 'Lights & Reflectors','slug' => 'lights-reflectors'],
            ['name' => 'Helmets',           'slug' => 'helmets'],
            ['name' => 'Tools',             'slug' => 'tools'],
            ['name' => 'Accessories',       'slug' => 'accessories'],
        ];
    }

    public function inventoryItems(): array
    {
        return [
            // ── Tubes & Tires ────────────────────────────────────────────
            ['sku' => 'BIKE-TUBE-700-25',  'name' => 'Continental Tube 700x23-25',     'category_slug' => 'tubes-tires', 'shop_cost_cents' => 450,  'shop_sell_price_cents' => 1099, 'stock_count' => 48, 'reorder_threshold' => 12],
            ['sku' => 'BIKE-TUBE-700-32',  'name' => 'Continental Tube 700x28-32',     'category_slug' => 'tubes-tires', 'shop_cost_cents' => 475,  'shop_sell_price_cents' => 1199, 'stock_count' => 32, 'reorder_threshold' => 10],
            ['sku' => 'BIKE-TUBE-26-FLAT', 'name' => '26" Tube, Schrader, 1.75-2.1',   'category_slug' => 'tubes-tires', 'shop_cost_cents' => 350,  'shop_sell_price_cents' => 899,  'stock_count' => 24, 'reorder_threshold' => 8],
            ['sku' => 'BIKE-TUBE-275',     'name' => '27.5" Tube, Presta, 2.1-2.4',    'category_slug' => 'tubes-tires', 'shop_cost_cents' => 525,  'shop_sell_price_cents' => 1299, 'stock_count' => 18, 'reorder_threshold' => 6],
            ['sku' => 'BIKE-TUBE-29',      'name' => '29" Tube, Presta, 2.0-2.4',      'category_slug' => 'tubes-tires', 'shop_cost_cents' => 525,  'shop_sell_price_cents' => 1299, 'stock_count' => 22, 'reorder_threshold' => 6],
            ['sku' => 'BIKE-TIRE-GP5000',  'name' => 'Continental GP 5000 700x25',     'category_slug' => 'tubes-tires', 'shop_cost_cents' => 3500, 'shop_sell_price_cents' => 7499, 'stock_count' => 8,  'reorder_threshold' => 4],
            ['sku' => 'BIKE-TIRE-G-ONE',   'name' => 'Schwalbe G-One 700x40 Gravel',   'category_slug' => 'tubes-tires', 'shop_cost_cents' => 3200, 'shop_sell_price_cents' => 6999, 'stock_count' => 6,  'reorder_threshold' => 3],
            ['sku' => 'BIKE-TIRE-MAXXIS',  'name' => 'Maxxis Minion DHF 29x2.5 EXO',   'category_slug' => 'tubes-tires', 'shop_cost_cents' => 4200, 'shop_sell_price_cents' => 8999, 'stock_count' => 5,  'reorder_threshold' => 3],
            ['sku' => 'BIKE-TIRE-MARATHON','name' => 'Schwalbe Marathon Plus 700x35',  'category_slug' => 'tubes-tires', 'shop_cost_cents' => 3000, 'shop_sell_price_cents' => 6499, 'stock_count' => 4,  'reorder_threshold' => 3],
            ['sku' => 'BIKE-TIRE-COMMUTE', 'name' => 'Vee Tire City Slick 700x32',     'category_slug' => 'tubes-tires', 'shop_cost_cents' => 1800, 'shop_sell_price_cents' => 3999, 'stock_count' => 12, 'reorder_threshold' => 4],
            ['sku' => 'BIKE-PATCH-KIT',    'name' => 'Park Tool Vulcanizing Patch Kit','category_slug' => 'tubes-tires', 'shop_cost_cents' => 250,  'shop_sell_price_cents' => 599,  'stock_count' => 36, 'reorder_threshold' => 10],
            ['sku' => 'BIKE-SEALANT',      'name' => 'Stans NoTubes Sealant 32oz',     'category_slug' => 'tubes-tires', 'shop_cost_cents' => 1500, 'shop_sell_price_cents' => 3299, 'stock_count' => 14, 'reorder_threshold' => 5],

            // ── Drivetrain ───────────────────────────────────────────────
            ['sku' => 'BIKE-CHAIN-HG54',   'name' => 'Shimano HG54 10-speed Chain',    'category_slug' => 'drivetrain', 'shop_cost_cents' => 1200, 'shop_sell_price_cents' => 2999,  'stock_count' => 10, 'reorder_threshold' => 4],
            ['sku' => 'BIKE-CHAIN-HG601',  'name' => 'Shimano HG601 11-speed Chain',   'category_slug' => 'drivetrain', 'shop_cost_cents' => 1800, 'shop_sell_price_cents' => 3999,  'stock_count' => 8,  'reorder_threshold' => 4],
            ['sku' => 'BIKE-CHAIN-XT12',   'name' => 'Shimano XT M8100 12-speed Chain','category_slug' => 'drivetrain', 'shop_cost_cents' => 2800, 'shop_sell_price_cents' => 5499,  'stock_count' => 2,  'reorder_threshold' => 4], // LOW
            ['sku' => 'BIKE-CHAIN-SRAM',   'name' => 'SRAM PC-1110 11-speed Chain',    'category_slug' => 'drivetrain', 'shop_cost_cents' => 1900, 'shop_sell_price_cents' => 3999,  'stock_count' => 6,  'reorder_threshold' => 3],
            ['sku' => 'BIKE-CASS-HG500',   'name' => 'Shimano HG500 10sp 11-32T',      'category_slug' => 'drivetrain', 'shop_cost_cents' => 2900, 'shop_sell_price_cents' => 5999,  'stock_count' => 4,  'reorder_threshold' => 3],
            ['sku' => 'BIKE-CASS-XT11',    'name' => 'Shimano XT M8000 11sp 11-46T',   'category_slug' => 'drivetrain', 'shop_cost_cents' => 6800, 'shop_sell_price_cents' => 12999, 'stock_count' => 3,  'reorder_threshold' => 2],
            ['sku' => 'BIKE-RD-105',       'name' => 'Shimano 105 R7000 Rear Derail.', 'category_slug' => 'drivetrain', 'shop_cost_cents' => 4500, 'shop_sell_price_cents' => 8999,  'stock_count' => 2,  'reorder_threshold' => 2],
            ['sku' => 'BIKE-FD-105',       'name' => 'Shimano 105 R7000 Front Derail.','category_slug' => 'drivetrain', 'shop_cost_cents' => 3200, 'shop_sell_price_cents' => 6499,  'stock_count' => 2,  'reorder_threshold' => 2],
            ['sku' => 'BIKE-SHIFT-CABLE',  'name' => 'Shimano Stainless Shift Cable',  'category_slug' => 'drivetrain', 'shop_cost_cents' => 150,  'shop_sell_price_cents' => 449,   'stock_count' => 48, 'reorder_threshold' => 12],
            ['sku' => 'BIKE-SHIFT-HOUSING','name' => 'Jagwire Shift Housing 4mm/10ft', 'category_slug' => 'drivetrain', 'shop_cost_cents' => 800,  'shop_sell_price_cents' => 1999,  'stock_count' => 6,  'reorder_threshold' => 3],
            ['sku' => 'BIKE-PEDAL-SPD',    'name' => 'Shimano PD-M520 SPD Pedals',     'category_slug' => 'drivetrain', 'shop_cost_cents' => 2200, 'shop_sell_price_cents' => 4999,  'stock_count' => 5,  'reorder_threshold' => 2],
            ['sku' => 'BIKE-PEDAL-FLAT',   'name' => 'Race Face Chester Flat Pedals',  'category_slug' => 'drivetrain', 'shop_cost_cents' => 1800, 'shop_sell_price_cents' => 4499,  'stock_count' => 4,  'reorder_threshold' => 2],

            // ── Brakes ───────────────────────────────────────────────────
            ['sku' => 'BIKE-PAD-ULTEGRA',  'name' => 'Shimano Ultegra Brake Pads R55C4','category_slug' => 'brakes', 'shop_cost_cents' => 1200, 'shop_sell_price_cents' => 2499, 'stock_count' => 2, 'reorder_threshold' => 5], // LOW
            ['sku' => 'BIKE-PAD-105-RIM',  'name' => 'Shimano 105 Brake Pads',          'category_slug' => 'brakes', 'shop_cost_cents' => 900,  'shop_sell_price_cents' => 1999, 'stock_count' => 14, 'reorder_threshold' => 5],
            ['sku' => 'BIKE-PAD-XT-DISC',  'name' => 'Shimano XT Resin Disc Pads',      'category_slug' => 'brakes', 'shop_cost_cents' => 1100, 'shop_sell_price_cents' => 2299, 'stock_count' => 18, 'reorder_threshold' => 6],
            ['sku' => 'BIKE-PAD-XT-METAL', 'name' => 'Shimano XT Metal Disc Pads',      'category_slug' => 'brakes', 'shop_cost_cents' => 1500, 'shop_sell_price_cents' => 2999, 'stock_count' => 11, 'reorder_threshold' => 4],
            ['sku' => 'BIKE-PAD-SRAM-G2',  'name' => 'SRAM G2 Organic Disc Pads',       'category_slug' => 'brakes', 'shop_cost_cents' => 1800, 'shop_sell_price_cents' => 3499, 'stock_count' => 8,  'reorder_threshold' => 4],
            ['sku' => 'BIKE-BRAKE-FLUID',  'name' => 'Shimano Mineral Oil 100ml',       'category_slug' => 'brakes', 'shop_cost_cents' => 550,  'shop_sell_price_cents' => 1299, 'stock_count' => 16, 'reorder_threshold' => 6],
            ['sku' => 'BIKE-BRAKE-DOT',    'name' => 'SRAM DOT 5.1 Brake Fluid 4oz',    'category_slug' => 'brakes', 'shop_cost_cents' => 700,  'shop_sell_price_cents' => 1499, 'stock_count' => 9,  'reorder_threshold' => 4],
            ['sku' => 'BIKE-BRAKE-CABLE',  'name' => 'Jagwire Brake Cable Stainless',   'category_slug' => 'brakes', 'shop_cost_cents' => 200,  'shop_sell_price_cents' => 599,  'stock_count' => 42, 'reorder_threshold' => 12],
            ['sku' => 'BIKE-BRAKE-HOUSING','name' => 'Jagwire Brake Housing 5mm/10ft',  'category_slug' => 'brakes', 'shop_cost_cents' => 900,  'shop_sell_price_cents' => 2199, 'stock_count' => 5,  'reorder_threshold' => 3],
            ['sku' => 'BIKE-ROTOR-160',    'name' => 'Shimano RT54 Centerlock 160mm',   'category_slug' => 'brakes', 'shop_cost_cents' => 1800, 'shop_sell_price_cents' => 3999, 'stock_count' => 6,  'reorder_threshold' => 3],
            ['sku' => 'BIKE-ROTOR-180',    'name' => 'Shimano RT54 Centerlock 180mm',   'category_slug' => 'brakes', 'shop_cost_cents' => 2000, 'shop_sell_price_cents' => 4499, 'stock_count' => 4,  'reorder_threshold' => 2],

            // ── Lubes & Cleaners ─────────────────────────────────────────
            ['sku' => 'BIKE-LUBE-WET',     'name' => 'Finish Line Wet Lube 4oz',         'category_slug' => 'lubes-cleaners', 'shop_cost_cents' => 600,  'shop_sell_price_cents' => 1399, 'stock_count' => 22, 'reorder_threshold' => 8],
            ['sku' => 'BIKE-LUBE-DRY',     'name' => 'Finish Line Dry Lube 4oz',         'category_slug' => 'lubes-cleaners', 'shop_cost_cents' => 600,  'shop_sell_price_cents' => 1399, 'stock_count' => 24, 'reorder_threshold' => 8],
            ['sku' => 'BIKE-LUBE-CERAMIC', 'name' => 'Finish Line Ceramic Wax Lube',     'category_slug' => 'lubes-cleaners', 'shop_cost_cents' => 1100, 'shop_sell_price_cents' => 2499, 'stock_count' => 10, 'reorder_threshold' => 4],
            ['sku' => 'BIKE-DEGREASER',    'name' => 'Pedros Pig Juice Degreaser 32oz',  'category_slug' => 'lubes-cleaners', 'shop_cost_cents' => 1200, 'shop_sell_price_cents' => 2499, 'stock_count' => 8,  'reorder_threshold' => 4],
            ['sku' => 'BIKE-WASH',         'name' => 'Pedros Green Fizz Bike Wash 1L',   'category_slug' => 'lubes-cleaners', 'shop_cost_cents' => 1300, 'shop_sell_price_cents' => 2699, 'stock_count' => 11, 'reorder_threshold' => 4],
            ['sku' => 'BIKE-GREASE-PARK',  'name' => 'Park Tool PolyLube 1000 4oz',      'category_slug' => 'lubes-cleaners', 'shop_cost_cents' => 700,  'shop_sell_price_cents' => 1599, 'stock_count' => 14, 'reorder_threshold' => 5],
            ['sku' => 'BIKE-GREASE-PHIL',  'name' => 'Phil Wood Grease 3oz',             'category_slug' => 'lubes-cleaners', 'shop_cost_cents' => 800,  'shop_sell_price_cents' => 1899, 'stock_count' => 6,  'reorder_threshold' => 3],

            // ── Lights & Reflectors ──────────────────────────────────────
            ['sku' => 'BIKE-LIGHT-F-1000', 'name' => 'Cygolite Metro Pro 1000 Front',    'category_slug' => 'lights-reflectors', 'shop_cost_cents' => 4500, 'shop_sell_price_cents' => 8999,  'stock_count' => 7, 'reorder_threshold' => 3],
            ['sku' => 'BIKE-LIGHT-F-500',  'name' => 'Cygolite Metro 500 Front',         'category_slug' => 'lights-reflectors', 'shop_cost_cents' => 3000, 'shop_sell_price_cents' => 5999,  'stock_count' => 12, 'reorder_threshold' => 4],
            ['sku' => 'BIKE-LIGHT-R-150',  'name' => 'Cygolite Hotshot 150 Rear',        'category_slug' => 'lights-reflectors', 'shop_cost_cents' => 2400, 'shop_sell_price_cents' => 4999,  'stock_count' => 10, 'reorder_threshold' => 4],
            ['sku' => 'BIKE-LIGHT-SET',    'name' => 'Bontrager Ion 200/Flare R Set',    'category_slug' => 'lights-reflectors', 'shop_cost_cents' => 4000, 'shop_sell_price_cents' => 7999,  'stock_count' => 6,  'reorder_threshold' => 3],
            ['sku' => 'BIKE-REFLECTOR',    'name' => 'Wheel Reflector Pack (4)',         'category_slug' => 'lights-reflectors', 'shop_cost_cents' => 200,  'shop_sell_price_cents' => 599,   'stock_count' => 28, 'reorder_threshold' => 8],

            // ── Helmets ──────────────────────────────────────────────────
            ['sku' => 'BIKE-HELM-GIRO-S',  'name' => 'Giro Register MIPS Small',         'category_slug' => 'helmets', 'shop_cost_cents' => 3500, 'shop_sell_price_cents' => 6999,  'stock_count' => 4, 'reorder_threshold' => 2],
            ['sku' => 'BIKE-HELM-GIRO-M',  'name' => 'Giro Register MIPS Medium',        'category_slug' => 'helmets', 'shop_cost_cents' => 3500, 'shop_sell_price_cents' => 6999,  'stock_count' => 6, 'reorder_threshold' => 2],
            ['sku' => 'BIKE-HELM-GIRO-L',  'name' => 'Giro Register MIPS Large',         'category_slug' => 'helmets', 'shop_cost_cents' => 3500, 'shop_sell_price_cents' => 6999,  'stock_count' => 4, 'reorder_threshold' => 2],
            ['sku' => 'BIKE-HELM-BELL-M',  'name' => 'Bell Avenue LED MIPS Medium',      'category_slug' => 'helmets', 'shop_cost_cents' => 4000, 'shop_sell_price_cents' => 7999,  'stock_count' => 3, 'reorder_threshold' => 2],
            ['sku' => 'BIKE-HELM-PROFRD',  'name' => 'Smith Trace MIPS Road',            'category_slug' => 'helmets', 'shop_cost_cents' => 11000,'shop_sell_price_cents' => 22000, 'stock_count' => 2, 'reorder_threshold' => 1],
            ['sku' => 'BIKE-HELM-FULLFC',  'name' => 'Bell Sanction Full-Face MTB',      'category_slug' => 'helmets', 'shop_cost_cents' => 7500, 'shop_sell_price_cents' => 14999, 'stock_count' => 2, 'reorder_threshold' => 1],
            ['sku' => 'BIKE-HELM-KIDS',    'name' => 'Bell Sidetrack Childrens MIPS',    'category_slug' => 'helmets', 'shop_cost_cents' => 2500, 'shop_sell_price_cents' => 5499,  'stock_count' => 5, 'reorder_threshold' => 2],

            // ── Tools ────────────────────────────────────────────────────
            ['sku' => 'BIKE-MULTI-IB',     'name' => 'Crank Bros M19 Multi-Tool',        'category_slug' => 'tools', 'shop_cost_cents' => 1800, 'shop_sell_price_cents' => 3999, 'stock_count' => 12, 'reorder_threshold' => 4],
            ['sku' => 'BIKE-PUMP-MINI',    'name' => 'Lezyne Pressure Drive Mini Pump',  'category_slug' => 'tools', 'shop_cost_cents' => 2200, 'shop_sell_price_cents' => 4499, 'stock_count' => 8,  'reorder_threshold' => 3],
            ['sku' => 'BIKE-PUMP-FLOOR',   'name' => 'Topeak JoeBlow Sport III Pump',    'category_slug' => 'tools', 'shop_cost_cents' => 3000, 'shop_sell_price_cents' => 5999, 'stock_count' => 4,  'reorder_threshold' => 2],
            ['sku' => 'BIKE-CO2-CART',    'name' => 'Genuine Innovations 16g CO2 (2pk)','category_slug' => 'tools', 'shop_cost_cents' => 400,  'shop_sell_price_cents' => 999,  'stock_count' => 36, 'reorder_threshold' => 12],
            ['sku' => 'BIKE-CO2-INFL',     'name' => 'Lezyne Trigger Speed Drive CO2',   'category_slug' => 'tools', 'shop_cost_cents' => 1900, 'shop_sell_price_cents' => 3999, 'stock_count' => 6,  'reorder_threshold' => 2],
            ['sku' => 'BIKE-TIRE-LEVER',   'name' => 'Pedros Tire Levers (pair)',        'category_slug' => 'tools', 'shop_cost_cents' => 200,  'shop_sell_price_cents' => 599,  'stock_count' => 44, 'reorder_threshold' => 14],

            // ── Accessories ──────────────────────────────────────────────
            ['sku' => 'BIKE-BOTTLE-CAGE',  'name' => 'Blackburn Cinch CF Bottle Cage',   'category_slug' => 'accessories', 'shop_cost_cents' => 800,  'shop_sell_price_cents' => 1799, 'stock_count' => 18, 'reorder_threshold' => 5],
            ['sku' => 'BIKE-BOTTLE-PURIST','name' => 'Specialized Purist Bottle 24oz',   'category_slug' => 'accessories', 'shop_cost_cents' => 500,  'shop_sell_price_cents' => 1399, 'stock_count' => 32, 'reorder_threshold' => 8],
            ['sku' => 'BIKE-SADDLEBAG',    'name' => 'Topeak Aero Wedge Saddle Bag M',   'category_slug' => 'accessories', 'shop_cost_cents' => 1500, 'shop_sell_price_cents' => 3499, 'stock_count' => 9,  'reorder_threshold' => 3],
            ['sku' => 'BIKE-FRAME-PUMP',   'name' => 'Lezyne CNC Loaded Frame Pump',     'category_slug' => 'accessories', 'shop_cost_cents' => 2600, 'shop_sell_price_cents' => 5499, 'stock_count' => 5,  'reorder_threshold' => 2],
            ['sku' => 'BIKE-BELL',         'name' => 'Mirrycle Incredibell Brass',       'category_slug' => 'accessories', 'shop_cost_cents' => 600,  'shop_sell_price_cents' => 1499, 'stock_count' => 14, 'reorder_threshold' => 4],
            ['sku' => 'BIKE-GRIPS-LOCKON', 'name' => 'ESI Chunky Silicone Grips',        'category_slug' => 'accessories', 'shop_cost_cents' => 1400, 'shop_sell_price_cents' => 2999, 'stock_count' => 8,  'reorder_threshold' => 3],
            ['sku' => 'BIKE-BARTAPE',      'name' => 'Fizik Vento Microtex Bar Tape',    'category_slug' => 'accessories', 'shop_cost_cents' => 1800, 'shop_sell_price_cents' => 3799, 'stock_count' => 11, 'reorder_threshold' => 4],
            ['sku' => 'BIKE-GLOVES-FF',    'name' => 'Pearl Izumi Elite Gel Full Finger','category_slug' => 'accessories', 'shop_cost_cents' => 2000, 'shop_sell_price_cents' => 4499, 'stock_count' => 7,  'reorder_threshold' => 3],
            ['sku' => 'BIKE-GLOVES-HF',    'name' => 'Bontrager Solstice Half Finger',   'category_slug' => 'accessories', 'shop_cost_cents' => 1500, 'shop_sell_price_cents' => 3499, 'stock_count' => 8,  'reorder_threshold' => 3],
            ['sku' => 'BIKE-LOCK-U',       'name' => 'Kryptonite Evolution Mini-7 U-Lock','category_slug' => 'accessories','shop_cost_cents' => 4500, 'shop_sell_price_cents' => 8999, 'stock_count' => 6,  'reorder_threshold' => 2],
            ['sku' => 'BIKE-LOCK-CABLE',   'name' => 'Kryptonite KryptoFlex 1218 Cable', 'category_slug' => 'accessories', 'shop_cost_cents' => 1200, 'shop_sell_price_cents' => 2499, 'stock_count' => 10, 'reorder_threshold' => 4],
            ['sku' => 'BIKE-FENDER-FULL',  'name' => 'Planet Bike Cascadia ALX Fenders', 'category_slug' => 'accessories', 'shop_cost_cents' => 3800, 'shop_sell_price_cents' => 7499, 'stock_count' => 3,  'reorder_threshold' => 2],
            ['sku' => 'BIKE-FENDER-MTB',   'name' => 'Mucky Nutz Front Fender MTB',      'category_slug' => 'accessories', 'shop_cost_cents' => 1100, 'shop_sell_price_cents' => 2499, 'stock_count' => 5,  'reorder_threshold' => 2],
            ['sku' => 'BIKE-CHAIN-MASTER', 'name' => 'KMC Missing Link 11s (pair)',      'category_slug' => 'accessories', 'shop_cost_cents' => 350,  'shop_sell_price_cents' => 899,  'stock_count' => 24, 'reorder_threshold' => 8],
        ];
    }

    public function quoteCount(): int { return 10; }
    public function draftCount(): int { return 5; }
    public function classesEnabledOverride(): ?bool { return null; }
    public function membershipProducts(): array { return []; }
    public function packProducts(): array { return []; }
    public function bookingMode(): string { return 'drop_off'; }

}
