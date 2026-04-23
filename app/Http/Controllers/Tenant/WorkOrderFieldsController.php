<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\WorkOrderFieldType;
use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantWorkOrderField;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkOrderFieldsController extends Controller
{
    public function index()
    {
        $tenant = tenant();

        $fields = TenantWorkOrderField::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->get();

        $jsFields = $fields->map(fn($f) => [
            'id'                  => $f->id,
            'label'               => $f->label,
            'field_type'          => $f->field_type,
            'options'             => $f->options ?? [],
            'help_text'           => $f->help_text,
            'is_required'         => (bool) $f->is_required,
            'is_identifier'       => (bool) $f->is_identifier,
            'is_customer_visible' => (bool) $f->is_customer_visible,
            'sort_order'          => (int) $f->sort_order,
        ])->values()->toArray();

        return view('tenant.work-order-fields.index', [
            'jsFields'    => $jsFields,
            'fieldTypes'  => WorkOrderFieldType::options(),
        ]);
    }

    public function store(Request $request)
    {
        $tenant = tenant();

        $data = $this->validated($request);
        if (is_string($data)) {
            return $this->err($data);
        }

        return DB::transaction(function () use ($tenant, $data) {
            // If this field is flagged as identifier, clear any existing identifier
            if (!empty($data['is_identifier'])) {
                TenantWorkOrderField::where('tenant_id', $tenant->id)
                    ->where('is_identifier', true)
                    ->update(['is_identifier' => false]);
            }

            $maxOrder = TenantWorkOrderField::where('tenant_id', $tenant->id)->max('sort_order') ?? 0;

            $field = TenantWorkOrderField::create([
                'tenant_id'           => $tenant->id,
                'label'               => $data['label'],
                'field_type'          => $data['field_type'],
                'options'             => $data['options'],
                'help_text'           => $data['help_text'],
                'is_required'         => $data['is_required'],
                'is_identifier'       => $data['is_identifier'],
                'is_customer_visible' => $data['is_customer_visible'],
                'sort_order'          => $maxOrder + 10,
            ]);

            return response()->json(['ok' => true, 'data' => $this->payload($field)]);
        });
    }

    public function update(Request $request, string $subdomain, string $id)
    {
        $tenant = tenant();
        $op = $request->input('op', 'save');

        if ($op === 'reorder')      return $this->reorder($request, $tenant);
        if ($op === 'update_field') return $this->updateField($request, $tenant, $id);

        // Default: full save (from edit modal)
        $field = TenantWorkOrderField::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $data = $this->validated($request);
        if (is_string($data)) {
            return $this->err($data);
        }

        return DB::transaction(function () use ($tenant, $field, $data) {
            if (!empty($data['is_identifier']) && !$field->is_identifier) {
                TenantWorkOrderField::where('tenant_id', $tenant->id)
                    ->where('is_identifier', true)
                    ->where('id', '!=', $field->id)
                    ->update(['is_identifier' => false]);
            }

            $field->update([
                'label'               => $data['label'],
                'field_type'          => $data['field_type'],
                'options'             => $data['options'],
                'help_text'           => $data['help_text'],
                'is_required'         => $data['is_required'],
                'is_identifier'       => $data['is_identifier'],
                'is_customer_visible' => $data['is_customer_visible'],
            ]);

            return response()->json(['ok' => true, 'data' => $this->payload($field->fresh())]);
        });
    }

    public function destroy(Request $request, string $subdomain, string $id)
    {
        $tenant = tenant();

        $field = TenantWorkOrderField::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $field->delete();

        return response()->json(['ok' => true]);
    }

    private function updateField(Request $request, $tenant, ?string $id)
    {
        if (!$id) return $this->err('Field id is required.');

        $field = TenantWorkOrderField::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $fieldName = (string) $request->input('field', '');
        $value = $request->input('value');

        $allowed = ['label', 'help_text', 'is_required', 'is_identifier', 'is_customer_visible'];
        if (!in_array($fieldName, $allowed, true)) {
            return $this->err("Field '{$fieldName}' is not inline-editable.");
        }

        if ($fieldName === 'is_identifier' && (bool) $value) {
            TenantWorkOrderField::where('tenant_id', $tenant->id)
                ->where('is_identifier', true)
                ->where('id', '!=', $field->id)
                ->update(['is_identifier' => false]);
            $field->is_identifier = true;
        } elseif (in_array($fieldName, ['is_required', 'is_identifier', 'is_customer_visible'], true)) {
            $field->{$fieldName} = (bool) $value;
        } elseif ($fieldName === 'label') {
            $label = trim((string) $value);
            if ($label === '') return $this->err('Label is required.');
            $field->label = $label;
        } else {
            $field->{$fieldName} = $value !== null ? (string) $value : null;
        }

        $field->save();

        return response()->json(['ok' => true, 'data' => $this->payload($field->fresh())]);
    }

    private function reorder(Request $request, $tenant)
    {
        $order = $request->input('order', []);
        if (!is_array($order)) return $this->err('order must be an array of field ids.');

        foreach ($order as $i => $fieldId) {
            TenantWorkOrderField::where('tenant_id', $tenant->id)
                ->where('id', $fieldId)
                ->update(['sort_order' => ($i + 1) * 10]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Parse + validate create/update payload.
     * Returns an array on success, or an error message string on failure.
     */
    private function validated(Request $request): array|string
    {
        $label = trim((string) $request->input('label', ''));
        if ($label === '') return 'Label is required.';
        if (mb_strlen($label) > 100) return 'Label must be 100 characters or fewer.';

        $fieldType = (string) $request->input('field_type', 'text');
        $validTypes = array_map(fn($c) => $c->value, WorkOrderFieldType::cases());
        if (!in_array($fieldType, $validTypes, true)) {
            return "Invalid field type: {$fieldType}.";
        }

        $helpText = trim((string) $request->input('help_text', '')) ?: null;
        if ($helpText && mb_strlen($helpText) > 255) {
            return 'Help text must be 255 characters or fewer.';
        }

        $options = null;
        if ($fieldType === 'select') {
            $raw = $request->input('options', []);
            if (is_string($raw)) {
                $raw = array_map('trim', explode("\n", $raw));
            }
            if (!is_array($raw)) $raw = [];
            $options = array_values(array_filter(array_map('trim', $raw), fn($v) => $v !== ''));
            if (count($options) < 2) return 'Dropdown fields need at least 2 options.';
            if (count($options) > 50) return 'Maximum 50 options per dropdown.';
        }

        return [
            'label'               => $label,
            'field_type'          => $fieldType,
            'options'             => $options,
            'help_text'           => $helpText,
            'is_required'         => $request->boolean('is_required'),
            'is_identifier'       => $request->boolean('is_identifier'),
            'is_customer_visible' => $request->boolean('is_customer_visible', true),
        ];
    }

    private function payload(TenantWorkOrderField $f): array
    {
        return [
            'id'                  => $f->id,
            'label'               => $f->label,
            'field_type'          => $f->field_type,
            'options'             => $f->options ?? [],
            'help_text'           => $f->help_text,
            'is_required'         => (bool) $f->is_required,
            'is_identifier'       => (bool) $f->is_identifier,
            'is_customer_visible' => (bool) $f->is_customer_visible,
            'sort_order'          => (int) $f->sort_order,
        ];
    }

    private function err(string $msg, int $status = 422)
    {
        return response()->json(['ok' => false, 'error' => $msg], $status);
    }
}
