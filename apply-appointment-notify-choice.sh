#!/bin/bash
# appointment-notify-choice — staff appointments stop emailing on their own.
#
#   AppointmentController::store() calls BookingService::createAppointment(),
#   which unconditionally dispatches SendBookingConfirmationJob. That service
#   is the PUBLIC self-serve booking path, reused for staff-created
#   appointments — so booking a customer in from the admin emails and texts
#   them exactly as if they had booked themselves, with no way to decline.
#
#   The service had no way to know who was creating: same method, two very
#   different situations. A customer booking themselves expects an immediate
#   confirmation. A shop booking someone in at the counter, or blocking out a
#   slot, usually does not want one — and definitely doesn't want it sent
#   before anyone has checked the details.
#
#   createAppointment() now takes $notify, defaulting to TRUE so the public
#   path and every other caller behave exactly as before. Only the staff
#   route passes false.
#
#   Sending stays possible, deliberately, via a new endpoint that dispatches
#   the same job with a channel choice — email, sms, or both. The job already
#   does both channels, each gated by tenant settings, so this reuses that
#   rather than inventing a second way to send.
#
#   This patch is the decision plumbing. The post-save modal offering
#   Text / Email / Both / Don't notify is the next piece and calls this
#   endpoint.
# NO MIGRATION. Server: optimize:clear
set -e
if grep -q "MARKER-NOTIFY-CHOICE" app/Services/BookingService.php; then
  echo "appointment-notify-choice already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ service
python3 - <<'ANC_0_EOF'
import io
p = 'app/Services/BookingService.php'
s = io.open(p, encoding='utf-8').read()

old = """    public function createAppointment(array $data, string $tenantId): TenantAppointment"""
assert s.count(old) == 1, ('signature', s.count(old))
new = """    /**
     * MARKER-NOTIFY-CHOICE — $notify.
     *
     * This method serves two situations that look identical in code and are
     * nothing alike in practice: a customer booking themselves, who expects
     * an instant confirmation, and a shop booking someone in at the counter,
     * who usually does not want one fired off before anyone has checked the
     * details. It had no way to tell them apart, so staff-created
     * appointments emailed and texted the customer automatically.
     *
     * Defaults to true, so the public booking path and every existing caller
     * are unchanged. Only the staff route opts out.
     */
    public function createAppointment(array $data, string $tenantId, bool $notify = true): TenantAppointment"""
s = s.replace(old, new)

old = """                SendBookingConfirmationJob::dispatch($appointment->id)->afterCommit();"""
assert s.count(old) == 1, ('dispatch', s.count(old))
new = """                // MARKER-NOTIFY-CHOICE — only when the caller asked for it.
                if ($notify) {
                    SendBookingConfirmationJob::dispatch($appointment->id)->afterCommit();
                }"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('service ok')
ANC_0_EOF

# ------------------------------------------------------------------ job
python3 - <<'ANC_1_EOF'
import io
p = 'app/Jobs/SendBookingConfirmationJob.php'
s = io.open(p, encoding='utf-8').read()

old = """    public function handle(): void"""
assert s.count(old) == 1, ('handle', s.count(old))
new = """    /**
     * MARKER-NOTIFY-CHOICE — which channels this dispatch may use.
     *
     * null keeps the original behaviour: both channels, each still gated by
     * the tenant's own notification settings. A staff member choosing "text
     * only" passes ['sms'], and the tenant setting can still veto it — this
     * narrows what may be sent, it never overrides a shop's own switch.
     *
     * @var array<int,string>|null
     */
    public ?array $onlyChannels = null;

    public function forChannels(?array $channels): self
    {
        $this->onlyChannels = $channels;
        return $this;
    }

    private function channelAllowed(string $channel): bool
    {
        return $this->onlyChannels === null || in_array($channel, $this->onlyChannels, true);
    }

    public function handle(): void"""
s = s.replace(old, new)

old = """        if ($smsEnabled && $customer->phone) {"""
assert s.count(old) == 1, ('sms gate', s.count(old))
new = """        if ($smsEnabled && $customer->phone && $this->channelAllowed('sms')) {"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('job ok')
ANC_1_EOF

# gate the email channel too — find its condition
python3 - <<'ANC_2_EOF'
import io, re
p = 'app/Jobs/SendBookingConfirmationJob.php'
s = io.open(p, encoding='utf-8').read()

m = re.search(r"( *)if \(\$emailEnabled([^)]*)\) \{", s)
assert m, 'email gate not found'
old = m.group(0)
new = m.group(1) + "if ($emailEnabled" + m.group(2) + " && $this->channelAllowed('email')) {"
s = s.replace(old, new, 1)

io.open(p, 'w', encoding='utf-8').write(s)
print('email gate ok:', old.strip()[:60])
ANC_2_EOF

# ------------------------------------------------------------------ controller
python3 - <<'ANC_3_EOF'
import io
p = 'app/Http/Controllers/Tenant/AppointmentController.php'
s = io.open(p, encoding='utf-8').read()

old = """            $appointment = app(\\App\\Services\\BookingService::class)
                ->createAppointment($payload, $tenant->id);"""
assert s.count(old) == 1, ('create call', s.count(old))
new = """            // MARKER-NOTIFY-CHOICE — a staff-created appointment notifies nobody
            // by default. Booking a customer in at the counter shouldn't fire
            // a confirmation before anyone has checked the details; sending is
            // an explicit choice via appointments.notify.
            $appointment = app(\\App\\Services\\BookingService::class)
                ->createAppointment($payload, $tenant->id, false);"""
s = s.replace(old, new)

# the notify action
old = """    public function store(Request $request)"""
assert s.count(old) == 1, ('store', s.count(old))
new = """    /**
     * MARKER-NOTIFY-CHOICE — send the confirmation on purpose.
     *
     * Dispatches the SAME job the public booking path uses, narrowed to the
     * chosen channels, so there is one way to send a confirmation rather than
     * two that can drift. Tenant notification settings still apply: this
     * narrows what may go out, it never overrides a shop's own switch.
     */
    public function notify(Request $request, string $id)
    {
        $tenant = tenant();

        $data = $request->validate([
            'channels'   => ['required', 'array', 'min:1'],
            'channels.*' => ['in:email,sms'],
        ]);

        $appointment = \\App\\Models\\Tenant\\TenantAppointment::where('tenant_id', $tenant->id)
            ->findOrFail($id);

        \\App\\Jobs\\SendBookingConfirmationJob::dispatch($appointment->id)
            ->forChannels($data['channels']);

        $what = count($data['channels']) > 1
            ? 'Text and email'
            : ($data['channels'][0] === 'sms' ? 'Text' : 'Email');

        return response()->json([
            'ok'      => true,
            'message' => $what . ' on its way to the customer.',
        ]);
    }

    public function store(Request $request)"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('controller ok')
ANC_3_EOF

# ------------------------------------------------------------------ route
python3 - <<'ANC_4_EOF'
import io, re
p = 'routes/web.php'
s = io.open(p, encoding='utf-8').read()

m = re.search(r"[ \t]*Route::post\('/appointments'[^\n]*\n", s)
assert m, 'appointments store route not found'
indent = re.match(r'[ \t]*', m.group(0)).group(0)

add = (indent + "// MARKER-NOTIFY-CHOICE — send a confirmation deliberately, after the\n"
     + indent + "// appointment has been saved and looked at.\n"
     + indent + "Route::post('/appointments/{id}/notify', [TenantControllers\\AppointmentController::class, 'notify'])->name('appointments.notify');\n")

s = s[:m.end()] + add + s[m.end():]
io.open(p, 'w', encoding='utf-8').write(s)
print('route ok')
ANC_4_EOF

echo
echo "appointment-notify-choice applied."
