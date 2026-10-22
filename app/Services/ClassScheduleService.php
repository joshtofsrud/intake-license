<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantClassRegistration;
use App\Models\Tenant\TenantClassSchedule;
use App\Models\Tenant\TenantClassSession;
use App\Models\Tenant\TenantClassTemplate;
use App\Models\Tenant\TenantResource;
use App\Support\MySQLLock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Repeating classes. A schedule's classes are created as far ahead as the
 * studio's window (Booking rules › "Repeating classes are created … weeks
 * ahead"); a daily run keeps the window full.
 *
 * Upcoming bookings never disappear when a schedule changes: edits move
 * booked classes (and email the people booked when the time changes), and
 * stopping a schedule only removes classes nobody is booked into.
 *
 * Dates are the studio's own calendar days and times its wall clock.
 * MARKER-CLASSES-P7
 */
class ClassScheduleService
{
    private const OPEN = ['scheduled', 'confirmed'];

    public function create(Tenant $tenant, TenantClassTemplate $tpl, array $days, string $time, string $startsOn,
                           ?string $endsOn = null, ?int $capacity = null, ?string $notes = null): TenantClassSchedule
    {
        $days = array_values(array_unique(array_map('intval', array_filter($days, fn ($d) => $d !== '' && $d !== null))));
        if (! $days) {
            throw new RuntimeException('Pick at least one day.');
        }
        $s = TenantClassSchedule::create([
            'tenant_id'              => $tenant->id,
            'class_template_id'      => $tpl->id,
            'days'                   => $days,
            'start_time'             => $time,
            'capacity'               => $capacity,
            'instructor_resource_id' => $tpl->instructor_resource_id,
            'notes'                  => $notes,
            'starts_on'              => $startsOn,
            'ends_on'                => $endsOn,
        ]);
        $this->extend($s);
        return $s;
    }

    /** Create the schedule's missing classes up to the window. Returns how many were made. */
    public function extend(TenantClassSchedule $s): int
    {
        $tenant = Tenant::find($s->tenant_id);
        $tpl    = TenantClassTemplate::find($s->class_template_id);
        if (! $tenant || ! $tpl) {
            return 0;
        }
        $tz    = $tenant->timezone();
        $today = Carbon::now($tz)->startOfDay();
        $from  = Carbon::parse($s->starts_on->format('Y-m-d'), $tz)->max($today);
        $to    = $today->copy()->addWeeks(ClassRules::for($tenant)->get('publish_weeks'));
        if ($s->ends_on) {
            $to = $to->min(Carbon::parse($s->ends_on->format('Y-m-d'), $tz));
        }
        if ($from->gt($to)) {
            return 0;
        }

        return app(MySQLLock::class)->withLock("intake:{$s->tenant_id}:schedule:{$s->id}", function () use ($s, $tenant, $tpl, $tz, $from, $to) {
            // Every date this schedule already has (deleted classes too, so a
            // class removed by hand is never put back).
            $have = TenantClassSession::withTrashed()->where('schedule_id', $s->id)->pluck('starts_at')
                ->map(fn ($t) => $t->copy()->setTimezone($tz)->toDateString())->flip();
            $skip = collect($s->skip_dates ?? [])->flip();
            $days = array_map('intval', $s->days ?? []);
            $resourceId = $s->instructor_resource_id;
            $instructor = $resourceId ? TenantResource::where('tenant_id', $s->tenant_id)->find($resourceId)?->name : null;

            $made = 0;
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $ymd = $d->toDateString();
                if (! in_array($d->dayOfWeek, $days, true) || isset($have[$ymd]) || isset($skip[$ymd])) {
                    continue;
                }
                $start = Carbon::parse($ymd . ' ' . $s->start_time, $tz)->utc();
                if ($start->isPast()) {
                    continue;
                }
                TenantClassSession::create([
                    'tenant_id'              => $s->tenant_id,
                    'class_template_id'      => $tpl->id,
                    'schedule_id'            => $s->id,
                    'starts_at'              => $start,
                    'ends_at'                => $start->copy()->addMinutes($tpl->duration_minutes),
                    'instructor_resource_id' => $resourceId,
                    'instructor_snapshot'    => $instructor,
                    'capacity_snapshot'      => $s->capacity ?? $tpl->default_capacity,
                    'status'                 => 'confirmed',
                    'notes'                  => $s->notes,
                ]);
                $made++;
            }
            return $made;
        });
    }

    /** Keep every open schedule's window full (daily). */
    public function extendAll(): int
    {
        $made = 0;
        TenantClassSchedule::where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', now()->subDay()->toDateString()))
            ->chunkById(200, function ($list) use (&$made) {
                foreach ($list as $s) {
                    $made += $this->extend($s);
                }
            });
        return $made;
    }

    /**
     * Change one class: date, time, instructor, capacity. Moving a class off
     * its schedule's date keeps the schedule from recreating it there.
     *
     * @return array{emailed:int}
     */
    public function editOne(TenantClassSession $session, array $c): array
    {
        $tenant = Tenant::find($session->tenant_id);
        $tz     = $tenant->timezone();
        $this->checkCapacity([$session], $c['capacity'] ?? null);

        $oldLocal = $session->starts_at->copy()->setTimezone($tz);
        $date     = $c['date'] ?? $oldLocal->toDateString();
        $time     = $c['time'] ?? $oldLocal->format('H:i');
        $start    = Carbon::parse($date . ' ' . $time, $tz)->utc();
        if (! $start->equalTo($session->starts_at) && $start->isPast()) {
            throw new RuntimeException('Pick a time that has not passed.');
        }

        return DB::transaction(function () use ($session, $c, $tz, $oldLocal, $date, $start) {
            if ($session->schedule_id && $date !== $oldLocal->toDateString()) {
                $s = TenantClassSchedule::find($session->schedule_id);
                if ($s) {
                    $s->update(['skip_dates' => array_values(array_unique(array_merge($s->skip_dates ?? [], [$oldLocal->toDateString()])))]);
                }
            }
            return ['emailed' => $this->apply($session, $start, $c)];
        });
    }

    /**
     * Change this class and every later class on its schedule. The schedule
     * splits here, so classes before this one keep their old details.
     *
     * @return array{updated:int, emailed:int}
     */
    public function editFrom(TenantClassSession $session, array $c): array
    {
        $old = TenantClassSchedule::findOrFail($session->schedule_id);
        $tenant = Tenant::find($session->tenant_id);
        $tz     = $tenant->timezone();
        $fromDay = $session->starts_at->copy()->setTimezone($tz)->toDateString();

        $affected = TenantClassSession::where('schedule_id', $old->id)->whereIn('status', self::OPEN)
            ->where('starts_at', '>=', Carbon::parse($fromDay, $tz)->utc())->orderBy('starts_at')->get();
        $this->checkCapacity($affected->all(), $c['capacity'] ?? null);

        $time = $c['time'] ?? $old->start_time;
        $res  = DB::transaction(function () use ($old, $session, $c, $tz, $fromDay, $affected, $time) {
            $values = ['start_time' => $time];
            if (! empty($c['capacity'])) {
                $values['capacity'] = (int) $c['capacity'];
            }
            if (array_key_exists('instructor_resource_id', $c)) {
                $values['instructor_resource_id'] = $c['instructor_resource_id'];
            }

            if ($fromDay <= $old->starts_on->format('Y-m-d')) {
                $old->update($values);
                $target = $old;
            } else {
                $target = $old->replicate(['id']);
                $target->fill($values + [
                    'starts_on'  => $fromDay,
                    'skip_dates' => array_values(array_filter($old->skip_dates ?? [], fn ($d) => $d >= $fromDay)),
                ]);
                $target->save();
                $old->update([
                    'ends_on'    => Carbon::parse($fromDay, $tz)->subDay()->toDateString(),
                    'skip_dates' => array_values(array_filter($old->skip_dates ?? [], fn ($d) => $d < $fromDay)),
                ]);
            }

            $emailed = 0;
            foreach ($affected as $x) {
                $day   = $x->starts_at->copy()->setTimezone($tz)->toDateString();
                $start = Carbon::parse($day . ' ' . $time, $tz)->utc();
                if ($start->isPast()) {
                    $start = $x->starts_at; // a class already under way keeps its time
                }
                $x->schedule_id = $target->id;
                $emailed += $this->apply($x, $start, $c);
            }
            return ['updated' => $affected->count(), 'emailed' => $emailed, 'schedule' => $target];
        });

        $this->extend($res['schedule']);
        unset($res['schedule']);
        return $res;
    }

    /**
     * Stop a schedule after a given day. Later classes nobody is booked into
     * are removed; classes with bookings stay for staff to move or cancel.
     *
     * @return array{removed:int, kept:int}
     */
    public function stopAfter(TenantClassSchedule $s, string $lastDay): array
    {
        $tenant = Tenant::find($s->tenant_id);
        $tz     = $tenant->timezone();
        return DB::transaction(function () use ($s, $lastDay, $tz) {
            $s->update(['ends_on' => $lastDay < $s->starts_on->format('Y-m-d') ? $s->starts_on->format('Y-m-d') : $lastDay]);
            $after = TenantClassSession::where('schedule_id', $s->id)->whereIn('status', self::OPEN)
                ->where('starts_at', '>=', Carbon::parse($lastDay, $tz)->addDay()->utc())->get();
            $removed = 0; $kept = 0;
            foreach ($after as $x) {
                $booked = TenantClassRegistration::where('class_session_id', $x->id)
                    ->whereIn('status', ['registered', 'checked_in', 'waitlisted'])->exists();
                if ($booked) {
                    $kept++;
                    continue;
                }
                $x->delete();
                $removed++;
            }
            return ['removed' => $removed, 'kept' => $kept];
        });
    }

    // ------------------------------------------------------------------

    /** Write the changes to one class; returns how many people were emailed a new time. */
    private function apply(TenantClassSession $x, Carbon $start, array $c): int
    {
        $tpl     = TenantClassTemplate::find($x->class_template_id);
        $oldAt   = $x->starts_at->copy();
        $x->starts_at = $start;
        $x->ends_at   = $start->copy()->addMinutes($tpl?->duration_minutes ?? 60);
        if (! empty($c['capacity'])) {
            $x->capacity_snapshot = (int) $c['capacity'];
        }
        if (array_key_exists('instructor_resource_id', $c)) {
            $x->instructor_resource_id = $c['instructor_resource_id'];
            $x->instructor_snapshot    = $c['instructor_resource_id']
                ? TenantResource::where('tenant_id', $x->tenant_id)->find($c['instructor_resource_id'])?->name
                : null;
        }
        $x->save();

        if ($oldAt->equalTo($start)) {
            return 0;
        }
        $n = 0;
        $regs = TenantClassRegistration::where('class_session_id', $x->id)
            ->whereIn('status', ['registered', 'waitlisted'])->get();
        foreach ($regs as $r) {
            ClassMessages::changed($r, $oldAt);
            $n++;
        }
        return $n;
    }

    private function checkCapacity(array $sessions, ?int $cap): void
    {
        if (! $cap) {
            return;
        }
        $over = collect($sessions)->filter(fn ($x) => $x->activeRegistrations()->count() > $cap)->count();
        if ($over) {
            throw new RuntimeException($over === 1
                ? "That class already has more than {$cap} booked. Pick a higher capacity."
                : "{$over} of those classes already have more than {$cap} booked. Pick a higher capacity.");
        }
    }
}
