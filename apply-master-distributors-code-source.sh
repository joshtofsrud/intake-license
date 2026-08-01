#!/bin/bash
# master-distributors-code-source — trust the form state, not a side effect.
#
#   The page's $code property was only ever set by the Select's
#   afterStateUpdated callback. If that callback doesn't fire — and a
#   dehydrated(false) field inside a statePath'd form is exactly the kind of
#   place it can be skipped — the dropdown shows BTI while every action still
#   runs against HLC.
#
#   That single fact explains both symptoms: the banner kept saying "HLC
#   connected" after selecting BTI, and Test connection returned 401 for BTI
#   because it was testing HLC's key against BTI's endpoint.
#
#   The selection is already in $data['code']. Reading it there makes the
#   dropdown the source of truth, so no action can act on a distributor other
#   than the one on screen. The property stays as the fallback for the first
#   render, before any selection exists.
#
#   Also drops dehydrated(false), so the value survives in form state rather
#   than being stripped out of it.
# NO MIGRATION. Server: optimize:clear && php artisan filament:cache-components
set -e
if grep -q "MARKER-CODE-SOURCE" app/Filament/Pages/Distributors.php; then
  echo "master-distributors-code-source already applied — aborting."; exit 1
fi

python3 - <<'MCS_0_EOF'
import io
p = 'app/Filament/Pages/Distributors.php'
s = io.open(p, encoding='utf-8').read()

# ---------------------------------------------------------------- accessor
old = """    public string $code = 'HLC';"""
assert s.count(old) == 1, s.count(old)
new = """    public string $code = 'HLC';

    /**
     * MARKER-CODE-SOURCE — the distributor actually selected on screen.
     *
     * $code used to be maintained solely by the Select's afterStateUpdated
     * callback. When that didn't fire, the dropdown said BTI while every
     * action still ran against HLC — which is why the banner kept reading
     * "HLC connected" and why testing BTI returned 401: it was testing HLC's
     * key against BTI's endpoint.
     *
     * The selection already lives in $data['code']; reading it from there
     * makes the dropdown the source of truth. The property remains the
     * fallback for the first render, before a selection exists.
     */
    protected function currentCode(): string
    {
        $fromForm = strtoupper((string) ($this->data['code'] ?? ''));

        if ($fromForm !== '' && app(\\App\\Services\\Distributors\\DistributorRegistry::class)->isSupported($fromForm)) {
            $this->code = $fromForm;
        }

        return $this->code;
    }"""
s = s.replace(old, new)

# ---------------------------------------------------------------- keep it in state
old = """                            ->dehydrated(false)
                            ->columnSpanFull(),"""
assert s.count(old) == 1, s.count(old)
new = """                            ->columnSpanFull(),"""
s = s.replace(old, new)

# ---------------------------------------------------------------- use it
# Every action and the view data should ask, not assume.
for old_line, new_line in [
    ("        $conn = PlatformDistributorConnection::forCode($this->code);",
     "        $conn = PlatformDistributorConnection::forCode($this->currentCode());"),
]:
    n = s.count(old_line)
    assert n >= 1, (old_line, n)
    s = s.replace(old_line, new_line)

s = s.replace("$this->usesAuthStyle($this->code)", "$this->usesAuthStyle($this->currentCode())")
# field visibility and the delta button must follow the live selection too
s = s.replace("->visible(fn () => strtoupper($this->code) !== 'BTI')",
              "->visible(fn () => strtoupper($this->currentCode()) !== 'BTI')")
s = s.replace("->visible(fn () => strtoupper($this->code) === 'BTI'),",
              "->visible(fn () => strtoupper($this->currentCode()) === 'BTI'),")
s = s.replace("->packCredentials($this->code, $state)", "->packCredentials($this->currentCode(), $state)")
s = s.replace("->make($this->code, ['api_key'", "->make($this->currentCode(), ['api_key'")
s = s.replace("$conn->distributor_code = $this->code;", "$conn->distributor_code = $this->currentCode();")
s = s.replace("->title($this->code . ' connection saved')", "->title($this->currentCode() . ' connection saved')")
s = s.replace("->title('Connected to ' . $this->code)", "->title('Connected to ' . $this->currentCode())")
s = s.replace("SyncDistributorCatalogJob::dispatch($this->code, $delta);",
              "SyncDistributorCatalogJob::dispatch($this->currentCode(), $delta);")
s = s.replace("['distributor_code' => $this->code, 'source_ref' => 'catalog'],",
              "['distributor_code' => $this->currentCode(), 'source_ref' => 'catalog'],")
s = s.replace("->where('distributor_code', $this->code)->where('source_ref', 'catalog')->first();",
              "->where('distributor_code', $this->currentCode())->where('source_ref', 'catalog')->first();")
s = s.replace("->where('distributor_code', $this->code)->orderBy('brand_name')->get(),",
              "->where('distributor_code', $this->currentCode())->orderBy('brand_name')->get(),")

io.open(p, 'w', encoding='utf-8').write(s)
print('code source ok; remaining bare $this->code uses:', s.count('$this->code'))
MCS_0_EOF

# the view reads $this->code directly too
python3 - <<'MCS_1_EOF'
import io
p = 'resources/views/filament/pages/distributors.blade.php'
s = io.open(p, encoding='utf-8').read()
n = s.count('$this->code')
s = s.replace('$this->code', '$conn->distributor_code')
io.open(p, 'w', encoding='utf-8').write(s)
print('view now reads the loaded connection instead of the property, replaced:', n)
MCS_1_EOF

echo
echo "master-distributors-code-source applied."
