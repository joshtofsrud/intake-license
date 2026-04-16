<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly TenantAppointment $appointment
    ) {}

    public function envelope(): Envelope
    {
        $svc = EmailService::forTenant($this->tenant);
        $subject = $svc->interpolate(
            $this->tenant->emailTemplate('booking_confirmation')['subject']
                ?? 'Your booking is confirmed — {{ra_number}}',
            $this->vars()
        );

        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->tenant->emailFromAddress(),
                $this->tenant->emailFromName()
            ),
            replyTo: $this->tenant->email_reply_to
                ? [new \Illuminate\Mail\Mailables\Address($this->tenant->email_reply_to)]
                : [],
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-confirmation',
            with: [
                'tenant'      => $this->tenant,
                'appointment' => $this->appointment,
                'vars'        => $this->vars(),
            ]
        );
    }

    private function vars(): array
    {
        $appt = $this->appointment;
        return [
            'first_name'       => $appt->customer_first_name,
            'last_name'        => $appt->customer_last_name,
            'ra_number'        => $appt->ra_number,
            'appointment_date' => $appt->appointment_date->format('l, F j, Y'),
            'total'            => format_money($appt->total_cents),
            'shop_name'        => $this->tenant->name,
            'accent'           => $this->tenant->accent_color ?? '#BEF264',
            'accent_text'      => \App\Support\ColorHelper::accentTextColor($this->tenant->accent_color ?? '#BEF264'),
        ];
    }
}
