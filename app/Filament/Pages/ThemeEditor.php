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

    /**
     * MARKER-THEME-TEXT-SIZE
     *
     * SIZE_TOKENS: text sizing, deliberately NOT split per theme. The
     * storage schema is (theme, token_key), so these are written to both
     * 'b' and 'c' with the same value on publish — one field, two rows.
     * Values are px strings; each CSS rule keeps its original number as
     * the var() fallback, so an unset token changes nothing.
     */
    public const SIZE_TOKENS = [
        'Status tiles' => [
            'ia-fs-tile-label' => ['Tile label', '11.5px'],
            'ia-fs-tile-count' => ['Tile number', '32px'],
            'ia-fs-tile-desc'  => ['Tile description', '12.5px'],
        ],
        'Calendar' => [
            'ia-fs-cal-appt'     => ['Appointment block', '11px'],
            'ia-fs-cal-appt-sub' => ['Block service / time line', '10px'],
        ],
        'Tables' => [
            'ia-fs-table' => ['List table rows', '13px'],
        ],
    ];

    /** Flat key => default map, for prefilling the form. */
    public static function sizeDefaults(): array
    {
        $out = [];
        foreach (self::SIZE_TOKENS as $tokens) {
            foreach ($tokens as $key => [$label, $default]) {
                $out[$key] = $default;
            }
        }
        return $out;
    }

    /**
     * HEX_TOKENS: which token keys use #xxxxxx values (vs rgba()).
     * These get a ColorPicker for visual editing. Others stay as TextInput
     * because Filament's ColorPicker doesn't handle rgba alpha well.
     */
    public const HEX_TOKENS = [
        'ia-bg',
        'ia-surface',
        'ia-surface-2',
        'ia-text',
        'ia-input-bg',
        'ia-side-bg',
        'ia-side-active-text',
    ];

    public function mount(): void
    {
        if (!Schema::hasTable('theme_settings')) {
            $this->data = ['b' => [], 'c' => [], 'size' => self::sizeDefaults()];
            return;
        }

        $rows = ThemeSetting::all();
        $byTheme = ['b' => [], 'c' => []];
        foreach ($rows as $r) {
            // Prefer draft_value if it exists, otherwise published.
            $byTheme[$r->theme][$r->token_key] = $r->draft_value ?? $r->published_value;
        }
        // MARKER-THEME-TEXT-SIZE — sizes live under both themes but are
        // edited once; read 'b' as the source of truth, fall back to the
        // hardcoded CSS value so the field is never blank.
        $sizes = [];
        foreach (self::sizeDefaults() as $key => $default) {
            $sizes[$key] = $byTheme['b'][$key] ?? $default;
        }
        $byTheme['size'] = $sizes;

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

                        // MARKER-THEME-TEXT-SIZE — applies to both themes.
                        Tabs\Tab::make('Text sizes')
                            ->icon('heroicon-o-language')
                            ->schema($this->sizeFields()),
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
                if (in_array($key, self::HEX_TOKENS, true)) {
                    // Hex token: visual color picker.
                    $fields[] = ColorPicker::make("{$theme}.{$key}")
                        ->label($label)
                        ->helperText("--{$key}")
                        ->required()
                        ->live(debounce: 250);
                } else {
                    // Rgba / alpha-overlay token: text input.
                    // (Filament's ColorPicker doesn't round-trip alpha cleanly.)
                    $fields[] = TextInput::make("{$theme}.{$key}")
                        ->label($label)
                        ->helperText("--{$key} · rgba alpha-overlay")
                        ->placeholder('rgba(0,0,0,.10)')
                        ->required()
                        ->maxLength(255)
                        ->live(debounce: 250);
                }
            }
            $sections[] = Section::make($groupName)
                ->columns(2)
                ->schema($fields)
                ->collapsible();
        }
        return $sections;
    }

    /**
     * MARKER-THEME-TEXT-SIZE — one set of size fields, applied to both
     * themes. Values must be a plain px length; anything else is rejected
     * before it can reach the emitted <style> block.
     */
    private function sizeFields(): array
    {
        $sections = [];
        foreach (self::SIZE_TOKENS as $groupName => $tokens) {
            $fields = [];
            foreach ($tokens as $key => [$label, $default]) {
                $fields[] = TextInput::make("size.{$key}")
                    ->label($label)
                    ->helperText("--{$key} · default {$default}")
                    ->placeholder($default)
                    ->required()
                    ->maxLength(12)
                    ->rule('regex:/^\\d{1,3}(\\.\\d)?px$/')
                    ->validationMessages([
                        'regex' => 'Use a px value, e.g. 14px or 12.5px.',
                    ])
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

        // MARKER-THEME-PUBLISH-COUNT — what the banner is showing right
        // now, read before the fan-out below rewrites $this->data. This is
        // a count of FIELDS the user edited; $service->publish() returns a
        // count of ROWS, and a size field is two rows. Reporting the row
        // count made the toast contradict the banner.
        $reported = $this->getDirtyCountProperty();

        // MARKER-THEME-TEXT-SIZE — copy the single size tab into both
        // themes before the normal write loop picks the data up. Doing it
        // here (rather than a separate loop) means dirty-count, audit rows
        // and publish all treat sizes exactly like any other token.
        foreach (($this->data['size'] ?? []) as $key => $value) {
            $this->data['b'][$key] = $value;
            $this->data['c'][$key] = $value;
        }

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

        // MARKER-THEME-PUBLISH-COUNT — prefer the field count; fall back
        // to rows if it somehow came back empty so the toast still reads.
        $shown = $reported > 0 ? $reported : $published;

        Notification::make()->success()
            ->title("Published {$shown} change" . ($shown === 1 ? '' : 's'))
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
        // MARKER-THEME-TEXT-SIZE-DIRTY — size tokens are counted below,
        // once each. Skipping them here matters after a publish, which
        // copies them into both themes and would otherwise double-count.
        $sizeDefaults = self::sizeDefaults();

        foreach (['b', 'c'] as $theme) {
            $values = $this->data[$theme] ?? [];
            foreach ($values as $key => $value) {
                if (array_key_exists($key, $sizeDefaults)) {
                    continue;
                }
                $pub = $published[$theme][$key] ?? null;
                if ($pub !== null && (string) $pub !== (string) $value) {
                    $count++;
                }
            }
        }

        // MARKER-THEME-TEXT-SIZE-DIRTY — one field, one count. A token
        // that has never been published has no row, so the CSS default is
        // the thing being changed away from; without this the first edit
        // on a fresh install reads as no change at all.
        foreach ($sizeDefaults as $key => $default) {
            $value = $this->data['size'][$key] ?? null;
            if ($value === null) {
                continue;
            }
            $pub = $published['b'][$key] ?? $default;
            if ((string) $pub !== (string) $value) {
                $count++;
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
