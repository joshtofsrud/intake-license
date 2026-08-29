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
        return \App\Models\RaiseMessageTemplate::merged();
    }

    // MARKER-RAISE-INVITE — $extra carries ad-hoc tokens, currently the
    // personal note typed on the invite. Optional, so existing callers stand.
    public static function render(string $key, Investor $investor, array $extra = []): ?array
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
            '{cap}'       => '$' . number_format(Investor::cap()),
            '{portal}'    => $investor->portalUrl(),
            '{bank}'      => $wire['bank']      ?: '[not set]',
            '{account}'   => $wire['account']   ?: '[not set]',
            '{routing}'   => $wire['routing']   ?: '[not set]',
            '{reference}' => $wire['reference'] ?: $investor->name,
            '{sender}'    => RaiseSetting::get('sender_name', 'Josh'),
        ];

        foreach ($extra as $token => $value) {
            $replacements['{' . trim($token, '{}') . '}'] = (string) $value;
        }

        // MARKER-RAISE-COMPOSE — the prepend that used to live here is gone. It
        // stacked a personal note on top of a template that already opened with
        // a greeting, so invitations said hello twice. Invitation text is now
        // authored in full on the invite form.

        return [
            'label'   => $template['label'],
            'subject' => strtr($template['subject'], $replacements),
            'body'    => strtr($template['body'], $replacements),
        ];
    }

    /**
     * MARKER-RAISE-COMPOSE — substitute into text that isn't a stored template.
     *
     * The invite form composes its own subject and body, so the placeholder
     * substitution has to be available without going through templates().
     */
    public static function renderRaw(string $subject, string $body, Investor $investor): array
    {
        $wire = RaiseSetting::wireInstructions();

        $replacements = [
            '{name}'      => $investor->name,
            '{amount}'    => '$' . number_format((int) $investor->amount),
            '{percent}'   => $investor->percent . '%',
            '{cap}'       => '$' . number_format(Investor::cap()),
            '{portal}'    => $investor->portalUrl(),
            '{bank}'      => $wire['bank']      ?: '[not set]',
            '{account}'   => $wire['account']   ?: '[not set]',
            '{routing}'   => $wire['routing']   ?: '[not set]',
            '{reference}' => $wire['reference'] ?: $investor->name,
            '{sender}'    => RaiseSetting::get('sender_name', 'Josh'),
        ];

        return [
            'subject' => strtr($subject, $replacements),
            'body'    => strtr($body, $replacements),
        ];
    }

    /**
     * MARKER-RAISE-HTML — the body, split into paragraphs and links.
     *
     * A line that is nothing but a URL becomes a button; everything else
     * stays a paragraph of exactly the text that was typed. Nothing is
     * reworded, reordered or added.
     */
    public static function blocks(string $body): array
    {
        $out = [];

        foreach (preg_split("/\n[ \t]*\n/", trim($body)) as $para) {
            $para = trim($para);
            if ($para === '') { continue; }

            if (preg_match('~^https?://\S+$~', $para)) {
                $out[] = [
                    'type'  => 'link',
                    'url'   => $para,
                    'label' => str_contains($para, '/invest/i/') ? 'Open your page' : 'Open the link',
                ];
                continue;
            }

            $out[] = ['type' => 'text', 'text' => $para];
        }

        return $out;
    }

    /** MARKER-RAISE-HTML — the shell around a message, or null if it can't render. */
    public static function html(string $subject, string $body): ?string
    {
        try {
            return view('emails.invest', [
                'subject' => $subject,
                'blocks'  => static::blocks($body),
            ])->render();
        } catch (\Throwable $e) {
            Log::error('MARKER-RAISE-HTML render failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** MARKER-RAISE-COMPOSE — send exactly what the preview showed. */
    public static function sendRaw(Investor $investor, string $subject, string $body): bool
    {
        if (! $investor->email) {
            InvestorEvent::log($investor->id, 'message_skipped', 'No email on file');

            return false;
        }

        $message = static::renderRaw($subject, $body, $investor);

        // MARKER-RAISE-HTML — multipart. The text part is what was typed; an
        // HTML-only message is a deliverability signal, and some people read
        // plain text on purpose.
        $html = static::html($message['subject'], $message['body']);

        try {
            Mail::send([], [], function ($mail) use ($investor, $message, $html) {
                $mail->to($investor->email, $investor->name)
                     ->subject($message['subject'])
                     ->text($message['body']);

                if ($html) { $mail->html($html); }
            });
        } catch (\Throwable $e) {
            Log::error('MARKER-RAISE-COMPOSE send failed', ['investor' => $investor->id, 'error' => $e->getMessage()]);

            return false;
        }

        InvestorEvent::log($investor->id, 'message_sent', 'Invitation sent: ' . $message['subject']);

        return true;
    }

    /** Returns true when the message actually went out. */
    public static function send(string $key, Investor $investor, array $extra = []): bool
    {
        if (! $investor->email) {
            InvestorEvent::log($investor->id, 'message_skipped', 'No email on file, skipped: ' . $key);

            return false;
        }

        $message = static::render($key, $investor, $extra);

        if (! $message) {
            return false;
        }

        // MARKER-RAISE-HTML — same shell and same multipart shape as sendRaw.
        $html = static::html($message['subject'], $message['body']);

        try {
            Mail::send([], [], function ($mail) use ($investor, $message, $html) {
                $mail->to($investor->email, $investor->name)
                     ->subject($message['subject'])
                     ->text($message['body']);

                if ($html) { $mail->html($html); }
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
