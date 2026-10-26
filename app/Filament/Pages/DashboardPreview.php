<?php

namespace App\Filament\Pages;

use App\Models\DashboardTemplate;
use App\Models\Tenant;
use App\Models\Tenant\TenantRole;
use App\Support\Dashboard\Audience;
use App\Support\Dashboard\BlockRegistry;
use App\Support\Dashboard\TemplateStore;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * MARKER-DASH-PREVIEW: full-screen preview of a template or one block, drawn
 * from a real shop's data as one of its roles would see it. Opened from the
 * Templates and Blocks tabs, the template editor and the block builder.
 * Read-only.
 */
class DashboardPreview extends Page
{
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'tenants';

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'dashboard-preview';
    protected static ?string $title = 'Preview';
    protected static string $view = 'filament.pages.dashboard-preview';

    #[Url] public string $t = '';
    #[Url] public string $b = '';
    #[Url] public string $v = 'live';
    #[Url] public string $shop = '';
    #[Url] public string $role = 'owner';
    #[Url] public string $device = 'desktop';
    #[Url] public int $w = 12;

    public function mount(): void
    {
        if ($this->t === '' && $this->b === '') {
            $this->redirect(Dashboards::getUrl());
            return;
        }
        if ($this->shop === '' || ! Tenant::whereKey($this->shop)->exists()) {
            $this->shop = (string) $this->defaultShop();
        }
        if ($this->t !== '') {
            $tpl = DashboardTemplate::where('key', $this->t)->first();
            if (! $tpl) { $this->redirect(Dashboards::getUrl()); return; }
            if (! $tpl->live_version_id) $this->v = 'draft';
        }
    }

    /** Ground Control when it's here, else the shop with the most bookings, so the figures aren't empty. */
    private function defaultShop(): ?string
    {
        return Tenant::where('is_active', true)->where('subdomain', 'grndctrl')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('tenant_appointments')->join('tenants', 'tenants.id', '=', 'tenant_appointments.tenant_id')
                ->where('tenants.is_active', true)->groupBy('tenant_appointments.tenant_id')->orderByRaw('COUNT(*) DESC')->value('tenant_appointments.tenant_id')
            ?? Tenant::where('is_active', true)->orderBy('name')->value('id');
    }

    public function updatedShop(): void
    {
        $this->role = 'owner';
    }

    public function getTitle(): string
    {
        if ($this->b !== '') return 'Preview: ' . (TemplateStore::blockNames()[$this->b] ?? 'block');

        return 'Preview: ' . (DashboardTemplate::where('key', $this->t)->value('name') ?? 'template');
    }

    protected function getViewData(): array
    {
        $tpl = $this->t !== '' ? DashboardTemplate::with('liveVersion')->where('key', $this->t)->first() : null;
        $tenant = Tenant::find($this->shop);
        $roles = $tenant ? TenantRole::where('tenant_id', $tenant->id)->get()
            ->reject(fn ($r) => $r->isOwnerRole())->sortBy(fn ($r) => ($r->is_system ? '0' : '1') . $r->name)->pluck('name', 'id')->all() : [];

        // whether this shop would get the template at all
        $gets = null;
        if ($tpl && $tenant) {
            $gets = $tpl->is_default ? [true, 'the default goes to every shop'] : Audience::check($tpl->audience, $tenant);
        }
        $blockDef = $this->b !== '' ? (BlockRegistry::all()[$this->b] ?? null) : null;

        return [
            'tpl'      => $tpl,
            'tenant'   => $tenant,
            'shops'    => Tenant::where('is_active', true)->orderBy('name')->limit(300)->pluck('name', 'id')->all(),
            'roles'    => $roles,
            'gets'     => $gets,
            'hasDraft' => $tpl && is_array($tpl->draft_layout),
            'hasLive'  => $tpl && $tpl->live_version_id,
            'blockDef' => $blockDef,
            'blockName'=> $this->b !== '' ? (TemplateStore::blockNames()[$this->b] ?? $this->b) : null,
            'src'      => $tenant ? route('admin.dashboard-preview.frame', array_filter([
                't' => $this->t ?: null, 'v' => $this->t ? $this->v : null, 'b' => $this->b ?: null, 'w' => $this->b ? $this->w : null,
                'shop' => $tenant->id, 'role' => $this->role,
            ])) : null,
            'back'     => $this->b !== ''
                ? (! empty($blockDef['custom']) ? DashboardBlockBuilder::getUrl(['k' => $this->b]) : DashboardBlocks::getUrl())
                : DashboardEditor::getUrl(['t' => $this->t]),
        ];
    }
}
