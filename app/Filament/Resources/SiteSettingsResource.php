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
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'marketing';

    protected static ?string $model = SiteSettings::class;

    protected static ?string $navigationIcon  = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Platform';
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

            Forms\Components\Section::make('Brand assets')
                ->description('URLs to override the default shipped assets. Leave blank to use the files in /public.')
                ->schema([
                    Forms\Components\TextInput::make('logo_url')
                        ->label('Logo URL')
                        ->url()
                        ->helperText('Default: /logo.svg. Should be a 168×36 SVG with icon + wordmark.'),

                    Forms\Components\TextInput::make('favicon_url')
                        ->label('Favicon URL')
                        ->url()
                        ->helperText('Default: /favicon.svg. Should be a square SVG, optimized for small sizes.'),

                    Forms\Components\TextInput::make('og_image_url')
                        ->label('OG share image URL')
                        ->url()
                        ->helperText('Default: /og-image.png. Should be 1200×630 PNG.'),
                ]),

            Forms\Components\Section::make('Social links')
                ->description('Shown in the footer. Leave blank to hide that platform.')
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('twitter_url')->label('Twitter / X URL')->url(),
                    Forms\Components\TextInput::make('linkedin_url')->label('LinkedIn URL')->url(),
                    Forms\Components\TextInput::make('github_url')->label('GitHub URL')->url(),
                ]),

            Forms\Components\Section::make('Analytics')
                ->description('Tracking codes injected into every marketing page.')
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('plausible_domain')
                        ->label('Plausible / Fathom domain')
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
