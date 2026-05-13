<?php

namespace App\Filament\Pages;

use App\Models\ThemeSetting;
use App\Models\ThemeSettingsAudit;
use App\Services\ThemeSettingsService;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Schema;

/**
 * ThemeEditor — master admin UI for editing platform theme tokens.
 *
 * Form data lives in $this->data, keyed as ['b' => [..tokens..], 'c' => [...]].
 * Changes are tracked against the published values in DB. "Publish" writes
 * to draft_value then immediately publishes (we collapse the draft/publish
 * lifecycle to a single explicit action here for simplicity; the underlying
 * service still supports separate setDraft/publish if needed).
 *
 * The view (theme-editor.blade.php) reads $this->data directly to render
 * the live preview pane — so as the user types, Livewire re-renders and
 * the preview updates without any custom JS.
 */
class ThemeEditor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';
    protected static ?string $navigationLabel = 'Themes';
    protected static ?string $navigationGroup = 'Configuration';
    protected static ?int $navigationSort = 5;
    protected static ?string $title = 'Theme editor';

    protected static string $view = 'filament.pages.theme-editor';

    public ?array $data = [];

    /** Token groups for both themes — single source of truth. */
    public const GROUPS = [
        'Surfaces' => [
            'ia-bg'           => 'Page background',
            'ia-surface'      => 'Card surface',
            'ia-surface-2'    => 'Surface (hover / fill)',
        ],
        'Borders' => [
            'ia-border'        => 'Default border',
            'ia-border-strong' => 'Strong border',
        ],
        'Text' => [
            'ia-text'        => 'Primary text',
            'ia-text-muted'  => 'Muted text',
            'ia-text-dim'    => 'Dim text (labels)',
        ],
        'Interactive' => [
            'ia-hover'    => 'Hover overlay',
            'ia-input-bg' => 'Input background',
        ],
        'Sidebar' => [
            'ia-side-bg'          => 'Sidebar background',
            'ia-side-text'        => 'Sidebar text',
            'ia-side-hover'       => 'Sidebar hover',
            'ia-side-active-bg'   => 'Active item background',
            'ia-side-active-text' => 'Active item text',
            'ia-side-border'      => 'Sidebar border',
            'ia-side-section'     => 'Section label',
        ],
    ];

    public function mount(): void
    {
        if (!Schema::hasTable('theme_settings')) {
            $this->data = ['b' => [], 'c' => []];
            return;
        }

        $rows = ThemeSetting::all();
        $byTheme = ['b' => [], 'c' => []];
        foreach ($rows as $r) {
            // Prefer draft_value if it exists, otherwise published.
            $byTheme[$r->theme][$r->token_key] = $r->draft_value ?? $r->published_value;
        }
        $this->data = $byTheme;
        $this->form->fill($this->data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make('Theme')
                    ->tabs([
                        Tabs\Tab::make('Light theme (b)')
                            ->icon('heroicon-o-sun')
                            ->schema($this->themeFields('b')),

                        Tabs\Tab::make('Dark theme (c)')
                            ->icon('heroicon-o-moon')
                            ->schema($this->themeFields('c')),
                    ]),
            ])
            ->statePath('data');
    }

    /** Build form fields for one theme. */
    private function themeFields(string $theme): array
    {
        $sections = [];
        foreach (self::GROUPS as $groupName => $tokens) {
            $fields = [];
            foreach ($tokens as $key => $label) {
                $fields[] = TextInput::make("{$theme}.{$key}")
                    ->label($label)
                    ->helperText("--{$key}")
                    ->required()
                    ->maxLength(255)
                    ->live(debounce: 250);
            }
            $sections[] = Section::make($groupName)
                ->columns(2)
                ->schema($fields)
                ->collapsible();
        }
        return $sections;
    }

    public function publish(): void
    {
        if (!Schema::hasTable('theme_settings')) {
            Notification::make()->danger()
                ->title('Migration not run')->body('theme_settings table not found.')->send();
            return;
        }

        $service = app(ThemeSettingsService::class);
        $userId = auth()->id();
        $changes = 0;

        foreach (['b', 'c'] as $theme) {
            $values = $this->data[$theme] ?? [];
            foreach ($values as $key => $value) {
                $value = (string) $value;
                $current = ThemeSetting::where('theme', $theme)
                    ->where('token_key', $key)
                    ->value('published_value');
                if ($current === $value) {
                    continue;
                }
                $service->setDraft($theme, $key, $value, $userId);
                $changes++;
            }
        }

        if ($changes === 0) {
            Notification::make()->info()->title('No changes to publish')->send();
            return;
        }

        $published = $service->publish(null, $userId);

        Notification::make()->success()
            ->title("Published {$published} change" . ($published === 1 ? '' : 's'))
            ->body('Theme updates are live for all tenants on next page load.')
            ->send();
    }

    public function revert(): void
    {
        // Reload published values into the form, discarding any unsaved typing.
        $this->mount();

        Notification::make()->success()
            ->title('Reverted unsaved changes')->send();
    }

    /** Detect changes between current form state and published DB values. */
    public function getDirtyCountProperty(): int
    {
        if (!Schema::hasTable('theme_settings')) return 0;

        $count = 0;
        $rows = ThemeSetting::all(['theme', 'token_key', 'published_value']);
        $published = [];
        foreach ($rows as $r) {
            $published[$r->theme][$r->token_key] = $r->published_value;
        }
        foreach (['b', 'c'] as $theme) {
            $values = $this->data[$theme] ?? [];
            foreach ($values as $key => $value) {
                $pub = $published[$theme][$key] ?? null;
                if ($pub !== null && (string) $pub !== (string) $value) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /** Recent audit entries for the bottom of the page. */
    public function getRecentAuditsProperty()
    {
        if (!Schema::hasTable('theme_settings_audits')) return collect();

        return ThemeSettingsAudit::orderBy('created_at', 'desc')
            ->limit(20)
            ->get();
    }
}
