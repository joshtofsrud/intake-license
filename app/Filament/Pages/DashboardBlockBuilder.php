<?php

namespace App\Filament\Pages;

use App\Models\DashboardBlock;
use App\Models\Tenant;
use App\Support\AdminAccess;
use App\Support\Dashboard\BlockStore;
use App\Support\Dashboard\CustomBlocks;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * MARKER-DASH-BUILDER: make or edit one made-here block, with a preview
 * drawn from a real shop's data. Nothing is stored until Save.
 */
class DashboardBlockBuilder extends Page
{
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'tenants';

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'dashboard-block';
    protected static ?string $title = 'Block';
    protected static string $view = 'filament.pages.dashboard-block-builder';

    #[Url]
    public string $k = '';

    public string $name = '';
    public string $kind = 'data';

    // data
    public string $source = 'appointments';
    public array $rules = [];
    public string $measure = 'count';
    public ?string $field = null;
    public string $period = 'last_30';
    public bool $compare = true;
    public string $display = 'number';
    public string $line = '';

    // action / note
    public string $action = 'new_appointment';
    public string $text = '';
    public string $until = '';

    // preview
    public string $shop = '';
    public int $width = 6;

    public function mount(): void
    {
        if ($this->k !== '') {
            $b = DashboardBlock::where('key', $this->k)->first();
            if (! $b || $b->is_builtin) {
                $this->redirect(DashboardBlocks::getUrl());
                return;
            }
            $this->load($b);
        }
        // preview at Ground Control if it's here, else the busiest active shop, so the figures aren't all zero
        $this->shop = (string) (Tenant::where('is_active', true)->where('subdomain', 'grndctrl')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('tenant_appointments')->join('tenants', 'tenants.id', '=', 'tenant_appointments.tenant_id')
                ->where('tenants.is_active', true)->groupBy('tenant_appointments.tenant_id')->orderByRaw('COUNT(*) DESC')->value('tenant_appointments.tenant_id')
            ?? Tenant::where('is_active', true)->orderBy('name')->value('id') ?? '');
    }

    private function load(DashboardBlock $b): void
    {
        $this->name = $b->name;
        $this->kind = $b->kind;
        $c = CustomBlocks::normalize($b->kind, (array) $b->config);
        foreach ($c as $key => $v) {
            if ($key === 'until') { $this->until = (string) $v; continue; }
            if (property_exists($this, $key)) $this->{$key} = $v ?? ($key === 'field' ? null : $this->{$key});
        }
    }

    public function getTitle(): string
    {
        return $this->k === '' ? 'New block' : ($this->name ?: 'Block');
    }

    public function canManage(): bool
    {
        return AdminAccess::level(Auth::guard('web')->user(), 'tenants') === 'full';
    }

    /** What's on screen, as it would be stored. */
    private function config(): array
    {
        return CustomBlocks::normalize($this->kind, match ($this->kind) {
            'action' => ['action' => $this->action, 'line' => $this->line],
            'note'   => ['text' => $this->text, 'until' => $this->until ?: null],
            default  => ['source' => $this->source, 'rules' => $this->rules, 'measure' => $this->measure, 'field' => $this->field,
                         'period' => $this->period, 'compare' => $this->compare, 'display' => $this->display, 'line' => $this->line],
        });
    }

    /** Keeps the on-screen choices consistent after any change (a list can only be rows, and so on). */
    private function settle(): void
    {
        if ($this->kind !== 'data') return;
        $c = $this->config();
        $this->measure = $c['measure'];
        $this->field = $c['field'];
        $this->period = $c['period'];
        $this->display = $c['display'];
    }

    public function updated($prop): void
    {
        if (str_starts_with($prop, 'rules.')) return; // kept as typed until it's valid
        $this->settle();
    }

    public function setSource(string $s): void
    {
        if (! isset(CustomBlocks::sources()[$s])) return;
        $this->source = $s;
        $this->rules = [];
        $this->field = null;
        $this->settle();
    }

    public function addRule(): void
    {
        $fields = CustomBlocks::sources()[$this->source]['fields'];
        $f = array_key_first($fields);
        $this->rules[] = $this->blankRule($f, $fields[$f]);
    }

    private function blankRule(string $f, array $def): array
    {
        return $def['type'] === 'enum' ? [$f, 'is', array_key_first($def['options'])] : [$f, 'more', ''];
    }

    public function ruleField(int $i, string $f): void
    {
        $def = CustomBlocks::sources()[$this->source]['fields'][$f] ?? null;
        if (! $def || ! isset($this->rules[$i])) return;
        $this->rules[$i] = $this->blankRule($f, $def);
    }

    public function removeRule(int $i): void
    {
        unset($this->rules[$i]);
        $this->rules = array_values($this->rules);
    }

    public function save()
    {
        if (! $this->canManage()) {
            Notification::make()->title('View access only')->danger()->send();
            return null;
        }
        $new = $this->k === '';
        try {
            $b = BlockStore::save($new ? null : $this->k, $this->name, $this->kind, $this->config());
        } catch (\Throwable $e) {
            Notification::make()->title($e instanceof \InvalidArgumentException ? $e->getMessage() : 'That did not save. Try again.')->danger()->send();
            if (! $e instanceof \InvalidArgumentException) report($e);
            return null;
        }
        Notification::make()->title($new ? $b->name . ' saved' : 'Changes saved')
            ->body($new ? 'Add it to a template in the editor. Shops see it once that template is published.' : 'Dashboards that have it show the change on their next load.')
            ->success()->send();
        if ($new) {
            return redirect(self::getUrl(['k' => $b->key]));
        }
        $this->load($b);

        return null;
    }

    protected function getViewData(): array
    {
        $cfg = $this->config();
        $stored = $this->k !== '' ? DashboardBlock::where('key', $this->k)->first() : null;
        $tenant = $this->shop !== '' ? Tenant::find($this->shop) : null;

        $preview = null;
        $previewError = null;
        if ($tenant) {
            try {
                $preview = [
                    'def'    => ['name' => $this->name !== '' ? $this->name : 'Untitled block', 'kind' => $this->kind, 'config' => $cfg],
                    'tenant' => $tenant,
                    'result' => $this->kind === 'data' ? CustomBlocks::compute($cfg, $tenant) : ['kind' => $this->kind],
                ];
            } catch (\Throwable $e) {
                report($e);
                $previewError = 'The preview could not be worked out for this shop.';
            }
        }
        $lacks = null;
        if ($tenant && $this->kind !== 'note') {
            $area = CustomBlocks::access($this->kind, $cfg)['all'][0] ?? null;
            $gate = $area ? (\App\Support\SectionRegistry::all()[$area]['gate'] ?? null) : null;
            if ($gate && ! $tenant->{$gate}) $lacks = \App\Support\SectionRegistry::all()[$area]['label'];
        }

        $usage = $stored ? (BlockStore::usage()[$stored->key] ?? []) : [];
        $src = CustomBlocks::sources()[$cfg['source'] ?? 'appointments'] ?? null;

        return [
            'cfg'       => $cfg,
            'src'       => $src,
            'sources'   => CustomBlocks::sources(),
            'canEdit'   => $this->canManage(),
            'isNew'     => $this->k === '',
            'dirty'     => ! $stored || $stored->name !== trim($this->name) || $stored->kind !== $this->kind
                            || json_encode(CustomBlocks::normalize($stored->kind, (array) $stored->config)) !== json_encode($cfg),
            'usage'     => $usage,
            'liveShops' => $stored && collect($usage)->where('live', true)->isNotEmpty() ? BlockStore::shopCount($stored->key) : 0,
            'sentence'  => CustomBlocks::sentence($this->kind, $cfg),
            'who'       => ($a = CustomBlocks::access($this->kind, $cfg)['all'][0] ?? null) ? (\App\Support\SectionRegistry::all()[$a]['label'] ?? $a) : null,
            'preview'   => $preview,
            'previewError' => $previewError,
            'lacks'     => $lacks,
            'shops'     => Tenant::where('is_active', true)->orderBy('name')->limit(300)->pluck('name', 'id')->all(),
        ];
    }
}
