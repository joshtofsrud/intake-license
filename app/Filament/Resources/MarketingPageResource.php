<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketingPageResource\Pages;
use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Marketing pages resource — master admin UI for editing intake.works content.
 *
 * Scopes TenantPage to the platform tenant only (is_platform=true). Every
 * row represents an editable marketing URL:
 *
 *   home                → intake.works/
 *   pricing             → intake.works/pricing
 *   features            → intake.works/features
 *   contact             → intake.works/contact
 *   docs                → intake.works/docs
 *   __industry_template → template for every /for/{slug} page
 *   {any custom slug}   → intake.works/{slug}
 *
 * Editing happens via the existing tenant page builder (same three-column
 * live preview editor). The "Edit content" action routes through
 * /admin/marketing-pages/{id}/content which binds the platform tenant and
 * redirects into the editor.
 */
class MarketingPageResource extends Resource
{
    protected static ?string $model = TenantPage::class;

    protected static ?string $navigationIcon  = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?string $navigationLabel = 'Marketing pages';
    protected static ?int    $navigationSort  = 10;
    protected static ?string $recordTitleAttribute = 'title';

    /**
     * Limit every query to the platform tenant. Guards against accidentally
     * listing real tenant pages in the master admin.
     */
    public static function getEloquentQuery(): Builder
    {
        $platform = Tenant::where('is_platform', true)->first();

        if (! $platform) {
            // No platform tenant yet — return an empty query rather than
            // crashing. Admin can run the seeder via php artisan.
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()->where('tenant_id', $platform->id);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Page settings')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(191),

                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->helperText('URL path. "home" is root, others are /slug. Internal slugs starting with __ are hidden from visitors.')
                        ->rule('regex:/^[a-z0-9_][a-z0-9_-]*$/')
                        ->disabled(fn ($record) => $record?->is_home || ($record?->slug && str_starts_with($record->slug, '__'))),

                    Forms\Components\Toggle::make('is_published')
                        ->helperText('Visitors only see published pages.'),

                    Forms\Components\Toggle::make('is_in_nav')
                        ->label('Show in navigation')
                        ->helperText('Adds to the top nav on other marketing pages.'),

                    Forms\Components\TextInput::make('nav_order')
                        ->numeric()
                        ->default(0)
                        ->visible(fn ($get) => $get('is_in_nav')),
                ])->columns(2),

            Forms\Components\Section::make('SEO')
                ->schema([
                    Forms\Components\TextInput::make('meta_title')
                        ->maxLength(191)
                        ->helperText('Shows in browser tab and search results. Leave blank to use the page title.'),

                    Forms\Components\Textarea::make('meta_description')
                        ->rows(2)
                        ->maxLength(300)
                        ->helperText('Shows under the title in Google results. Aim for 150–160 characters.'),
                ])->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('is_home', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->description(fn (TenantPage $p) => static::urlForPage($p))
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('slug')
                    ->badge()
                    ->color(fn (TenantPage $p) => str_starts_with($p->slug, '__') ? 'warning' : 'gray'),

                Tables\Columns\TextColumn::make('sections_count')
                    ->label('Sections')
                    ->counts('sections')
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_home')
                    ->label('Home')
                    ->boolean()
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_published')
                    ->label('Published')
                    ->boolean()
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_in_nav')
                    ->label('In nav')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('edit_content')
                    ->label('Edit content')
                    ->icon('heroicon-o-paint-brush')
                    ->color('primary')
                    ->url(fn (TenantPage $p) => route('admin.marketing-pages.edit-content', $p->id)),

                Tables\Actions\Action::make('view_live')
                    ->label('View live')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (TenantPage $p) => static::urlForPage($p))
                    ->openUrlInNewTab()
                    ->visible(fn (TenantPage $p) => $p->is_published && ! str_starts_with($p->slug, '__')),

                Tables\Actions\EditAction::make()
                    ->label('Settings'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        // Never let anyone bulk-delete the home or template pages
                        ->action(function ($records) {
                            $records->each(function ($r) {
                                if ($r->is_home || str_starts_with($r->slug, '__')) return;
                                $r->delete();
                            });
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListMarketingPages::route('/'),
            'create' => Pages\CreateMarketingPage::route('/create'),
            'edit'   => Pages\EditMarketingPage::route('/{record}/edit'),
        ];
    }

    /**
     * Given a marketing page, return the public URL it's served at.
     *
     *   home     → https://intake.works/
     *   pricing  → https://intake.works/pricing
     *   __*      → (internal, no public URL)
     */
    public static function urlForPage(TenantPage $page): string
    {
        $domain = config('intake.domain', 'intake.works');

        if (str_starts_with($page->slug, '__')) {
            return '(internal template — not directly accessible)';
        }

        if ($page->is_home) {
            return 'https://' . $domain . '/';
        }

        return 'https://' . $domain . '/' . $page->slug;
    }
}
