#!/bin/bash
# apply-gift-card-emails.sh
#
# MARKER-GC-EMAILS — gift card emails become first-class Communication
# Center messages instead of a hardcoded blade nobody could edit.
#
#   - Two catalog entries: "Gift card delivery" (the card, to its recipient)
#     and "Gift card purchase receipt" (confirmation, to the buyer). Both get
#     the on/off toggle, the subject/body editor, and the test send that
#     every other message already has — the catalog drives all three, so no
#     new UI is needed.
#   - Built-in defaults live in EmailService::defaultTemplate() with the card
#     visual from the approved mockup inline in the body, so an untouched
#     shop still sends something that looks designed, and an edited one keeps
#     working (the visual is part of the editable body, not chrome).
#   - DeliverGiftCardJob moves off view('emails.gift-card') onto
#     EmailService::send('gift_card_delivery', ...), so tenant edits actually
#     take effect. It now also honors the toggle.
#   - The purchase receipt is genuinely new: online buyers previously got only
#     a confirmation page, so a closed tab left them with nothing.
#
# Requires the gift card patches; MARKER-GC-SETTINGS supplies the terms line.
set -e

MARKER="MARKER-GC-EMAILS"
CC="app/Http/Controllers/Tenant/CommunicationController.php"

[ -f app/Jobs/DeliverGiftCardJob.php ] || { echo "ERROR: requires apply-gift-cards-core.sh"; exit 1; }
grep -q "MARKER-GC-SETTINGS" app/Services/Tenant/GiftCardService.php || { echo "ERROR: requires apply-gift-card-settings.sh"; exit 1; }
if grep -q "$MARKER" "$CC" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

# ---------------------------------------------------------------
# 1. Built-in defaults
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Services/EmailService.php'
src = io.open(p, encoding='utf-8').read()

a = """            'status_update' => ["""
assert src.count(a) == 1
src = src.replace(a, """            // MARKER-GC-EMAILS -- the card visual sits INSIDE the editable body
            // so a shop that customizes the copy keeps the design, and one that
            // never opens the editor still sends something that looks made.
            'gift_card_delivery' => [
                'subject'   => "You've received a {$shop} gift card",
                'body_html' => "<p>{{recipient_name}}, you've been sent a gift card for {$shop}.</p>
<p>{{gift_message}}</p>
<table role='presentation' width='100%' style='margin:20px 0;border-collapse:collapse'>
  <tr><td style='background:#161616;color:#ffffff;border-radius:14px;padding:24px 26px'>
    <div style='font-size:12px;letter-spacing:.1em;text-transform:uppercase;opacity:.55'>{$shop}</div>
    <div style='font-size:34px;font-weight:800;padding-top:10px'>{{card_amount}}</div>
    <div style='font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;opacity:.45;padding-top:16px'>Card code</div>
    <div style='font-family:monospace;font-size:16px;letter-spacing:.16em;padding-top:6px'>{{card_code}}</div>
  </td></tr>
</table>
<p>Use it in store or online — just give this code at checkout. {{gift_policy}}</p>
<p>Check the balance any time at <a href='{{balance_url}}'>{{balance_url}}</a>.</p>
<p>— The {$shop} team</p>",
            ],
            'gift_card_purchase_receipt' => [
                'subject'   => "Your {$shop} gift card purchase",
                'body_html' => "<p>Hi {{first_name}},</p>
<p>Thanks — your gift card purchase went through.</p>
<table style='font-size:14px;line-height:1.8'>
  <tr><td style='color:#666;padding-right:16px'>Amount</td><td><strong>{{card_amount}}</strong></td></tr>
  <tr><td style='color:#666'>Type</td><td>{{card_type}}</td></tr>
  <tr><td style='color:#666'>Delivery</td><td>{{card_delivery}}</td></tr>
</table>
<p>{{card_next_step}}</p>
<p>— The {$shop} team</p>",
            ],
            'status_update' => [""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: built-in defaults')
PY

# ---------------------------------------------------------------
# 2. Catalog entries + sample vars for the test send
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Http/Controllers/Tenant/CommunicationController.php'
src = io.open(p, encoding='utf-8').read()

a = "            ['group'=>'Lifecycle','key'=>'booking_confirmation',"
assert src.count(a) == 1
src = src.replace(a, """            // MARKER-GC-EMAILS -- only listed for shops that actually have gift
            // cards; the catalog drives the toggles, the editor and the test
            // send, so a shop without them never sees dead switches.
            ['group'=>'Transactional','key'=>'gift_card_delivery','label'=>'Gift card delivery','desc'=>'The card itself, sent to whoever it is for','fires'=>'An e-gift is paid for, or its delivery date arrives','channels'=>['email'],'template'=>'gift_card_delivery','editor'=>'body','vars'=>['recipient_name','shop_name','card_amount','card_code','gift_message','gift_policy','balance_url'],'def_subject'=>'You\\'ve received a {{shop_name}} gift card','def_body'=>'{{recipient_name}}, you\\'ve been sent a gift card for {{shop_name}}.'],
            ['group'=>'Transactional','key'=>'gift_card_purchase_receipt','label'=>'Gift card purchase receipt','desc'=>'Confirmation to whoever bought the card','fires'=>'A gift card is bought on your website','channels'=>['email'],'template'=>'gift_card_purchase_receipt','editor'=>'body','vars'=>['first_name','shop_name','card_amount','card_type','card_delivery','card_next_step'],'def_subject'=>'Your {{shop_name}} gift card purchase','def_body'=>'Thanks — your gift card purchase went through.'],
""" + a, 1)

# hide the two entries unless the tenant has gift cards
b = """    public function index()
    {
        $tenant  = tenant();
        $catalog = $this->catalog();
"""
assert src.count(b) == 1
src = src.replace(b, """    public function index()
    {
        $tenant  = tenant();
        $catalog = $this->catalog();

        // MARKER-GC-EMAILS
        if (! $tenant->gift_cards_visible) {
            $catalog = array_values(array_filter(
                $catalog,
                fn ($m) => ! str_starts_with($m['key'], 'gift_card_')
            ));
        }
""", 1)

# toggles + saveTemplate + sendTest all read catalog(); filter there too so a
# shop without gift cards cannot post its way into the settings.
c = """    public function updateToggles(Request $request)
    {
        $tenant   = tenant();
        $smsReady = $this->smsReady($tenant);

        $settings = $tenant->settings ?? [];
        foreach ($this->catalog() as $m) {"""
assert src.count(c) == 1
src = src.replace(c, """    public function updateToggles(Request $request)
    {
        $tenant   = tenant();
        $smsReady = $this->smsReady($tenant);

        $settings = $tenant->settings ?? [];
        foreach ($this->catalog() as $m) {
            // MARKER-GC-EMAILS -- skip gift messages the shop cannot send.
            if (str_starts_with($m['key'], 'gift_card_') && ! $tenant->gift_cards_visible) {
                continue;
            }""", 1)

# sample vars for the test send
d = """            'sale_number'      => 'S-TEST-0001',"""
assert src.count(d) == 1
src = src.replace(d, """            'sale_number'      => 'S-TEST-0001',
            // MARKER-GC-EMAILS
            'recipient_name'   => 'Sam',
            'card_amount'      => '$50.00',
            'card_code'        => 'GC-TEST-0000-0000',
            'gift_message'     => 'Happy birthday — go get something good.',
            'gift_policy'      => \\App\\Services\\Tenant\\GiftCardService::config($tenant)['policy_line'],
            'balance_url'      => rtrim((string) $tenant->publicUrl(), '/') . '/gift-cards/balance',
            'card_type'        => 'E-gift card',
            'card_delivery'    => 'Sent to sam@example.com',
            'card_next_step'   => 'The code is in the recipient\\'s email.',""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: catalog entries + sample vars')
PY

# ---------------------------------------------------------------
# 3. Delivery job onto the editable template
# ---------------------------------------------------------------
cat > app/Jobs/DeliverGiftCardJob.php <<'EOF'
<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Tenant\TenantGiftCard;
use App\Services\EmailService;
use App\Services\Tenant\GiftCardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-GIFTCARDS — email an e-gift card to its recipient and stamp
 * delivered_at. Dispatched at issue time and by gift-cards:deliver.
 * Idempotent via the delivered_at check.
 *
 * MARKER-GC-EMAILS — now renders through EmailService::send() against the
 * 'gift_card_delivery' template, so edits made in the Communication Center
 * actually reach customers, and the shop's on/off toggle is honored.
 * delivered_at is only stamped when the send is attempted, so a card
 * suppressed by the toggle stays deliverable if the shop turns it back on.
 */
class DeliverGiftCardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $giftCardId)
    {
    }

    public function handle(): void
    {
        $card = TenantGiftCard::find($this->giftCardId);
        if (! $card || $card->delivered_at !== null || $card->status !== 'active' || blank($card->recipient_email)) {
            return;
        }

        $tenant = Tenant::find($card->tenant_id);
        if (! $tenant) {
            return;
        }

        if (! $tenant->notificationEnabled('gift_card_delivery_email')) {
            return;
        }

        try {
            $cfg = GiftCardService::config($tenant);
            $balanceUrl = rtrim((string) $tenant->publicUrl(), '/') . '/gift-cards/balance';

            EmailService::forTenant($tenant)->send('gift_card_delivery', $card->recipient_email, [
                'recipient_name' => $card->recipient_name ?: 'Hello',
                'shop_name'      => $tenant->name,
                'card_amount'    => '$' . number_format($card->balance_cents / 100, 2),
                'card_code'      => $card->code,
                'gift_message'   => (string) ($card->gift_message ?? ''),
                'gift_policy'    => $cfg['policy_line'],
                'balance_url'    => $balanceUrl,
                'first_name'     => $card->recipient_name ?: 'there',
            ]);

            $card->update(['delivered_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('gift_card.delivery_failed', [
                'gift_card_id' => $card->id,
                'tenant_id'    => $card->tenant_id,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
EOF
echo "ok: DeliverGiftCardJob on the editable template"

# ---------------------------------------------------------------
# 4. Purchase receipt to the buyer
# ---------------------------------------------------------------
cat > app/Jobs/SendGiftCardPurchaseReceiptJob.php <<'EOF'
<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Tenant\TenantGiftCard;
use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-GC-EMAILS — confirmation to whoever BOUGHT a gift card online.
 * Deliberately never contains the code: for an e-gift the code belongs to
 * the recipient, and for a pickup card it is read out in store.
 */
class SendGiftCardPurchaseReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $giftCardId)
    {
    }

    public function handle(): void
    {
        $card = TenantGiftCard::find($this->giftCardId);
        if (! $card || $card->status === 'pending' || blank($card->purchaser_email)) {
            return;
        }

        $tenant = Tenant::find($card->tenant_id);
        if (! $tenant || ! $tenant->notificationEnabled('gift_card_purchase_receipt_email')) {
            return;
        }

        $isEgift = $card->type === 'egift';

        if ($isEgift) {
            $delivery = $card->deliver_on
                ? 'Emailed to ' . $card->recipient_email . ' on ' . $card->deliver_on->format('M j, Y')
                : 'Emailed to ' . $card->recipient_email;
            $next = 'The card code is in the recipient\'s email — you don\'t need to pass anything along.';
        } else {
            $delivery = 'Ready to pick up in store';
            $next = 'Bring this email with you and we\'ll hand over the card.';
        }

        try {
            EmailService::forTenant($tenant)->send('gift_card_purchase_receipt', $card->purchaser_email, [
                'first_name'     => $card->purchaser_name ?: 'there',
                'shop_name'      => $tenant->name,
                'card_amount'    => '$' . number_format($card->original_cents / 100, 2),
                'card_type'      => $isEgift ? 'E-gift card' : 'Physical card',
                'card_delivery'  => $delivery,
                'card_next_step' => $next,
            ]);
        } catch (\Throwable $e) {
            Log::warning('gift_card.purchase_receipt_failed', [
                'gift_card_id' => $card->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
EOF

python3 - <<'PY'
import io
p = 'app/Http/Controllers/Tenant/GiftCardPublicController.php'
src = io.open(p, encoding='utf-8').read()

a = """                    $deliverToday = $card->deliver_on === null
                        || $card->deliver_on->lte($tenant->localToday());
                    if ($card->type === 'egift' && $deliverToday) {
                        \\App\\Jobs\\DeliverGiftCardJob::dispatch($card->id);
                    }"""
assert src.count(a) == 1
src = src.replace(a, """                    $deliverToday = $card->deliver_on === null
                        || $card->deliver_on->lte($tenant->localToday());
                    if ($card->type === 'egift' && $deliverToday) {
                        \\App\\Jobs\\DeliverGiftCardJob::dispatch($card->id);
                    }

                    // MARKER-GC-EMAILS -- the buyer gets their own confirmation;
                    // before this, closing the thanks page left them with nothing.
                    \\App\\Jobs\\SendGiftCardPurchaseReceiptJob::dispatch($card->id);""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: purchase receipt dispatched on activation')
PY

# The old hardcoded template is now unreachable; leave the file in place but
# mark it so nobody edits it expecting a change to ship.
if [ -f resources/views/emails/gift-card.blade.php ]; then
  python3 - <<'PY'
import io
p = 'resources/views/emails/gift-card.blade.php'
s = io.open(p, encoding='utf-8').read()
if 'MARKER-GC-EMAILS' not in s:
    io.open(p, 'w', encoding='utf-8').write(
        "{{-- MARKER-GC-EMAILS -- SUPERSEDED. Gift card delivery now renders from\n"
        "     the 'gift_card_delivery' template (Communication Center / EmailService\n"
        "     defaults). Editing this file changes nothing. Kept only so an older\n"
        "     queued job that still references it does not fatal mid-deploy. --}}\n" + s)
    print('ok: old blade marked superseded')
PY
fi

echo ""
echo "== gift card emails applied =="
echo "Post-deploy: php artisan optimize:clear + queue:restart"
