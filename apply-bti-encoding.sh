#!/bin/bash
# bti-encoding — BTI's CSV is Windows-1252, not UTF-8.
#
#   The full import wrote 24,642 of 24,643 rows. The one failure:
#     Incorrect string value: '\xA0RS-73...' for column 'display_subtitle'
#   vendor_item_id came through as "\u00a0RS-73-04" — a non-breaking space.
#
#   Two separate faults behind one row:
#
#   1. A bare 0xA0 byte is NOT valid UTF-8 (a UTF-8 NBSP is 0xC2 0xA0), so
#      BTI is serving Windows-1252. MySQL's utf8mb4 column rejected the byte.
#      One row surfaced it, but every curly quote, degree sign, en dash and
#      accented brand name in that feed is the same defect waiting on a
#      product nobody has imported yet — so this is fixed at the read, for
#      every column, not patched at the one field that happened to fail.
#
#   2. PHP's trim() only strips ASCII whitespace, so it left the NBSP in
#      place even though the field map asked for a trim. The `trim` cast now
#      strips unicode whitespace too, which matters wherever a distributor
#      pads a part number with something that isn't a plain space — and an
#      MPN with an invisible leading character never matches its counterpart
#      at another distributor.
#
#   Conversion is conditional: valid UTF-8 is left exactly as it is, so a
#   feed that later switches to UTF-8 needs no change here. Only bytes that
#   can't be UTF-8 get run through the Windows-1252 mapping.
#
#   Re-run the full sync after deploying to pick up the missed row.
# NO MIGRATION. Server: optimize:clear.
set -e
if grep -q "MARKER-BTI-ENCODING" app/Services/Distributors/BtiClient.php; then
  echo "bti-encoding already applied — aborting."; exit 1
fi

# ------------------------------------------------------------- client: read
python3 - <<'BEN_0_EOF'
import io
p = 'app/Services/Distributors/BtiClient.php'
s = io.open(p, encoding='utf-8').read()

old = """                yield array_combine($header, array_map(
                    fn ($v) => $v === null ? '' : trim((string) $v),
                    $line
                ));"""
assert s.count(old) == 1, s.count(old)
new = """                yield array_combine($header, array_map(
                    fn ($v) => $v === null ? '' : $this->utf8(trim((string) $v)),
                    $line
                ));"""
s = s.replace(old, new)

old = """    // ---------------------------------------------------------------- api"""
assert s.count(old) == 1, s.count(old)
new = """    /**
     * MARKER-BTI-ENCODING \u2014 BTI serves Windows-1252, not UTF-8.
     *
     * Found via a single row whose vendor_item_id began with a bare 0xA0
     * (a Windows-1252 non-breaking space; the UTF-8 form is 0xC2 0xA0).
     * MySQL rejected the insert outright. That one row is not the extent of
     * it \u2014 every curly quote, en dash, degree sign and accented brand name
     * in the feed carries the same defect, so the conversion happens here,
     * on every value, rather than at the field that happened to fail first.
     *
     * Conditional on purpose: anything already valid UTF-8 is returned
     * untouched, so if BTI switches encoding this needs no change and
     * double-encoding can't happen.
     */
    private function utf8(string $v): string
    {
        if ($v === '' || mb_check_encoding($v, 'UTF-8')) {
            return $v;
        }
        return mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }

    // ---------------------------------------------------------------- api"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('client encoding ok')
BEN_0_EOF

# ------------------------------------------------------- resolver: real trim
python3 - <<'BEN_1_EOF'
import io
p = 'app/Services/Distributors/DistributorMapResolver.php'
s = io.open(p, encoding='utf-8').read()

old = """            // trim: BTI ships vendor_item_id as " SOX-6M".
            'trim'   => trim((string) $v),"""
assert s.count(old) == 1, s.count(old)
new = """            // MARKER-BTI-ENCODING
            // trim: BTI ships vendor_item_id as " SOX-6M", and at least one
            // row pads it with a NON-BREAKING space, which PHP's trim() does
            // not touch \u2014 it only strips ASCII whitespace. An MPN carrying an
            // invisible leading character never matches its counterpart at
            // another distributor, so this strips unicode whitespace too.
            'trim'   => preg_replace(
                '/^[\\s\\x{00A0}\\x{200B}\\x{FEFF}]+|[\\s\\x{00A0}\\x{200B}\\x{FEFF}]+$/u',
                '',
                (string) $v
            ),"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('trim cast ok')
BEN_1_EOF

echo
echo "bti-encoding applied."
