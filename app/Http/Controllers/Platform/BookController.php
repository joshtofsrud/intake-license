<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformBooking;
use App\Models\PlatformBookingSetting;
use App\Models\PlatformBookingType;
use App\Services\Platform\BookingAvailabilityService;
use App\Services\Platform\BookingMailer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// MARKER-SCHED-PUBLIC — public booking on intake.works.
class BookController extends Controller
{
    public function __construct(
        private BookingAvailabilityService $avail,
        private BookingMailer $mailer,
    ) {}

    private function type(string $slug): PlatformBookingType
    {
        $t = PlatformBookingType::where('slug', $slug)->first();
        abort_unless($t && $t->isPublic(), 404);
        return $t;
    }

    public function show(string $slug)
    {
        $type = $this->type($slug);
        return view('marketing.book', [
            'type'   => $type,
            'closed' => ! $type->is_active,
        ]);
    }

    /**
     * GET /book/{slug}/slots?from=Y-m-d&to=Y-m-d  (host-zone dates, ≤45 days)
     * Returns UTC ISO starts; the page buckets them into the visitor's zone.
     */
    public function slots(string $slug, Request $request)
    {
        $type = $this->type($slug);
        if (! $type->is_active) {
            return response()->json(['slots' => [], 'host_tz' => $this->avail->timezone()]);
        }
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        $tz   = $this->avail->timezone();
        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $tz)->startOfDay();
        $to   = CarbonImmutable::createFromFormat('Y-m-d', $data['to'], $tz)->startOfDay();
        if ($from->diffInDays($to) > 45) {
            $to = $from->addDays(45);
        }

        $slots = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            foreach ($this->avail->slotsForDay($type, $d->format('Y-m-d')) as $s) {
                $slots[] = $s->toIso8601String();
            }
        }

        return response()->json(['slots' => $slots, 'host_tz' => $tz, 'length' => $type->length_min]);
    }

    public function store(string $slug, Request $request)
    {
        $type = $this->type($slug);
        if (! $type->is_active) {
            return redirect()->route('book.show', $slug);
        }
        // Honeypot: real people never fill this in.
        if (filled($request->input('company_website'))) {
            return redirect()->route('book.show', $slug);
        }

        $rules = [
            'name'     => 'required|string|max:120',
            'email'    => 'required|email|max:191',
            'phone'    => 'nullable|string|max:64',
            'start'    => 'required|date',
            'timezone' => 'required|string|max:64',
            'answers'  => 'nullable|array',
            'source_url' => 'nullable|string|max:512',
        ];
        if ($type->location_mode === PlatformBookingType::LOCATION_CHOICE) {
            $rules['location'] = 'required|in:meet,phone';
        }
        if (in_array($type->location_mode, [PlatformBookingType::LOCATION_PHONE], true) || $request->input('location') === 'phone') {
            $rules['phone'] = 'required|string|max:64';
        }
        foreach ($type->questionList() as $q) {
            $rules['answers.' . $q['key']] = ($q['required'] ? 'required' : 'nullable') . '|string|max:1000';
        }
        $data = $request->validate($rules, [
            'phone.required' => "We'll need a number to call you on.",
            'answers.*.required' => 'Please fill this in.',
        ]);

        if (! in_array($data['timezone'], timezone_identifiers_list(), true)) {
            $data['timezone'] = $this->avail->timezone();
        }

        $start = CarbonImmutable::parse($data['start'])->utc()->setSecond(0);
        if (! $this->avail->isOfferable($type, $start)) {
            return back()->withInput()->withErrors(['start' => 'That time was just taken — please pick another.']);
        }

        $mode = $type->location_mode === PlatformBookingType::LOCATION_CHOICE
            ? $data['location']
            : $type->location_mode;
        $detail = match ($mode) {
            'phone' => $data['phone'] ?? null,
            'meet'  => $type->meet_url,
            default => null,
        };

        $answers = [];
        foreach ($type->questionList() as $q) {
            $answers[$q['key']] = trim((string) ($data['answers'][$q['key']] ?? ''));
        }

        $booking = DB::transaction(function () use ($type, $data, $start, $mode, $detail, $answers, $request) {
            $b = PlatformBooking::create([
                'booking_type_id' => $type->id,
                'name'            => trim($data['name']),
                'email'           => trim($data['email']),
                'phone'           => trim((string) ($data['phone'] ?? '')) ?: null,
                'company'         => trim((string) ($answers['company'] ?? '')) ?: null,
                'answers'         => $answers,
                'starts_at'       => $start,
                'ends_at'         => $start->addMinutes($type->length_min),
                'timezone'        => $data['timezone'],
                'location_mode'   => $mode,
                'location_detail' => $detail,
                'status'          => PlatformBooking::STATUS_CONFIRMED,
                'source_kind'     => PlatformBooking::SOURCE_PUBLIC,
                'source_url'      => substr((string) ($data['source_url'] ?? $request->headers->get('referer') ?? ''), 0, 512) ?: null,
            ]);
            // Double-booking guard: if two people grabbed the same slot in the
            // same second, the second insert loses.
            $clash = PlatformBooking::active()
                ->where('id', '<', $b->id)
                ->between($b->starts_at, $b->ends_at)
                ->exists();
            if ($clash) {
                $b->delete();
                return null;
            }
            $b->logEvent('created', 'booker', ['ip' => $request->ip()]);
            return $b;
        });

        if (! $booking) {
            return back()->withInput()->withErrors(['start' => 'That time was just taken — please pick another.']);
        }

        $this->mailer->confirmation($booking);
        $this->mailer->notifyAdmin($booking, 'created');
        Log::info('MARKER-SCHED-PUBLIC booked', ['id' => $booking->id, 'type' => $type->slug]);

        return redirect()->route('book.manage', ['token' => $booking->token, 'new' => 1]);
    }

    // ---- manage ----------------------------------------------------

    private function byToken(string $token): PlatformBooking
    {
        return PlatformBooking::with('type')->where('token', $token)->firstOrFail();
    }

    public function manage(string $token, Request $request)
    {
        $b = $this->byToken($token);
        return view('marketing.book-manage', [
            'booking' => $b,
            'isNew'   => (bool) $request->query('new'),
            'canMove' => $b->isActive() && $b->starts_at->isFuture(),
        ]);
    }

    public function cancel(string $token, Request $request)
    {
        $b = $this->byToken($token);
        if ($b->isActive() && $b->starts_at->isFuture()) {
            $b->cancel('booker', null);
            $this->mailer->cancelled($b, 'booker');
            $this->mailer->notifyAdmin($b, 'cancelled');
        }
        return redirect()->route('book.manage', $token);
    }

    public function rescheduleForm(string $token)
    {
        $b = $this->byToken($token);
        abort_unless($b->type && $b->isActive() && $b->starts_at->isFuture(), 404);
        return view('marketing.book', [
            'type'    => $b->type,
            'closed'  => false,
            'booking' => $b,
        ]);
    }

    public function reschedule(string $token, Request $request)
    {
        $b = $this->byToken($token);
        abort_unless($b->type && $b->isActive() && $b->starts_at->isFuture(), 404);
        $data  = $request->validate(['start' => 'required|date', 'timezone' => 'nullable|string|max:64']);
        $start = CarbonImmutable::parse($data['start'])->utc()->setSecond(0);
        if (! $this->avail->isOfferable($b->type, $start)) {
            return back()->withInput()->withErrors(['start' => 'That time was just taken — please pick another.']);
        }
        if (! empty($data['timezone']) && in_array($data['timezone'], timezone_identifiers_list(), true)) {
            $b->forceFill(['timezone' => $data['timezone']])->save();
        }
        $b->reschedule($start, 'booker');
        $this->mailer->rescheduled($b, 'booker');
        $this->mailer->notifyAdmin($b, 'rescheduled');
        return redirect()->route('book.manage', ['token' => $token, 'moved' => 1]);
    }

    public function ics(string $token)
    {
        $b = $this->byToken($token);
        return response($this->mailer->ics($b), 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="intake-call.ics"',
        ]);
    }
}
