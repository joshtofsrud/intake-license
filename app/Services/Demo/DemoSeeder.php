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
use App\Models\Tenant\TenantResource;
use App\Models\Tenant\TenantServiceAddon;
use App\Models\Tenant\TenantServiceCategory;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantUser;
use App\Models\Tenant\TenantInventoryCategory;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Models\Tenant\TenantLocation;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSaleItem;
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

        // Set classes_enabled from override or auto-derive from classTemplates.
        $override = $this->industry->classesEnabledOverride();
        $classesEnabled = $override !== null
            ? $override
            : !empty($this->industry->classTemplates());
        if ($tenant->classes_enabled !== $classesEnabled) {
            $tenant->update(['classes_enabled' => $classesEnabled]);
            $this->log("  Classes addon: " . ($classesEnabled ? 'enabled' : 'disabled'));
        }

        // Inventory (categories + items + per-location stock).
        $this->seedInventory($tenant);

        // Quotes + drafts (open sales-in-progress for the dashboard).
        $this->seedQuotesAndDrafts($tenant, $owner, $customers);

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

            // a paid appointment carries the sale and
            // payment behind it, as the app records them. Without these the page
            // read "Paid in full — 0 payments on file" in every demo.
            if ($paidCents > 0) {
                $saleDate = $date->toDateString();
                $sale = \App\Models\Tenant\TenantSale::create([
                    'tenant_id'          => $tenant->id,
                    'sale_number'        => app(\App\Services\Tenant\SaleService::class)->nextSaleNumber($tenant->id, $saleDate),
                    'sale_date'          => $saleDate,
                    'status'             => 'completed',
                    'payment_status'     => $paidCents >= $total ? 'paid' : 'partial',
                    'customer_id'        => $customer->id,
                    'appointment_id'     => $appointment->id,
                    'rang_up_by_user_id' => $owner->id,
                    'subtotal_cents'     => $subtotal,
                    'tax_cents'          => $tax,
                    'total_cents'        => $total,
                    'paid_at'            => $seededCreatedAt,
                ]);
                \App\Models\Tenant\TenantSalePayment::create([
                    'tenant_id'           => $tenant->id,
                    'sale_id'             => $sale->id,
                    'amount_cents'        => $paidCents,
                    'kind'                => 'payment',
                    'source'              => 'manual_entry',
                    'method'              => $paymentMethod ?: 'card',
                    'recorded_by_user_id' => $owner->id,
                    'recorded_at'         => $seededCreatedAt,
                ]);
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

        // Post-seed: redistribute any NULL resource_ids round-robin across
        // active resources. Works around an intermittent seed-time bug where
        // ~80% of round-robin assignments silently land as NULL despite the
        // FK target being valid. Bug only manifests at this scale + transaction
        // shape; doesn't affect real admin/booking flows. Fix here is a raw
        // UPDATE so we don't trigger model events.
        $nullCount = \Illuminate\Support\Facades\DB::table('tenant_appointments')
            ->where('tenant_id', $tenant->id)
            ->whereNull('resource_id')
            ->count();

        if ($nullCount > 0 && $resourceCount > 0) {
            $cursor = 0;
            \Illuminate\Support\Facades\DB::table('tenant_appointments')
                ->where('tenant_id', $tenant->id)
                ->whereNull('resource_id')
                ->orderBy('id')
                ->select('id')
                ->cursor()
                ->each(function ($row) use ($resourceIds, $resourceCount, &$cursor) {
                    \Illuminate\Support\Facades\DB::table('tenant_appointments')
                        ->where('id', $row->id)
                        ->update(['resource_id' => $resourceIds[$cursor++ % $resourceCount]]);
                });
            $this->log("  Redistributed: {$nullCount} appointments backfilled across {$resourceCount} resources.");
        }
    }



    /**
     * Seed inventory categories + items + per-location stock rows.
     *
     * Items are distributed across the tenant's active locations: each item
     * gets a tenant_inventory_item_locations row per location, with stock
     * split evenly across them (rounded; any remainder lands on the first
     * location). Aggregate stock on the item row matches the sum.
     *
     * Reorder thresholds are applied at BOTH the item level and the
     * location level so the low-stock dashboard card works regardless of
     * whether a current_location_id is set in session.
     *
     * Idempotent on re-seed at the tenant level: caller (DemoPopulate
     * --fresh) wipes the tenant before calling seed().
     */
    private function seedInventory(Tenant $tenant): void
    {
        $categories = $this->industry->inventoryCategories();
        $items = $this->industry->inventoryItems();

        if (empty($items)) {
            $this->log("  Inventory: industry has no items defined, skipping.");
            return;
        }

        // 1. Seed categories.
        $categoryIdsBySlug = [];
        foreach ($categories as $i => $cat) {
            $row = TenantInventoryCategory::create([
                'id'         => (string) Str::uuid(),
                'tenant_id'  => $tenant->id,
                'name'       => $cat['name'],
                'slug'       => $cat['slug'],
                'sort_order' => $i,
                'source'     => 'manual',
            ]);
            $categoryIdsBySlug[$cat['slug']] = $row->id;
        }

        // 2. Get all active locations on this tenant for stock distribution.
        $locationIds = TenantLocation::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at')
            ->pluck('id')
            ->all();

        if (empty($locationIds)) {
            $this->log("  Inventory: no locations found, cannot distribute stock. Skipping.");
            return;
        }

        $locationCount = count($locationIds);

        // 3. Seed items + per-location stock.
        $itemRows = [];
        $locationRows = [];

        foreach ($items as $item) {
            $itemId = (string) Str::uuid();
            $totalStock = (int) ($item['stock_count'] ?? 0);
            $threshold = $item['reorder_threshold'] ?? null;

            $catSlug = $item['category_slug'] ?? null;
            $catId = $catSlug && isset($categoryIdsBySlug[$catSlug])
                ? $categoryIdsBySlug[$catSlug]
                : null;

            $itemRows[] = [
                'id'                     => $itemId,
                'tenant_id'              => $tenant->id,
                'category_id'            => $catId,
                'sku'                    => $item['sku'],
                'name'                   => $item['name'],
                'description'            => $item['description'] ?? null,
                'shop_cost_cents'        => $item['shop_cost_cents'] ?? null,
                'shop_sell_price_cents'  => $item['shop_sell_price_cents'] ?? null,
                'shop_reorder_threshold' => $threshold,
                'computed_stock_count'   => $totalStock,
                'allow_oversell'         => true,
                'is_active'              => true,
                'created_at'             => now(),
                'updated_at'             => now(),
            ];

            // Distribute stock across locations (even split, remainder to first).
            $perLoc = intdiv($totalStock, $locationCount);
            $remainder = $totalStock - ($perLoc * $locationCount);
            foreach ($locationIds as $idx => $locId) {
                $stockHere = $perLoc + ($idx === 0 ? $remainder : 0);
                $locationRows[] = [
                    'id'                     => (string) Str::uuid(),
                    'tenant_id'              => $tenant->id,
                    'inventory_item_id'      => $itemId,
                    'location_id'            => $locId,
                    'computed_stock_count'   => $stockHere,
                    'shop_reorder_threshold' => $threshold,
                    'is_active'              => true,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ];
            }
        }

        // Batch insert for speed (these tables can be 100+ rows).
        foreach (array_chunk($itemRows, 200) as $chunk) {
            DB::table('tenant_inventory_items')->insert($chunk);
        }
        foreach (array_chunk($locationRows, 200) as $chunk) {
            DB::table('tenant_inventory_item_locations')->insert($chunk);
        }

        $this->log("  Inventory: " . count($items) . " items across " . count($categories) . " categories, distributed across {$locationCount} location(s).");
    }


    /**
     * Seed quote-status and draft-status sales rows. These appear in the
     * Register's "Drafts & Quotes" UI and feed Reports → Sales filters.
     *
     * Both quotes and drafts use payment_status (the schema enum was
     * expanded in patch 2026-05-04 to include 'quote' and 'draft').
     * status stays 'pending' since neither is finalized.
     *
     * Each row gets 1-3 line items pulled randomly from the tenant's
     * existing service catalog so the totals look realistic.
     */
    private function seedQuotesAndDrafts(Tenant $tenant, TenantUser $owner, array $customers): void
    {
        $quoteCount = $this->industry->quoteCount();
        $draftCount = $this->industry->draftCount();

        if ($quoteCount === 0 && $draftCount === 0) {
            return;
        }

        // Need at least 1 customer and 1 service to seed meaningful rows.
        if (empty($customers)) {
            $this->log("  Quotes/drafts: no customers, skipping.");
            return;
        }

        $services = TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get(['id', 'name', 'price_cents'])
            ->all();
        if (empty($services)) {
            $this->log("  Quotes/drafts: no services, skipping.");
            return;
        }

        // Sale numbers are unique per tenant. Use a high starting number to
        // avoid collisions with the live counter once the tenant goes live.
        $startNumber = 9000;

        $today = Carbon::now();
        $created = 0;

        $seedOne = function (string $paymentStatus) use ($tenant, $owner, $customers, $services, $today, &$startNumber, &$created) {
            $customer = $customers[array_rand($customers)];
            $numItems = random_int(1, 3);
            $pickedServices = (array) array_rand($services, min($numItems, count($services)));
            if (!is_array($pickedServices)) {
                $pickedServices = [$pickedServices];
            }

            $saleId = (string) Str::uuid();
            $saleItemRows = [];
            $subtotal = 0;
            foreach ($pickedServices as $idx) {
                $svc = $services[$idx];
                $price = (int) ($svc->price_cents ?? 0);
                $qty = 1;
                $lineTotal = $price * $qty;
                $subtotal += $lineTotal;
                $saleItemRows[] = [
                    'id'               => (string) Str::uuid(),
                    'tenant_id'        => $tenant->id,
                    'sale_id'          => $saleId,
                    'type'             => 'service',
                    'service_id'       => $svc->id,
                    'name_snapshot'    => $svc->name,
                    'quantity'         => $qty,
                    'unit_price_cents' => $price,
                    'line_total_cents' => $lineTotal,
                    'is_taxable'       => true,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ];
            }

            DB::table('tenant_sales')->insert([
                'id'                  => $saleId,
                'tenant_id'           => $tenant->id,
                'sale_number'         => 'S-' . str_pad((string)($startNumber++), 5, '0', STR_PAD_LEFT),
                'sale_date'           => $today->copy()->subDays(random_int(0, 7))->toDateString(),
                'status'              => 'pending',
                'payment_status'      => $paymentStatus,
                'customer_id'         => $customer->id,
                'rang_up_by_user_id'  => $owner->id,
                'subtotal_cents'      => $subtotal,
                'total_cents'         => $subtotal,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            DB::table('tenant_sale_items')->insert($saleItemRows);
            $created++;
        };

        for ($i = 0; $i < $quoteCount; $i++) {
            $seedOne('quote');
        }
        for ($i = 0; $i < $draftCount; $i++) {
            $seedOne('draft');
        }

        $this->log("  Quotes/drafts: {$quoteCount} quotes + {$draftCount} drafts seeded.");
    }

    private function pickStatus(Carbon $date, Carbon $today): string
    {
        if ($date->greaterThan($today)) {
            return $this->weightedPick(['confirmed' => 70, 'pending' => 30]);
        }
        // same-day appointments are still in the
        // future at the moment of seeding; never assign 'completed' to them.
        if ($date->isSameDay($today)) {
            return $this->weightedPick(['in_progress' => 40, 'confirmed' => 40, 'pending' => 20]);
        }
        $daysAgo = abs($today->diffInDays($date));
        if ($daysAgo <= 2) {
            return $this->weightedPick(['completed' => 45, 'closed' => 45, 'in_progress' => 5, 'cancelled' => 5]);
        }
        if ($daysAgo <= 14) {
            return $this->weightedPick(['closed' => 91, 'cancelled' => 6, 'refunded' => 3]); // no legacy 'shipped'
        }
        return $this->weightedPick(['closed' => 94, 'cancelled' => 4, 'refunded' => 2]);
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
     * The class side of a studio demo: built by ClassStudioDemo around today,
     * in the studio's own timezone (MARKER-CLASSES-DEMO). The old seeding
     * here put class times in UTC, so every class showed hours early, and
     * only covered two weeks either side of the day it ran.
     */
    private function seedClasses(Tenant $tenant, array $customers): void
    {
        if (empty($this->industry->classTemplates())) {
            return; // Industry has no class architecture.
        }
        (new ClassStudioDemo($this->industry, $this->logger))->seed($tenant);
        $this->log("  Classes: enabled.");
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
