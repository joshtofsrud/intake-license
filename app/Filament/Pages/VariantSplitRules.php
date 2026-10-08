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
use Illuminate\Support\Facades\DB;

/**
 * MARKER-OPTION-SPLIT: master-admin editor for the rules that split a
 * variant's run-together Version text into separate register dropdowns.
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
        $this->form->fill([
            'rules' => VariantSplitRule::orderBy('sort')->orderBy('id')->get()->map(fn ($r) => [
                'attribute'  => $r->attribute,
                'applies_to' => $r->applies_to,
                'words'      => implode("\n", $r->words ?? []),
                'is_active'  => (bool) $r->is_active,
            ])->all(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Repeater::make('rules')
                ->label('Rules, in the order their dropdowns appear')
                ->schema([
                    TextInput::make('attribute')->label('Option name')->required()->maxLength(40)
                        ->placeholder('Casing'),
                    TextInput::make('applies_to')->label('Only when the catalog category contains')->maxLength(120)
                        ->placeholder('Leave blank for every category')
                        ->helperText('Matched against the distributor catalog category, e.g. "Tires > Mountain Tires".'),
                    Textarea::make('words')->label('Words that belong to this option')->rows(5)->required()
                        ->helperText('One per line. Longer entries are tried first, so "EXO+" is found before "EXO".'),
                    Toggle::make('is_active')->label('On')->default(true),
                ])
                ->columns(2)
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state) => trim(($state['attribute'] ?? '') . (($state['applies_to'] ?? '') !== '' ? ' · ' . $state['applies_to'] : '')) ?: 'New rule')
                ->addActionLabel('Add rule'),
        ])->statePath('data');
    }

    public function save(): void
    {
        $rules = $this->form->getState()['rules'] ?? [];
        DB::transaction(function () use ($rules) {
            VariantSplitRule::query()->delete();
            foreach (array_values($rules) as $i => $r) {
                $words = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($r['words'] ?? '')) ?: []))));
                if (trim((string) ($r['attribute'] ?? '')) === '' || ! $words) { continue; }
                VariantSplitRule::create([
                    'attribute'  => trim((string) $r['attribute']),
                    'applies_to' => trim((string) ($r['applies_to'] ?? '')) ?: null,
                    'words'      => $words,
                    'sort'       => $i,
                    'is_active'  => (bool) ($r['is_active'] ?? true),
                ]);
            }
        });

        Notification::make()->success()->title('Rules saved')
            ->body('The register uses them on the next search.')->send();
    }
}
