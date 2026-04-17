<?php

namespace App\Filament\Resources\DebugLogResource\Pages;

use App\Filament\Resources\DebugLogResource;
use App\Models\DebugLog;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewDebugLog extends ViewRecord
{
    protected static string $resource = DebugLogResource::class;

    /**
     * Header actions for jumping from a single log row to related rows.
     * The record's fields are rendered by Filament via the resource's
     * form() definition (all disabled).
     */
    protected function getHeaderActions(): array
    {
        /** @var DebugLog $record */
        $record = $this->record;

        $actions = [];

        if ($record->correlation_id) {
            $relatedCount = DebugLog::where('correlation_id', $record->correlation_id)
                ->where('id', '!=', $record->id)
                ->count();

            $actions[] = Actions\Action::make('trace')
                ->label('See full request trace' . ($relatedCount ? " ($relatedCount other)" : ''))
                ->icon('heroicon-o-link')
                ->color('gray')
                ->url(DebugLogResource::getUrl('index', [
                    'tableFilters' => ['correlation_id' => ['correlation_id' => $record->correlation_id]],
                ]));
        }

        if ($record->fingerprint) {
            $groupCount = DebugLog::where('fingerprint', $record->fingerprint)->count();
            if ($groupCount > 1) {
                $actions[] = Actions\Action::make('group')
                    ->label("See all $groupCount instances")
                    ->icon('heroicon-o-squares-2x2')
                    ->color('gray')
                    ->url(DebugLogResource::getUrl('index', [
                        'tableFilters' => ['fingerprint' => ['fingerprint' => $record->fingerprint]],
                    ]));
            }
        }

        return $actions;
    }
}
