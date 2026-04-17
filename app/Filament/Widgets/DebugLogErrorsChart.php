<?php

namespace App\Filament\Widgets;

use App\Models\DebugLog;
use Filament\Widgets\ChartWidget;

/**
 * 14-day stacked line showing daily error + warning counts, so you can
 * eyeball whether something broke recently.
 */
class DebugLogErrorsChart extends ChartWidget
{
    protected static ?string $heading = 'Errors & warnings — last 14 days';
    protected static ?int    $sort    = 2;
    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn ($n) => now()->subDays($n)->toDateString());

        $counts = DebugLog::selectRaw("DATE(created_at) as d, channel, severity, COUNT(*) as c")
            ->where('created_at', '>=', now()->subDays(14)->startOfDay())
            ->where(function ($q) {
                $q->where('channel', 'error')
                  ->orWhereIn('severity', ['warning', 'critical']);
            })
            ->groupBy('d', 'channel', 'severity')
            ->get();

        $errors = $days->map(function ($d) use ($counts) {
            return $counts
                ->where('d', $d)
                ->whereIn('severity', ['error', 'critical'])
                ->sum('c');
        });

        $warnings = $days->map(function ($d) use ($counts) {
            return $counts->where('d', $d)->where('severity', 'warning')->sum('c');
        });

        return [
            'datasets' => [
                [
                    'label' => 'Errors',
                    'data'  => $errors->all(),
                    'borderColor'     => 'rgb(239, 68, 68)',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.12)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Warnings',
                    'data'  => $warnings->all(),
                    'borderColor'     => 'rgb(245, 158, 11)',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.12)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $days->map(fn ($d) => \Carbon\Carbon::parse($d)->format('M j'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
