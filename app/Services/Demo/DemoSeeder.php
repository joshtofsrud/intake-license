<?php

namespace App\Services\Demo;

use App\Models\Tenant;
use App\Models\Tenant\TenantAddon;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentNote;
use App\Models\Tenant\TenantAppointmentResponse;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantClassMembershipProduct;
use App\Models\Tenant\TenantClassPackProduct;
use App\Models\Tenant\TenantClassRegistration;
use App\Models\Tenant\TenantClassSession;
use App\Models\Tenant\TenantClassTemplate;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantCustomerMembership;
use App\Models\Tenant\TenantCustomerPack;
use App\Models\Tenant\TenantFormField;
use App\Models\Tenant\TenantFormSection;
use App\Models\Tenant\TenantReceivingMethod;
use App\Models\Tenant\TenantResource;
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
    private const CUSTOMER_COUNT       = 200;
    private const APPOINTMENT_COUNT    = 1800;
    private const CUSTOMER_SPREAD_DAYS = 365;
    private const CAPACITY_PER_DAY     = 16;

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

        // Lock booking_mode from the industry contract before any appointment
        // generation. The seeder picks receiving methods downstream based on
        // this setting.
        $tenant->update(['booking_mode' => $this->industry->bookingMode()]);

        $owner = $this->createOwner($tenant, $ownerName, $ownerEmail, $ownerPassword);
        $this->seedAdditionalResources($tenant);
        $this->seedCapacityRules($tenant);
        $this->seedReceivingMethods($tenant);
        $this->seedFormFields($tenant);
        [$categoriesBySlug, $servicesBySlug] = $this->seedCatalog($tenant);
        $addonsByService = $this->seedAddons($tenant, $servicesBySlug);
        $customers = $this->seedCustomers($tenant);
        $this->seedAppointments($tenant, $owner, $customers, $servicesBySlug, $addonsByService);

        // Class architecture (yoga/fitness/etc). No-op for industries that
        // return [] from classTemplates/membershipProducts/packProducts.
        $this->seedClasses($tenant, $customers);

        // Sub-seeders (waitlist, campaigns, pages)
        // Work-order field definitions + responses (must run after appointments exist)
        (new WorkOrderSeeder($this->industry, $this->logger))->seed($tenant);

        (new WaitlistSeeder($this->logger))->seed($tenant, $customers, $servicesBySlug);
        (new CampaignsSeeder($this->logger))->seed($tenant, $owner, $customers);

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

    /**
     * Seed industry-defined additional resources. The owner resource is
     * auto-created by TenantUserObserver when createOwner() runs; this fills
     * out the rest from the industry contract. Empty contract = single-resource.
     */
    private function seedAdditionalResources(Tenant $tenant): void
    {
        $rows = $this->industry->additionalResources();
        if (empty($rows)) {
            $this->log("  Resources: owner-only (industry has no additional resources).");
            return;
        }

        // Owner resource was just created by TenantUserObserver and has
        // sort_order = 0 (or whatever the observer set). New resources start
        // after that.
        $startSort = (int) (\App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->max('sort_order') ?? -1) + 1;

        foreach ($rows as $i => $row) {
            \App\Models\Tenant\TenantResource::create([
                'id'                       => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id'                => $tenant->id,
                'name'                     => $row['name'],
                'subtitle'                 => $row['subtitle'] ?? null,
                'color_hex'                => $row['color_hex'] ?? '#8A8A82',
                'type'                     => $row['type'] ?? 'staff',
                'sort_order'               => $startSort + $i,
                'is_active'                => true,
                'max_appointments_per_day' => $row['max_appointments_per_day'] ?? null,
            ]);
        }

        $count = count($rows) + 1; // +1 for owner
        $this->log("  Resources: {$count} total (owner + " . count($rows) . " seeded).");
    }

    private function seedCapacityRules(Tenant $tenant): void
    {
        // New schema (2026-04-28 rebuild):
        //   is_closed flag explicit; weekends seeded as closed.
        //   max_appointments NULL by default — it's now an OPTIONAL shop-wide
        //   override on top of per-resource caps. Demo data uses resource caps
        //   (seeded elsewhere) as the primary capacity ceiling.
        //   open/close/slot_interval seeded for non-closed days so time-slot
        //   mode tenants have a working grid out of the box.
        for ($dow = 0; $dow <= 6; $dow++) {
            $isWeekend = $dow === 0 || $dow === 6;
            TenantCapacityRule::create([
                'tenant_id'             => $tenant->id,
                'rule_type'             => 'default',
                'day_of_week'           => $dow,
                'specific_date'         => null,
                'is_closed'             => $isWeekend,
                'max_appointments'      => null,
                'open_time'             => $isWeekend ? null : '09:00:00',
                'close_time'            => $isWeekend ? null : '17:00:00',
                'slot_interval_minutes' => 60,
                'note'                  => null,
            ]);
        }
        $this->log("  Capacity: 9-5 Mon-Fri, closed weekends. Per-resource caps govern.");
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

            $daysAgo = random_int(0, self::CUSTOMER_SPREAD_DAYS);
            $createdAt = $now->copy()->subDays($daysAgo)->subHours(random_int(0, 23))->subMinutes(random_int(0, 59));

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

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('tenant_customers')->insert($chunk);
        }

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

    private function monthlyWeight(int $month): int
    {
        return match ($month) {
            1  => 3,  2  => 4,  3  => 5,  4  => 6,  5  => 9,  6  => 14,
            7  => 15, 8  => 14, 9  => 9,  10 => 6,  11 => 5,  12 => 3,
        };
    }

    private function buildSeasonalDatePool(int $totalNeeded): array
    {
        $pool = [];
        $today = Carbon::now()->startOfDay();
        $start = $today->copy()->subDays(365);
        $end = $today->copy()->addDays(14);

        $cursor = $start->copy();
        while ($cursor->lessThanOrEqualTo($end)) {
            if (!$cursor->isWeekend()) {
                $weight = $this->monthlyWeight((int) $cursor->format('n'));
                if ($cursor->greaterThan($today)) {
                    $weight = max(1, (int) round($weight * 0.3));
                }
                $dayVariance = random_int(60, 140) / 100;
                $appointmentsThisDay = max(0, (int) round($weight * $dayVariance));
                for ($i = 0; $i < $appointmentsThisDay; $i++) {
                    $pool[] = $cursor->copy();
                }
            }
            $cursor->addDay();
        }

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

        // Pull active resources once for round-robin assignment. Seeded
        // appointments distribute across resources so the calendar populates
        // with realistic resource diversity. If no resources exist (shouldn't
        // happen post-seedAdditionalResources, but defensive), assignments
        // remain NULL and calendar groups them under "unassigned."
        $resourceIds = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();
        $resourceCount = count($resourceIds);
        $resourceCursor = 0;

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

            // Pick a receiving method that matches the tenant's booking_mode.
            //
            // drop_off mode → seed only drop-off-style methods (ask_for_time=false).
            //                 No appointment_time set; calendar shows them via
            //                 the drop-off (capacity bar) view.
            // time_slots mode → seed only time-bound methods (ask_for_time=true).
            //                   appointment_time gets a real slot.
            //
            // Other methods (e.g. mail-in) stay installed for admin use but
            // aren't picked by the seeder. Mixing modes in seeded data would
            // produce appointments that can't render in the active calendar.
            $industryMethods = $this->industry->receivingMethods();
            $isTimeSlots = $this->industry->bookingMode() === 'time_slots';

            $eligibleMethods = array_values(array_filter(
                $industryMethods,
                fn($m) => $isTimeSlots ? !empty($m['ask_for_time']) : empty($m['ask_for_time']),
            ));

            if (empty($eligibleMethods)) {
                // Defensive fallback — shouldn't happen if industry data is
                // consistent with bookingMode(), but rather than throw on a
                // mismatched contract, just use the first method as-is.
                $eligibleMethods = $industryMethods;
            }

            $methodCount = count($eligibleMethods);
            if ($methodCount === 1) {
                $method = $eligibleMethods[0];
            } else {
                // First eligible method weighted heaviest; rest split evenly.
                $weights = array_fill(0, $methodCount, (int) floor(30 / ($methodCount - 1)));
                $weights[0] = 70;
                $methodIdx = $this->weightedPickIndex($weights);
                $method = $eligibleMethods[$methodIdx];
            }
            $receivingName = $method['name'];

            $appointmentTime = null;
            $appointmentEndTime = null;
            $receivingTime = null;
            if (!empty($method['ask_for_time'])) {
                $hour = random_int(9, 16);
                $minute = [0, 30][array_rand([0, 30])];
                $appointmentTime = sprintf('%02d:%02d:00', $hour, $minute);
                $endMinutes = $hour * 60 + $minute + $totalDuration;
                $appointmentEndTime = sprintf('%02d:%02d:00', intdiv($endMinutes, 60) % 24, $endMinutes % 60);
                $receivingTime = sprintf('%d:%02d %s', ($hour > 12 ? $hour - 12 : $hour), $minute, $hour >= 12 ? 'PM' : 'AM');
            }
            $trackingNumber = !empty($method['ask_for_tracking'])
                ? '1Z' . strtoupper(Str::random(16))
                : null;

            $raNumber = TenantAppointment::generateRaNumber($tenant->id, $date->toDateString());

            // Round-robin resource assignment. Modulo handles any pool size.
            $assignedResourceId = $resourceCount > 0
                ? $resourceIds[$resourceCursor++ % $resourceCount]
                : null;

            $appointment = TenantAppointment::create([
                'tenant_id'                 => $tenant->id,
                'customer_id'               => $customer->id,
                'resource_id'               => $assignedResourceId,
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
            ]);

            // Force the seeded created_at past Eloquent's auto-timestamp handling
            $seededCreatedAt = $this->appointmentCreationDate($date, $status);
            $appointment->created_at = $seededCreatedAt;
            $appointment->updated_at = $seededCreatedAt;
            $appointment->saveQuietly();

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

    private function pickStatus(Carbon $date, Carbon $today): string
    {
        if ($date->greaterThan($today)) {
            return $this->weightedPick(['confirmed' => 70, 'pending' => 30]);
        }
        if ($date->isSameDay($today)) {
            return $this->weightedPick(['in_progress' => 40, 'confirmed' => 30, 'completed' => 20, 'pending' => 10]);
        }
        $daysAgo = abs($today->diffInDays($date));
        if ($daysAgo <= 2) {
            return $this->weightedPick(['completed' => 45, 'closed' => 45, 'in_progress' => 5, 'cancelled' => 5]);
        }
        if ($daysAgo <= 14) {
            return $this->weightedPick(['closed' => 88, 'cancelled' => 6, 'refunded' => 3, 'shipped' => 3]);
        }
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

    // ------------------------------------------------------------------
    // Class architecture (yoga/fitness/etc.)
    // ------------------------------------------------------------------

    /**
     * Seed class templates, sessions, membership/pack products, and a
     * realistic mix of customer assignments + registrations. No-op when
     * the industry contract returns no class data.
     *
     * Generated:
     *  - Templates from classTemplates(), instructor resolved by index
     *  - 28 days of sessions (-14 to +14 from today) per template/schedule pair
     *  - Membership products from membershipProducts()
     *  - Pack products from packProducts()
     *  - Active memberships for ~25% of customers, packs for ~20%
     *  - Past registrations marked completed/no_show, future registrations
     *    consuming the right payment source, with some sessions filled to
     *    full to demonstrate waitlist behavior.
     */
    private function seedClasses(Tenant $tenant, array $customers): void
    {
        $templates = $this->industry->classTemplates();
        $memberships = $this->industry->membershipProducts();
        $packs = $this->industry->packProducts();

        if (empty($templates) && empty($memberships) && empty($packs)) {
            return; // Industry has no class architecture — clean skip.
        }

        // Flip the gate so the sidebar and customer-facing UI surface classes.
        $tenant->update(['classes_enabled' => true]);

        // Resolve instructor resources by sort_order index. Index 0 = owner
        // (auto-seeded), 1+ = additional resources.
        $resources = TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->values();

        $templatesBySlug = $this->seedClassTemplates($tenant, $templates, $resources);
        $sessionsByTemplate = $this->seedClassSessions($tenant, $templatesBySlug);
        $membershipProducts = $this->seedMembershipProducts($tenant, $memberships);
        $packProducts = $this->seedPackProducts($tenant, $packs);

        // Customer assignments + registrations only happen when there's
        // something to register against AND someone to register.
        if (!empty($sessionsByTemplate) && !empty($customers)) {
            $customerMemberships = $this->seedCustomerMemberships(
                $tenant, $customers, $membershipProducts
            );
            $customerPacks = $this->seedCustomerPacks(
                $tenant, $customers, $packProducts
            );
            $this->seedClassRegistrations(
                $tenant, $sessionsByTemplate, $customers,
                $customerMemberships, $customerPacks
            );
        }

        $this->log("  Classes: enabled.");
    }

    private function seedClassTemplates(Tenant $tenant, array $templates, $resources): array
    {
        $bySlug = [];
        foreach ($templates as $t) {
            $instructorIdx = $t['instructor_index'] ?? null;
            $instructor = ($instructorIdx !== null && isset($resources[$instructorIdx]))
                ? $resources[$instructorIdx]
                : ($resources[0] ?? null); // owner fallback

            $bySlug[$t['slug']] = TenantClassTemplate::create([
                'tenant_id'              => $tenant->id,
                'name'                   => $t['name'],
                'slug'                   => $t['slug'],
                'description'            => $t['description'] ?? null,
                'duration_minutes'       => $t['duration_minutes'],
                'default_capacity'       => $t['default_capacity'],
                'instructor_resource_id' => $instructor?->id,
                'price_cents'            => $t['price_cents'],
                'is_active'              => true,
                'metadata'               => ['schedule' => $t['schedule'] ?? []],
            ]);
        }
        $this->log("  Class templates: " . count($bySlug));
        return $bySlug;
    }

    /**
     * Generate 28 days of sessions (-14 to +14 from today) per template/schedule
     * pair. Past sessions get status=completed, future sessions get
     * status=confirmed.
     *
     * Returns: ['template-slug' => [TenantClassSession, ...]] split into
     * past and future for downstream registration seeding.
     */
    private function seedClassSessions(Tenant $tenant, array $templatesBySlug): array
    {
        $byTemplate = [];
        $today = Carbon::now()->startOfDay();
        $start = $today->copy()->subDays(14);
        $end = $today->copy()->addDays(14);

        $totalSessions = 0;

        foreach ($templatesBySlug as $slug => $template) {
            $byTemplate[$slug] = ['past' => [], 'future' => []];
            $schedule = $template->metadata['schedule'] ?? [];

            foreach ($schedule as $slot) {
                $cursor = $start->copy();
                while ($cursor->lessThanOrEqualTo($end)) {
                    if ($cursor->dayOfWeek === $slot['dow']) {
                        [$h, $m] = explode(':', $slot['time']);
                        $startsAt = $cursor->copy()->setTime((int) $h, (int) $m);
                        $endsAt = $startsAt->copy()->addMinutes($template->duration_minutes);

                        $isPast = $startsAt->isPast();
                        $instructorName = null;
                        if ($template->instructor_resource_id) {
                            $instructorName = TenantResource::find($template->instructor_resource_id)?->name;
                        }

                        $session = TenantClassSession::create([
                            'tenant_id'              => $tenant->id,
                            'class_template_id'      => $template->id,
                            'starts_at'              => $startsAt,
                            'ends_at'                => $endsAt,
                            'instructor_resource_id' => $template->instructor_resource_id,
                            'instructor_snapshot'    => $instructorName,
                            'capacity_snapshot'      => $template->default_capacity,
                            'status'                 => $isPast ? 'completed' : 'confirmed',
                        ]);

                        $byTemplate[$slug][$isPast ? 'past' : 'future'][] = $session;
                        $totalSessions++;
                    }
                    $cursor->addDay();
                }
            }
        }

        $this->log("  Class sessions: {$totalSessions} (past + future across all templates).");
        return $byTemplate;
    }

    private function seedMembershipProducts(Tenant $tenant, array $memberships): array
    {
        $products = [];
        foreach ($memberships as $m) {
            $products[] = TenantClassMembershipProduct::create([
                'tenant_id'     => $tenant->id,
                'name'          => $m['name'],
                'description'   => $m['description'] ?? null,
                'type'          => $m['type'],
                'monthly_limit' => $m['type'] === 'capped' ? $m['monthly_limit'] : null,
                'price_cents'   => $m['price_cents'],
                'is_active'     => true,
            ]);
        }
        $this->log("  Membership products: " . count($products));
        return $products;
    }

    private function seedPackProducts(Tenant $tenant, array $packs): array
    {
        $products = [];
        foreach ($packs as $p) {
            $products[] = TenantClassPackProduct::create([
                'tenant_id'    => $tenant->id,
                'name'         => $p['name'],
                'description'  => $p['description'] ?? null,
                'credit_count' => $p['credit_count'],
                'expiry_days'  => $p['expiry_days'],
                'price_cents'  => $p['price_cents'],
                'is_active'    => true,
            ]);
        }
        $this->log("  Pack products: " . count($products));
        return $products;
    }

    /**
     * Assign active memberships to ~25% of customers. Period starts somewhere
     * between 0 and 28 days ago (so some are mid-period, some near rollover).
     *
     * Returns: ['customer_id' => TenantCustomerMembership]
     */
    private function seedCustomerMemberships(Tenant $tenant, array $customers, array $products): array
    {
        if (empty($products)) { return []; }

        $byCustomer = [];
        $today = Carbon::now()->startOfDay();

        // ~25% of customers get a membership.
        $count = (int) round(count($customers) * 0.25);
        $picked = collect($customers)->shuffle()->take($count);

        foreach ($picked as $customer) {
            $product = $products[array_rand($products)];

            // Period starts 0-28 days ago, runs 30 days from there.
            $daysIntoPeriod = random_int(0, 28);
            $periodStart = $today->copy()->subDays($daysIntoPeriod);
            $periodEnd = $periodStart->copy()->addDays(30);

            // For capped memberships, use up some classes so the dashboard
            // shows realistic "X of Y used" numbers.
            $used = 0;
            if ($product->type === 'capped' && $product->monthly_limit) {
                $maxAlreadyUsed = (int) floor($product->monthly_limit * ($daysIntoPeriod / 30));
                $used = random_int(0, min($maxAlreadyUsed, $product->monthly_limit - 1));
            } elseif ($product->type === 'unlimited') {
                $used = random_int(0, max(1, $daysIntoPeriod / 3));
            }

            $membership = TenantCustomerMembership::create([
                'tenant_id'                => $tenant->id,
                'customer_id'              => $customer->id,
                'product_id'               => $product->id,
                'status'                   => 'active',
                'current_period_start'     => $periodStart->toDateString(),
                'current_period_end'       => $periodEnd->toDateString(),
                'classes_used_this_period' => $used,
            ]);

            $byCustomer[$customer->id] = $membership;
        }

        $this->log("  Customer memberships: " . count($byCustomer) . " active.");
        return $byCustomer;
    }

    /**
     * Assign packs to ~20% of customers. Mix of fresh, partially-used, and
     * close-to-expiry packs so the customer portal shows variety.
     *
     * Returns: ['customer_id' => [TenantCustomerPack, ...]]
     */
    private function seedCustomerPacks(Tenant $tenant, array $customers, array $products): array
    {
        if (empty($products)) { return []; }

        $byCustomer = [];
        $today = Carbon::now()->startOfDay();

        // ~20% of customers get at least one pack.
        $count = (int) round(count($customers) * 0.20);
        $picked = collect($customers)->shuffle()->take($count);

        foreach ($picked as $customer) {
            // 80% one pack, 20% two (different sizes).
            $packCount = $this->weightedPick([1 => 80, 2 => 20]);
            $usedProductIds = [];

            for ($i = 0; $i < $packCount; $i++) {
                $available = array_filter($products, fn($p) => !in_array($p->id, $usedProductIds, true));
                if (empty($available)) { break; }
                $product = $available[array_rand($available)];
                $usedProductIds[] = $product->id;

                // Variety in pack age:
                //  - 50% recently bought (< 1/3 used)
                //  - 30% mid-life (1/3 to 2/3 used)
                //  - 20% near-empty or near-expiry
                $stage = $this->weightedPick(['fresh' => 50, 'mid' => 30, 'late' => 20]);
                $remaining = match ($stage) {
                    'fresh' => max(1, (int) round($product->credit_count * (random_int(70, 100) / 100))),
                    'mid'   => max(1, (int) round($product->credit_count * (random_int(35, 65) / 100))),
                    'late'  => random_int(1, max(1, (int) floor($product->credit_count * 0.25))),
                };

                // Bought somewhere between 1 day and (expiry_days - 7) ago.
                $maxAge = max(1, $product->expiry_days - 7);
                $boughtDaysAgo = random_int(1, $maxAge);
                $expiresAt = $today->copy()->subDays($boughtDaysAgo)->addDays($product->expiry_days);

                $pack = TenantCustomerPack::create([
                    'tenant_id'         => $tenant->id,
                    'customer_id'       => $customer->id,
                    'product_id'        => $product->id,
                    'credits_total'     => $product->credit_count,
                    'credits_remaining' => $remaining,
                    'expires_at'        => $expiresAt->toDateString(),
                    'status'            => 'active',
                ]);

                $byCustomer[$customer->id][] = $pack;
            }
        }

        $totalPacks = array_sum(array_map('count', $byCustomer));
        $this->log("  Customer packs: {$totalPacks} active across " . count($byCustomer) . " customers.");
        return $byCustomer;
    }

    /**
     * Seed class registrations:
     *   - Past sessions: ~70-90% capacity, status=completed (a few no_show/cancelled)
     *   - Future sessions: 30-80% capacity, status=registered (one or two
     *     filled to capacity to demonstrate waitlist UI)
     *
     * Bypasses ClassRegistrationService and writes directly because:
     *   1. Speed (bulk seeds)
     *   2. We control the historical mix of statuses
     *   3. Membership counters and pack credits are already pre-set to realistic
     *      values in seedCustomerMemberships/seedCustomerPacks; double-counting
     *      every historical registration would over-deplete them
     */
    private function seedClassRegistrations(
        Tenant $tenant,
        array $sessionsByTemplate,
        array $customers,
        array $customerMemberships,
        array $customerPacks,
    ): void {
        $totalRegs = 0;
        $waitlistsCreated = 0;
        $customersById = [];
        foreach ($customers as $c) { $customersById[$c->id] = $c; }
        $allCustomerIds = array_keys($customersById);

        // Track per-session registered customer IDs to enforce the unique
        // (session, customer, status) constraint.
        $sessionRegistrants = [];

        foreach ($sessionsByTemplate as $slug => $buckets) {
            // Past sessions
            foreach ($buckets['past'] as $session) {
                $fillRate = random_int(60, 90) / 100;
                $targetCount = (int) round($session->capacity_snapshot * $fillRate);
                $registered = 0;

                $shuffled = collect($allCustomerIds)->shuffle()->all();
                $sessionRegistrants[$session->id] = [];

                foreach ($shuffled as $cid) {
                    if ($registered >= $targetCount) { break; }
                    if (in_array($cid, $sessionRegistrants[$session->id], true)) { continue; }

                    $payment = $this->pickHistoricalPayment($cid, $customerMemberships, $customerPacks);

                    // Outcome mix for past sessions
                    $outcome = $this->weightedPick([
                        'completed'  => 80,
                        'no_show'    => 10,
                        'cancelled'  => 10,
                    ]);

                    // checked_in == "showed up." Map completed (legacy term in
                    // some industry copy) to checked_in for active-state correctness.
                    $status = $outcome === 'completed' ? 'checked_in' : $outcome;

                    TenantClassRegistration::create([
                        'tenant_id'        => $tenant->id,
                        'class_session_id' => $session->id,
                        'customer_id'      => $cid,
                        'status'           => $status,
                        'payment_method'   => $payment['method'],
                        'membership_id'    => $payment['membership_id'],
                        'pack_id'          => $payment['pack_id'],
                        'paid_cents'       => $payment['paid_cents'],
                        'registered_at'    => $session->starts_at->copy()->subDays(random_int(1, 5)),
                        'cancelled_at'     => $status === 'cancelled' ? $session->starts_at->copy()->subHours(random_int(2, 24)) : null,
                    ]);

                    $sessionRegistrants[$session->id][] = $cid;
                    $registered++;
                    $totalRegs++;
                }
            }

            // Future sessions — pick one to fill to capacity for waitlist demo
            $futureSessions = $buckets['future'];
            $fillToCapIdx = !empty($futureSessions) && random_int(1, 3) === 1
                ? array_rand($futureSessions)
                : null;

            foreach ($futureSessions as $idx => $session) {
                $isFillToCap = $idx === $fillToCapIdx;
                if ($isFillToCap) {
                    // Fill to capacity, then 2-4 waitlisters
                    $targetCount = $session->capacity_snapshot;
                    $waitlistTarget = random_int(2, 4);
                } else {
                    $fillRate = random_int(30, 80) / 100;
                    $targetCount = (int) round($session->capacity_snapshot * $fillRate);
                    $waitlistTarget = 0;
                }

                $registered = 0;
                $waitlisted = 0;
                $waitlistPos = 0;
                $shuffled = collect($allCustomerIds)->shuffle()->all();
                $sessionRegistrants[$session->id] = [];

                foreach ($shuffled as $cid) {
                    if ($registered >= $targetCount && $waitlisted >= $waitlistTarget) { break; }
                    if (in_array($cid, $sessionRegistrants[$session->id], true)) { continue; }

                    $payment = $this->pickHistoricalPayment($cid, $customerMemberships, $customerPacks);

                    if ($registered < $targetCount) {
                        $status = 'registered';
                        $waitlistPosForRow = null;
                        $registered++;
                    } else {
                        $status = 'waitlisted';
                        $waitlistPos++;
                        $waitlistPosForRow = $waitlistPos;
                        $waitlisted++;
                        $waitlistsCreated++;
                    }

                    TenantClassRegistration::create([
                        'tenant_id'         => $tenant->id,
                        'class_session_id'  => $session->id,
                        'customer_id'       => $cid,
                        'status'            => $status,
                        'payment_method'    => $payment['method'],
                        'membership_id'     => $payment['membership_id'],
                        'pack_id'           => $payment['pack_id'],
                        'paid_cents'        => $payment['paid_cents'],
                        'waitlist_position' => $waitlistPosForRow,
                        'registered_at'     => Carbon::now()->subDays(random_int(0, 7))->subHours(random_int(0, 23)),
                    ]);

                    $sessionRegistrants[$session->id][] = $cid;
                    $totalRegs++;
                }
            }
        }

        $this->log("  Class registrations: {$totalRegs} ({$waitlistsCreated} on waitlists across full sessions).");
    }

    /**
     * Pick a payment method snapshot for a historical/seeded registration.
     * Doesn't decrement live balances — this is purely for the registration
     * row's payment provenance fields. Customer membership/pack balances are
     * pre-seeded with realistic usage in seedCustomerMemberships/Packs.
     */
    private function pickHistoricalPayment(
        string $customerId,
        array $customerMemberships,
        array $customerPacks,
    ): array {
        $hasMembership = isset($customerMemberships[$customerId]);
        $hasPack = isset($customerPacks[$customerId]) && !empty($customerPacks[$customerId]);

        if ($hasMembership) {
            return [
                'method'        => 'membership',
                'membership_id' => $customerMemberships[$customerId]->id,
                'pack_id'       => null,
                'paid_cents'    => 0,
            ];
        }

        if ($hasPack) {
            return [
                'method'        => 'pack',
                'membership_id' => null,
                'pack_id'       => $customerPacks[$customerId][0]->id,
                'paid_cents'    => 0,
            ];
        }

        // Drop-in pricing — grab a sane default. Per-class is the one that
        // shows up as paid_cents on the registration.
        return [
            'method'        => $this->weightedPick(['per_class' => 70, 'cash' => 30]),
            'membership_id' => null,
            'pack_id'       => null,
            'paid_cents'    => 2500, // Matches typical drop-in price.
        ];
    }

    // ------------------------------------------------------------------
    // Random helpers
    // ------------------------------------------------------------------

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
