<?php

namespace Tests\Feature;

use App\Exceptions\LockAcquisitionException;
use App\Models\Tenant;
use App\Models\Tenant\TenantResource;
use App\Models\Tenant\TenantServiceItem;
use App\Services\BookingService;
use App\Support\MySQLLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Concurrency tests for the booking pipeline.
 *
 * These tests exercise MySQL advisory locks. They require the test DB
 * to be MySQL (not SQLite) because GET_LOCK/RELEASE_LOCK don't exist
 * outside MySQL.
 *
 * Testing strategy: rather than actually forking parallel HTTP requests
 * (which is hard to orchestrate inside PHPUnit), we simulate a race by
 * acquiring a lock on one connection, then testing that a second booking
 * attempt correctly fails fast. This models the same logical state as
 * two simultaneous requests without needing process-level parallelism.
 *
 * The real-world end-to-end concurrency validation lives in
 * scripts/test-booking-race.sh — which fires actual parallel HTTP requests
 * at the dog-food tenant on a live server.
 */
class BookingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected TenantResource $maya;
    protected TenantResource $dev;
    protected TenantServiceItem $haircut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTestTenant();
    }

    protected function seedTestTenant(): void
    {
        // TODO(calendar S3+): replace with model factories once they exist.
        $this->markTestSkipped(
            'Fixture setup requires tenant factory + capacity rule factory. '
            . 'Enable once test infrastructure scaffolding is complete. '
            . 'See scripts/test-booking-race.sh for real-world validation today.'
        );
    }

    public function test_same_slot_same_resource_serializes(): void
    {
        $lock = app(MySQLLock::class);
        $lockKey = 'intake:' . substr($this->tenant->id, 0, 8)
                 . ':book:' . substr($this->maya->id, 0, 8)
                 . ':2026050114' . '00';

        $lock->acquire($lockKey, 5);

        try {
            $this->expectException(LockAcquisitionException::class);
            $lock->acquire($lockKey, 1);
        } finally {
            $lock->release($lockKey);
        }
    }

    public function test_different_resources_same_slot_do_not_collide(): void
    {
        $lock = app(MySQLLock::class);

        $mayaKey = 'intake:' . substr($this->tenant->id, 0, 8)
                 . ':book:' . substr($this->maya->id, 0, 8)
                 . ':2026050114' . '00';
        $devKey  = 'intake:' . substr($this->tenant->id, 0, 8)
                 . ':book:' . substr($this->dev->id, 0, 8)
                 . ':2026050114' . '00';

        $lock->acquire($mayaKey, 5);

        try {
            $lock->acquire($devKey, 1);
            $lock->release($devKey);
            $this->assertTrue(true, 'Dev lock acquired while Maya lock held');
        } finally {
            $lock->release($mayaKey);
        }
    }

    public function test_availability_recheck_catches_just_taken_slot(): void
    {
        $bookingService = app(BookingService::class);

        $payload = [
            'first_name'       => 'Alex',
            'last_name'        => 'Tester',
            'email'            => 'alex@test.local',
            'date'             => '2026-05-01',
            'appointment_time' => '14:00:00',
            'resource_id'      => $this->maya->id,
            'items'            => [
                ['service_item_id' => $this->haircut->id, 'addon_ids' => []],
            ],
            'payment_method'   => 'none',
        ];

        $first = $bookingService->createAppointment($payload, $this->tenant->id);
        $this->assertNotNull($first->id);

        $payload['email']      = 'beth@test.local';
        $payload['first_name'] = 'Beth';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/just taken/i');
        $bookingService->createAppointment($payload, $this->tenant->id);
    }

    public function test_lock_key_format_for_time_slot_booking(): void
    {
        $reflected = new \ReflectionMethod(BookingService::class, 'computeLockKey');
        $reflected->setAccessible(true);
        $bookingService = app(BookingService::class);

        $key = $reflected->invoke(
            $bookingService,
            'time_slots',
            'abc12345-f00d-cafe-beef-000000000000',
            '2026-05-01',
            '14:00',
            'def67890-1234-5678-9abc-000000000000'
        );

        $this->assertLessThanOrEqual(64, strlen($key), 'Lock key must fit MySQL 64-char limit');
        $this->assertStringStartsWith('intake:', $key);
        $this->assertStringContainsString('book:', $key);
    }

    public function test_lock_key_format_for_dropoff_booking(): void
    {
        $reflected = new \ReflectionMethod(BookingService::class, 'computeLockKey');
        $reflected->setAccessible(true);
        $bookingService = app(BookingService::class);

        $key = $reflected->invoke(
            $bookingService,
            'drop_off',
            'abc12345-f00d-cafe-beef-000000000000',
            '2026-05-01',
            null,
            null
        );

        $this->assertLessThanOrEqual(64, strlen($key));
        $this->assertStringStartsWith('intake:', $key);
        $this->assertStringContainsString('drop:', $key);
    }
}
