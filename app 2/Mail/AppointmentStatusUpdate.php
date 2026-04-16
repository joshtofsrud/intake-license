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

class AppointmentStatusUpdate extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly TenantAppointment $appointment,
        public readonly string $oldStatus,
        public readonly string $statusNote = ''
    ) {}

    public function envelope(): Envelope
    {
        $svc     = EmailService::forTenant($this->tenant);
        $subject = $svc->interpolate(
            $this->tenant->emailTemplate('status_update')['subject']
                ?? 'Your work order {{ra_number}} has been updated',
            $this->vars()
        );

        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                $this->tenant->emailFromAddress(),
                $this->tenant->emailFromName()
            ),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.status-update',
            with: [
                'tenant'      => $this->tenant,
                'appointment' => $this->appointment,
                'vars'        => $this->vars(),
            ]
        );
    }

    private function vars(): array
    {
        $statusLabels = [
            'pending'     => 'Pending',
            'confirmed'   => 'Confirmed',
            'in_progress' => 'In progress',
            'completed'   => 'Completed — ready for pickup',
            'shipped'     => 'Shipped',
            'closed'      => 'Closed',
            'cancelled'   => 'Cancelled',
        ];
        return [
            'first_name'  => $this->appointment->customer_first_name,
            'ra_number'   => $this->appointment->ra_number,
            'status'      => $statusLabels[$this->appointment->status] ?? $this->appointment->status,
            'status_note' => $this->statusNote,
            'shop_name'   => $this->tenant->name,
            'accent'      => $this->tenant->accent_color ?? '#BEF264',
            'accent_text' => \App\Support\ColorHelper::accentTextColor($this->tenant->accent_color ?? '#BEF264'),
        ];
    }
}
