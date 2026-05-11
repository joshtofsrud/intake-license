<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlatformNavItemResource\Pages;
use App\Models\Tenant;
use App\Models\Tenant\TenantNavItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master admin: edit the platform tenant's navigation items.
 * Items are scoped to the platform tenant (is_platform=true).
 * Renders on every marketing page via the CMS shell.
 */
class PlatformNavItemResource extends Resource
{
    protected static ?string $model = TenantNavItem::class;

    protected static ?string $navigationIcon  = 'heroicon-o-bars-3';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?string $navigationLabel = 'Navigation';
    protected static ?int    $navigationSort  = 11;
    protected static ?string $recordTitleAttribute = 'label';

    protected static ?string $modelLabel       = 'Nav item';
    protected static ?string $pluralModelLabel = 'Navigation';
    protected static ?string $breadcrumb       = 'Navigation';
    protected static ?string $slug             = 'navigation';

    /**
     * Hide from navigation if the migration hasn't run yet.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('tenant_nav_items');
    }

    public static function getEloquentQuery(): Builder
    {
        $platform = Tenant::where('is_platform', true)->first();
        if (! $platform) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }
        return parent::getEloquentQuery()
            ->where('tenant_id', $platform->id)
            ->orderBy('sort_order');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Link')
                ->schema([
                    Forms\Components\TextInput::make('label')
                        ->required()
                        ->maxLength(64)
                        ->helperText('Shown in the nav. Keep short — one or two words.'),

                    Forms\Components\TextInput::make('url')
                        ->required()
                        ->maxLength(500)
                        ->helperText('Use "/path" for internal pages. Use "https://..." for external links.'),

                    Forms\Components\Toggle::make('is_external')
                        ->label('External link')
                        ->helperText('Marks this as off-site. Shown with a different badge in admin.')
                        ->default(false),

                    Forms\Components\Toggle::make('open_in_new_tab')
                        ->label('Open in new tab')
                        ->helperText('Adds target="_blank" rel="noopener". Recommended for external links.')
                        ->default(false),

                    Forms\Components\TextInput::make('sort_order')
                        ->numeric()
                        ->default(0)
                        ->helperText('Lower numbers appear first.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order', 'asc')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->weight('medium')
                    ->searchable(),

                Tables\Columns\TextColumn::make('url')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->limit(50)
                    ->searchable(),

                Tables\Columns\IconColumn::make('is_external')
                    ->label('External')
                    ->boolean(),

                Tables\Columns\IconColumn::make('open_in_new_tab')
                    ->label('New tab')
                    ->boolean(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order')
                    ->numeric()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPlatformNavItems::route('/'),
            'create' => Pages\CreatePlatformNavItem::route('/create'),
            'edit'   => Pages\EditPlatformNavItem::route('/{record}/edit'),
        ];
    }
}
