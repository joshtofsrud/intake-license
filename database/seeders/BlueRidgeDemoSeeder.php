<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentItem;
use App\Models\Tenant\TenantCalendarBreak;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantResource;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantWalkinHold;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BlueRidgeDemoSeeder extends Seeder
{
    protected const TENANT_ID = 'a19c4cee-64f4-4a1b-b95b-95b226b27d25';

    public function run(): void
    {
        $tenant = Tenant::findOrFail(self::TENANT_ID);
        $resources = TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($resources->count() < 2) {
            $this->command->error('Expected at least 2 active resources. Aborting.');
            return;
        }

        $services = TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get();
        if ($services->isEmpty()) {
            $this->command->error('No active services found. Aborting.');
            return;
        }

        $customers = TenantCustomer::where('tenant_id', $tenant->id)
            ->inRandomOrder()
            ->take(100)
            ->get();
        if ($customers->count() < 50) {
            $this->command->error('Expected at least 50 customers. Aborting.');
            return;
        }

        $this->command->info("Seeding Blue Ridge with {$resources->count()} resources, {$services->count()} services, {$customers->count()} customer pool.");

        $historicalCount = $this->seedHistorical($tenant, $resources, $services, $customers, 40);
        $this->command->info("Historical: {$historicalCount}");

        $cancelledCount = $this->seedCancelled($tenant, $resources, $services, $customers, 20);
        $this->command->info("Cancelled: {$cancelledCount}");

        $thisWeekCount = $this->seedThisWeek($tenant, $resources, $services, $customers, 20);
        $this->command->info("This week: {$thisWeekCount}");

        $futureCount = $this->seedFutureViaBookingService($tenant, $resources, $services, $customers, 60);
        $this->command->info("Future via booking service: {$futureCount}");

        $breakCount = $this->seedBreaks($tenant, $resources);
        $this->command->info("Breaks: {$breakCount}");

        $holdCount = $this->seedWalkinHolds($tenant, $resources);
        $this->command->info("Holds: {$holdCount}");

        $total = $historicalCount + $cancelledCount + $thisWeekCount + $futureCount;
        $this->command->info("Done. {$total} appointments + {$breakCount} breaks + {$holdCount} holds.");
    }

    protected function seedHistorical($tenant, $resources, $services, $customers, int $target): int
    {
        $count = 0;
        for ($i = 0; $i < $target; $i++) {
            $daysAgo = random_int(1, 30);
            $date = Carbon::today()->copy()->subDays($daysAgo);
            $resource = $resources->random();
            $service = $services->random();
            $customer = $customers->random();

            $durationMin = max(30, (int) $service->duration_minutes);
            $latestStart = 17 - (int) ceil($durationMin / 60);
            if ($latestStart < 9) $latestStart = 9;
            $startHour = random_int(9, max(9, $latestStart));

            $apptTime = sprintf('%02d:00:00', $startHour);
            $endTime  = Carbon::parse($apptTime)->addMinutes($durationMin)->format('H:i:s');

            $this->insertAppointment($tenant, $resource, $service, $customer, [
                'appointment_date' => $date->toDateString(),
                'appointment_time' => $apptTime,
                'appointment_end_time' => $endTime,
                'status' => 'closed',
                'payment_status' => 'paid',
            ]);
            $count++;
        }
        return $count;
    }

    protected function seedCancelled($tenant, $resources, $services, $customers, int $target): int
    {
        $count = 0;
        for ($i = 0; $i < $target; $i++) {
            $daysOffset = random_int(-25, 10);
            $date = Carbon::today()->copy()->addDays($daysOffset);
            $resource = $resources->random();
            $service = $services->random();
            $customer = $customers->random();

            $durationMin = max(30, (int) $service->duration_minutes);
            $startHour = random_int(9, 16);
            $apptTime = sprintf('%02d:00:00', $startHour);
            $endTime = Carbon::parse($apptTime)->addMinutes($durationMin)->format('H:i:s');

            $this->insertAppointment($tenant, $resource, $service, $customer, [
                'appointment_date' => $date->toDateString(),
                'appointment_time' => $apptTime,
                'appointment_end_time' => $endTime,
                'status' => 'cancelled',
                'payment_status' => random_int(0, 1) ? 'refunded' : 'unpaid',
            ]);
            $count++;
        }
        return $count;
    }

    protected function seedThisWeek($tenant, $resources, $services, $customers, int $target): int
    {
        $count = 0;
        $used = [];
        $attempts = 0;
        while ($count < $target && $attempts < $target * 10) {
            $attempts++;
            $daysAhead = random_int(0, 6);
            $date = Carbon::today()->copy()->addDays($daysAhead);
            $resource = $resources->random();
            $service = $services->random();
            $customer = $customers->random();

            $durationMin = max(30, (int) $service->duration_minutes);
            $slot = $this->findFreeSlot($used, $resource->id, $date->toDateString(), $durationMin, 9, 17);
            if ($slot === null) continue;

            [$startMin, $endMin] = $slot;
            $apptTime = $this->minutesToHms($startMin);
            $endTime  = $this->minutesToHms($endMin);

            $status = random_int(0, 100) < 70 ? 'confirmed' : 'pending';
            $this->insertAppointment($tenant, $resource, $service, $customer, [
                'appointment_date' => $date->toDateString(),
                'appointment_time' => $apptTime,
                'appointment_end_time' => $endTime,
                'status' => $status,
                'payment_status' => 'unpaid',
            ]);
            $used[$resource->id][$date->toDateString()][] = [$startMin, $endMin];
            $count++;
        }
        return $count;
    }

    protected function seedFutureViaBookingService($tenant, $resources, $services, $customers, int $target): int
    {
        $bookingService = app(BookingService::class);
        $count = 0;
        $attempts = 0;

        while ($count < $target && $attempts < $target * 10) {
            $attempts++;
            $daysAhead = random_int(7, 20);
            $date = Carbon::today()->copy()->addDays($daysAhead)->toDateString();
            $resource = $resources->random();
            $service = $services->random();
            $customer = $customers->random();

            $minutes = random_int(18, 32) * 30;
            $apptTime = $this->minutesToHms($minutes);

            try {
                $bookingService->createAppointment([
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'date' => $date,
                    'appointment_time' => $apptTime,
                    'resource_id' => $resource->id,
                    'items' => [
                        ['service_item_id' => $service->id, 'addon_ids' => []],
                    ],
                    'payment_method' => 'none',
                ], $tenant->id);
                $count++;
            } catch (\Throwable $e) {
                continue;
            }
        }
        return $count;
    }

    protected function seedBreaks($tenant, $resources): int
    {
        $count = 0;
        foreach ($resources as $resource) {
            TenantCalendarBreak::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'resource_id' => $resource->id,
                'label' => 'Lunch',
                'starts_at' => Carbon::today()->setTime(12, 30),
                'ends_at' => Carbon::today()->setTime(13, 30),
                'is_recurring' => true,
                'recurrence_type' => 'weekly',
                'recurrence_config' => ['days' => ['mon', 'tue', 'wed', 'thu', 'fri']],
                'recurrence_until' => Carbon::today()->addMonths(3)->toDateString(),
            ]);
            $count++;
        }
        return $count;
    }

    protected function seedWalkinHolds($tenant, $resources): int
    {
        $count = 0;
        foreach ($resources as $resource) {
            TenantWalkinHold::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'resource_id' => $resource->id,
                'starts_at' => Carbon::today()->setTime(14, 0),
                'ends_at' => Carbon::today()->setTime(15, 0),
                'auto_release_at' => Carbon::today()->setTime(14, 15),
                'notes' => 'Walk-in window',
                'is_recurring' => false,
            ]);
            $count++;
        }
        return $count;
    }

    protected function insertAppointment($tenant, $resource, $service, $customer, array $overrides): string
    {
        $id = (string) Str::uuid();
        $durationMin = max(30, (int) $service->duration_minutes);
        $ra = 'BR-' . strtoupper(Str::random(8));

        TenantAppointment::create(array_merge([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'resource_id' => $resource->id,
            'ra_number' => $ra,
            'customer_first_name' => $customer->first_name,
            'customer_last_name' => $customer->last_name,
            'customer_email' => $customer->email,
            'customer_phone' => $customer->phone,
            'total_duration_minutes' => $durationMin,
            'prep_before_minutes_snapshot' => (int) ($service->prep_before_minutes ?? 0),
            'cleanup_after_minutes_snapshot' => (int) ($service->cleanup_after_minutes ?? 0),
            'slot_weight' => (int) ($service->slot_weight ?? 1),
            'slot_weight_auto' => (int) ($service->slot_weight ?? 1),
            'slot_weight_overridden' => false,
            'payment_method' => 'none',
            'subtotal_cents' => (int) $service->price_cents,
            'tax_cents' => 0,
            'total_cents' => (int) $service->price_cents,
            'paid_cents' => 0,
        ], $overrides));

        TenantAppointmentItem::create([
            'id' => (string) Str::uuid(),
            'appointment_id' => $id,
            'service_item_id' => $service->id,
            'item_name_snapshot' => $service->name,
            'price_cents' => (int) $service->price_cents,
            'duration_minutes_snapshot' => (int) $service->duration_minutes,
            'prep_before_minutes_snapshot' => (int) ($service->prep_before_minutes ?? 0),
            'cleanup_after_minutes_snapshot' => (int) ($service->cleanup_after_minutes ?? 0),
        ]);

        return $id;
    }

    protected function findFreeSlot(array $used, string $resourceId, string $date, int $durationMin, int $openH, int $closeH): ?array
    {
        $existing = $used[$resourceId][$date] ?? [];
        $closeMin = $closeH * 60;

        for ($startMin = $openH * 60; $startMin + $durationMin <= $closeMin; $startMin += 30) {
            $endMin = $startMin + $durationMin;
            $conflict = false;
            foreach ($existing as [$exStart, $exEnd]) {
                if ($startMin < $exEnd && $endMin > $exStart) {
                    $conflict = true;
                    break;
                }
            }
            if (!$conflict) return [$startMin, $endMin];
        }
        return null;
    }

    protected function minutesToHms(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%02d:%02d:00', $h, $m);
    }
}
