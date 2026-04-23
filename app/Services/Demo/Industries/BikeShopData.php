<?php

namespace App\Services\Demo\Industries;

use App\Models\Tenant;

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

    public function pageContent(Tenant $tenant): array
    {
        $shopName = $tenant->name;

        return [
            'home' => [
                'meta_title'       => "{$shopName} - Expert Bike Service in Spokane",
                'meta_description' => 'Tune-ups, repairs, suspension service, and bike fits. Drop-off friendly. Spokane and Coeur dAlene.',
                'sections' => [
                    [
                        'type' => 'nav',
                        'content' => [
                            'show_logo'    => true,
                            'show_tagline' => false,
                            'cta_label'    => 'Book Now',
                            'cta_url'      => '/book',
                            'bg_style'     => 'solid',
                        ],
                    ],
                    [
                        'type' => 'hero',
                        'content' => [
                            'headline'            => 'Expert bike service. Back on the road fast.',
                            'subheading'          => 'Tune-ups, repairs, and fits by mechanics who actually ride. Drop-off friendly. No appointment required.',
                            'bg_type'             => 'color',
                            'bg_color'            => '#0a0a0a',
                            'overlay_opacity'     => 0.4,
                            'text_color'          => '#ffffff',
                            'cta_primary_label'   => 'Book Service',
                            'cta_primary_url'     => '/book',
                            'cta_secondary_label' => 'See Services',
                            'cta_secondary_url'   => '#services',
                            'height'              => 'large',
                        ],
                        'padding' => 'normal',
                    ],
                    [
                        'type' => 'services',
                        'content' => [
                            'heading'     => 'Services',
                            'subheading'  => 'From quick tune-ups to full builds.',
                            'show_prices' => true,
                            'layout'      => 'grid',
                        ],
                    ],
                    [
                        'type' => 'text_image',
                        'content' => [
                            'heading'    => 'Built for people who ride',
                            'body'       => "Every mechanic on our team races, rides, or both. We know what a bike should feel like when it is dialed, and we know the difference between a shop that services bikes and a shop that cares about them.\n\nDrop your bike off today. We will get it back to you soon, feeling like it should.",
                            'image_url'  => null,
                            'image_side' => 'right',
                            'cta_label'  => 'About Us',
                            'cta_url'    => '/about',
                        ],
                    ],
                    [
                        'type' => 'cta_banner',
                        'content' => [
                            'headline'  => 'Ready to ride again?',
                            'subtext'   => 'Most tune-ups finished in 24 hours.',
                            'cta_label' => 'Drop Off Now',
                            'cta_url'   => '/book',
                            'bg_style'  => 'accent',
                        ],
                    ],
                    [
                        'type' => 'footer',
                        'content' => [
                            'show_address'   => true,
                            'show_hours'     => true,
                            'show_social'    => true,
                            'copyright_line' => '(c) ' . date('Y') . ' ' . $shopName,
                        ],
                    ],
                ],
            ],

            'about' => [
                'meta_title'       => "About - {$shopName}",
                'meta_description' => 'A Spokane bike shop run by riders. Meet the team and learn how we work.',
                'sections' => [
                    [
                        'type' => 'nav',
                        'content' => [
                            'show_logo'    => true,
                            'show_tagline' => false,
                            'cta_label'    => 'Book Now',
                            'cta_url'      => '/book',
                            'bg_style'     => 'solid',
                        ],
                    ],
                    [
                        'type' => 'hero',
                        'content' => [
                            'headline'            => 'We ride. We fix. We care.',
                            'subheading'          => 'A Spokane bike shop run by mechanics who spend as much time on the trail as in the shop.',
                            'bg_type'             => 'color',
                            'bg_color'            => '#1a1a1a',
                            'text_color'          => '#ffffff',
                            'cta_primary_label'   => null,
                            'cta_primary_url'     => null,
                            'cta_secondary_label' => null,
                            'cta_secondary_url'   => null,
                            'height'              => 'medium',
                        ],
                    ],
                    [
                        'type' => 'text_image',
                        'content' => [
                            'heading'    => 'Our story',
                            'body'       => "We opened our doors because the bike shops we grew up with kept disappearing, replaced by chain stores that moved parts but did not understand bikes.\n\nWe wanted a shop where mechanics had time to do the job right. Where a tune-up was not a checklist but a conversation about how your bike feels when you ride it. Where you were not a ticket number, you were someone who rides.\n\nThat is what we built. We hope it shows in the work.",
                            'image_url'  => null,
                            'image_side' => 'left',
                            'cta_label'  => null,
                            'cta_url'    => null,
                        ],
                    ],
                    [
                        'type' => 'text_image',
                        'content' => [
                            'heading'    => 'How we work',
                            'body'       => "Drop off your bike any time we are open. We will do a quick walk-through with you: what is feeling off, what you want done, anything to watch for.\n\nWe text or email when your bike is ready. Most services are finished in 24 hours. Complex work (full builds, suspension rebuilds) we will quote you a realistic turnaround on the spot.\n\nIf something is not right after you pick up, bring it back. We stand behind our work.",
                            'image_url'  => null,
                            'image_side' => 'right',
                            'cta_label'  => 'Book a Service',
                            'cta_url'    => '/book',
                        ],
                    ],
                    [
                        'type' => 'footer',
                        'content' => [
                            'show_address'   => true,
                            'show_hours'     => true,
                            'show_social'    => true,
                            'copyright_line' => '(c) ' . date('Y') . ' ' . $shopName,
                        ],
                    ],
                ],
            ],

            'contact' => [
                'meta_title'       => "Contact - {$shopName}",
                'meta_description' => 'Get in touch. Spokane, WA bike shop.',
                'sections' => [
                    [
                        'type' => 'nav',
                        'content' => [
                            'show_logo'    => true,
                            'show_tagline' => false,
                            'cta_label'    => 'Book Now',
                            'cta_url'      => '/book',
                            'bg_style'     => 'solid',
                        ],
                    ],
                    [
                        'type' => 'hero',
                        'content' => [
                            'headline'            => 'Get in touch',
                            'subheading'          => 'Questions about a specific job? Parts availability? Team rides? We will get back to you quickly.',
                            'bg_type'             => 'color',
                            'bg_color'            => '#1a1a1a',
                            'text_color'          => '#ffffff',
                            'cta_primary_label'   => null,
                            'cta_primary_url'     => null,
                            'cta_secondary_label' => null,
                            'cta_secondary_url'   => null,
                            'height'              => 'small',
                        ],
                    ],
                    [
                        'type' => 'contact_form',
                        'content' => [
                            'heading'            => 'Send us a message',
                            'subheading'         => 'We typically reply within a business day.',
                            'show_name_field'    => true,
                            'show_email_field'   => true,
                            'show_phone_field'   => true,
                            'show_message_field' => true,
                            'submit_label'       => 'Send Message',
                            'success_message'    => 'Thanks, we will be in touch soon.',
                        ],
                    ],
                    [
                        'type' => 'footer',
                        'content' => [
                            'show_address'   => true,
                            'show_hours'     => true,
                            'show_social'    => true,
                            'copyright_line' => '(c) ' . date('Y') . ' ' . $shopName,
                        ],
                    ],
                ],
            ],
        ];
    }
}
