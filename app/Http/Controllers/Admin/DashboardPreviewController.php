<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DashboardBlock;
use App\Models\Tenant;
use App\Models\Tenant\TenantRole;
use App\Models\Tenant\TenantUser;
use App\Support\Dashboard\BlockAccess;
use App\Support\Dashboard\BlockRegistry;
use App\Support\Dashboard\DashboardPage;
use App\Support\Dashboard\DashboardTemplates;
use App\Support\Dashboard\Layout;
use App\Support\Dashboard\TemplateStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * MARKER-DASH-PREVIEW: one shop's dashboard as one of its roles would see
 * it, drawn inside master admin's preview (DashboardPreview page, in an
 * iframe). The same frame views and DashboardPage data as the shop's own
 * dashboard; only the layout around it differs (no sidebar, nothing
 * clickable). Read-only: nothing here writes to the shop.
 *
 *   ?t=key&v=live|draft   a template          ?b=key&w=4|6|8|12   one block
 *   &shop=tenant id       &role=role id, or "owner"
 */
class DashboardPreviewController extends Controller
{
    public function frame(Request $request)
    {
        $tenant = Tenant::findOrFail((string) $request->query('shop'));
        $user = self::person($tenant, (string) $request->query('role', 'owner'));

        $hadTenant = app()->bound('tenant');
        $prevTenant = $hadTenant ? app('tenant') : null;
        app()->instance('tenant', $tenant);
        try {
            $see = BlockAccess::for($user);
            $template = self::template($request, $tenant);
            $rows = Layout::personal($template['rows'], $see->blocked(), [], 'none');

            $theme = (array) ($tenant->settings ?? []);
            $theme = in_array($theme['admin_theme'] ?? 'c', ['b', 'c'], true) ? ($theme['admin_theme'] ?? 'c') : 'c';
            View::share('currentTenant', $tenant);
            View::share('adminTheme', $theme);

            // a single block has no switch; a template shows the shop's own switch, with it in
            $switch = [];
            if ($template['key'] !== 'preview') {
                $switch = DashboardTemplates::switchable($tenant);
                if (! in_array($template['key'], array_column($switch, 'key'), true)) $switch[] = $template;
            }

            $data = DashboardPage::data($tenant, $user, $see, $rows) + [
                'activeTemplate'  => $template,
                'switchTemplates' => $switch,
                'customize'       => null,
                'dashLayout'      => 'layouts.tenant.preview',
                'pageTitle'       => 'Preview',
            ];
            $html = view(DashboardTemplates::frameView($template), $data)->render();
        } finally {
            $hadTenant ? app()->instance('tenant', $prevTenant) : app()->forgetInstance('tenant');
        }

        return response($html)->header('X-Frame-Options', 'SAMEORIGIN')->header('Cache-Control', 'no-store');
    }

    /** A stand-in person with the chosen role. Never saved. */
    public static function person(Tenant $tenant, string $roleId): TenantUser
    {
        $role = $roleId !== 'owner' ? TenantRole::where('tenant_id', $tenant->id)->find($roleId) : null;
        $u = new TenantUser();
        $u->forceFill(['tenant_id' => $tenant->id, 'name' => '', 'is_active' => true,
            'role' => ! $role || $role->isOwnerRole() ? 'owner' : ($role->is_system && $role->name === 'Manager' ? 'manager' : 'staff')]);
        $u->setRelation('accessRole', $role);
        $u->setRelation('tenant', $tenant);

        return $u;
    }

    /** The template (or one block) to draw, in DashboardTemplates' shape. */
    private static function template(Request $request, Tenant $tenant): array
    {
        $hidden = DashboardBlock::whereNotNull('hidden_at')->pluck('key')->all();
        $b = (string) $request->query('b', '');
        if ($b !== '') {
            abort_unless(isset(BlockRegistry::all()[$b]), 404);
            $w = (int) $request->query('w', 12);
            $w = in_array($w, [4, 6, 8], true) ? $w : 12;
            $rows = [['split' => $w === 12 ? [12] : [$w, 12 - $w], 'cols' => $w === 12 ? [[['b' => $b]]] : [[['b' => $b]], []]]];

            return ['key' => 'preview', 'name' => 'Preview', 'frame' => 'standard', 'label' => 'Preview', 'beta' => false, 'icon' => 'custom',
                'default' => false, 'rearrange' => 'none', 'audience' => null,
                'rows' => Layout::visible($rows)];
        }

        $t = TemplateStore::find((string) $request->query('t'));
        $layout = $request->query('v') === 'draft' ? TemplateStore::workingLayout($t) : (TemplateStore::liveLayout($t) ?? TemplateStore::workingLayout($t));

        return ['key' => $t->key, 'name' => $t->name, 'frame' => array_key_exists($t->frame, DashboardTemplates::FRAMES) ? $t->frame : 'standard',
            'label' => $t->switch_label ?: $t->name, 'beta' => (bool) $t->beta_badge, 'icon' => $t->icon ?: $t->frame,
            'default' => (bool) $t->is_default, 'rearrange' => 'none', 'audience' => $t->audience,
            'rows' => Layout::visible($layout, $hidden)];
    }
}
