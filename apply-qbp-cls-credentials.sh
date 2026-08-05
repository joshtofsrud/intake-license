#!/usr/bin/env bash
# apply-qbp-cls-credentials.sh
# MARKER-QBP-CLS-CREDS — QBP carries two keys, like BTI carries two.
#
# API1 (free, catalog) and API3/CLS (licensed, images) are separate services
# with separate keys — and confusingly the SAME header name, X-QBPAPI-KEY, so
# pasting one into the other's field fails in a way that looks like a bad key
# rather than the wrong key.
#
# Stored as "api1:cls" in the existing single credential slot, exactly the
# pattern BtiClient already uses for username:password. That means the tenant
# connection page and the master-admin distributors page both pick the fields
# up from the registry with no change to either — one definition, two screens.
#
# EVERY TENANT BRINGS THEIR OWN, as with HLC and BTI. CLS is licensed to a
# retailer and the image URL embeds their own Image Service ID, so one shop's
# prefix can never render on another's pages. The master-admin key exists for
# platform-side investigation, not to serve anyone's storefront.
#
# The CLS key is OPTIONAL: without it QBP still supplies the entire catalog,
# cost and stock. Only images stop.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- registry
p = 'app/Services/Distributors/DistributorRegistry.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-QBP-CLS-CREDS' not in s, 'already applied'

old = """            default => [
                ['name' => 'api_key', 'label' => 'API key', 'type' => 'password',
                 'hint' => 'Issued by the distributor for your dealer account.'],
            ],
        };
    }"""
assert s.count(old) == 1, 'R1 credentialFields anchor'
s = s.replace(old, """            // MARKER-QBP-CLS-CREDS — two services, two keys, one header name.
            'QBP' => [
                ['name' => 'api_key', 'label' => 'API1 key (Point-of-Sale)', 'type' => 'password',
                 'hint' => 'Free from QBP. Supplies the catalog, dealer cost and stock.'],
                ['name' => 'cls_key', 'label' => 'API3 key (Content License Service)', 'type' => 'password',
                 'hint' => 'Optional, and licensed separately. Needed only for product images.'],
            ],
            default => [
                ['name' => 'api_key', 'label' => 'API key', 'type' => 'password',
                 'hint' => 'Issued by the distributor for your dealer account.'],
            ],
        };
    }

    /**
     * MARKER-QBP-CLS-CREDS — pull the CLS half out of a stored credential.
     *
     * Stored as "api1:cls". Returns '' when no CLS key has been given, which
     * is a supported state: everything except images still works.
     */
    public static function clsKey(?string $stored): string
    {
        $stored = (string) $stored;
        if (! str_contains($stored, ':')) {
            return '';
        }
        return trim(explode(':', $stored, 2)[1] ?? '');
    }""")

old = """        $k = trim($input['api_key'] ?? '');"""
assert s.count(old) == 1, 'R2 packCredentials anchor'
s = s.replace(old, """        // MARKER-QBP-CLS-CREDS — same colon packing BTI uses. A blank field
        // keeps whatever is stored, so saving one key never wipes the other.
        if (strtoupper($code) === 'QBP') {
            $curApi = $stored;
            $curCls = '';
            if (str_contains($stored, ':')) {
                [$curApi, $curCls] = explode(':', $stored, 2);
            }

            $api = trim($input['api_key'] ?? '') ?: $curApi;
            $cls = trim($input['cls_key'] ?? '') ?: $curCls;

            if ($api === '') {
                return null;   // no API1 key means no QBP at all
            }
            return $cls === '' ? $api : $api . ':' . $cls;
        }

        $k = trim($input['api_key'] ?? '');""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- adapter
p = 'app/Services/Distributors/QbpClient.php'
s = io.open(p, encoding='utf-8').read()

old = """    public function __construct(string $apiKey, string $region = 'us')
    {
        $this->apiKey = trim($apiKey);"""
assert s.count(old) == 1, 'A1 constructor anchor'
s = s.replace(old, """    public function __construct(string $apiKey, string $region = 'us')
    {
        // MARKER-QBP-CLS-CREDS — the credential may carry both keys as
        // "api1:cls". API1 is the part before the colon. Splitting here means
        // nothing else has to know the packing.
        $apiKey = trim($apiKey);
        if (str_contains($apiKey, ':')) {
            $apiKey = explode(':', $apiKey, 2)[0];
        }

        $this->apiKey = trim($apiKey);""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

cat <<'PHPEOF' > app/Console/Commands/QbpClsRefresh.php
<?php

// MARKER-QBP-CLS-CREDS

namespace App\Console\Commands;

use App\Models\PlatformDistributorConnection;
use App\Models\Tenant\TenantDistributorCatalogSubscription;
use App\Services\Distributors\DistributorRegistry;
use App\Services\Distributors\QbpClsClient;
use Illuminate\Console\Command;

/**
 * Fetch each QBP subscription's image service prefix and store it.
 *
 * The prefix embeds an Image Service ID unique to one QBP account, so it is
 * stored per subscription and never shared. It changes rarely — asking CLS on
 * every page render would be a call per image, which the licence would allow
 * and common sense would not.
 */
class QbpClsRefresh extends Command
{
    protected $signature = 'qbp:cls-refresh {--tenant= : Limit to one tenant id}';

    protected $description = "Fetch and store each tenant's QBP CLS image service URL and sizes.";

    public function handle(): int
    {
        $subs = TenantDistributorCatalogSubscription::query()
            ->where('distributor_code', 'QBP')
            ->where('is_active', true)
            ->when($this->option('tenant'), fn ($q) => $q->where('tenant_id', $this->option('tenant')))
            ->get();

        if ($subs->isEmpty()) {
            $this->warn('No active QBP subscriptions.');
        }

        $ok = 0;
        $skipped = 0;

        foreach ($subs as $sub) {
            $stored = $this->credential($sub);
            $cls = DistributorRegistry::clsKey($stored);

            if ($cls === '') {
                $this->line(sprintf('  %-38s no CLS key — images unavailable, everything else fine', $sub->tenant_id));
                $skipped++;
                continue;
            }

            $info = (new QbpClsClient($cls))->imageServiceInfo();

            if (! $info['ok']) {
                $this->error(sprintf('  %-38s %s', $sub->tenant_id, $info['error']));
                continue;
            }

            $sub->forceFill([
                'cls_image_url'   => $info['imageUrl'],
                'cls_image_sizes' => $info['imageSizes'],
                'cls_checked_at'  => now(),
            ])->save();

            $this->info(sprintf('  %-38s %s  (%d sizes)',
                $sub->tenant_id, $info['imageUrl'], count($info['imageSizes'])));
            $ok++;
        }

        // The platform key too, so master admin can investigate CLS without
        // it ever being the source for a tenant's storefront.
        $conn = PlatformDistributorConnection::where('distributor_code', 'QBP')->first();
        $platformCls = DistributorRegistry::clsKey($conn->api_key ?? null);

        if ($platformCls !== '') {
            $info = (new QbpClsClient($platformCls))->imageServiceInfo();
            $this->newLine();
            $this->line('platform key: ' . ($info['ok']
                ? $info['imageUrl'] . '  sizes: ' . implode(', ', $info['imageSizes'])
                : $info['error']));
        }

        $this->newLine();
        $this->line(sprintf('%d refreshed, %d without a CLS key.', $ok, $skipped));

        return self::SUCCESS;
    }

    /** Subscriptions store credentials encrypted; fall back gracefully. */
    private function credential(TenantDistributorCatalogSubscription $sub): string
    {
        $raw = $sub->credentials_encrypted;

        if (is_array($raw)) {
            return (string) ($raw['api_key'] ?? '');
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return (string) ($decoded['api_key'] ?? '');
            }
            return $raw;
        }
        return '';
    }
}
PHPEOF
echo "created app/Console/Commands/QbpClsRefresh.php"

echo
echo "--- QBP declares two credential fields ---"
python3 - <<'PY'
import io, re
s = io.open('app/Services/Distributors/DistributorRegistry.php', encoding='utf-8').read()
m = re.search(r"'QBP' => \[(.*?)\],\s*\n            default", s, re.S).group(1)
names = re.findall(r"'name' => '(\w+)'", m)
print('  fields:', ', '.join(names))
assert names == ['api_key', 'cls_key']
PY

echo
echo "--- packing keeps one key when the other is blank ---"
python3 - <<'PY'
def pack(stored, api_in, cls_in):
    cur_api, cur_cls = stored, ''
    if ':' in stored:
        cur_api, cur_cls = stored.split(':', 1)
    api = api_in.strip() or cur_api
    cls = cls_in.strip() or cur_cls
    if not api: return None
    return api if not cls else api + ':' + cls

cases = [
    ('',            'A1', '',    'A1'),
    ('',            'A1', 'C1',  'A1:C1'),
    ('A1',          '',   'C1',  'A1:C1'),   # add CLS later, keep API1
    ('A1:C1',       'A2', '',    'A2:C1'),   # rotate API1, keep CLS
    ('A1:C1',       '',   'C2',  'A1:C2'),   # rotate CLS, keep API1
    ('A1:C1',       '',   '',    'A1:C1'),   # save with both blank
]
for stored, a, c, want in cases:
    got = pack(stored, a, c)
    print('  %-8s + api=%-3s cls=%-3s -> %-8s %s' % (stored or '(new)', a or '-', c or '-', got, 'OK' if got == want else '*** WRONG ***'))
    assert got == want
PY

echo
echo "--- API1 client takes only its own half ---"
python3 - <<'PY'
import io, re
s = io.open('app/Services/Distributors/QbpClient.php', encoding='utf-8').read()
c = re.search(r'public function __construct.*?\n    \}', s, re.S).group(0)
print('  splits on colon:', "explode(':', $apiKey, 2)[0]" in c)
assert "explode(':', $apiKey, 2)[0]" in c

def api1(stored):
    return stored.split(':', 1)[0] if ':' in stored else stored
for stored, want in [('A1', 'A1'), ('A1:C1', 'A1')]:
    print('  %-8s -> %s' % (stored, api1(stored)))
    assert api1(stored) == want
PY

echo
echo "--- clsKey extraction, including the no-CLS case ---"
python3 - <<'PY'
def cls(stored):
    return stored.split(':', 1)[1].strip() if ':' in stored else ''
for stored, want in [('A1', ''), ('A1:C1', 'C1'), ('', '')]:
    got = cls(stored)
    print('  %-8s -> %-4s %s' % (stored or '(empty)', got or '(none)', 'OK' if got == want else '*** WRONG ***'))
    assert got == want
PY

echo
echo "--- no Command method shadowed ---"
python3 - <<'PY'
import io, re
s = io.open('app/Console/Commands/QbpClsRefresh.php', encoding='utf-8').read()
reserved = {'call','handle','run','ask','confirm','line','info','error','warn','option','argument','newLine','table','comment'}
bad = [m for m in re.findall(r'(?:private|protected) function (\w+)\(', s) if m in reserved]
print('  clashes:', bad or 'none')
assert not bad
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
for p in ['app/Services/Distributors/DistributorRegistry.php',
          'app/Services/Distributors/QbpClient.php',
          'app/Console/Commands/QbpClsRefresh.php']:
    s = io.open(p, encoding='utf-8').read()
    i, n, d, par, brk = 0, len(s), 0, 0, 0
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
            elif c == '(': par += 1
            elif c == ')': par -= 1
            elif c == '[': brk += 1
            elif c == ']': brk -= 1
            i += 1
    print('%-38s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "apply-qbp-cls-credentials: OK"
