<?php

namespace App\Filament\Pages;

use App\Models\LiveViewSession;
use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use App\Services\LiveView as LiveViewService;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * MARKER-LIVE-VIEW: ask a shop to let us see their Intake tab, and watch it
 * once they allow it. View only. See App\Services\LiveView.
 */
class LiveView extends Page
{
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'impersonation';

    protected static ?string $navigationIcon  = 'heroicon-o-eye';
    protected static ?string $navigationLabel = 'Live view';
    protected static ?string $navigationGroup = 'Tenants';
    protected static ?int    $navigationSort  = 45;
    protected static ?string $slug = 'live-view';
    protected static ?string $title = 'Live view';
    protected static string $view = 'filament.pages.live-view';

    #[Url] public string $shop = '';
    #[Url] public string $s = '';
    public string $person = '';

    public function mount(): void
    {
        if ($this->s !== '') {
            $sess = LiveViewSession::find($this->s);
            if (! $sess) { $this->s = ''; return; }
            $this->shop = $sess->tenant_id;
        }
    }

    public function updatedShop(): void
    {
        $this->person = '';
    }

    public function ask(): void
    {
        $tenant = Tenant::find($this->shop);
        if (! $tenant) return;
        $person = $this->person !== '' && TenantUser::where('tenant_id', $tenant->id)->whereKey($this->person)->exists() ? $this->person : null;
        $sess = LiveViewService::request($tenant, $person, auth()->user());

        $this->redirect(self::getUrl(['s' => $sess->id]));
    }

    public function stop(): void
    {
        $sess = LiveViewSession::find($this->s);
        if ($sess) LiveViewService::end($sess, 'Intake support pressed End', auth()->user()?->name);

        $this->redirect(self::getUrl(['s' => $this->s]));
    }

    public function again(): void
    {
        $sess = LiveViewSession::find($this->s);
        if (! $sess) return;
        $this->shop = $sess->tenant_id;
        $this->person = (string) $sess->tenant_user_id;
        $this->ask();
    }

    public function getTitle(): string
    {
        $sess = $this->s !== '' ? LiveViewSession::with('tenant')->find($this->s) : null;

        return $sess ? 'Live view: ' . ($sess->tenant?->name ?? 'shop') : 'Live view';
    }

    protected function getViewData(): array
    {
        $sess = $this->s !== '' ? LiveViewSession::with('tenant')->find($this->s) : null;
        if ($sess) LiveViewService::refresh($sess);
        $tenant = $this->shop !== '' ? Tenant::find($this->shop) : null;

        return [
            'sess'    => $sess,
            'state'   => $sess ? LiveViewService::describe($sess) : null,
            'whom'    => $sess ? LiveViewService::whom($sess) : null,
            'tenant'  => $tenant,
            'shops'   => Tenant::where('is_active', true)->orderBy('name')->limit(500)->pluck('name', 'id')->all(),
            'people'  => $tenant ? TenantUser::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all() : [],
            'online'  => $tenant ? LiveViewService::online($tenant) : [],
            'stream'  => $sess ? route('admin.live-view.stream', $sess->id) : null,
            'recent'  => LiveViewSession::with('tenant')->latest()->limit(12)->get()->each(fn ($r) => LiveViewService::refresh($r)),
        ];
    }
}
