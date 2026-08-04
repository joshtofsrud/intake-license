#!/usr/bin/env bash
# apply-qbp-xml.sh
# MARKER-QBP-XML — API1 speaks XML, not JSON.
#
# Measured against the live API, seven header variants on two paths:
#
#   Accept: application/json              → 406, body "{}"
#   Accept: application/json; charset=..  → 406, body "{}"
#   Accept: application/xml               → 200, 77,601 bytes of XML
#   Accept: * / *                         → 200, same XML
#   (no Accept header)                    → 200, same XML
#
# The developer guide says "JSON is the recommended format for API1 requests
# and responses; however, both JSON and XML are fully supported." That is not
# true of the running service. Had the adapter been written from the guide it
# would have 406'd on every call — the same shape as the BTI cost bug, where
# the documentation and the data disagreed and the data won.
#
# Errors are XML-only too. On a bad path, XML returns
# <apiErrorResponse><errors><errorMessage>…, while JSON returns "{}" — so
# asking for JSON loses the reason as well as the payload.
#
# Envelope observed on 1/brand:
#   <brandResponse><responseStatus type="OK"/><errors/><brands><brand>…
# So every response carries a status attribute and an errors node before the
# payload. Both are checked rather than assumed, because a 200 with
# responseStatus other than OK is a failure the HTTP code does not show.
#
# SimpleXML's single-child trap is handled explicitly: <brands> with one
# <brand> yields an object where two yields an array. Everything that reads a
# collection goes through asList(), so a shop carrying one brand does not take
# a different code path from a shop carrying three hundred.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- adapter
p = 'app/Services/Distributors/QbpClient.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-QBP-XML' not in s, 'already applied'

old = """    private function get(string $path, array $query = [])
    {
        return Http::withHeaders([
                'X-QBPAPI-KEY' => $this->apiKey,
                'Accept'       => 'application/json',
            ])
            ->timeout((int) config('distributors.qbp.timeout', 60))
            ->get($this->base . $path, $query);
    }"""
assert s.count(old) == 1, 'X1 get anchor'
s = s.replace(old, """    /**
     * MARKER-QBP-XML — Accept: application/xml.
     *
     * Measured, not assumed: application/json returns 406 with an empty body
     * on every endpoint tried. XML is the only format the service actually
     * produces, and the only one that returns a readable error.
     */
    private function get(string $path, array $query = [])
    {
        return Http::withHeaders([
                'X-QBPAPI-KEY' => $this->apiKey,
                'Accept'       => 'application/xml',
            ])
            ->timeout((int) config('distributors.qbp.timeout', 60))
            ->get($this->base . $path, $query);
    }

    /**
     * MARKER-QBP-XML — XML body to a plain array.
     *
     * Attributes are prefixed with @ so responseStatus type="OK" survives as
     * ['@type' => 'OK'] rather than being dropped, which is how the envelope
     * reports failure on an HTTP 200.
     */
    private function xml(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $sx === false ? null : $this->sxToArray($sx);
    }

    private function sxToArray(\\SimpleXMLElement $el): array
    {
        $out = [];

        foreach ($el->attributes() as $k => $v) {
            $out['@' . $k] = (string) $v;
        }

        foreach ($el->children() as $name => $child) {
            $value = ($child->count() > 0 || $child->attributes()->count() > 0)
                ? $this->sxToArray($child)
                : trim((string) $child);

            if (array_key_exists($name, $out)) {
                // Second occurrence: promote to a list and keep both.
                if (! is_array($out[$name]) || ! array_is_list($out[$name])) {
                    $out[$name] = [$out[$name]];
                }
                $out[$name][] = $value;
            } else {
                $out[$name] = $value;
            }
        }

        if ($out === [] ) {
            $text = trim((string) $el);
            if ($text !== '') {
                return ['#text' => $text];
            }
        }

        return $out;
    }

    /**
     * MARKER-QBP-XML — SimpleXML gives an object for one child and a list for
     * two. Every collection read goes through this so one-item and many-item
     * responses take the same path.
     *
     * @return array<int,mixed>
     */
    private function asList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }
        return [$value];
    }""")

old = """        $json  = $res->json();
        $count = is_array($json) ? count($this->listish($json)) : 0;

        // A 200 carrying no brands is not a working connection — it usually
        // means the key is valid but the account has no catalog access.
        if ($count === 0) {"""
assert s.count(old) == 1, 'X2 testConnection parse anchor'
s = s.replace(old, """        // MARKER-QBP-XML — parse the envelope, then the payload.
        $doc = $this->xml((string) $res->body());

        if ($doc === null) {
            return [
                'ok' => false, 'status' => $status,
                'body' => 'QBP answered with something that is not XML: '
                        . mb_substr((string) $res->body(), 0, 120),
            ];
        }

        // A 200 can still carry a failure in the envelope, so the status
        // attribute is checked rather than trusted from the HTTP code.
        $envelope = (string) ($doc['responseStatus']['@type'] ?? 'OK');
        if ($envelope !== '' && strtoupper($envelope) !== 'OK') {
            $err = $doc['errors']['errorMessage'] ?? null;
            return [
                'ok' => false, 'status' => $status,
                'body' => 'QBP reported ' . $envelope
                        . (is_string($err) && $err !== '' ? ': ' . $err : '.'),
            ];
        }

        $count = count($this->asList($doc['brands']['brand'] ?? null));

        // A 200 carrying no brands is not a working connection — it usually
        // means the key is valid but the account has no catalog access.
        if ($count === 0) {""")

# listish() was written for JSON and no longer has a caller.
old = """    /** QBP wraps lists in a named key; find it without assuming the name. */
    private function listish(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }
        foreach ($payload as $v) {
            if (is_array($v) && array_is_list($v)) {
                return $v;
            }
        }
        return [$payload];
    }"""
assert s.count(old) == 1, 'X3 listish anchor'
s = s.replace(old, """    /* MARKER-QBP-XML — the JSON-shaped listish() helper is gone; asList()
       above replaces it, and the difference matters: listish() hunted for
       whichever key held an array, which is a JSON habit. XML names its
       collections, so the path is known and only the one-versus-many shape
       needs normalising. */""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- probe
p = 'app/Console/Commands/QbpProbe.php'
s = io.open(p, encoding='utf-8').read()

old = """            $res = Http::withHeaders([
                    'X-QBPAPI-KEY' => $this->key,
                    'Accept'       => 'application/json',
                ])
                ->timeout((int) config('distributors.qbp.timeout', 60))
                ->get($this->base . $path);
        } catch (\\Throwable $e) {
            $this->error('  request failed: ' . $e->getMessage());
            return null;
        }"""
assert s.count(old) == 1, 'X4 probe request anchor'
s = s.replace(old, """            // MARKER-QBP-XML — XML, measured. JSON 406s on every endpoint.
            $res = Http::withHeaders([
                    'X-QBPAPI-KEY' => $this->key,
                    'Accept'       => 'application/xml',
                ])
                ->timeout((int) config('distributors.qbp.timeout', 60))
                ->get($this->base . $path);
        } catch (\\Throwable $e) {
            $this->error('  request failed: ' . $e->getMessage());
            return null;
        }""")

old = """        $json = $res->json();
        if ($json === null) {
            $this->warn('  not JSON: ' . substr($res->body(), 0, 200));
            return null;
        }

        if ($dump) {
            $pretty = (string) json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->line($this->option('raw') ? $pretty : substr($pretty, 0, 6000));
            if (! $this->option('raw') && strlen($pretty) > 6000) {
                $this->comment('  … trimmed. Re-run with --raw for all of it.');
            }
        }

        return $json;"""
assert s.count(old) == 1, 'X5 probe parse anchor'
s = s.replace(old, """        // MARKER-QBP-XML — dump the RAW XML, not a converted array. The field
        // map is written against QBP's own element names, and a conversion
        // step between what they send and what is on screen is exactly the
        // gap a mapping bug hides in.
        $body = (string) $res->body();

        if ($dump) {
            $pretty = $this->prettyXml($body);
            $this->line($this->option('raw') ? $pretty : mb_substr($pretty, 0, 6000));
            if (! $this->option('raw') && mb_strlen($pretty) > 6000) {
                $this->comment('  … trimmed. Re-run with --raw for all of it.');
            }
        }

        $arr = $this->xmlToArray($body);
        if ($arr === null) {
            $this->warn('  not XML: ' . mb_substr($body, 0, 200));
            return null;
        }

        return $arr;"""
)

old = """    private function listish(array $payload): array"""
assert s.count(old) == 1, 'X6 probe helper anchor'
s = s.replace(old, """    /** MARKER-QBP-XML — indent the XML so a nested product is readable. */
    private function prettyXml(string $body): string
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \\DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $ok = $doc->loadXML($body);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $ok ? (string) $doc->saveXML() : $body;
    }

    /** MARKER-QBP-XML — attributes kept, prefixed with @. */
    private function xmlToArray(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($sx === false) {
            return null;
        }

        $walk = function (\\SimpleXMLElement $el) use (&$walk): array {
            $out = [];
            foreach ($el->attributes() as $k => $v) {
                $out['@' . $k] = (string) $v;
            }
            foreach ($el->children() as $name => $child) {
                $value = ($child->count() > 0 || $child->attributes()->count() > 0)
                    ? $walk($child)
                    : trim((string) $child);
                if (array_key_exists($name, $out)) {
                    if (! is_array($out[$name]) || ! array_is_list($out[$name])) {
                        $out[$name] = [$out[$name]];
                    }
                    $out[$name][] = $value;
                } else {
                    $out[$name] = $value;
                }
            }
            return $out;
        };

        return $walk($sx);
    }

    private function listish(array $payload): array""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- XML everywhere, no JSON accept left ---"
grep -c "application/json" app/Services/Distributors/QbpClient.php app/Console/Commands/QbpProbe.php || true
echo "  (negotiate() deliberately still tries JSON — that is its job)"

echo
echo "--- envelope and collection handling ---"
grep -n "responseStatus\|asList\|errorMessage" app/Services/Distributors/QbpClient.php | head -6

echo
echo "--- one-versus-many normalising is exercised ---"
python3 - <<'PY'
import subprocess, textwrap, os
# Mirror asList()/sxToArray() semantics in python to prove the shape logic,
# since there is no php binary here to run the real thing.
def sx_to_array(pairs):
    out = {}
    for name, value in pairs:
        if name in out:
            if not isinstance(out[name], list):
                out[name] = [out[name]]
            out[name].append(value)
        else:
            out[name] = value
    return out

def as_list(v):
    if v is None or v == '': return []
    if isinstance(v, list): return v
    return [v]

one  = sx_to_array([('brand', {'id': '1'})])
many = sx_to_array([('brand', {'id': '1'}), ('brand', {'id': '2'})])
print('  one brand  →', len(as_list(one['brand'])), 'item')
print('  two brands →', len(as_list(many['brand'])), 'items')
print('  empty      →', len(as_list(None)), 'items')
assert len(as_list(one['brand'])) == 1 and len(as_list(many['brand'])) == 2
print('  single-child trap handled')
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
for p in ['app/Services/Distributors/QbpClient.php', 'app/Console/Commands/QbpProbe.php']:
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
    print('%-24s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "apply-qbp-xml: OK"
