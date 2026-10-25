<?php

namespace App\Filament\Pages;

use App\Models\DashboardTemplate;
use App\Models\Tenant\TenantUser;
use App\Support\AdminAccess;
use App\Support\Dashboard\Layout;
use App\Support\Dashboard\TemplateStore;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * MARKER-DASH-ADMIN: the shop dashboards. Every template (Overview, Tiles,
 * Brief and any built here), which one is the default, and the way into the
 * editor. Shops only see a template's live version.
 */
class Dashboards extends Page
{
    use \App\Support\UsesAdminNav;
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'tenants';

    protected static ?string $navigationIcon  = 'heroicon-o-squares-2x2';
    protected static ?string $navigationLabel = 'Dashboards';
    protected static ?string $navigationGroup = 'Tenants';
    protected static ?int    $navigationSort  = 40;
    protected static ?string $title = 'Dashboards';
    protected static ?string $slug  = 'dashboards';

    protected static string $view = 'filament.pages.dashboards';

    /** The default as chosen on screen; saved only on Save. */
    public string $default = '';

    public string $newName = '';
    public string $checkFind = '';
    public ?string $checkId = null;
    public string $newFrom = '';

    public function mount(): void
    {
        $this->default = $this->savedDefault();
    }

    private function savedDefault(): string
    {
        return (string) (DashboardTemplate::where('is_default', true)->value('key') ?? 'overview');
    }

    public function canManage(): bool
    {
        return AdminAccess::level(Auth::guard('web')->user(), 'tenants') === 'full';
    }

    private function guard(): bool
    {
        if ($this->canManage()) return true;
        Notification::make()->title('View access only')->body('Only admins with full Tenants access can change dashboards.')->danger()->send();

        return false;
    }

    private function fail(\Throwable $e): void
    {
        Notification::make()->title($e instanceof \InvalidArgumentException ? $e->getMessage() : 'That did not save. Try again.')->danger()->send();
        if (! $e instanceof \InvalidArgumentException) report($e);
    }

    public function resetChoice(): void
    {
        $this->default = $this->savedDefault();
    }

    public function save(): void
    {
        if (! $this->guard()) return;
        $was = $this->savedDefault();
        if ($this->default === $was) return;
        try {
            $t = TemplateStore::setDefault($this->default);
        } catch (\Throwable $e) {
            $this->default = $was;
            $this->fail($e);
            return;
        }
        debug_log()->audit('dashboard.default_changed', "Default dashboard: {$was} → {$this->default}", $t);
        Notification::make()->title($t->name . ' is now the default dashboard')
            ->body("People who haven't picked a view see it on their next page load.")->success()->send();
    }

    public function create()
    {
        if (! $this->guard()) return null;
        try {
            $t = TemplateStore::create($this->newName, $this->newFrom !== '' ? $this->newFrom : null);
        } catch (\Throwable $e) {
            $this->fail($e);
            return null;
        }
        $this->newName = '';

        return redirect(DashboardEditor::getUrl(['t' => $t->key]));
    }

    public function duplicate(string $key)
    {
        if (! $this->guard()) return null;
        try {
            $t = TemplateStore::duplicate($key);
        } catch (\Throwable $e) {
            $this->fail($e);
            return null;
        }

        return redirect(DashboardEditor::getUrl(['t' => $t->key]));
    }

    public function retire(string $key): void
    {
        if (! $this->guard()) return;
        try {
            $t = TemplateStore::retire($key);
        } catch (\Throwable $e) {
            $this->fail($e);
            return;
        }
        Notification::make()->title($t->name . ' retired')
            ->body('It has left every dashboard switch. Anyone who had it now sees the default.')->success()->send();
    }

    public function setCustomize(bool $on): void
    {
        if (! $this->guard()) return;
        TemplateStore::setCustomizeEnabled($on);
        Notification::make()->title($on ? 'Customizing is on' : 'Customizing is off for every shop')
            ->body($on ? "Each template's own setting applies again." : 'Customize disappears on their next load. What people chose is kept for when it comes back.')
            ->success()->send();
    }

    public function pickShop(string $id): void
    {
        $this->checkId = $id;
        $this->checkFind = '';
    }

    public function restore(string $key): void
    {
        if (! $this->guard()) return;
        try {
            $t = TemplateStore::restore($key);
        } catch (\Throwable $e) {
            $this->fail($e);
            return;
        }
        Notification::make()->title($t->name . ' restored as a draft')->body('Publish it to offer it to shops again.')->success()->send();
    }

    /** What one shop gets, and why — for "Check a shop". */
    private function checkResult($all): ?array
    {
        $tenant = $this->checkId ? \App\Models\Tenant::find($this->checkId) : null;
        if (! $tenant) return null;
        $lines = [];
        $offered = [];
        foreach ($all as $t) {
            if ($t->state !== 'live') {
                $lines[] = [$t->name, false, $t->state === 'retired' ? 'retired' : 'not published yet'];
                continue;
            }
            [$ok, $why] = $t->is_default ? [true, 'the default goes to every shop'] : \App\Support\Dashboard\Audience::check($t->audience, $tenant);
            $lines[] = [$t->name, $ok, $why];
            if ($ok) $offered[$t->key] = $t->name;
        }

        return [
            'name'  => $tenant->name,
            'facts' => collect([
                \App\Support\Dashboard\Audience::PLANS[$tenant->plan_tier ?? 'starter'] ?? $tenant->plan_tier,
                config('industry_packs.' . $tenant->industry_pack . '.label'),
            ])->filter()->implode(' · '),
            'lines' => $lines,
            'people' => \App\Models\Tenant\TenantUser::where('tenant_id', $tenant->id)->where('is_active', true)->get(['name', 'role', 'dashboard_view']),
            'default' => $all->firstWhere('is_default', true)?->name,
            'offered' => $offered,
            'names'   => $all->pluck('name', 'key')->all(),
        ];
    }

    protected function getViewData(): array
    {
        $all = DashboardTemplate::with('liveVersion')->orderBy('sort')->orderBy('id')->get();
        $picks = TemplateStore::pickCounts();
        $liveKeys = $all->where('state', 'live')->pluck('key')->all();

        $following = TenantUser::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('dashboard_view')->orWhereNotIn('dashboard_view', $liveKeys ?: ['']));

        $rows = $all->map(function (DashboardTemplate $t) use ($picks) {
            $working = TemplateStore::workingLayout($t);
            return [
                'key'      => $t->key,
                'name'     => $t->name,
                'builtin'  => $t->is_builtin,
                'state'    => $t->state,
                'version'  => $t->liveVersion?->version,
                'label'    => $t->switch_label ?: $t->name,
                'beta'     => $t->beta_badge,
                'default'  => $t->is_default,
                'blocks'   => count(Layout::blockKeys($working)),
                'pending'  => $t->liveVersion && is_array($t->draft_layout) ? count(TemplateStore::changes($t, $working)) : 0,
                'picked'   => (int) ($picks[$t->key] ?? 0),
                'retired'  => $t->retired_at,
                'audience' => \App\Support\Dashboard\Audience::describe($t->audience),
                'rearrange'=> $t->rearrange,
            ];
        });

        return [
            'active'         => $rows->where('state', '!=', 'retired')->values(),
            'retired'        => $rows->where('state', 'retired')->values(),
            'saved'          => $this->savedDefault(),
            'following'      => (clone $following)->count(),
            'followingShops' => (clone $following)->distinct()->count('tenant_id'),
            'canManage'      => $this->canManage(),
            'customizeOn'    => \App\Support\Dashboard\DashboardTemplates::customizeEnabled(),
            'checkHits'      => strlen(trim($this->checkFind)) >= 2
                ? \App\Models\Tenant::where('name', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($this->checkFind)) . '%')->orderBy('name')->limit(8)->pluck('name', 'id')->all()
                : [],
            'check'          => $this->checkResult($all),
        ];
    }
}
