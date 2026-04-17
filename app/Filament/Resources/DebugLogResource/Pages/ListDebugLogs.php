<?php

namespace App\Filament\Resources\DebugLogResource\Pages;

use App\Filament\Resources\DebugLogResource;
use App\Filament\Widgets\DebugLogHeaderStats;
use App\Filament\Widgets\DebugLogErrorsChart;
use App\Models\DebugLog;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDebugLogs extends ListRecords
{
    protected static string $resource = DebugLogResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            DebugLogHeaderStats::class,
            DebugLogErrorsChart::class,
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'errors' => Tab::make('Errors')
                ->modifyQueryUsing(fn (Builder $q) => $q
                    ->where('channel', 'error')
                    ->where('is_resolved', false)),

            'requests' => Tab::make('Requests')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('channel', 'request')),

            'mail' => Tab::make('Mail')
                ->modifyQueryUsing(fn (Builder $q) => $q->whereIn('channel', ['mail', 'sms'])),

            'auth' => Tab::make('Auth')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('channel', 'auth')),

            'audit' => Tab::make('Audit')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('channel', 'audit')),

            'jobs' => Tab::make('Jobs')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('channel', 'job')),

            'webhooks' => Tab::make('Webhooks')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('channel', 'webhook')),

            'impersonation' => Tab::make('Impersonation')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('channel', 'impersonation')),
        ];
    }
}
