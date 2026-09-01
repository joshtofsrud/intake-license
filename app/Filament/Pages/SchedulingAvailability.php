<?php

namespace App\Filament\Pages;

use App\Models\PlatformBookingSetting;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

// MARKER-SCHED-ADMIN — hours, rules and blocked dates behind the public slots.
class SchedulingAvailability extends Page
{
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'scheduling';

    protected static ?string $navigationIcon  = 'heroicon-o-clock';
    protected static ?string $navigationLabel = 'Availability';
    protected static ?string $navigationGroup = 'Scheduling';
    protected static ?string $title           = 'Availability';
    protected static ?int    $navigationSort  = 61;
    protected static ?string $slug            = 'scheduling-availability';

    protected static string $view = 'filament.pages.scheduling-availability';

    public const DAYS = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

    public const TIMEZONES = [
        'America/Los_Angeles' => 'Pacific', 'America/Denver' => 'Mountain', 'America/Phoenix' => 'Arizona',
        'America/Chicago' => 'Central', 'America/New_York' => 'Eastern', 'America/Anchorage' => 'Alaska', 'Pacific/Honolulu' => 'Hawaii',
    ];

    public string $timezone = 'America/Los_Angeles';
    /** ['mon' => ['on' => true, 'from' => '09:00', 'to' => '16:00'], ...] — one range per day in v1 */
    public array  $hours = [];
    public int    $minNoticeHours = 24;
    public int    $bufferMinutes  = 15;
    public int    $maxPerDay      = 4;
    public int    $windowWeeks    = 3;
    /** [['from' => 'Y-m-d', 'to' => 'Y-m-d', 'label' => ''], ...] */
    public array  $blocked = [];

    public string $newFrom = '';
    public string $newTo   = '';
    public string $newLabel = '';

    public function mount(): void
    {
        $r = PlatformBookingSetting::rules();
        $this->timezone       = $r['timezone'];
        $this->minNoticeHours = $r['min_notice_hours'];
        $this->bufferMinutes  = $r['buffer_minutes'];
        $this->maxPerDay      = $r['max_per_day'];
        $this->windowWeeks    = $r['window_weeks'];
        foreach (self::DAYS as $k => $_) {
            $ranges = $r['hours'][$k] ?? [];
            $this->hours[$k] = [
                'on'   => ! empty($ranges),
                'from' => $ranges[0][0] ?? '09:00',
                'to'   => $ranges[0][1] ?? '16:00',
            ];
        }
        $this->blocked = array_values($r['blocked_dates']);
    }

    public function save(): void
    {
        $this->validate([
            'timezone'       => 'required|string|in:' . implode(',', array_keys(self::TIMEZONES)),
            'minNoticeHours' => 'required|integer|min:0|max:720',
            'bufferMinutes'  => 'required|integer|min:0|max:180',
            'maxPerDay'      => 'required|integer|min:0|max:50',
            'windowWeeks'    => 'required|integer|min:1|max:26',
            'hours.*.from'   => 'required|date_format:H:i',
            'hours.*.to'     => 'required|date_format:H:i',
        ]);

        $hours = [];
        foreach (self::DAYS as $k => $_) {
            $d = $this->hours[$k];
            if (! empty($d['on']) && $d['from'] < $d['to']) {
                $hours[$k] = [[$d['from'], $d['to']]];
            } else {
                $hours[$k] = [];
            }
        }

        PlatformBookingSetting::put('timezone', $this->timezone);
        PlatformBookingSetting::putJson('hours', $hours);
        PlatformBookingSetting::put('min_notice_hours', (string) $this->minNoticeHours);
        PlatformBookingSetting::put('buffer_minutes', (string) $this->bufferMinutes);
        PlatformBookingSetting::put('max_per_day', (string) $this->maxPerDay);
        PlatformBookingSetting::put('window_weeks', (string) $this->windowWeeks);
        PlatformBookingSetting::putJson('blocked_dates', array_values($this->blocked));

        Notification::make()->title('Availability saved')->success()->send();
    }

    public function addBlocked(): void
    {
        $this->validate(['newFrom' => 'required|date', 'newTo' => 'nullable|date|after_or_equal:newFrom', 'newLabel' => 'nullable|string|max:80']);
        $this->blocked[] = ['from' => $this->newFrom, 'to' => $this->newTo ?: $this->newFrom, 'label' => trim($this->newLabel)];
        usort($this->blocked, fn ($a, $b) => strcmp($a['from'], $b['from']));
        $this->reset(['newFrom', 'newTo', 'newLabel']);
        PlatformBookingSetting::putJson('blocked_dates', array_values($this->blocked));
        Notification::make()->title('Dates blocked')->success()->send();
    }

    public function removeBlocked(int $i): void
    {
        unset($this->blocked[$i]);
        $this->blocked = array_values($this->blocked);
        PlatformBookingSetting::putJson('blocked_dates', $this->blocked);
    }
}
