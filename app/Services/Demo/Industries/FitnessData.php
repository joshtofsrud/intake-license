<?php

namespace App\Services\Demo\Industries;

class FitnessData implements IndustryDataContract
{
    public function slug(): string { return 'fitness-studios'; }
    public function label(): string { return 'Fitness Studios'; }
    public function defaultShopName(): string { return 'Mountainview Fitness'; }

    public function categories(): array
    {
        return [
            ['name' => 'Yoga',           'slug' => 'yoga',            'sort_order' => 10],
            ['name' => 'Pilates',        'slug' => 'pilates',         'sort_order' => 20],
            ['name' => 'HIIT & Cardio',  'slug' => 'hiit-cardio',     'sort_order' => 30],
            ['name' => 'Strength',       'slug' => 'strength',        'sort_order' => 40],
            ['name' => 'Personal Training','slug' => 'personal-training', 'sort_order' => 50],
            ['name' => 'Recovery',       'slug' => 'recovery',        'sort_order' => 60],
        ];
    }

    /**
     * 1:1 services — these populate the Services catalog. Group classes are
     * seeded separately as class templates. Personal training and recovery
     * services are real bookable appointments here.
     */
    public function servicesByCategory(): array
    {
        return [
            'personal-training' => [
                ['name' => 'Personal Training (60 min)',  'slug' => 'pt-60',  'description' => 'One-on-one strength and conditioning. Programmed to your goals.', 'price_cents' => 9500,  'duration_minutes' => 60, 'prep_before_minutes' => 5, 'cleanup_after_minutes' => 5, 'slot_weight' => 2],
                ['name' => 'Personal Training (30 min)',  'slug' => 'pt-30',  'description' => 'Focused 30-minute training session. Good for tune-ups between full sessions.', 'price_cents' => 5500, 'duration_minutes' => 30, 'prep_before_minutes' => 5, 'cleanup_after_minutes' => 5, 'slot_weight' => 1],
                ['name' => 'Movement Assessment',         'slug' => 'movement-assessment', 'description' => 'Initial intake and movement screen. Required before first PT session.', 'price_cents' => 6500, 'duration_minutes' => 60, 'prep_before_minutes' => 5, 'cleanup_after_minutes' => 5, 'slot_weight' => 2],
                ['name' => 'Semi-Private Training (2-3 people)', 'slug' => 'semi-private-pt', 'description' => 'Bring a friend or two. Programmed for the group.', 'price_cents' => 7500, 'duration_minutes' => 60, 'prep_before_minutes' => 5, 'cleanup_after_minutes' => 5, 'slot_weight' => 2],
            ],
            'recovery' => [
                ['name' => 'Sports Massage (60 min)',     'slug' => 'massage-60', 'description' => 'Deep tissue and trigger-point work. Good after heavy training weeks.', 'price_cents' => 11500, 'duration_minutes' => 60, 'prep_before_minutes' => 10, 'cleanup_after_minutes' => 10, 'slot_weight' => 2],
                ['name' => 'Sports Massage (30 min)',     'slug' => 'massage-30', 'description' => 'Targeted 30-minute work on a problem area.', 'price_cents' => 6500, 'duration_minutes' => 30, 'prep_before_minutes' => 5, 'cleanup_after_minutes' => 5, 'slot_weight' => 1],
                ['name' => 'Stretch Therapy',             'slug' => 'stretch-therapy', 'description' => 'Assisted stretching for mobility and recovery.', 'price_cents' => 7500, 'duration_minutes' => 45, 'prep_before_minutes' => 5, 'cleanup_after_minutes' => 5, 'slot_weight' => 2],
            ],
        ];
    }

    public function addons(): array
    {
        return [
            ['name' => 'Cupping (add to massage)',    'description' => 'Add cupping therapy to your massage.',                  'price_cents' => 2500, 'default_duration_minutes' => 15, 'applies_to' => ['massage-60', 'massage-30'],                'overrides' => []],
            ['name' => 'Goal-setting consult',        'description' => 'Add a 15-min goals discussion to your training session.', 'price_cents' => 0,    'default_duration_minutes' => 15, 'applies_to' => ['pt-60', 'movement-assessment'],            'overrides' => []],
            ['name' => 'Nutrition check-in',          'description' => '15-minute nutrition discussion with your trainer.',     'price_cents' => 2500, 'default_duration_minutes' => 15, 'applies_to' => ['pt-60', 'pt-30', 'semi-private-pt'],        'overrides' => []],
        ];
    }

    public function receivingMethods(): array
    {
        return [
            ['name' => 'Scheduled appointment', 'slug' => 'appointment', 'description' => 'Pick a time for your session.', 'ask_for_time' => true,  'ask_for_tracking' => false],
        ];
    }

    public function industryFormFields(): array
    {
        return [
            ['key' => 'experience_level', 'label' => 'Fitness Experience',          'type' => 'select',   'placeholder' => null, 'help_text' => 'Helps us program appropriately.', 'is_required' => false, 'width' => 'half', 'options' => ['Brand new', '6-12 months', '1-3 years', '3+ years', 'Athlete / competitive']],
            ['key' => 'goals',            'label' => 'Primary goal',                'type' => 'select',   'placeholder' => null, 'help_text' => null,                              'is_required' => false, 'width' => 'half', 'options' => ['General fitness', 'Strength', 'Weight loss', 'Mobility', 'Sport-specific', 'Recovery / rehab']],
            ['key' => 'injuries',         'label' => 'Injuries or limitations',     'type' => 'textarea', 'placeholder' => 'Anything we should know about?', 'help_text' => 'Past surgeries, chronic issues, current pain.', 'is_required' => false, 'width' => 'full', 'options' => null],
            ['key' => 'training_history', 'label' => 'Recent training history',     'type' => 'textarea', 'placeholder' => 'What have you been doing lately?',  'help_text' => null, 'is_required' => false, 'width' => 'full', 'options' => null],
        ];
    }

    public function sampleResponses(): array
    {
        return [
            'experience_level' => ['Brand new', '6-12 months', '1-3 years', '3+ years', 'Athlete / competitive'],
            'goals' => ['General fitness', 'Strength', 'Weight loss', 'Mobility', 'Sport-specific', 'Recovery / rehab'],
            'injuries' => [
                'None.',
                'Old left knee meniscus tear, occasional flare-ups.',
                'Lower back tightness, nothing diagnosed.',
                'Shoulder impingement, working through it.',
                'Recovering from ACL repair, cleared by PT.',
                'Tight hips, no acute issues.',
                'Plantar fasciitis on the right side.',
                'No known issues.',
            ],
            'training_history' => [
                'CrossFit 3x/week for the last year.',
                'Mostly running and yoga at home.',
                'Took 6 months off, easing back in.',
                'Lifting 4x/week, looking to add cardio.',
                'New to fitness, ready to start.',
                'Marathon training cycle just ended.',
                'Pilates 2x/week, want to add strength.',
                'Casual gym goer, no consistent program.',
            ],
        ];
    }

    public function firstNamePool(): array
    {
        return ['Aaliyah','Adrian','Alex','Alexa','Alexis','Allison','Amanda','Amber','Amy','Andrea','Andrew','Anna','Anthony','April','Ariel','Ashley','Audrey','Bailey','Ben','Benjamin','Beth','Brandon','Brian','Brianna','Brooke','Bryan','Caitlin','Caleb','Cameron','Carlos','Caroline','Casey','Catherine','Charlotte','Chelsea','Chris','Christina','Christopher','Claire','Cody','Colin','Connor','Daniel','Daniela','Danielle','David','Dawn','Derek','Devin','Diana','Dylan','Eleanor','Elena','Elias','Elise','Elizabeth','Ella','Emily','Emma','Eric','Erica','Erin','Ethan','Eva','Evan','Evelyn','Faith','Felix','Fiona','Gabriel','Gabriella','Garrett','Genevieve','Grace','Hailey','Hannah','Hayley','Heather','Henry','Hugh','Hunter','Ian','Iris','Isaac','Isabella','Isabelle','Jack','Jackie','Jacob','Jake','James','Jamie','Jasmine','Jason','Jayden','Jen','Jenna','Jennifer','Jeremy','Jessica','Joel','John','Jonathan','Jordan','Joseph','Joshua','Julia','Julian','Julie','Justin','Karen','Kate','Katelyn','Katherine','Kayla','Kelly','Kelsey','Kennedy','Kevin','Kim','Kimberly','Kirsten','Kristen','Kyle','Lana','Lauren','Layla','Leah','Leila','Levi','Liam','Lily','Linda','Lisa','Logan','Lucas','Lucia','Lucy','Luke','Mackenzie','Maddie','Madeline','Madison','Maggie','Marcus','Maria','Mariah','Marie','Mark','Martin','Mason','Matthew','Maya','Megan','Melanie','Melissa','Mia','Michael','Michelle','Mila','Miranda','Monica','Nathan','Naomi','Natalie','Nicholas','Nicole','Nina','Noah','Nora','Olivia','Owen','Paige','Patrick','Paul','Paula','Penelope','Peter','Philip','Phoebe','Quinn','Rachel','Rebecca','Reese','Riley','Robert','Rose','Ruby','Ryan','Sabrina','Sadie','Samantha','Samuel','Sarah','Sean','Seth','Sierra','Sofia','Sophia','Stella','Stephanie','Steven','Summer','Sydney','Taylor','Teresa','Tessa','Theo','Thomas','Tiffany','Travis','Trevor','Tyler','Vanessa','Veronica','Victor','Victoria','Violet','Vivian','Wesley','William','Wyatt','Zachary','Zoe'];
    }

    public function lastNamePool(): array
    {
        return ['Adams','Alvarez','Anderson','Bailey','Baker','Barnes','Bell','Bennett','Brooks','Brown','Bryant','Campbell','Carter','Castillo','Chen','Chavez','Clark','Coleman','Collins','Cook','Cooper','Cruz','Davis','Diaz','Dixon','Edwards','Ellis','Evans','Fisher','Flores','Foster','Garcia','Gomez','Gonzalez','Graham','Gray','Green','Griffin','Hall','Harris','Hayes','Henderson','Hernandez','Hill','Hoffman','Howard','Hughes','Jackson','James','Jenkins','Jimenez','Johnson','Jones','Kelly','Kim','King','Lee','Lewis','Long','Lopez','Martinez','Mitchell','Moore','Morales','Morgan','Morris','Murphy','Nelson','Nguyen','Olson','Owens','Park','Parker','Patel','Perez','Peterson','Phillips','Powell','Price','Ramirez','Reed','Reyes','Richardson','Rivera','Roberts','Rodriguez','Rogers','Romero','Rosales','Ross','Russell','Ryan','Sanchez','Sanders','Santos','Schmidt','Scott','Shaw','Simmons','Smith','Stewart','Sullivan','Taylor','Thomas','Thompson','Torres','Turner','Vargas','Walker','Ward','Watson','White','Williams','Wilson','Wood','Wright','Young','Zhang'];
    }

    public function workOrderFieldPresets(): array
    {
        // Fitness studios use work-order fields lightly — most info lives on
        // the appointment (PT goals, etc). Keeping a single identifier so
        // shared core machinery still has something to anchor on.
        return [
            [
                'label'               => 'Member ID',
                'field_type'          => 'text',
                'help_text'           => 'Auto-assigned. Used for class check-in.',
                'is_required'         => false,
                'is_identifier'       => true,
                'is_customer_visible' => false,
                'options'             => null,
            ],
            [
                'label'               => 'Waiver on file',
                'field_type'          => 'select',
                'help_text'           => null,
                'is_required'         => false,
                'is_identifier'       => false,
                'is_customer_visible' => false,
                'options'             => ['Yes', 'No'],
            ],
        ];
    }

    public function additionalResources(): array
    {
        // Owner resource auto-seeded by TenantUserObserver. These additional
        // staff are the instructors/trainers — they're assigned to class
        // templates and to PT appointments.
        return [
            ['name' => 'Sage Whitman', 'subtitle' => 'Yoga & mobility',     'color_hex' => '#7C3AED', 'max_appointments_per_day' => 8],
            ['name' => 'Marcus Lee',   'subtitle' => 'Strength & HIIT',     'color_hex' => '#EF4444', 'max_appointments_per_day' => 6],
            ['name' => 'Theo Park',    'subtitle' => 'Pilates & barre',     'color_hex' => '#34D399', 'max_appointments_per_day' => 8],
            ['name' => 'River Quinn',  'subtitle' => 'Personal training',   'color_hex' => '#F472B6', 'max_appointments_per_day' => 6],
        ];
    }

    public function workOrderSampleValues(): array
    {
        return [
            'Member ID' => function () {
                return 'M-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
            },
            'Waiver on file' => ['Yes', 'No'],
        ];
    }

    // ------------------------------------------------------------------
    // Class data (the meat of this industry pack)
    // ------------------------------------------------------------------

    /**
     * Class templates with weekly recurring schedules.
     *
     * `instructor_index` is an integer index into additionalResources()
     *   (0 = Sage, 1 = Marcus, 2 = Theo, 3 = River, null = owner).
     *
     * `schedule` is a list of [dow => 0..6 (Sun..Sat), time => 'HH:MM']
     *   pairs — the seeder will materialize ~2 weeks of sessions from
     *   each pair.
     */
    public function classTemplates(): array
    {
        return [
            // YOGA
            [
                'name'             => 'Vinyasa Flow',
                'slug'             => 'vinyasa-flow',
                'description'      => 'Dynamic flow linking breath to movement. All levels welcome.',
                'duration_minutes' => 60,
                'default_capacity' => 18,
                'price_cents'      => 2500,
                'instructor_index' => 0, // Sage
                'schedule'         => [
                    ['dow' => 1, 'time' => '06:30'], // Mon early
                    ['dow' => 3, 'time' => '06:30'], // Wed early
                    ['dow' => 5, 'time' => '09:00'], // Fri morning
                ],
            ],
            [
                'name'             => 'Yin & Restore',
                'slug'             => 'yin-restore',
                'description'      => 'Slow, long-held stretches and restorative postures. Great for recovery days.',
                'duration_minutes' => 75,
                'default_capacity' => 16,
                'price_cents'      => 2800,
                'instructor_index' => 0, // Sage
                'schedule'         => [
                    ['dow' => 0, 'time' => '17:00'], // Sun evening
                    ['dow' => 4, 'time' => '19:00'], // Thu evening
                ],
            ],
            [
                'name'             => 'Power Yoga',
                'slug'             => 'power-yoga',
                'description'      => 'Strength-focused vinyasa. Faster pace, stronger postures. Some experience helpful.',
                'duration_minutes' => 60,
                'default_capacity' => 18,
                'price_cents'      => 2500,
                'instructor_index' => 0, // Sage
                'schedule'         => [
                    ['dow' => 2, 'time' => '17:30'], // Tue evening
                    ['dow' => 6, 'time' => '08:00'], // Sat morning
                ],
            ],

            // PILATES
            [
                'name'             => 'Mat Pilates',
                'slug'             => 'mat-pilates',
                'description'      => 'Classical Pilates on the mat. Core, control, alignment.',
                'duration_minutes' => 50,
                'default_capacity' => 14,
                'price_cents'      => 2500,
                'instructor_index' => 2, // Theo
                'schedule'         => [
                    ['dow' => 1, 'time' => '12:00'], // Mon noon
                    ['dow' => 3, 'time' => '12:00'], // Wed noon
                ],
            ],
            [
                'name'             => 'Barre Burn',
                'slug'             => 'barre-burn',
                'description'      => 'Ballet-inspired strength and conditioning. Small movements, big burn.',
                'duration_minutes' => 50,
                'default_capacity' => 14,
                'price_cents'      => 2500,
                'instructor_index' => 2, // Theo
                'schedule'         => [
                    ['dow' => 2, 'time' => '06:30'], // Tue early
                    ['dow' => 4, 'time' => '06:30'], // Thu early
                    ['dow' => 6, 'time' => '10:00'], // Sat mid-morning
                ],
            ],

            // HIIT & STRENGTH
            [
                'name'             => 'HIIT 45',
                'slug'             => 'hiit-45',
                'description'      => '45 minutes of high-intensity intervals. Bring water. Modifications offered.',
                'duration_minutes' => 45,
                'default_capacity' => 16,
                'price_cents'      => 2500,
                'instructor_index' => 1, // Marcus
                'schedule'         => [
                    ['dow' => 1, 'time' => '17:30'], // Mon evening
                    ['dow' => 3, 'time' => '17:30'], // Wed evening
                    ['dow' => 5, 'time' => '06:30'], // Fri early
                ],
            ],
            [
                'name'             => 'Strength Foundations',
                'slug'             => 'strength-foundations',
                'description'      => 'Barbell and dumbbell strength training. Coaching on form. All experience levels.',
                'duration_minutes' => 60,
                'default_capacity' => 12,
                'price_cents'      => 2800,
                'instructor_index' => 1, // Marcus
                'schedule'         => [
                    ['dow' => 2, 'time' => '12:00'], // Tue noon
                    ['dow' => 4, 'time' => '17:30'], // Thu evening
                    ['dow' => 6, 'time' => '11:30'], // Sat late morning
                ],
            ],
            [
                'name'             => 'Conditioning Circuit',
                'slug'             => 'conditioning-circuit',
                'description'      => 'Bodyweight and kettlebell circuits. Strength endurance focus.',
                'duration_minutes' => 50,
                'default_capacity' => 14,
                'price_cents'      => 2500,
                'instructor_index' => 1, // Marcus
                'schedule'         => [
                    ['dow' => 0, 'time' => '09:30'], // Sun morning
                ],
            ],
        ];
    }

    public function membershipProducts(): array
    {
        return [
            [
                'name'          => 'Unlimited Monthly',
                'description'   => 'Unlimited classes, all formats. Best value for 6+ classes per month.',
                'type'          => 'unlimited',
                'monthly_limit' => null,
                'price_cents'   => 17900,
            ],
            [
                'name'          => '8 Classes / Month',
                'description'   => '8 classes per month. Resets monthly. Roughly twice a week.',
                'type'          => 'capped',
                'monthly_limit' => 8,
                'price_cents'   => 12900,
            ],
            [
                'name'          => '4 Classes / Month',
                'description'   => '4 classes per month. Once-a-week pace.',
                'type'          => 'capped',
                'monthly_limit' => 4,
                'price_cents'   => 7900,
            ],
        ];
    }

    public function packProducts(): array
    {
        return [
            [
                'name'         => 'Drop-in (single class)',
                'description'  => 'Single class, expires in 30 days. No commitment.',
                'credit_count' => 1,
                'expiry_days'  => 30,
                'price_cents'  => 2500,
            ],
            [
                'name'         => '5-Class Pack',
                'description'  => '5 classes, expires in 60 days. Mix and match formats.',
                'credit_count' => 5,
                'expiry_days'  => 60,
                'price_cents'  => 11000,
            ],
            [
                'name'         => '10-Class Pack',
                'description'  => '10 classes, expires in 90 days. Best per-class price for non-members.',
                'credit_count' => 10,
                'expiry_days'  => 90,
                'price_cents'  => 19000,
            ],
            [
                'name'         => '20-Class Pack',
                'description'  => '20 classes, expires in 6 months. For consistent attendees who don\'t want a recurring charge.',
                'credit_count' => 20,
                'expiry_days'  => 180,
                'price_cents'  => 34000,
            ],
        ];
    }

    public function bookingMode(): string { return 'time_slots'; }
}
