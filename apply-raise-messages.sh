#!/usr/bin/env bash
set -euo pipefail

# apply-raise-messages.sh — MARKER-RAISE-MESSAGES  (patch 2 of 3)
# The eight templated messages, automatic on their triggers, previewable and sendable by hand.
# Requires MARKER-RAISE-RECORDS.

echo "==> checking repo root"
test -f artisan || { echo "run this from the intake-license repo root"; exit 1; }

grep -q "MARKER-RAISE-RECORDS" app/Filament/Pages/Raise.php || { echo "apply-raise-records.sh must be applied first"; exit 1; }

if grep -q "MARKER-RAISE-MESSAGES" app/Filament/Pages/Raise.php; then
  echo "MARKER-RAISE-MESSAGES already present — nothing to do."
  exit 0
fi

mkdir -p config app/Services

echo "==> message templates"
cat > config/investor_messages.php <<'CFGEOF'
<?php

// MARKER-RAISE-MESSAGES
// The eight messages. Placeholders are replaced at send time:
//   {name} {amount} {percent} {cap} {portal} {bank} {account} {routing} {reference} {sender}
return [

    'invitation' => [
        'label'   => 'Invitation',
        'mode'    => 'manual',
        'trigger' => 'Sent by hand when you want someone to see the round',
        'subject' => 'Intake — investment opportunity',
        'body'    => "Hi {name},\n\nI'm raising a small round for Intake, the shop software I've been building. Everything is on one page, including the parts that could go wrong:\n\n{portal}\n\nThe link is personal to you. Happy to talk it through whenever suits.\n\n{sender}",
    ],

    'list_welcome' => [
        'label'   => 'Mailing-list welcome',
        'mode'    => 'automatic',
        'trigger' => 'Someone leaves their details on the shared invitation page',
        'subject' => 'Thanks for the interest in Intake',
        'body'    => "Hi {name},\n\nThanks for leaving your details. I'll be in touch directly — if you have questions before then, just reply to this message.\n\n{sender}",
    ],

    'commitment' => [
        'label'   => 'Commitment received',
        'mode'    => 'automatic',
        'trigger' => 'A commitment amount is recorded against the investor',
        'subject' => 'Your commitment to Intake',
        'body'    => "Hi {name},\n\nNoting your commitment of {amount}, which is {percent} at the {cap} post-money cap.\n\nNothing is owed yet. Paperwork comes next, then wire details.\n\nYour page: {portal}\n\n{sender}",
    ],

    'document_ready' => [
        'label'   => 'Document ready to sign',
        'mode'    => 'automatic',
        'trigger' => 'A document is uploaded and visible to the investor',
        'subject' => 'Your SAFE is ready to sign',
        'body'    => "Hi {name},\n\nThe paperwork is on your page and ready when you are:\n\n{portal}\n\nRead it properly, and take it to your own advisor if you want a second opinion.\n\n{sender}",
    ],

    'signed' => [
        'label'   => 'Signed, with wire instructions',
        'mode'    => 'automatic',
        'trigger' => 'The document is countersigned',
        'subject' => 'Countersigned — wire details inside',
        'body'    => "Hi {name},\n\nSigned on both sides. Wire details for {amount}:\n\nBank: {bank}\nAccount: {account}\nRouting: {routing}\nReference: {reference}\n\nThese details will never change. If you receive an email saying they have, it did not come from me — call before you act on it.\n\n{sender}",
    ],

    'funded' => [
        'label'   => 'Funds received',
        'mode'    => 'automatic',
        'trigger' => 'Funds are marked received',
        'subject' => 'Received — thank you',
        'body'    => "Hi {name},\n\n{amount} arrived. Thank you for backing this.\n\nYour countersigned copy stays on your page: {portal}\n\nI'll write with progress rather than leaving you guessing.\n\n{sender}",
    ],

    'closed' => [
        'label'   => 'Round closed',
        'mode'    => 'manual',
        'trigger' => 'Sent by hand once the round is done',
        'subject' => 'The round is closed',
        'body'    => "Hi {name},\n\nThe round is closed. Your position and documents stay available:\n\n{portal}\n\nFrom here it is the work: shops onboarded, product shipped. I'll keep you posted.\n\n{sender}",
    ],

    'declined' => [
        'label'   => 'Declined',
        'mode'    => 'manual',
        'trigger' => 'Sent by hand when someone passes',
        'subject' => 'Thanks for looking at Intake',
        'body'    => "Hi {name},\n\nUnderstood, and no hard feelings — thanks for taking the time to look.\n\nIf you want the occasional progress note, say the word.\n\n{sender}",
    ],

];
CFGEOF

echo "==> messenger service"
cat > app/Services/InvestorMessenger.php <<'MSGEOF'
<?php

namespace App\Services;

use App\Models\Investor;
use App\Models\InvestorEvent;
use App\Models\RaiseSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// MARKER-RAISE-MESSAGES
class InvestorMessenger
{
    public static function templates(): array
    {
        return config('investor_messages', []);
    }

    public static function render(string $key, Investor $investor): ?array
    {
        $template = static::templates()[$key] ?? null;

        if (! $template) {
            return null;
        }

        $wire = RaiseSetting::wireInstructions();

        $replacements = [
            '{name}'      => $investor->name,
            '{amount}'    => '$' . number_format((int) $investor->amount),
            '{percent}'   => $investor->percent . '%',
            '{cap}'       => '$' . number_format(Investor::CAP),
            '{portal}'    => $investor->portalUrl(),
            '{bank}'      => $wire['bank']      ?: '[not set]',
            '{account}'   => $wire['account']   ?: '[not set]',
            '{routing}'   => $wire['routing']   ?: '[not set]',
            '{reference}' => $wire['reference'] ?: $investor->name,
            '{sender}'    => RaiseSetting::get('sender_name', 'Josh'),
        ];

        return [
            'label'   => $template['label'],
            'subject' => strtr($template['subject'], $replacements),
            'body'    => strtr($template['body'], $replacements),
        ];
    }

    /** Returns true when the message actually went out. */
    public static function send(string $key, Investor $investor): bool
    {
        if (! $investor->email) {
            InvestorEvent::log($investor->id, 'message_skipped', 'No email on file, skipped: ' . $key);

            return false;
        }

        $message = static::render($key, $investor);

        if (! $message) {
            return false;
        }

        try {
            Mail::raw($message['body'], function ($mail) use ($investor, $message) {
                $mail->to($investor->email, $investor->name)->subject($message['subject']);
            });
        } catch (\Throwable $e) {
            Log::error('MARKER-RAISE-MESSAGES send failed', ['key' => $key, 'error' => $e->getMessage()]);
            InvestorEvent::log($investor->id, 'message_failed', $message['label'] . ' failed to send');

            return false;
        }

        InvestorEvent::log($investor->id, 'message_sent', 'Sent: ' . $message['label']);

        return true;
    }
}
MSGEOF

echo "==> wiring automatic sends"
python3 - <<'PYEOF'
import io
p = "app/Filament/Pages/Raise.php"
src = io.open(p, encoding="utf-8").read()
assert "MARKER-RAISE-MESSAGES" not in src, "Raise.php already carries the messages patch"

# 1. commitment email + event when an investor is added
anchor = """        $this->reset(['name', 'email', 'entity', 'amount']);"""
assert src.count(anchor) == 1, "addInvestor reset anchor found %d times" % src.count(anchor)
src = src.replace(anchor, """        \\App\\Models\\InvestorEvent::log($investor->id, 'committed', 'Commitment recorded: $' . number_format($investor->amount));
        \\App\\Services\\InvestorMessenger::send('commitment', $investor); // MARKER-RAISE-MESSAGES

""" + anchor, 1)

# addInvestor must keep a handle on the row it created
src = src.replace("""        Investor::create([
            'name'         => $data['name'],""",
"""        $investor = Investor::create([
            'name'         => $data['name'],""", 1)

# 2. signed → wire instructions
src = src.replace("""        $investor->forceFill(['signed_at' => now(), 'declined_at' => null])->save();""",
"""        $investor->forceFill(['signed_at' => now(), 'declined_at' => null])->save();
        \\App\\Models\\InvestorEvent::log($investor->id, 'signed', 'Marked signed');
        \\App\\Services\\InvestorMessenger::send('signed', $investor); // MARKER-RAISE-MESSAGES""", 1)

# 3. funded → receipt
src = src.replace("""        Notification::make()->title('Funds recorded for ' . $investor->name)->success()->send();""",
"""        \\App\\Models\\InvestorEvent::log($investor->id, 'funded', 'Funds received: $' . number_format($investor->amount_received));
        \\App\\Services\\InvestorMessenger::send('funded', $investor); // MARKER-RAISE-MESSAGES

        Notification::make()->title('Funds recorded for ' . $investor->name)->success()->send();""", 1)

# 4. declined is manual — log only, no automatic email
src = src.replace("""        $investor->forceFill(['declined_at' => now()])->save();""",
"""        $investor->forceFill(['declined_at' => now()])->save();
        \\App\\Models\\InvestorEvent::log($investor->id, 'declined', 'Marked declined');""", 1)

io.open(p, "w", encoding="utf-8").write(src)
print("   Raise page wired to the messenger")
PYEOF
echo "==> record page message panel"
python3 - <<'PYEOF'
import io
p = "app/Filament/Pages/InvestorRecord.php"
src = io.open(p, encoding="utf-8").read()
assert "MARKER-RAISE-MESSAGES" not in src

anchor = "    public function getViewData(): array"
assert src.count(anchor) == 1

addition = """    // MARKER-RAISE-MESSAGES
    public string $previewKey = '';

    public function previewMessage(string $key): void
    {
        $this->previewKey = $this->previewKey === $key ? '' : $key;
    }

    public function sendMessage(string $key): void
    {
        $investor = $this->investor();

        if ($key === 'invitation' && ! $investor->invited_at) {
            $investor->forceFill(['invited_at' => now()])->save();
        }

        $sent = \\App\\Services\\InvestorMessenger::send($key, $investor);

        $this->previewKey = '';

        if ($sent) {
            Notification::make()->title('Sent to ' . $investor->email)->success()->send();
        } else {
            Notification::make()->title('Not sent')->body('No email on file, or the mailer refused it. Check the activity log.')->danger()->send();
        }
    }

""" + anchor

src = src.replace(anchor, addition, 1)

src = src.replace("""            'cap'       => Investor::CAP,""",
"""            'cap'       => Investor::CAP,
            'templates' => \\App\\Services\\InvestorMessenger::templates(),
            'preview'   => $this->previewKey
                ? \\App\\Services\\InvestorMessenger::render($this->previewKey, $investor)
                : null,""", 1)

io.open(p, "w", encoding="utf-8").write(src)
print("   record page gained the message panel")
PYEOF
python3 - <<'PYEOF'
import io
p = "resources/views/filament/pages/investor-record.blade.php"
src = io.open(p, encoding="utf-8").read()
assert "MARKER-RAISE-MESSAGES" not in src

anchor = """        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Activity</div>"""
assert src.count(anchor) == 1, "activity anchor found %d times" % src.count(anchor)

messages = """        <!-- MARKER-RAISE-MESSAGES -->
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Messages</div>

            @foreach ($templates as $key => $template)
                <div class="py-2 border-b border-gray-100 dark:border-white/5">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-medium">{{ $template['label'] }}</div>
                            <div class="text-xs text-gray-500">{{ ucfirst($template['mode']) }} · {{ $template['trigger'] }}</div>
                        </div>
                        <x-filament::button size="xs" color="gray" wire:click="previewMessage('{{ $key }}')">
                            {{ $previewKey === $key ? 'Close' : 'Preview' }}
                        </x-filament::button>
                    </div>

                    @if ($previewKey === $key && $preview)
                        <div class="mt-2 rounded-lg bg-gray-50 dark:bg-white/5 p-3">
                            <div class="text-xs text-gray-500 mb-1">{{ $preview['subject'] }}</div>
                            <pre class="text-xs whitespace-pre-wrap font-sans">{{ $preview['body'] }}</pre>
                            <div class="mt-3">
                                <x-filament::button size="xs"
                                    wire:click="sendMessage('{{ $key }}')"
                                    wire:confirm="Send this to {{ $investor->email ?: 'nobody — no email on file' }}?">
                                    Send now
                                </x-filament::button>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach

            <p class="mt-3 text-xs text-gray-500">
                Automatic messages already fire on their trigger. Sending here is for the manual ones, or for
                resending after a change.
            </p>
        </div>

""" + anchor

src = src.replace(anchor, messages, 1)
io.open(p, "w", encoding="utf-8").write(src)
print("   record view gained the message panel")
PYEOF
echo "==> lead welcome"
python3 - <<'PYEOF'
import io
p = "app/Http/Controllers/InvestController.php"
src = io.open(p, encoding="utf-8").read()
assert "MARKER-RAISE-MESSAGES" not in src

anchor = """        Log::info('MARKER-INVEST-SITE lead captured'"""
assert src.count(anchor) == 1

src = src.replace(anchor, """        // MARKER-RAISE-MESSAGES — welcome the lead without creating an investor record
        try {
            \\Illuminate\\Support\\Facades\\Mail::raw(
                "Hi " . $data['name'] . ",\\n\\nThanks for leaving your details. I'll be in touch directly — if you have questions before then, just reply to this message.\\n\\nJosh",
                function ($mail) use ($data) {
                    $mail->to($data['email'], $data['name'])->subject('Thanks for the interest in Intake');
                }
            );
        } catch (\\Throwable $e) {
            Log::error('MARKER-RAISE-MESSAGES lead welcome failed', ['error' => $e->getMessage()]);
        }

""" + anchor, 1)

io.open(p, "w", encoding="utf-8").write(src)
print("   lead welcome wired")
PYEOF

echo ""
echo "MARKER-RAISE-MESSAGES applied."
echo "  automatic: list welcome, commitment, signed + wire details, funds received"
echo "  manual from the record page: invitation, document ready, round closed, declined"
echo "  set wire details on the Raise page BEFORE marking anyone signed"
