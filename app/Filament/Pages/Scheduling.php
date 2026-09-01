<?php

namespace App\Filament\Pages;

use App\Models\PlatformBooking;
use App\Models\PlatformBookingSetting;
use App\Models\PlatformBookingType;
use App\Support\AdminAccess;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

// MARKER-SCHED-ADMIN — week calendar + upcoming list + booking actions.
class Scheduling extends Page
{
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'scheduling';

    protected static ?string $navigationIcon  = 'heroicon-o-calendar-days';
    protected static ?string $navigationLabel = 'Calendar';
    protected static ?string $navigationGroup = 'Scheduling';
    protected static ?string $title           = 'Calendar';
    protected static ?int    $navigationSort  = 60;
    protected static ?string $slug            = 'scheduling';

    protected static string $view = 'filament.pages.scheduling';

    public string $weekStart = '';     // Y-m-d, Monday, host tz
    public string $filterType = 'all'; // 'all' | type id
    public string $listRange  = 'upcoming'; // upcoming | past | cancelled

    // selected booking
    public ?int   $selected = null;
    public string $notes    = '';

    // cancel modal
    public string $cancelMessage = '';

    // reschedule modal
    public string $rsDate = '';
    public string $rsTime = '';

    // new appointment modal
    public string $nType    = '';
    public string $nName    = '';
    public string $nEmail   = '';
    public string $nPhone   = '';
    public string $nCompany = '';
    public string $nDate    = '';
    public string $nTime    = '09:00';
    public int    $nLength  = 30;
    public string $nMode    = 'meet';
    public string $nDetail  = '';
    public string $nMessage = '';
    public string $nNotes   = '';

    public function mount(): void
    {
        $tz = PlatformBookingSetting::get('timezone');
        $this->weekStart = CarbonImmutable::now($tz)->startOfWeek()->format('Y-m-d');
        $this->nDate     = CarbonImmutable::now($tz)->addDay()->format('Y-m-d');
        $first = PlatformBookingType::activeOrdered()->first();
        if ($first) {
            $this->nType   = (string) $first->id;
            $this->nLength = $first->length_min;
            $this->nMode   = $first->location_mode === 'choice' ? 'meet' : $first->location_mode;
        }
    }

    // ---- navigation --------------------------------------------------

    public function prevWeek(): void { $this->weekStart = CarbonImmutable::parse($this->weekStart)->subWeek()->format('Y-m-d'); }
    public function nextWeek(): void { $this->weekStart = CarbonImmutable::parse($this->weekStart)->addWeek()->format('Y-m-d'); }
    public function thisWeek(): void { $this->weekStart = CarbonImmutable::now(PlatformBookingSetting::get('timezone'))->startOfWeek()->format('Y-m-d'); }

    public function updatedNType(string $v): void
    {
        if ($t = PlatformBookingType::find((int) $v)) {
            $this->nLength = $t->length_min;
            $this->nMode   = $t->location_mode === 'choice' ? 'meet' : $t->location_mode;
        }
    }

    // ---- selection ---------------------------------------------------

    public function select(int $id): void
    {
        $b = $this->visibleQuery()->find($id);
        if (! $b) {
            return;
        }
        $this->selected      = $b->id;
        $this->notes         = (string) $b->notes_internal;
        $this->cancelMessage = '';
        $this->rsDate        = $b->startsLocal()->format('Y-m-d');
        $this->rsTime        = $b->startsLocal()->format('H:i');
        $this->dispatch('open-modal', id: 'booking-detail');
    }

    private function current(): ?PlatformBooking
    {
        return $this->selected ? $this->visibleQuery()->find($this->selected) : null;
    }

    public function saveNotes(): void
    {
        if ($b = $this->current()) {
            $b->forceFill(['notes_internal' => trim($this->notes) ?: null])->save();
            Notification::make()->title('Notes saved')->success()->send();
        }
    }

    public function markCompleted(): void
    {
        if ($b = $this->current()) {
            $b->markCompleted();
            Notification::make()->title('Marked as completed')->success()->send();
            $this->dispatch('close-modal', id: 'booking-detail');
        }
    }

    public function markNoShow(): void
    {
        if ($b = $this->current()) {
            $b->markNoShow();
            Notification::make()->title('Marked as no-show')->success()->send();
            $this->dispatch('close-modal', id: 'booking-detail');
        }
    }

    public function cancelBooking(): void
    {
        if ($b = $this->current()) {
            $b->cancel('admin', trim($this->cancelMessage) ?: null);
            Notification::make()->title('Call cancelled')->body('The slot is open again.')->success()->send();
            $this->dispatch('close-modal', id: 'booking-cancel');
            $this->dispatch('close-modal', id: 'booking-detail');
        }
    }

    public function rescheduleBooking(): void
    {
        $b = $this->current();
        if (! $b) {
            return;
        }
        $this->validate(['rsDate' => 'required|date', 'rsTime' => 'required|date_format:H:i']);
        $tz    = PlatformBookingSetting::get('timezone');
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->rsDate . ' ' . $this->rsTime, $tz)->utc();
        $b->reschedule($start, 'admin');
        Notification::make()->title('Moved to ' . $start->setTimezone($tz)->format('D M j, g:i a'))->success()->send();
        $this->dispatch('close-modal', id: 'booking-reschedule');
        $this->dispatch('close-modal', id: 'booking-detail');
    }

    // ---- manual appointment ----------------------------------------

    public function createManual(): void
    {
        $this->validate([
            'nType'   => 'required|integer|exists:platform_booking_types,id',
            'nName'   => 'required|string|max:120',
            'nEmail'  => 'nullable|email|max:191',
            'nPhone'  => 'nullable|string|max:64',
            'nCompany'=> 'nullable|string|max:191',
            'nDate'   => 'required|date',
            'nTime'   => 'required|date_format:H:i',
            'nLength' => 'required|integer|min:5|max:480',
            'nMode'   => 'required|in:meet,phone,in_person',
            'nDetail' => 'nullable|string|max:255',
        ]);

        $tz    = PlatformBookingSetting::get('timezone');
        $type  = PlatformBookingType::find((int) $this->nType);
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->nDate . ' ' . $this->nTime, $tz)->utc();

        $detail = trim($this->nDetail);
        if ($detail === '' && $this->nMode === 'meet' && $type?->meet_url) {
            $detail = $type->meet_url;
        }

        $b = PlatformBooking::create([
            'booking_type_id' => $type?->id,
            'name'            => trim($this->nName),
            'email'           => trim($this->nEmail) ?: null,
            'phone'           => trim($this->nPhone) ?: null,
            'company'         => trim($this->nCompany) ?: null,
            'starts_at'       => $start,
            'ends_at'         => $start->addMinutes($this->nLength),
            'timezone'        => $tz,
            'location_mode'   => $this->nMode,
            'location_detail' => $detail ?: null,
            'status'          => PlatformBooking::STATUS_CONFIRMED,
            'source_kind'     => PlatformBooking::SOURCE_MANUAL,
            'source_url'      => null,
            'created_by'      => Auth::guard('web')->id(),
            'message_to_them' => trim($this->nMessage) ?: null,
            'notes_internal'  => trim($this->nNotes) ?: null,
        ]);
        $b->logEvent('created', 'admin', ['manual' => true]);

        Notification::make()->title('Appointment saved')->success()->send();
        $this->dispatch('close-modal', id: 'booking-new');
        $this->weekStart = $start->setTimezone($tz)->startOfWeek()->format('Y-m-d');
        $this->reset(['nName', 'nEmail', 'nPhone', 'nCompany', 'nDetail', 'nMessage', 'nNotes']);
    }

    // ---- data --------------------------------------------------------

    /** Investor calls are only visible to roles that hold the raise area. */
    private function visibleQuery()
    {
        $q = PlatformBooking::query()->with('type');
        if (! AdminAccess::allows(Auth::guard('web')->user(), 'raise')) {
            $q->whereDoesntHave('type', fn ($t) => $t->where('slug', 'investor'));
        }
        if ($this->filterType !== 'all') {
            $q->where('booking_type_id', (int) $this->filterType);
        }
        return $q;
    }

    protected function getViewData(): array
    {
        $tz    = PlatformBookingSetting::get('timezone');
        $start = CarbonImmutable::createFromFormat('Y-m-d', $this->weekStart, $tz)->startOfDay();
        $end   = $start->addDays(7);
        $now   = CarbonImmutable::now($tz);

        $week = $this->visibleQuery()
            ->between($start->utc(), $end->utc())
            ->whereIn('status', [PlatformBooking::STATUS_CONFIRMED, PlatformBooking::STATUS_RESCHEDULED, PlatformBooking::STATUS_COMPLETED, PlatformBooking::STATUS_NO_SHOW])
            ->orderBy('starts_at')->get();

        // Grid bounds: the configured hours, stretched to fit anything outside them.
        $hours = PlatformBookingSetting::getJson('hours', []);
        $gridStart = 24; $gridEnd = 0;
        foreach ($hours as $ranges) {
            foreach ($ranges as [$f, $t]) {
                $gridStart = min($gridStart, (int) substr($f, 0, 2));
                $gridEnd   = max($gridEnd, (int) ceil((int) substr($t, 0, 2) + ((int) substr($t, 3, 2) > 0 ? 1 : 0)));
            }
        }
        foreach ($week as $b) {
            $gridStart = min($gridStart, (int) $b->startsLocal()->format('G'));
            $gridEnd   = max($gridEnd, (int) $b->endsLocal()->format('G') + 1);
        }
        if ($gridStart >= $gridEnd) { $gridStart = 8; $gridEnd = 18; }
        $gridStart = max(0, $gridStart - 1);
        $gridEnd   = min(24, $gridEnd);

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $start->addDays($i);
            $events = $week->filter(fn ($b) => $b->startsLocal()->isSameDay($d))->map(function ($b) use ($gridStart) {
                $s = $b->startsLocal(); $e = $b->endsLocal();
                $top = ((int) $s->format('G') - $gridStart) * 60 + (int) $s->format('i');
                $len = max(20, (int) abs($s->diffInMinutes($e)));
                return ['b' => $b, 'top' => $top, 'height' => $len];
            })->values();
            $days[] = ['date' => $d, 'isToday' => $d->isSameDay($now), 'events' => $events, 'count' => $events->count()];
        }

        $list = $this->visibleQuery();
        $list = match ($this->listRange) {
            'past'      => $list->where('starts_at', '<', now())->whereIn('status', [PlatformBooking::STATUS_COMPLETED, PlatformBooking::STATUS_NO_SHOW, PlatformBooking::STATUS_CONFIRMED, PlatformBooking::STATUS_RESCHEDULED])->orderByDesc('starts_at')->limit(30),
            'cancelled' => $list->where('status', PlatformBooking::STATUS_CANCELLED)->orderByDesc('starts_at')->limit(30),
            default     => $list->upcoming()->where('starts_at', '<', now()->addDays(14))->limit(40),
        };

        return [
            'tz'          => $tz,
            'now'         => $now,
            'days'        => $days,
            'gridStart'   => $gridStart,
            'gridEnd'     => $gridEnd,
            'weekLabel'   => $start->format('D M j') . ' – ' . $start->addDays(6)->format('D M j, Y'),
            'upcoming'    => $list->get(),
            'types'       => PlatformBookingType::orderBy('sort_order')->get(),
            'booking'     => $this->current(),
            'nowTop'      => ($now->gte($start) && $now->lt($end)) ? (((int) $now->format('G') - $gridStart) * 60 + (int) $now->format('i')) : null,
            'nowDayIndex' => (int) $now->diffInDays($start, true) < 7 && $now->gte($start) ? (int) $start->diffInDays($now) : null,
        ];
    }
}
