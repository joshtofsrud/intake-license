<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SectionLibraryResource\Pages;
use App\Models\Tenant\TenantPageSection;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master admin: read-only catalog of available section types.
 * Shows what sections exist in marketing/sections/, how many pages use
 * each one, and links to docs for adding new types.
 *
 * Useful as a reference when planning content. NOT used for editing —
 * sections are edited in the page editor.
 */
class SectionLibraryResource extends Resource
{
    use \App\Support\UsesAdminNav; // MARKER-NAV-ORDER
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'marketing';

    // Sits on top of TenantPageSection but renders aggregated rows.
    protected static ?string $model = TenantPageSection::class;

    protected static ?string $navigationIcon  = 'heroicon-o-squares-2x2';
    protected static ?string $navigationGroup = 'Site & content';
    protected static ?string $navigationLabel = 'Section library';
    protected static ?int    $navigationSort  = 40;

    protected static ?string $modelLabel       = 'Section type';
    protected static ?string $pluralModelLabel = 'Section library';
    protected static ?string $breadcrumb       = 'Section library';
    protected static ?string $slug             = 'section-library';

    /**
     * Hide from navigation if the migration hasn't run yet.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('tenant_page_sections');
    }

    public static function getEloquentQuery(): Builder
    {
        // Aggregate: distinct section_type with count.
        return parent::getEloquentQuery()
            ->selectRaw('MIN(id) as id, section_type, COUNT(*) as usage_count')
            ->groupBy('section_type');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('section_type', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('section_type')
                    ->label('Section type')
                    ->formatStateUsing(fn ($state) => str_replace('_', ' ', ucfirst($state)))
                    ->weight('medium')
                    ->searchable(),

                Tables\Columns\TextColumn::make('section_type')
                    ->label('Identifier')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->copyable(),

                Tables\Columns\TextColumn::make('usage_count')
                    ->label('In use on')
                    ->formatStateUsing(fn ($state) => $state.' page'.($state === 1 ? '' : 's'))
                    ->sortable(),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSectionLibrary::route('/'),
        ];
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }
}
