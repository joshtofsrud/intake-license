<?php

namespace App\Services\Tenant;

// MARKER-RENTAL-WAIVER-DISPLAY-BE — one place that turns "the customer
// agreed" into the durable record: signature image, PDF, stamped columns.

use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantRentalAgreementTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RentalAgreementService
{
    /** Hard ceiling on a decoded signature. A pad drawing is a few KB. */
    private const MAX_SIGNATURE_BYTES = 400000;

    /**
     * Decode a "data:image/png;base64,..." payload and store it.
     *
     * Returns the stored path, or null when the payload is absent or does
     * not survive validation. A bad signature must never abort a check-out
     * that the customer has already agreed to at the counter — the stamped
     * columns are the record, the image is corroboration.
     */
    public function storeSignature(TenantRental $rental, int $version, ?string $dataUrl): ?string
    {
        if (! $dataUrl) {
            return null;
        }

        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', trim($dataUrl), $m)) {
            Log::warning('rental_agreement.signature_rejected', [
                'rental_id' => $rental->id, 'reason' => 'shape',
            ]);
            return null;
        }

        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) === 0 || strlen($bin) > self::MAX_SIGNATURE_BYTES) {
            Log::warning('rental_agreement.signature_rejected', [
                'rental_id' => $rental->id, 'reason' => 'size',
            ]);
            return null;
        }

        // PNG magic bytes — the mime in the data URL is caller-supplied.
        if (substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            Log::warning('rental_agreement.signature_rejected', [
                'rental_id' => $rental->id, 'reason' => 'magic',
            ]);
            return null;
        }

        $path = 'tenants/' . $rental->tenant_id . '/rental-signatures/'
              . $rental->rental_number . '-v' . $version . '.png';

        try {
            Storage::disk('public')->put($path, $bin);
        } catch (\Throwable $e) {
            Log::error('rental_agreement.signature_store_failed', [
                'rental_id' => $rental->id, 'error' => $e->getMessage(),
            ]);
            return null;
        }

        return $path;
    }

    /** Render the agreement PDF. Never throws — the PDF is a nicety. */
    public function renderPdf(
        $tenant,
        TenantRental $rental,
        TenantRentalAgreementTemplate $template,
        string $signerName,
        ?string $signaturePath
    ): ?string {
        try {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('tenant.rentals.agreement-pdf', [
                'tenant'        => $tenant,
                'rental'        => $rental,
                'template'      => $template,
                'signerName'    => $signerName,
                'signedAt'      => now(),
                'signaturePath' => $signaturePath,
            ])->setPaper('letter');

            $path = 'tenants/' . $tenant->id . '/rental-agreements/'
                  . $rental->rental_number . '-v' . $template->version . '.pdf';

            Storage::disk('public')->put($path, $pdf->output());
            return $path;
        } catch (\Throwable $e) {
            Log::error('rental_agreement.pdf_failed', [
                'rental_id' => $rental->id, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Stamp the signature onto the rental. Caller has already checked that
     * the rental is reserved and unsigned.
     *
     * @param string $method desk | display
     */
    public function finalize(
        $tenant,
        TenantRental $rental,
        TenantRentalAgreementTemplate $template,
        string $signerName,
        string $method,
        ?string $signatureDataUrl = null,
        ?string $ip = null
    ): TenantRental {
        $signaturePath = $this->storeSignature($rental, (int) $template->version, $signatureDataUrl);
        $pdfPath       = $this->renderPdf($tenant, $rental, $template, $signerName, $signaturePath);

        $where = $method === 'display' ? 'on the customer display' : 'at the desk';

        $rental->update([
            'agreement_template_version' => $template->version,
            'agreement_signed_at'        => now(),
            'agreement_method'           => $method,
            'agreement_signer_name'      => $signerName,
            'agreement_signature_path'   => $signaturePath,
            'agreement_signed_ip'        => $ip,
            'agreement_pdf_path'         => $pdfPath,
            'notes'                      => trim(($rental->notes ? $rental->notes . "\n" : '')
                . 'Agreement v' . $template->version . ' signed ' . $where . ' by ' . $signerName . '.'),
        ]);

        return $rental->refresh();
    }
}
