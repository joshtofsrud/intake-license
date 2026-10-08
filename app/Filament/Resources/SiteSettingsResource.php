<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteSettingsResource\Pages;
use App\Models\SiteSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * SiteSettingsResource — manages the singleton site_settings row.
 * Pattern: list view shows the one row, edit goes to the standard
 * EditRecord page. Avoids Filament-version-sensitive mount() overrides.
 */
class SiteSettingsResource extends Resource
{
    use \App\Support\UsesAdminNav;
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'marketing';

    protected static ?string $model = SiteSettings::class;

    protected static ?string $navigationIcon  = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Site & content';
    protected static ?string $navigationLabel = 'Site settings';
    protected static ?int    $navigationSort  = 30;

    protected static ?string $modelLabel       = 'Site settings';
    protected static ?string $pluralModelLabel = 'Site settings';
    protected static ?string $breadcrumb       = 'Site settings';
    protected static ?string $slug             = 'site-settings';

    /**
     * Hide from navigation if the migration hasn't run yet.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return Schema::hasTable('site_settings');
    }

    /**
     * Ensure the singleton row exists whenever this resource is queried.
     * Safe: SiteSettings::current() is firstOrCreate so no duplicate.
     */
    public static function getEloquentQuery(): Builder
    {
        if (Schema::hasTable('site_settings')) {
            SiteSettings::current();
        }
        return parent::getEloquentQuery();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identity')
                ->description('Default page title, meta description, and tagline. Used as fallback when a page doesn\'t set its own.')
                ->schema([
                    Forms\Components\TextInput::make('default_page_title')
                        ->label('Default page title')
                        ->maxLength(191),

                    Forms\Components\Textarea::make('default_meta_description')
                        ->label('Default meta description')
                        ->rows(2)
                        ->maxLength(500)
                        ->helperText('Used in <meta name="description"> when a page doesn\'t override.'),

                    Forms\Components\TextInput::make('footer_tagline')
                        ->label('Footer tagline')
                        ->maxLength(255)
                        ->helperText('Small text shown under the logo in the marketing footer.'),
                ]),

            // logo, favicon and share image moved to the Brand page
            // (master admin > Brand), where they are uploaded and used everywhere. The
            // old URL fields here were read by nothing; the columns stay, unused.
            Forms\Components\Section::make('Brand assets')
                ->schema([
                    Forms\Components\Placeholder::make('brand_moved')
                        ->label('')
                        ->content(new \Illuminate\Support\HtmlString(
                            'Logos, icon and share image are set on the <a href="' . e(\App\Filament\Pages\Brand::getUrl()) . '" style="text-decoration:underline">Brand</a> page.'
                        )),
                ]),

            Forms\Components\Section::make('Social links')
                ->description('Shown in the footer. Leave blank to hide that platform.')
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('twitter_url')->label('Twitter / X URL')->url(),
                    Forms\Components\TextInput::make('linkedin_url')->label('LinkedIn URL')->url(),
                    Forms\Components\TextInput::make('github_url')->label('GitHub URL')->url(),
                ]),

            // these now load (they were read by nothing).
            Forms\Components\Section::make('Analytics')
                ->description('Loaded on every public intake.works page. Not loaded in master admin, the rep panel, investor pages or booking-management links.')
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('ga4_id')
                        ->label('Google Analytics 4 measurement ID')
                        ->placeholder('G-XXXXXXXXXX')
                        ->maxLength(32)
                        ->regex('/^G-[A-Z0-9]{4,20}$/i')
                        ->validationMessages(['regex' => 'A GA4 measurement ID starts with G-, e.g. G-XXXXXXXXXX.']),

                    Forms\Components\TextInput::make('plausible_domain')
                        ->label('Plausible domain')
                        ->placeholder('intake.works'),

                    Forms\Components\TextInput::make('gtm_id')
                        ->label('Google Tag Manager ID')
                        ->placeholder('GTM-XXXXXXX')
                        ->maxLength(64),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('default_page_title')
                    ->label('Site title')
                    ->limit(60)
                    ->placeholder('(not set)'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->dateTime()
                    ->since(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Edit settings'),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSiteSettings::route('/'),
            'edit'  => Pages\EditSiteSettings::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool { return false; }
    public static function canDelete($record): bool { return false; }
}
