<?php

namespace App\Services\Demo;

use App\Models\Tenant;
use App\Models\Tenant\TenantAddon;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentNote;
use App\Models\Tenant\TenantAppointmentResponse;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantFormField;
use App\Models\Tenant\TenantFormSection;
use App\Models\Tenant\TenantReceivingMethod;
use App\Models\Tenant\TenantServiceAddon;
use App\Models\Tenant\TenantServiceCategory;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantUser;
use App\Services\Demo\Industries\IndustryDataContract;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder
{
    private const CUSTOMER_COUNT      = 200;
    private const APPOINTMENT_COUNT   = 1800;
    private const CUSTOMER_SPREAD_DAYS = 365;
    private const CAPACITY_PER_DAY    = 16;

    public function __construct(
        private readonly IndustryDataContract $industry,
        private readonly Closure $logger,
    ) {}

    private function log(string $msg): void { ($this->logger)($msg); }

    public function seed(
        Tenant $tenant,
        string $ownerName,
        string $ownerEmail,
        string $ownerPassword,
    ): void {
        $this->log("Seeding tenant [{$tenant->id}] as {$this->industry->label()}...");

        $owner = $this->createOwner($tenant, $ownerName, $ownerEmail, $ownerPassword);
        $this->seedCapacityRules($tenant);
        $this->seedReceivingMethods($tenant);
        $this->seedFormFields($tenant);
        [$categoriesBySlug, $servicesBySlug] = $this->seedCatalog($tenant);
        $addonsByService = $this->seedAddons($tenant, $servicesBySlug);
        $customers = $this->seedCustomers($tenant);
        $this->seedAppointments($tenant, $owner, $customers, $servicesBySlug, $addonsByService);

        $this->log("Done.");
    }

    private function createOwner(Tenant $tenant, string $name, string $email, string $password): TenantUser
    {
        $owner = TenantUser::create([
            'tenant_id' => $tenant->id,
            'name'      => $name,
            'email'     => $email,
            'password'  => Hash::make($password),
            'role'      => 'owner',
            'is_active' => true,
        ]);
        $this->log("  Owner user: {$name} <{$email}>");
        return $owner;
    }

    private function seedCapacityRules(Tenant $tenant): void
    {
        for ($dow = 0; $dow <= 6; $dow++) {
            $isWeekend = $dow === 0 || $dow === 6;
            TenantCapacityRule::create([
                'tenant_id'        => $tenant->id,
                'rule_type'        => 'default',
                'day_of_week'      => $dow,
                'specific_date'    => null,
                'max_appointments' => $isWeekend ? 0 : self::CAPACITY_PER_DAY,
                'note'             => null,
            ]);
        }
        $this->log("  Capacity: " . self::CAPACITY_PER_DAY . "/day Mon-Fri, closed weekends.");
    }

    private function seedReceivingMethods(Tenant $tenant): void
    {
        foreach ($this->industry->receivingMethods() as $i => $m) {
            TenantReceivingMethod::create([
                'tenant_id'        => $tenant->id,
                'name'             => $m['name'],
                'slug'             => $m['slug'],
                'description'      => $m['description'],
                'ask_for_time'     => $m['ask_for_time'],
                'ask_for_tracking' => $m['ask_for_tracking'],
                'is_active'        => true,
                'sort_order'       => ($i + 1) * 10,
            ]);
        }
        $this->log("  Receiving methods: " . count($this->industry->receivingMethods()));
    }

    private function seedFormFields(Tenant $tenant): void
    {
        $customerSection = TenantFormSection::create([
            'tenant_id'   => $tenant->id,
            'title'       => 'Your Information',
            'description' => 'So we can contact you when the job is done.',
            'is_core'     => true,
            'sort_order'  => 10,
        ]);

        $coreFields = [
            ['field_key' => 'first_name', 'field_type' => 'text',  'label' => 'First Name', 'is_required' => true,  'width' => 'half'],
            ['field_key' => 'last_name',  'field_type' => 'text',  'label' => 'Last Name',  'is_required' => true,  'width' => 'half'],
            ['field_key' => 'email',      'field_type' => 'email', 'label' => 'Email',      'is_required' => true,  'width' => 'half'],
            ['field_key' => 'phone',      'field_type' => 'tel',   'label' => 'Phone',      'is_required' => false, 'width' => 'half'],
        ];
        foreach ($coreFields as $i => $f) {
            TenantFormField::create([
                'tenant_id'   => $tenant->id,
                'section_id'  => $customerSection->id,
                'field_key'   => $f['field_key'],
                'field_type'  => $f['field_type'],
                'label'       => $f['label'],
                'is_required' => $f['is_required'],
                'is_core'     => true,
                'width'       => $f['width'],
                'sort_order'  => ($i + 1) * 10,
            ]);
        }

        $industryFields = $this->industry->industryFormFields();
        if (!empty($industryFields)) {
            $industrySection = TenantFormSection::create([
                'tenant_id'   => $tenant->id,
                'title'       => 'About the Job',
                'description' => 'A few details so we can plan the right service.',
                'is_core'     => false,
                'sort_order'  => 20,
            ]);
            foreach ($industryFields as $i => $f) {
                TenantFormField::create([
                    'tenant_id'   => $tenant->id,
                    'section_id'  => $industrySection->id,
                    'field_key'   => $f['key'],
                    'field_type'  => $f['type'],
                    'label'       => $f['label'],
                    'placeholder' => $f['placeholder'] ?? null,
                    'help_text'   => $f['help_text'] ?? null,
                    'is_required' => $f['is_required'],
                    'is_core'     => false,
                    'width'       => $f['width'],
                    'options'     => $f['options'] ?? null,
                    'sort_order'  => ($i + 1) * 10,
                ]);
            }
        }
        $this->log("  Form: core + " . count($industryFields) . " industry fields.");
    }

    private function seedCatalog(Tenant $tenant): array
    {
        $categoriesBySlug = [];
        foreach ($this->industry->categories() as $c) {
            $categoriesBySlug[$c['slug']] = TenantServiceCategory::create([
                'tenant_id'  => $tenant->id,
                'name'       => $c['name'],
                'slug'       => $c['slug'],
                'is_active'  => true,
                'sort_order' => $c['sort_order'],
            ]);
        }

        $servicesBySlug = [];
        $sortOrder = 10;
        foreach ($this->industry->servicesByCategory() as $categorySlug => $services) {
            if (!isset($categoriesBySlug[$categorySlug])) {
                throw new \RuntimeException("Unknown category slug: {$categorySlug}");
            }
            foreach ($services as $s) {
                $servicesBySlug[$s['slug']] = TenantServiceItem::create([
                    'tenant_id'             => $tenant->id,
                    'category_id'           => $categoriesBySlug[$categorySlug]->id,
                    'name'                  => $s['name'],
                    'slug'                  => $s['slug'],
                    'description'           => $s['description'],
                    'price_cents'           => $s['price_cents'],
                    'duration_minutes'      => $s['duration_minutes'],
                    'prep_before_minutes'   => $s['prep_before_minutes'],
                    'cleanup_after_minutes' => $s['cleanup_after_minutes'],
                    'slot_weight'           => $s['slot_weight'],
                    'is_active'             => true,
                    'sort_order'            => $sortOrder,
                ]);
                $sortOrder += 10;
            }
        }
        $this->log("  Services: " . count($servicesBySlug) . " items across " . count($categoriesBySlug) . " categories.");
        return [$categoriesBySlug, $servicesBySlug];
    }

    private function seedAddons(Tenant $tenant, array $servicesBySlug): array
    {
        $pivotsByService = [];
        foreach ($this->industry->addons() as $i => $a) {
            $addon = TenantAddon::create([
                'tenant_id'                => $tenant->id,
                'name'                     => $a['name'],
                'description'              => $a['description'],
                'price_cents'              => $a['price_cents'],
                'default_duration_minutes' => $a['default_duration_minutes'],
                'is_active'                => true,
                'sort_order'               => ($i + 1) * 10,
            ]);

            foreach ($a['applies_to'] as $serviceSlug) {
                if (!isset($servicesBySlug[$serviceSlug])) { continue; }
                $overrides = $a['overrides'][$serviceSlug] ?? [];
                $pivot = TenantServiceAddon::create([
                    'service_item_id'           => $servicesBySlug[$serviceSlug]->id,
                    'addon_id'                  => $addon->id,
                    'override_price_cents'      => $overrides['price_cents'] ?? null,
                    'override_duration_minutes' => $overrides['duration_minutes'] ?? null,
                    'sort_order'                => 0,
                ]);
                $pivotsByService[$serviceSlug][$addon->id] = ['addon' => $addon, 'pivot' => $pivot];
            }
        }
        $this->log("  Add-ons: " . count($this->industry->addons()) . " library items wired.");
        return $pivotsByService;
    }

    /**
     * Customers are created via raw DB insert to bypass Eloquent's timestamp
     * auto-management, which was overriding our seeded created_at values and
     * causing all customers to appear "new today" on the dashboard.
     *
     * Distribution: uniform across CUSTOMER_SPREAD_DAYS (365 days).
     */
    private function seedCustomers(Tenant $tenant): array
    {
        $first = $this->industry->firstNamePool();
        $last  = $this->industry->lastNamePool();

        $cities = [
            ['city' => 'Spokane',        'state' => 'WA', 'postcode' => '99201'],
            ['city' => 'Spokane',        'state' => 'WA', 'postcode' => '99202'],
            ['city' => 'Spokane',        'state' => 'WA', 'postcode' => '99203'],
            ['city' => 'Spokane',        'state' => 'WA', 'postcode' => '99207'],
            ['city' => 'Spokane',        'state' => 'WA', 'postcode' => '99208'],
            ['city' => 'Spokane Valley', 'state' => 'WA', 'postcode' => '99206'],
            ['city' => 'Spokane Valley', 'state' => 'WA', 'postcode' => '99216'],
            ['city' => 'Liberty Lake',   'state' => 'WA', 'postcode' => '99019'],
            ['city' => 'Cheney',         'state' => 'WA', 'postcode' => '99004'],
            ['city' => 'Mead',           'state' => 'WA', 'postcode' => '99021'],
            ['city' => 'Airway Heights', 'state' => 'WA', 'postcode' => '99001'],
            ['city' => 'Coeur dAlene',   'state' => 'ID', 'postcode' => '83814'],
            ['city' => 'Post Falls',     'state' => 'ID', 'postcode' => '83854'],
            ['city' => 'Hayden',         'state' => 'ID', 'postcode' => '83835'],
        ];

        $streetNames = ['Maple','Oak','Cedar','Pine','Elm','Birch','Spruce','Washington','Monroe','Lincoln','Jefferson','Madison','Division','Hamilton','Mission','Francis','Wellesley','Ridgeview','Hillcrest','Sunset','Lakeview','Riverside'];
        $streetTypes = ['St','Ave','Ln','Dr','Rd','Way','Ct'];

        $rows = [];
        $usedEmails = [];
        $now = Carbon::now();

        for ($i = 0; $i < self::CUSTOMER_COUNT; $i++) {
            $f = $first[array_rand($first)];
            $l = $last[array_rand($last)];

            $attempt = 0;
            do {
                $suffix = $attempt === 0 ? '' : (string) random_int(2, 99);
                $email = strtolower($f . '.' . $l . $suffix . '@example.com');
                $attempt++;
            } while (isset($usedEmails[$email]) && $attempt < 10);
            $usedEmails[$email] = true;

            $city = $cities[array_rand($cities)];

            // Uniform distribution over CUSTOMER_SPREAD_DAYS (365 days)
            $daysAgo = random_int(0, self::CUSTOMER_SPREAD_DAYS);
            $createdAt = $now->copy()
                ->subDays($daysAgo)
                ->subHours(random_int(0, 23))
                ->subMinutes(random_int(0, 59));

            $rows[] = [
                'id'            => (string) Str::uuid(),
                'tenant_id'     => $tenant->id,
                'first_name'    => $f,
                'last_name'     => $l,
                'email'         => $email,
                'phone'         => $this->generatePhone(),
                'address_line1' => random_int(100, 9999) . ' ' . $streetNames[array_rand($streetNames)] . ' ' . $streetTypes[array_rand($streetTypes)],
                'city'          => $city['city'],
                'state'         => $city['state'],
                'postcode'      => $city['postcode'],
                'country'       => 'US',
                'created_at'    => $createdAt->toDateTimeString(),
                'updated_at'    => $createdAt->toDateTimeString(),
            ];
        }

        // Insert in chunks via raw DB to bypass Eloquent timestamp overrides
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('tenant_customers')->insert($chunk);
        }

        // Hydrate Eloquent models for the rest of the seeder to use
        $ids = array_column($rows, 'id');
        $customers = TenantCustomer::whereIn('id', $ids)->get()->all();

        $this->log("  Customers: " . count($customers) . " spread across " . self::CUSTOMER_SPREAD_DAYS . " days.");
        return $customers;
    }

    private function generatePhone(): string
    {
        $areaCodes = ['509','208','509','509'];
        return sprintf('(%s) %03d-%04d', $areaCodes[array_rand($areaCodes)], random_int(200, 999), random_int(0, 9999));
    }

    /**
     * Seasonal monthly weight for Pacific Northwest bike shop.
     * Each value is an approximate target of appointments PER OPEN DAY (weekday)
     * in that month. Summer peaks, winter valleys.
     *
     * Keyed 1-12 (Jan-Dec).
     */
    private function monthlyWeight(int $month): int
    {
        return match ($month) {
            1  => 3,  // Jan - winter trough
            2  => 4,  // Feb
            3  => 5,  // Mar
            4  => 6,  // Apr
            5  => 9,  // May - shoulder
            6  => 14, // Jun - summer
            7  => 15, // Jul - peak summer
            8  => 14, // Aug
            9  => 9,  // Sep - shoulder
            10 => 6,  // Oct
            11 => 5,  // Nov
            12 => 3,  // Dec - winter trough
        };
    }

    /**
     * Build a pool of appointment dates distributed seasonally.
     * Looks backward one year + forward 2 weeks from today.
     *
     * Returns an array of Carbon dates, with frequency of each date
     * weighted by that date's month. Weekends are excluded entirely.
     *
     * @return array<Carbon>
     */
    private function buildSeasonalDatePool(int $totalNeeded): array
    {
        $pool = [];
        $today = Carbon::now()->startOfDay();
        $start = $today->copy()->subDays(365);
        $end = $today->copy()->addDays(14);

        // Future weights should be lower — these are bookings that haven't
        // happened yet, so even summer future-dated appointments are rarer.
        $cursor = $start->copy();
        while ($cursor->lessThanOrEqualTo($end)) {
            if (!$cursor->isWeekend()) {
                $weight = $this->monthlyWeight((int) $cursor->format('n'));

                // Scale down future dates — customers book ahead but not heavily
                if ($cursor->greaterThan($today)) {
                    $weight = max(1, (int) round($weight * 0.3));
                }

                // Add randomness per day: some days cluster, some are quiet
                $dayVariance = random_int(60, 140) / 100;  // 0.6 to 1.4
                $appointmentsThisDay = max(0, (int) round($weight * $dayVariance));

                for ($i = 0; $i < $appointmentsThisDay; $i++) {
                    $pool[] = $cursor->copy();
                }
            }
            $cursor->addDay();
        }

        // Shuffle and trim to the requested count
        shuffle($pool);
        return array_slice($pool, 0, $totalNeeded);
    }

    private function seedAppointments(
        Tenant $tenant,
        TenantUser $owner,
        array $customers,
        array $servicesBySlug,
        array $addonsByService,
    ): void {
        $serviceSlugs = array_keys($servicesBySlug);
        $sampleResponses = $this->industry->sampleResponses();
        $today = Carbon::now()->startOfDay();

        $datePool = $this->buildSeasonalDatePool(self::APPOINTMENT_COUNT);
        $actualCount = count($datePool);

        $this->log("  Generating {$actualCount} appointments with seasonal distribution...");

        $created = 0;
        foreach ($datePool as $date) {
            $numServices = $this->weightedPick([1 => 70, 2 => 25, 3 => 5]);
            $pickedSlugs = (array) array_rand(array_flip($serviceSlugs), $numServices);
            if ($numServices === 1) { $pickedSlugs = [$pickedSlugs[0]]; }

            $subtotal = 0;
            $totalDuration = 0;
            $maxSlotWeight = 1;
            $itemsToCreate = [];
            $addonsToCreate = [];

            foreach ($pickedSlugs as $slug) {
                $svc = $servicesBySlug[$slug];
                $subtotal += $svc->price_cents;
                $totalDuration += $svc->duration_minutes;
                $maxSlotWeight = max($maxSlotWeight, $svc->slot_weight);
                $itemsToCreate[] = [
                    'service_item_id'           => $svc->id,
                    'item_name_snapshot'        => $svc->name,
                    'price_cents'               => $svc->price_cents,
                    'duration_minutes_snapshot' => $svc->duration_minutes,
                ];

                if (isset($addonsByService[$slug]) && random_int(1, 100) <= 45) {
                    $availableAddons = $addonsByService[$slug];
                    $numAddons = random_int(1, min(2, count($availableAddons)));
                    $addonIdsPicked = (array) array_rand($availableAddons, $numAddons);
                    if ($numAddons === 1) { $addonIdsPicked = [$addonIdsPicked[0]]; }
                    foreach ($addonIdsPicked as $addonId) {
                        $bundle = $availableAddons[$addonId];
                        $addon = $bundle['addon'];
                        $pivot = $bundle['pivot'];
                        $addonPrice = $pivot->override_price_cents ?? $addon->price_cents;
                        $addonDuration = $pivot->override_duration_minutes ?? $addon->default_duration_minutes;
                        $subtotal += $addonPrice;
                        $totalDuration += $addonDuration;
                        $addonsToCreate[] = [
                            'addon_id'                  => $addon->id,
                            'addon_name_snapshot'       => $addon->name,
                            'price_cents'               => $addonPrice,
                            'duration_minutes_snapshot' => $addonDuration,
                        ];
                    }
                }
            }

            $tax = (int) round($subtotal * 0.089);
            $total = $subtotal + $tax;
            $customer = $customers[array_rand($customers)];

            $status = $this->pickStatus($date, $today);
            $paymentStatus = $this->pickPaymentStatus($status);
            $paidCents = $paymentStatus === 'paid' ? $total
                : ($paymentStatus === 'partial' ? (int) round($total * 0.5)
                : ($paymentStatus === 'refunded' ? $total : 0));
            $paymentMethod = $paymentStatus !== 'unpaid'
                ? $this->weightedPick(['stripe' => 70, 'cash' => 20, 'paypal' => 10])
                : null;

            $receivingMethods = ['Drop-off at shop', 'Mail-in', 'Scheduled appointment'];
            $receivingIdx = $this->weightedPickIndex([70, 15, 15]);
            $receivingName = $receivingMethods[$receivingIdx];
            $appointmentTime = null;
            $appointmentEndTime = null;
            $receivingTime = null;
            if ($receivingIdx === 2) {
                $hour = random_int(9, 16);
                $minute = [0, 30][array_rand([0, 30])];
                $appointmentTime = sprintf('%02d:%02d:00', $hour, $minute);
                $endMinutes = $hour * 60 + $minute + $totalDuration;
                $appointmentEndTime = sprintf('%02d:%02d:00', intdiv($endMinutes, 60) % 24, $endMinutes % 60);
                $receivingTime = sprintf('%d:%02d %s', ($hour > 12 ? $hour - 12 : $hour), $minute, $hour >= 12 ? 'PM' : 'AM');
            }
            $trackingNumber = $receivingIdx === 1 ? '1Z' . strtoupper(Str::random(16)) : null;

            $raNumber = TenantAppointment::generateRaNumber($tenant->id, $date->toDateString());

            $appointment = TenantAppointment::create([
                'tenant_id'                 => $tenant->id,
                'customer_id'               => $customer->id,
                'ra_number'                 => $raNumber,
                'customer_first_name'       => $customer->first_name,
                'customer_last_name'        => $customer->last_name,
                'customer_email'            => $customer->email,
                'customer_phone'            => $customer->phone,
                'appointment_date'          => $date->toDateString(),
                'appointment_time'          => $appointmentTime,
                'appointment_end_time'      => $appointmentEndTime,
                'total_duration_minutes'    => $totalDuration,
                'slot_weight'               => $maxSlotWeight,
                'slot_weight_auto'          => $maxSlotWeight,
                'slot_weight_overridden'    => false,
                'receiving_method_snapshot' => $receivingName,
                'receiving_time_snapshot'   => $receivingTime,
                'tracking_number'           => $trackingNumber,
                'status'                    => $status,
                'payment_status'            => $paymentStatus,
                'payment_method'            => $paymentMethod,
                'subtotal_cents'            => $subtotal,
                'tax_cents'                 => $tax,
                'total_cents'               => $total,
                'paid_cents'                => $paidCents,
                'created_at'                => $this->appointmentCreationDate($date, $status),
            ]);

            foreach ($itemsToCreate as $item) {
                $appointment->items()->create($item);
            }
            foreach ($addonsToCreate as $addon) {
                $appointment->addons()->create($addon);
            }

            foreach ($sampleResponses as $fieldKey => $source) {
                $value = is_callable($source) ? $source() : $source[array_rand($source)];
                $fieldLabel = match ($fieldKey) {
                    'bike_make'         => 'Bike Brand',
                    'bike_model'        => 'Model',
                    'bike_year'         => 'Model Year',
                    'issue_description' => 'Whats going on?',
                    default             => $fieldKey,
                };
                TenantAppointmentResponse::create([
                    'appointment_id'       => $appointment->id,
                    'field_key_snapshot'   => $fieldKey,
                    'field_label_snapshot' => $fieldLabel,
                    'response_value'       => $value,
                ]);
            }

            if (random_int(1, 100) <= 15) {
                TenantAppointmentNote::create([
                    'appointment_id'      => $appointment->id,
                    'user_id'             => $owner->id,
                    'note_type'           => 'staff',
                    'is_customer_visible' => false,
                    'note_content'        => $this->pickStaffNote(),
                ]);
            }
            $created++;
        }
        $this->log("  Appointments: {$created}");
    }

    /**
     * Tighter status distribution so "completed" only means
     * "ready for pickup" — current/very-recent jobs only.
     */
    private function pickStatus(Carbon $date, Carbon $today): string
    {
        if ($date->greaterThan($today)) {
            return $this->weightedPick(['confirmed' => 70, 'pending' => 30]);
        }
        if ($date->isSameDay($today)) {
            return $this->weightedPick(['in_progress' => 40, 'confirmed' => 30, 'completed' => 20, 'pending' => 10]);
        }

        $daysAgo = $today->diffInDays($date);
        if ($daysAgo <= 2) {
            // Recent past: mostly completed (ready for pickup) or closed (handed off)
            return $this->weightedPick(['completed' => 45, 'closed' => 45, 'in_progress' => 5, 'cancelled' => 5]);
        }
        if ($daysAgo <= 14) {
            // Past 2 weeks: almost all closed, no more ready-for-pickup
            return $this->weightedPick(['closed' => 88, 'cancelled' => 6, 'refunded' => 3, 'shipped' => 3]);
        }
        // Older: almost entirely closed
        return $this->weightedPick(['closed' => 92, 'cancelled' => 4, 'refunded' => 2, 'shipped' => 2]);
    }

    private function pickPaymentStatus(string $status): string
    {
        return match ($status) {
            'pending', 'confirmed' => 'unpaid',
            'in_progress'          => $this->weightedPick(['unpaid' => 60, 'partial' => 40]),
            'completed'            => $this->weightedPick(['paid' => 75, 'partial' => 20, 'unpaid' => 5]),
            'closed', 'shipped'    => 'paid',
            'cancelled'            => $this->weightedPick(['unpaid' => 80, 'refunded' => 20]),
            'refunded'             => 'refunded',
            default                => 'unpaid',
        };
    }

    private function appointmentCreationDate(Carbon $appointmentDate, string $status): Carbon
    {
        if (in_array($status, ['pending', 'confirmed'], true)) {
            return $appointmentDate->copy()->subDays(random_int(0, 10))->subHours(random_int(0, 23));
        }
        return $appointmentDate->copy()->subDays(random_int(0, 3))->subHours(random_int(0, 10));
    }

    private function pickStaffNote(): string
    {
        $notes = [
            'Customer prefers text over email.',
            'Called to confirm pickup window.',
            'Needs hanger replacement - ordered, will arrive Thursday.',
            'Asked about upgrade options for next season.',
            'Referred by current customer.',
            'Repeat customer - annual service.',
            'Pickup done, handed off personally.',
            'Dropped off early, parked inside.',
            'Discussed tubeless upgrade - deferred.',
            'Quoted additional work, approved.',
        ];
        return $notes[array_rand($notes)];
    }

    private function weightedPick(array $weightMap): string|int
    {
        $total = array_sum($weightMap);
        $r = random_int(1, $total);
        $cum = 0;
        foreach ($weightMap as $key => $weight) {
            $cum += $weight;
            if ($r <= $cum) { return $key; }
        }
        return array_key_first($weightMap);
    }

    private function weightedPickIndex(array $weights): int
    {
        $total = array_sum($weights);
        $r = random_int(1, $total);
        $cum = 0;
        foreach ($weights as $i => $weight) {
            $cum += $weight;
            if ($r <= $cum) { return $i; }
        }
        return 0;
    }
}
