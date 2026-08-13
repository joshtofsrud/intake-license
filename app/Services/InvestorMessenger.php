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
            '{cap}'       => '$' . number_format(Investor::cap()),
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
