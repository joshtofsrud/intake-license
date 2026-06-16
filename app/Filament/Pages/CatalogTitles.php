<?php
// MARKER-PATCH-HLCE

namespace App\Filament\Pages;

use App\Jobs\RecomposeAndSyncTitlesJob;
use App\Models\CatalogTitleSetting;
use App\Models\PlatformDistributorCatalog;
use App\Services\Distributors\CatalogTitleComposer;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Master-admin: edit catalog title / subtitle / search templates per
 * distributor with a live preview on real rows.
 */
class CatalogTitles extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-tag';
    protected static ?string $navigationLabel = 'Catalog Titles';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 20;

    protected static string $view = 'filament.pages.catalog-titles';

    public ?array $data = [];
    public string $code = '*';
    /** SKUs shown in the live preview — defaults span look-alikes that stress titles. */
    public string $previewSkus = '210273-01, 210273-02, 210273-03, 120520-02, 120520-03, 120520-04, 120520-05';

    /** Token reference shown beside the editor. */
    public const TOKENS = [
        '{brand}'      => 'Manufacturer — "SRAM"',
        '{model}'      => 'Product name — "Code 2011+"',
        '{mpn}'        => 'Mfg part number',
        '{type}'       => 'Specific category / L1 — "Disc Brake Pads"',
        '{type0}'      => 'Broad category / L0 — "Brake Pads"',
        '{unit}'       => 'Sellable unit — PAIR / KIT / EA',
        '{size}'       => 'Parsed size (if a size pattern matches)',
        '{color}'      => 'Color attribute',
        '{attr:NAME}'  => 'A single named attribute, e.g. {attr:Disc Pad Material}',
        '{allattr}'    => 'Every attribute, "Name Value" — exhaustive, for search',
    ];

    public function mount(): void
    {
        $this->loadCode($this->code);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('code')->label('Distributor')->native(false)
                ->options($this->distributorOptions())
                ->default('*')->live()
                ->afterStateUpdated(fn ($state) => $this->loadCode((string) $state)),

            TextInput::make('title_template')->label('Display title')
                ->helperText('Short — what the cashier scans.')
                ->live(debounce: 400)->maxLength(255),

            TextInput::make('subtitle_template')->label('Subtitle (descriptor)')
                ->helperText('The readable line tenants use to confirm the right item.')
                ->live(debounce: 400)->maxLength(255),

            TextInput::make('search_template')->label('Search blob (hidden)')
                ->helperText('Exhaustive — indexed for search, not shown.')
                ->live(debounce: 400)->maxLength(255),

            TextInput::make('previewSkus')->label('Preview SKUs')
                ->helperText('Comma-separated variant numbers to preview against.')
                ->live(debounce: 600),
        ])->statePath('data');
    }

    protected function distributorOptions(): array
    {
        $opts = ['*' => '★ Global default (*)'];
        foreach (PlatformDistributorCatalog::query()->distinct()->pluck('distributor_code') as $c) {
            if ($c) { $opts[$c] = $c; }
        }
        return $opts;
    }

    public function loadCode(string $code): void
    {
        $row = CatalogTitleSetting::query()->where('distributor_code', $code)->first()
            ?? CatalogTitleSetting::query()->where('distributor_code', '*')->first();

        $this->code = $code;
        $this->data['code']              = $code;
        $this->data['title_template']    = $row->title_template ?? '{brand} {model} {size} {color}';
        $this->data['subtitle_template'] = $row->subtitle_template ?? '{mpn}';
        $this->data['search_template']   = $row->search_template ?? '';
        $this->data['previewSkus']       = $this->data['previewSkus'] ?? $this->previewSkus;
    }

    /** Resolve current (unsaved) templates against the real preview rows. */
    public function previewRows(): array
    {
        $composer = app(CatalogTitleComposer::class);
        $code = $this->data['code'] ?? '*';
        $skus = collect(explode(',', (string) ($this->data['previewSkus'] ?? $this->previewSkus)))
            ->map(fn ($s) => trim($s))->filter()->values();

        $rows = PlatformDistributorCatalog::query()
            ->whereIn('distributor_variant_no', $skus)
            ->get()->keyBy('distributor_variant_no');

        $resolveCode = $code === '*' ? ($rows->first()->distributor_code ?? 'HLC') : $code;

        $out = [];
        $titleCounts = [];
        foreach ($skus as $sku) {
            $row = $rows->get($sku);
            if (! $row) { $out[] = ['sku' => $sku, 'missing' => true]; continue; }
            $parts = $composer->partsFromRow($row);
            $title = $composer->renderTemplate($resolveCode, (string) ($this->data['title_template'] ?? ''), $parts);
            $sub   = $composer->renderTemplate($resolveCode, (string) ($this->data['subtitle_template'] ?? ''), $parts);
            $search= $composer->renderTemplate($resolveCode, (string) ($this->data['search_template'] ?? ''), $parts);
            $titleCounts[mb_strtolower($title)] = ($titleCounts[mb_strtolower($title)] ?? 0) + 1;
            $out[] = ['sku' => $sku, 'title' => $title, 'subtitle' => $sub, 'search' => $search];
        }
        foreach ($out as &$r) {
            $r['collides'] = ! ($r['missing'] ?? false) && ($titleCounts[mb_strtolower($r['title'] ?? '')] ?? 0) > 1;
        }
        return $out;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Save templates')->action('save'),

            Action::make('saveRecompose')->label('Save & Recompose')->color('primary')
                ->requiresConfirmation()
                ->modalDescription('Saves, then rebuilds titles on every catalog row for this distributor and pushes them to tenant items. Runs in the background.')
                ->action('saveAndRecompose'),
        ];
    }

    public function save(): void
    {
        $code = $this->data['code'] ?? '*';
        CatalogTitleSetting::query()->updateOrCreate(
            ['distributor_code' => $code],
            [
                'title_template'    => $this->data['title_template'] ?? '{brand} {model}',
                'subtitle_template' => $this->data['subtitle_template'] ?? '{mpn}',
                'search_template'   => $this->data['search_template'] ?? '',
                'is_active'         => true,
            ]
        );
        Notification::make()->success()->title('Templates saved')
            ->body('Preview reflects the new templates. Recompose to apply to stored rows.')->send();
    }

    public function saveAndRecompose(): void
    {
        $this->save();
        $code = ($this->data['code'] ?? '*');
        RecomposeAndSyncTitlesJob::dispatch($code === '*' ? null : $code);
        Notification::make()->success()->title('Recompose queued')
            ->body('Rebuilding catalog titles + syncing tenant items in the background.')->send();
    }

    public function getViewData(): array
    {
        return [
            'tokens'  => self::TOKENS,
            'preview' => $this->previewRows(),
        ];
    }
}
