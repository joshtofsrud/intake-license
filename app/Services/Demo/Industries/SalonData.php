<?php

namespace App\Services\Demo\Industries;

class SalonData implements IndustryDataContract
{
    public function slug(): string { return 'hair-salons'; }
    public function label(): string { return 'Hair Salons'; }
    public function defaultShopName(): string { return 'Bella Salon'; }

    public function categories(): array
    {
        return [
            ['name' => 'Cuts',           'slug' => 'cuts',            'sort_order' => 10],
            ['name' => 'Color',          'slug' => 'color',           'sort_order' => 20],
            ['name' => 'Highlights',     'slug' => 'highlights',      'sort_order' => 30],
            ['name' => 'Treatments',     'slug' => 'treatments',      'sort_order' => 40],
            ['name' => 'Styling',        'slug' => 'styling',         'sort_order' => 50],
            ['name' => 'Special Events', 'slug' => 'special-events',  'sort_order' => 60],
        ];
    }

    public function servicesByCategory(): array
    {
        return [
            'cuts' => [
                ['name' => "Women's Cut",      'slug' => 'womens-cut',      'description' => 'Consultation, shampoo, cut, and blow-dry style.',                       'price_cents' => 6500,  'duration_minutes' => 60,  'prep_before_minutes' => 5,  'cleanup_after_minutes' => 5,  'slot_weight' => 1],
                ['name' => "Men's Cut",        'slug' => 'mens-cut',        'description' => 'Classic or modern cut with neck shave finish.',                         'price_cents' => 4500,  'duration_minutes' => 30,  'prep_before_minutes' => 5,  'cleanup_after_minutes' => 5,  'slot_weight' => 1],
                ['name' => "Kid's Cut (12 & under)", 'slug' => 'kids-cut',  'description' => 'Quick, kid-friendly cut. Includes a lollipop.',                          'price_cents' => 2800,  'duration_minutes' => 30,  'prep_before_minutes' => 5,  'cleanup_after_minutes' => 5,  'slot_weight' => 1],
                ['name' => 'Bang Trim',        'slug' => 'bang-trim',       'description' => 'Quick fringe maintenance. Free for clients within 6 weeks of a cut.',   'price_cents' => 1500,  'duration_minutes' => 15,  'prep_before_minutes' => 0,  'cleanup_after_minutes' => 5,  'slot_weight' => 1],
            ],
            'color' => [
                ['name' => 'Single Process Color',     'slug' => 'single-process-color',     'description' => 'All-over color refresh or grey coverage.',                              'price_cents' => 9500,  'duration_minutes' => 90,  'prep_before_minutes' => 10, 'cleanup_after_minutes' => 10, 'slot_weight' => 2],
                ['name' => 'Root Touch-Up',            'slug' => 'root-touch-up',            'description' => 'Color regrowth at roots only.',                                          'price_cents' => 7500,  'duration_minutes' => 60,  'prep_before_minutes' => 10, 'cleanup_after_minutes' => 10, 'slot_weight' => 2],
                ['name' => 'Double Process Color',     'slug' => 'double-process-color',     'description' => 'Lift and tone for dramatic color changes.',                              'price_cents' => 16500, 'duration_minutes' => 180, 'prep_before_minutes' => 15, 'cleanup_after_minutes' => 15, 'slot_weight' => 3],
                ['name' => 'Color Correction',         'slug' => 'color-correction',         'description' => 'Repair previous color work. Quoted after consult.',                      'price_cents' => 22500, 'duration_minutes' => 240, 'prep_before_minutes' => 15, 'cleanup_after_minutes' => 15, 'slot_weight' => 4],
            ],
            'highlights' => [
                ['name' => 'Partial Highlights',  'slug' => 'partial-highlights',  'description' => 'Highlights through the top and crown. Includes toner and style.',          'price_cents' => 12500, 'duration_minutes' => 120, 'prep_before_minutes' => 10, 'cleanup_after_minutes' => 10, 'slot_weight' => 3],
                ['name' => 'Full Highlights',     'slug' => 'full-highlights',     'description' => 'Foiled highlights throughout. Includes toner, glaze, and style.',         'price_cents' => 17500, 'duration_minutes' => 180, 'prep_before_minutes' => 15, 'cleanup_after_minutes' => 15, 'slot_weight' => 3],
                ['name' => 'Balayage',            'slug' => 'balayage',            'description' => 'Hand-painted, sun-kissed dimension. Includes toner and style.',           'price_cents' => 19500, 'duration_minutes' => 180, 'prep_before_minutes' => 15, 'cleanup_after_minutes' => 15, 'slot_weight' => 3],
                ['name' => 'Babylights',          'slug' => 'babylights',          'description' => 'Fine, natural-looking highlights for soft dimension.',                    'price_cents' => 18500, 'duration_minutes' => 180, 'prep_before_minutes' => 15, 'cleanup_after_minutes' => 15, 'slot_weight' => 3],
            ],
            'treatments' => [
                ['name' => 'Olaplex Treatment',         'slug' => 'olaplex-treatment',         'description' => 'Bond-building treatment for damaged or color-treated hair.',          'price_cents' => 4500, 'duration_minutes' => 30, 'prep_before_minutes' => 5,  'cleanup_after_minutes' => 5,  'slot_weight' => 1],
                ['name' => 'Deep Conditioning',         'slug' => 'deep-conditioning',         'description' => 'Hydrating mask with scalp massage.',                                  'price_cents' => 3500, 'duration_minutes' => 30, 'prep_before_minutes' => 5,  'cleanup_after_minutes' => 5,  'slot_weight' => 1],
                ['name' => 'Keratin Smoothing',         'slug' => 'keratin-smoothing',         'description' => 'Frizz reduction and smoothing treatment. Lasts 3-5 months.',           'price_cents' => 25000, 'duration_minutes' => 180, 'prep_before_minutes' => 15, 'cleanup_after_minutes' => 15, 'slot_weight' => 3],
                ['name' => 'Scalp Treatment',           'slug' => 'scalp-treatment',           'description' => 'Detoxifying scalp treatment with massage.',                            'price_cents' => 4500, 'duration_minutes' => 45, 'prep_before_minutes' => 5,  'cleanup_after_minutes' => 5,  'slot_weight' => 1],
            ],
            'styling' => [
                ['name' => 'Blowout',                'slug' => 'blowout',                'description' => 'Wash and professional blow-dry style.',                                 'price_cents' => 4500, 'duration_minutes' => 45, 'prep_before_minutes' => 5,  'cleanup_after_minutes' => 5,  'slot_weight' => 1],
                ['name' => 'Updo / Special Style',   'slug' => 'updo-special-style',     'description' => 'Formal styling for events. Pinning, curls, finished setting.',          'price_cents' => 8500, 'duration_minutes' => 75, 'prep_before_minutes' => 10, 'cleanup_after_minutes' => 10, 'slot_weight' => 2],
                ['name' => 'Curl Set',               'slug' => 'curl-set',               'description' => 'Wash, set, and finish in your preferred curl pattern.',                 'price_cents' => 6500, 'duration_minutes' => 60, 'prep_before_minutes' => 5,  'cleanup_after_minutes' => 5,  'slot_weight' => 2],
            ],
            'special-events' => [
                ['name' => 'Bridal Trial',         'slug' => 'bridal-trial',         'description' => '90-minute consultation and trial styling for the wedding day.',           'price_cents' => 12500, 'duration_minutes' => 90,  'prep_before_minutes' => 10, 'cleanup_after_minutes' => 10, 'slot_weight' => 2],
                ['name' => 'Bridal Day-Of Style',  'slug' => 'bridal-day-of-style',  'description' => 'Wedding day hair. Includes touch-up before ceremony if same-day.',          'price_cents' => 17500, 'duration_minutes' => 120, 'prep_before_minutes' => 15, 'cleanup_after_minutes' => 15, 'slot_weight' => 3],
                ['name' => 'Prom / Formal Style',  'slug' => 'prom-formal-style',    'description' => 'Special occasion style with optional accessories.',                       'price_cents' => 9500,  'duration_minutes' => 75,  'prep_before_minutes' => 10, 'cleanup_after_minutes' => 10, 'slot_weight' => 2],
            ],
        ];
    }

    public function addons(): array
    {
        return [
            ['name' => 'Olaplex Add-On',         'description' => 'Add Olaplex bond-builder to any color service.',                'price_cents' => 3500, 'default_duration_minutes' => 15, 'applies_to' => ['single-process-color', 'root-touch-up', 'double-process-color', 'partial-highlights', 'full-highlights', 'balayage', 'babylights'], 'overrides' => []],
            ['name' => 'Toner / Glaze',          'description' => 'Color-correcting toner or shine-enhancing glaze.',              'price_cents' => 2500, 'default_duration_minutes' => 20, 'applies_to' => ['partial-highlights', 'full-highlights', 'balayage', 'single-process-color', 'root-touch-up'],                                              'overrides' => []],
            ['name' => 'Conditioning Mask',      'description' => 'Hydrating mask treatment during your service.',                  'price_cents' => 2000, 'default_duration_minutes' => 15, 'applies_to' => ['womens-cut', 'mens-cut', 'single-process-color', 'partial-highlights', 'full-highlights', 'balayage'],                                   'overrides' => []],
            ['name' => 'Scalp Massage',          'description' => '10-minute scalp massage during shampoo.',                        'price_cents' => 1500, 'default_duration_minutes' => 10, 'applies_to' => ['womens-cut', 'blowout', 'single-process-color', 'partial-highlights', 'full-highlights', 'balayage'],                                       'overrides' => []],
            ['name' => 'Long Hair Surcharge',    'description' => 'For hair longer than mid-back. Extra product and time.',         'price_cents' => 2000, 'default_duration_minutes' => 15, 'applies_to' => ['single-process-color', 'partial-highlights', 'full-highlights', 'balayage', 'blowout'],                                                  'overrides' => []],
            ['name' => 'Beard Trim',             'description' => 'Add a clean beard shape-up to a haircut.',                       'price_cents' => 1500, 'default_duration_minutes' => 15, 'applies_to' => ['mens-cut'],                                                                                                                                  'overrides' => []],
            ['name' => 'Deep Repair Treatment',  'description' => 'Intensive K18 or Olaplex treatment for very damaged hair.',     'price_cents' => 5500, 'default_duration_minutes' => 30, 'applies_to' => ['color-correction', 'double-process-color', 'keratin-smoothing'],                                                                              'overrides' => []],
        ];
    }

    public function receivingMethods(): array
    {
        return [
            ['name' => 'Scheduled appointment', 'slug' => 'appointment', 'description' => 'Pick a time. Salon services run on a tight schedule.', 'ask_for_time' => true,  'ask_for_tracking' => false],
            ['name' => 'Walk-in',               'slug' => 'walkin',      'description' => 'Subject to availability. Best to call ahead.',         'ask_for_time' => false, 'ask_for_tracking' => false],
        ];
    }

    public function industryFormFields(): array
    {
        return [
            ['key' => 'hair_length',     'label' => 'Hair Length',           'type' => 'select',   'placeholder' => null, 'help_text' => 'Approximate is fine.',                  'is_required' => false, 'width' => 'half', 'options' => ['Pixie / Short', 'Chin-length', 'Shoulder-length', 'Mid-back', 'Waist-length or longer']],
            ['key' => 'hair_texture',    'label' => 'Hair Texture',          'type' => 'select',   'placeholder' => null, 'help_text' => null,                                    'is_required' => false, 'width' => 'half', 'options' => ['Straight', 'Wavy', 'Curly', 'Coily', 'Mixed']],
            ['key' => 'last_color_date', 'label' => 'Last Color Service',    'type' => 'text',     'placeholder' => 'e.g. 6 weeks ago, never colored', 'help_text' => null,      'is_required' => false, 'width' => 'half', 'options' => null],
            ['key' => 'goal',            'label' => 'What are you hoping for today?', 'type' => 'textarea', 'placeholder' => 'Describe your goal or share an inspiration.', 'help_text' => null, 'is_required' => false, 'width' => 'full', 'options' => null],
        ];
    }

    public function sampleResponses(): array
    {
        return [
            'hair_length'     => ['Pixie / Short', 'Chin-length', 'Shoulder-length', 'Mid-back', 'Waist-length or longer'],
            'hair_texture'    => ['Straight', 'Wavy', 'Curly', 'Coily', 'Mixed'],
            'last_color_date' => ['Never colored', '6 weeks ago', '8 weeks ago', '3 months ago', 'A year ago', 'Just last week'],
            'goal' => [
                'Just a trim, keeping the same shape.',
                'Going lighter for summer. Maybe some face-framing pieces.',
                'Need to cover greys and freshen up the cut.',
                'Want to grow it out but it needs cleanup.',
                'Trying balayage for the first time.',
                'Big chop! Ready for a pixie.',
                'Wedding next month, doing a trial run.',
                'Color correction please. Last salon went too brassy.',
                'Olaplex treatment, hair has been feeling fragile.',
                'Just need a blowout for an event tonight.',
                'Refreshing my highlights, same as last time.',
                'Trying out bangs.',
            ],
        ];
    }

    public function firstNamePool(): array
    {
        return ['Aaliyah','Abby','Adriana','Alex','Alexa','Alexis','Alicia','Allison','Amanda','Amber','Amy','Andrea','Angela','Anna','April','Ariel','Ashley','Audrey','Bailey','Bella','Beth','Brianna','Brittany','Brooke','Caitlin','Camila','Carmen','Caroline','Casey','Cassandra','Catherine','Charlotte','Chelsea','Chloe','Christina','Claire','Cynthia','Daniela','Danielle','Daphne','Dawn','Diana','Eleanor','Elena','Elise','Elizabeth','Ella','Ellie','Emily','Emma','Erica','Erin','Eva','Evelyn','Faith','Fiona','Gabriella','Genevieve','Grace','Hailey','Hannah','Hayley','Heather','Heidi','Holly','Iris','Isabella','Isabelle','Jackie','Jacqueline','Jamie','Jasmine','Jen','Jenna','Jennifer','Jessica','Jocelyn','Jordan','Julia','Julie','Karen','Kate','Katelyn','Katherine','Kayla','Kelly','Kelsey','Kennedy','Kimberly','Kirsten','Kristen','Lana','Lauren','Layla','Leah','Leila','Lily','Linda','Lisa','Lola','Lucia','Lucy','Lydia','Mackenzie','Maddie','Madeline','Madison','Maggie','Margaret','Maria','Mariah','Marie','Marissa','Mary','Maya','Megan','Melanie','Melissa','Mia','Michelle','Mila','Mira','Miranda','Monica','Naomi','Natalia','Natalie','Nicole','Nina','Nora','Olivia','Paige','Pamela','Patricia','Paula','Penelope','Phoebe','Quinn','Rachel','Rebecca','Reese','Riley','Rose','Ruby','Sabrina','Sadie','Samantha','Sarah','Savannah','Scarlett','Selena','Shannon','Sierra','Skyler','Sofia','Sophia','Stella','Stephanie','Summer','Sydney','Tara','Taylor','Teresa','Tessa','Theresa','Tiffany','Valeria','Vanessa','Veronica','Victoria','Violet','Vivian','Whitney','Yvette','Zoe'];
    }

    public function lastNamePool(): array
    {
        return ['Adams','Alvarez','Anderson','Bailey','Baker','Barnes','Bell','Bennett','Brooks','Brown','Bryant','Campbell','Carter','Castillo','Chen','Chavez','Clark','Coleman','Collins','Cook','Cooper','Cruz','Davis','Diaz','Dixon','Edwards','Ellis','Evans','Fisher','Flores','Foster','Garcia','Gomez','Gonzalez','Graham','Gray','Green','Griffin','Hall','Harris','Hayes','Henderson','Hernandez','Hill','Hoffman','Howard','Hughes','Jackson','James','Jenkins','Jimenez','Johnson','Jones','Kelly','Kim','King','Lee','Lewis','Long','Lopez','Martinez','Mitchell','Moore','Morales','Morgan','Morris','Murphy','Nelson','Nguyen','Olson','Owens','Park','Parker','Patel','Perez','Peterson','Phillips','Powell','Price','Ramirez','Reed','Reyes','Richardson','Rivera','Roberts','Rodriguez','Rogers','Romero','Rosales','Ross','Russell','Ryan','Sanchez','Sanders','Santos','Schmidt','Scott','Shaw','Simmons','Smith','Stewart','Sullivan','Taylor','Thomas','Thompson','Torres','Turner','Vargas','Walker','Ward','Watson','White','Williams','Wilson','Wood','Wright','Young','Zhang'];
    }

    public function workOrderFieldPresets(): array
    {
        return [
            [
                'label'               => 'Stylist History',
                'field_type'          => 'text',
                'help_text'           => 'Have you been here before? Who did you see last?',
                'is_required'         => false,
                'is_identifier'       => false,
                'is_customer_visible' => true,
                'options'             => null,
            ],
            [
                'label'               => 'Allergies / Sensitivities',
                'field_type'          => 'text',
                'help_text'           => 'Anything we should avoid? PPD, fragrance, etc.',
                'is_required'         => false,
                'is_identifier'       => false,
                'is_customer_visible' => true,
                'options'             => null,
            ],
            [
                'label'               => 'Reference Photo',
                'field_type'          => 'text',
                'help_text'           => 'Link or describe an inspiration look.',
                'is_required'         => false,
                'is_identifier'       => false,
                'is_customer_visible' => true,
                'options'             => null,
            ],
            [
                'label'               => 'Card on File',
                'field_type'          => 'select',
                'help_text'           => 'Required for new bookings to hold the slot.',
                'is_required'         => false,
                'is_identifier'       => true,
                'is_customer_visible' => false,
                'options'             => ['Yes', 'No'],
            ],
        ];
    }

    public function additionalResources(): array
    {
        // Owner resource (Iris) auto-seeded by TenantUserObserver. These are
        // the additional stylists. Time-slot mode: caps populated for demo
        // visibility but grid math governs primary capacity.
        return [
            ['name' => 'Sage Whitman', 'subtitle' => 'Vinyasa & cuts',   'color_hex' => '#3B82F6', 'max_appointments_per_day' => 8],
            ['name' => 'Theo Park',    'subtitle' => 'Color specialist', 'color_hex' => '#34D399', 'max_appointments_per_day' => 8],
            ['name' => 'River Quinn',  'subtitle' => 'Bridal & events',  'color_hex' => '#F472B6', 'max_appointments_per_day' => 6],
        ];
    }

    public function workOrderSampleValues(): array
    {
        return [
            'Stylist History' => [
                'First time here.',
                'Saw Maya last visit.',
                'Saw Iris last time, loved it.',
                "Don't remember the name, was about a year ago.",
                'Sage has done my color for two years.',
                'New to the area, looking for a regular stylist.',
            ],
            'Allergies / Sensitivities' => [
                'None',
                'PPD sensitivity',
                'Fragrance-sensitive scalp',
                'Latex allergy',
                'No known allergies',
                'Recently had an allergic reaction to ammonia',
            ],
            'Reference Photo' => [
                'Sent over text',
                'Will show on phone',
                'Pinterest board on file',
                'Same as last time',
                'Just want a clean trim, no big change',
                'Will describe in person',
            ],
            'Card on File' => ['Yes', 'No'],
        ];
    }

}
