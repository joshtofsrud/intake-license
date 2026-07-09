<?php
// MARKER-PATCH-622 — manage search rules (synonyms + redirects) from the
// Traffic report's Search rules card.

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantSearchRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SearchRulesController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'type'      => ['required', 'in:synonym,redirect'],
            'from_term' => ['required', 'string', 'max:120'],
            'to_value'  => ['required', 'string', 'max:300'],
            'label'     => ['nullable', 'string', 'max:120'],
        ]);

        if ($data['type'] === 'redirect' && !str_starts_with($data['to_value'], '/') && !str_starts_with($data['to_value'], 'http')) {
            $data['to_value'] = '/' . ltrim($data['to_value'], '/');
        }

        TenantSearchRule::updateOrCreate(
            ['tenant_id' => tenant()->id, 'type' => $data['type'], 'from_term' => mb_strtolower(trim($data['from_term']))],
            ['to_value' => trim($data['to_value']), 'label' => $data['label'] ?? null, 'created_by' => Auth::guard('tenant')->id()]
        );

        return back()->with('success', ucfirst($data['type']) . ' rule saved.');
    }

    public function destroy(Request $request, string $ruleId)
    {
        TenantSearchRule::where('tenant_id', tenant()->id)->where('id', $ruleId)->delete();
        return back()->with('success', 'Rule removed.');
    }
}

