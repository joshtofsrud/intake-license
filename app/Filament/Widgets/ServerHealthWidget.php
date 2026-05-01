<?php

namespace App\Filament\Widgets;

use App\Services\Admin\ServerHealthService;
use Filament\Widgets\Widget;

/**
 * Server health card row at the top of the admin dashboard.
 *
 * Polls every 30 seconds via wire:poll on the rendered view. The service
 * is cached for 5 seconds so concurrent admin sessions don't multiply
 * system command load.
 *
 * Sort order is -10 so this lands above the existing PlatformStatsWidget
 * (sort=1) — server health is the first thing Josh wants to see when
 * something feels off.
 */
class ServerHealthWidget extends Widget
{
    protected static string $view = 'filament.widgets.server-health';

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return [
            'health' => app(ServerHealthService::class)->snapshot(),
        ];
    }
}
