<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MARKER-SO-HANDOVER: an action would leave a special order without a
 * defined next step, so the person has to choose one first.
 *
 * Thrown from inside the work (usually inside a DB transaction, so nothing
 * has changed yet). A JSON caller gets 409 + the question; the shared dialog
 * (layouts/tenant/_so-choices) asks it and repeats the same request with
 * so_choice[<order id>] = <option> added. A page form gets sent back with the
 * question as an error flash, because a plain form can't be replayed.
 *
 * $fields: list of
 *   ['id' => order id, 'label' => 'SO-0058 · Cassette', 'note' => 'on order, due Nov 6',
 *    'options' => [['value' => 'stock', 'label' => '…', 'help' => '…'], …], 'default' => 'stock']
 */
class SpecialOrderChoiceRequired extends \RuntimeException
{
    public function __construct(
        public readonly string $title,
        public readonly string $messageText,
        public readonly array $fields,
        public readonly string $confirmLabel = 'Continue',
        public readonly bool $danger = false,
        public readonly ?array $reason = null, // ['required' => bool, 'placeholder' => '…', 'when' => 'free']
    ) {
        parent::__construct($title);
    }

    /** Handled: never logged as an error. */
    public function report(): bool
    {
        return true;
    }

    public function render(Request $request)
    {
        $payload = [
            'ok'           => false,
            'needs_choice' => true,
            'message'      => $this->title,
            'choice'       => [
                'title'   => $this->title,
                'message' => $this->messageText,
                'fields'  => $this->fields,
                'confirm' => $this->confirmLabel,
                'danger'  => $this->danger,
                'reason'  => $this->reason,
            ],
        ];

        if ($request->expectsJson() || $request->ajax()) {
            return new JsonResponse($payload, 409);
        }

        return back()->with('flash', ['type' => 'error', 'message' => $this->title . ' ' . $this->messageText]);
    }

    /** The choice made for an order on this request, if any. */
    public static function picked(string $specialOrderId): ?string
    {
        $all = (array) request()->input('so_choice', []);
        $v = $all[$specialOrderId] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function reason(): ?string
    {
        $r = trim((string) request()->input('so_reason', ''));

        return $r !== '' ? mb_substr($r, 0, 255) : null;
    }
}
