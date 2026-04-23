<?php

namespace App\Services\Demo;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentWorkOrderResponse;
use App\Models\Tenant\TenantWorkOrderField;
use App\Services\Demo\Industries\IndustryDataContract;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds work-order field definitions for a tenant, then populates
 * realistic values on every seeded appointment.
 *
 * Run AFTER appointments have been seeded — needs appointment IDs
 * to exist in the DB.
 */
class WorkOrderSeeder
{
    public function __construct(
        private readonly IndustryDataContract $industry,
        private readonly Closure $logger,
    ) {}

    private function log(string $msg): void { ($this->logger)($msg); }

    public function seed(Tenant $tenant): void
    {
        $presets = $this->industry->workOrderFieldPresets();

        if (empty($presets)) {
            $this->log("  Work order fields: skipped (no presets for this industry).");
            return;
        }

        // 1. Create the field definitions
        $fieldsByLabel = [];
        $sortOrder = 10;
        $identifierSeen = false;
        foreach ($presets as $p) {
            // Enforce single-identifier rule
            $isIdentifier = (bool) ($p['is_identifier'] ?? false);
            if ($isIdentifier && $identifierSeen) {
                $isIdentifier = false;
            }
            if ($isIdentifier) { $identifierSeen = true; }

            $field = TenantWorkOrderField::create([
                'tenant_id'           => $tenant->id,
                'label'               => $p['label'],
                'field_type'          => $p['field_type'],
                'options'             => $p['options'] ?? null,
                'help_text'           => $p['help_text'] ?? null,
                'is_required'         => (bool) ($p['is_required'] ?? false),
                'is_identifier'       => $isIdentifier,
                'is_customer_visible' => (bool) ($p['is_customer_visible'] ?? true),
                'sort_order'          => $sortOrder,
            ]);
            $fieldsByLabel[$p['label']] = $field;
            $sortOrder += 10;
        }

        $this->log("  Work order fields: " . count($fieldsByLabel) . " fields defined.");

        // 2. Populate responses on every appointment
        $sampleValues = $this->industry->workOrderSampleValues();
        $identifierField = null;
        foreach ($fieldsByLabel as $f) {
            if ($f->is_identifier) { $identifierField = $f; break; }
        }

        $appointmentIds = TenantAppointment::where('tenant_id', $tenant->id)
            ->pluck('id', 'id');

        $responseRows = [];
        $identifierUpdates = [];
        $now = now()->toDateTimeString();

        foreach ($appointmentIds as $appointmentId) {
            foreach ($fieldsByLabel as $label => $field) {
                // Not every appointment fills every field — 85% chance per field
                if (random_int(1, 100) > 85) { continue; }

                $source = $sampleValues[$label] ?? null;
                if ($source === null) { continue; }

                $value = is_callable($source) ? $source() : $source[array_rand($source)];

                $responseRows[] = [
                    'id'                   => (string) Str::uuid(),
                    'tenant_id'            => $tenant->id,
                    'appointment_id'       => $appointmentId,
                    'field_id'             => $field->id,
                    'field_label_snapshot' => $field->label,
                    'response_value'       => $value,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ];

                // If this field is the promoted identifier, queue a column update
                if ($identifierField && $field->id === $identifierField->id) {
                    $identifierUpdates[$appointmentId] = [
                        'identifier'       => $value,
                        'identifier_label' => $field->label,
                    ];
                }
            }
        }

        // Bulk insert responses in chunks
        foreach (array_chunk($responseRows, 500) as $chunk) {
            DB::table('tenant_appointment_work_order_responses')->insert($chunk);
        }

        // Update identifier columns on appointments (can't bulk-update different values cleanly in MySQL,
        // so we batch by value: group appointments that got the same serial? no, each is unique. Do raw case.)
        // Simplest: per-appointment update. At 1800 appointments this is ~1800 queries but only runs on seed.
        foreach ($identifierUpdates as $appointmentId => $update) {
            DB::table('tenant_appointments')
                ->where('id', $appointmentId)
                ->update($update);
        }

        $this->log("  Work order responses: " . count($responseRows)
            . " filled, " . count($identifierUpdates) . " identifiers promoted.");
    }
}
