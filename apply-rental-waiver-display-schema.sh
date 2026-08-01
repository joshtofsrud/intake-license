#!/usr/bin/env bash
# apply-rental-waiver-display-schema.sh
# MARKER-RENTAL-WAIVER-DISPLAY — patch 1 of 3: schema.
#
#   tenant_rentals gains the evidence columns a real signature needs:
#     agreement_signer_name     — today the signer's name only lands in notes
#     agreement_signature_path  — the drawn signature PNG
#     agreement_signed_ip       — who signed it, from where
#
#   tenant_registers gains a display OVERRIDE. This is deliberately NOT the
#   existing display_cart blob: display_cart is pushed by the register page's
#   JS and displayPoll expires it after 90s, so a customer reading a seven
#   section waiver would watch it vanish mid-read. The override is written
#   once by the rental check-out flow and persists until signed, recalled,
#   or aged out — which is also what lets staff navigate away from the
#   check-out page while the customer reads.
#
#   display_sign_nonce ties one signature POST to one push, so a stale tab
#   can't re-sign after a recall.
set -e

python3 <<'PY'
import io, os, re

MIG = 'database/migrations/2026_07_31_000100_rental_waiver_display.php'
assert not os.path.exists(MIG), 'migration already exists — patch already applied?'

open(MIG, 'w', encoding='utf-8').write('''<?php

// MARKER-RENTAL-WAIVER-DISPLAY — signature evidence on rentals + a persistent
// display override on registers.

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_rentals', function (Blueprint $t) {
            // The typed/drawn name. Until now this only survived in notes,
            // which meant the signed-state UI had nothing to show.
            $t->string('agreement_signer_name', 160)->nullable()->after('agreement_method');
            // Relative path on the public disk. Nullable: desk signings that
            // predate this patch (and the typed-name path) have no image.
            $t->string('agreement_signature_path')->nullable()->after('agreement_signer_name');
            // v6 addresses are 45 chars at worst.
            $t->string('agreement_signed_ip', 45)->nullable()->after('agreement_signature_path');
        });

        Schema::table('tenant_registers', function (Blueprint $t) {
            // null = normal cart mirroring. 'agreement' = waiver takes over.
            $t->string('display_mode', 20)->nullable()->after('cart_updated_at');
            $t->foreignUuid('display_rental_id')->nullable()->after('display_mode')
              ->constrained('tenant_rentals')->nullOnDelete();
            $t->timestamp('display_mode_at')->nullable()->after('display_rental_id');
            $t->string('display_sign_nonce', 64)->nullable()->after('display_mode_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_registers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('display_rental_id');
            $t->dropColumn(['display_mode', 'display_mode_at', 'display_sign_nonce']);
        });

        Schema::table('tenant_rentals', function (Blueprint $t) {
            $t->dropColumn([
                'agreement_signer_name',
                'agreement_signature_path',
                'agreement_signed_ip',
            ]);
        });
    }
};
''')
print('created', MIG)

# ---------------------------------------------------------------- register model
p = 'app/Models/Tenant/TenantRegister.php'
s = io.open(p, encoding='utf-8').read()

old = """        'display_token', 'display_logo', 'display_cart', 'cart_updated_at', 'is_active',
    ];"""
assert s.count(old) == 1, 'R1 fillable anchor'
s = s.replace(old, """        'display_token', 'display_logo', 'display_cart', 'cart_updated_at', 'is_active',
        // MARKER-RENTAL-WAIVER-DISPLAY — persistent override, see the migration.
        'display_mode', 'display_rental_id', 'display_mode_at', 'display_sign_nonce',
    ];""")

old = """        'cart_updated_at' => 'datetime',
        'is_active'       => 'boolean',
    ];"""
assert s.count(old) == 1, 'R2 casts anchor'
s = s.replace(old, """        'cart_updated_at' => 'datetime',
        'is_active'       => 'boolean',
        'display_mode_at' => 'datetime', // MARKER-RENTAL-WAIVER-DISPLAY
    ];""")

old = """    public static function freshToken(): string"""
assert s.count(old) == 1, 'R3 method anchor'
s = s.replace(old, """    /**
     * MARKER-RENTAL-WAIVER-DISPLAY — is a waiver currently owning this screen?
     *
     * The 30 minute ceiling is a stranded-screen guard, not a signing deadline:
     * if a customer wanders off mid-waiver the tablet returns to the idle
     * greeting on its own rather than sitting on someone else's agreement.
     */
    public function agreementIsLive(): bool
    {
        return $this->display_mode === 'agreement'
            && $this->display_rental_id !== null
            && $this->display_mode_at !== null
            && $this->display_mode_at->gt(now()->subMinutes(30));
    }

    /** Drop the override and return the screen to normal cart mirroring. */
    public function clearDisplayMode(): void
    {
        $this->update([
            'display_mode'       => null,
            'display_rental_id'  => null,
            'display_mode_at'    => null,
            'display_sign_nonce' => null,
        ]);
    }

    public static function freshToken(): string""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- rental model
p = 'app/Models/Tenant/TenantRental.php'
s = io.open(p, encoding='utf-8').read()
m = re.search(r"'agreement_method'", s)
assert m, 'R4 — agreement_method not found in TenantRental fillable/casts'
# Add the three new columns next to agreement_method wherever it is declared.
old = "'agreement_method',"
assert s.count(old) == 1, 'R5 fillable anchor (agreement_method,)'
s = s.replace(old, "'agreement_method', 'agreement_signer_name', 'agreement_signature_path', 'agreement_signed_ip',")
io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- balance check ---"
python3 - <<'PY'
import io, glob
def bal(p):
    s = io.open(p, encoding='utf-8').read()
    i, n, d = 0, len(s), 0
    while i < n:
        c = s[i]
        if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
            while i < n and s[i] != '\n': i += 1
        elif c == '/' and i+1 < n and s[i+1] == '*':
            i += 2
            while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
            i += 2
        elif c in '"\'':
            q = c; i += 1
            while i < n and s[i] != q:
                if s[i] == '\\': i += 1
                i += 1
            i += 1
        else:
            if c == '{': d += 1
            elif c == '}': d -= 1
            i += 1
    return d
for f in ['app/Models/Tenant/TenantRegister.php',
          'app/Models/Tenant/TenantRental.php',
          'database/migrations/2026_07_31_000100_rental_waiver_display.php']:
    print(f, 'braces', bal(f))
PY

echo
echo "apply-rental-waiver-display-schema: OK"
