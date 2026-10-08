<?php

namespace App\Filament\Pages;

use App\Models\VariantSplitRule;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-OPTION-SPLIT / MARKER-OPTION-FIELDS: master-admin editor for the
 * options the register picker splits out (Casing, Compound, Bead, TPI):
 * which distributor fields each reads, its known values, and the spellings
 * that mean the same thing.
 */
class VariantSplitRules extends Page implements HasForms
{
    use \App\Support\UsesAdminNav;
    use \App\Support\GatedByAdminArea;
    use InteractsWithForms;

    protected static string $adminArea = 'catalog';

    protected static ?string $navigationIcon  = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationLabel = 'Option splitting';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 70;
    protected static ?string $title = 'Option splitting';

    protected static string $view = 'filament.pages.variant-split-rules';

    public ?array $data = [];

    public function mount(): void
    {
        $lines = fn ($a) => implode("\n", array_map('strval', (array) ($a ?? [])));
        $this->form->fill([
            'rules' => VariantSplitRule::orderBy('sort')->orderBy('id')->get()->map(fn ($r) => [
                'attribute'    => $r->attribute,
                'applies_to'   => $r->applies_to,
                'fields'       => implode(', ', (array) ($r->fields ?? [])),
                'mixed_fields' => implode(', ', (array) ($r->mixed_fields ?? [])),
                'words'        => $lines($r->words),
                'aliases'      => implode("\n", array_map(fn ($k, $v) => "$k = $v", array_keys((array) ($r->aliases ?? [])), (array) ($r->aliases ?? []))),
                'is_active'    => (bool) $r->is_active,
            ])->all(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Repeater::make('rules')
                ->label('Options, in the order their dropdowns appear')
                ->schema([
                    TextInput::make('attribute')->label('Option name')->required()->maxLength(40)->placeholder('Casing'),
                    TextInput::make('applies_to')->label('Only when the catalog category contains')->maxLength(120)
                        ->placeholder('Blank: every category')
                        ->helperText('e.g. "Tire" matches "Tires > Mountain Tires" and "Tires".'),
                    TextInput::make('fields')->label('Distributor fields that hold only this option')
                        ->placeholder('Compound, Tire Compound')
                        ->helperText('Comma separated. The first one a catalog row has is used, whatever its value.'),
                    TextInput::make('mixed_fields')->label('Fields that mix this option with other things')
                        ->placeholder('Tire Technology')
                        ->helperText('Only values in the known list below are taken from these.'),
                    Textarea::make('words')->label('Known values')->rows(5)
                        ->helperText('One per line. Also used to read the option from a title when a distributor sends no field.'),
                    Textarea::make('aliases')->label('Other spellings')->rows(5)
                        ->placeholder("3C MaxxTerra = MaxxTerra\nDC = Dual")
                        ->helperText('One per line: what a distributor writes = the name shown.'),
                    Toggle::make('is_active')->label('On')->default(true),
                ])
                ->columns(2)
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state) => trim(($state['attribute'] ?? '') . (($state['applies_to'] ?? '') !== '' ? ' · ' . $state['applies_to'] : '')) ?: 'New option')
                ->addActionLabel('Add option'),
        ])->statePath('data');
    }

    public function save(): void
    {
        $rules = $this->form->getState()['rules'] ?? [];
        $list = fn ($s, $sep) => array_values(array_unique(array_filter(array_map('trim', preg_split($sep, (string) $s) ?: []))));
        DB::transaction(function () use ($rules, $list) {
            VariantSplitRule::query()->delete();
            foreach (array_values($rules) as $i => $r) {
                if (trim((string) ($r['attribute'] ?? '')) === '') { continue; }
                $aliases = [];
                foreach ($list($r['aliases'] ?? '', '/\r?\n/') as $line) {
                    if (str_contains($line, '=')) {
                        [$from, $to] = array_map('trim', explode('=', $line, 2));
                        if ($from !== '' && $to !== '') { $aliases[$from] = $to; }
                    }
                }
                VariantSplitRule::create([
                    'attribute'    => trim((string) $r['attribute']),
                    'applies_to'   => trim((string) ($r['applies_to'] ?? '')) ?: null,
                    'fields'       => $list($r['fields'] ?? '', '/\s*,\s*/'),
                    'mixed_fields' => $list($r['mixed_fields'] ?? '', '/\s*,\s*/'),
                    'words'        => $list($r['words'] ?? '', '/\r?\n/'),
                    'aliases'      => $aliases,
                    'sort'         => $i,
                    'is_active'    => (bool) ($r['is_active'] ?? true),
                ]);
            }
        });

        // re-read every catalog row in the background with the new rules
        Artisan::queue('catalog:spec-attrs', ['--all' => true]);

        Notification::make()->success()->title('Options saved')
            ->body('Catalog rows are being re-read in the background; the register picks the new values up within a few minutes.')->send();
    }
}
