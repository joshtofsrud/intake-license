#!/bin/bash
# distributor-priority-fix — 1-2-3 numbering, scoped to what's on screen.
#
#   Redoes the two patches that never landed on this machine. Both were
#   written against each other and against box-actions, so when the first
#   failed everything after it was patching a file that didn't exist. This
#   one is anchored on the code that is actually there.
#
#   1. PRIORITIES WEREN'T CONTIGUOUS. Reordering swapped the two stored
#      values, which kept the ORDER right but left the numbers arbitrary —
#      two distributors both on the default sat at 50 and 50, and a swap
#      could leave 1 next to 50. The screen showed position so it looked
#      fine, but the stored value didn't state the position, and the
#      resolution step that reads it has to trust the number rather than
#      re-sort. Any change now renumbers the whole list 1..N.
#
#   2. THE LIST WASN'T SCOPED. renumber and reorder walked EVERY
#      subscription row for the tenant, while the screen lists only
#      distributors the registry supports. A leftover subscription for an
#      unsupported code — QBP was created and abandoned during this work —
#      holds a position in that list and shifts every index after it, so an
#      arrow moves a different box than the one clicked. That is the most
#      likely reason the arrows appeared to do nothing.
#
#   Renumbering also runs on page load when the list isn't already
#   contiguous, which migrates rows still sitting at the default of 50. It
#   writes only where a value differs, so a normal view stays read-only.
# NO MIGRATION. Server: optimize:clear
set -e
if grep -q "MARKER-PRIORITY-FIX" app/Http/Controllers/Tenant/DistributorController.php; then
  echo "distributor-priority-fix already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-POSTED-CODE" app/Http/Controllers/Tenant/DistributorController.php; then
  echo "run apply-tenant-test-uses-posted-code.sh first — aborting."; exit 1
fi

python3 - <<'DPF_0_EOF'
import io
p = 'app/Http/Controllers/Tenant/DistributorController.php'
s = io.open(p, encoding='utf-8').read()

# ---------------------------------------------------------------- helpers
old = """    /**
     * MARKER-POSTED-CODE — the distributor the request is about."""
assert s.count(old) == 1, ('helper anchor', s.count(old))
new = """    /**
     * MARKER-PRIORITY-FIX — the tenant's subscriptions in priority order,
     * limited to distributors the registry supports.
     *
     * That limit is the fix: this used to return every subscription row,
     * while the screen lists only supported codes. A leftover row for a
     * code with no adapter (QBP was created and abandoned mid-build) holds
     * a position here, shifts every index after it, and makes an arrow move
     * a different box than the one that was clicked.
     */
    private function orderedSubs()
    {
        $codes = app(\\App\\Services\\Distributors\\DistributorRegistry::class)->supported();

        return TenantDistributorCatalogSubscription::where('tenant_id', tenant()->id)
            ->whereIn('distributor_code', $codes)
            ->orderBy('data_priority')
            ->orderBy('distributor_code')
            ->get();
    }

    /**
     * MARKER-PRIORITY-FIX — rewrite priorities as 1..N in their current
     * order, so the stored number IS the position.
     *
     * Swapping the two values kept the order right but left the numbers
     * arbitrary. Writes only where a value differs, so calling this on every
     * page load costs nothing once the list is correct — it exists on the
     * read path because rows created before this all sit at the default of
     * 50, and a shop that never touches the arrows would otherwise keep
     * numbers that mean nothing.
     *
     * @param  \\Illuminate\\Support\\Collection $subs already in the wanted order
     */
    private function renumber($subs): void
    {
        $n = 1;
        foreach ($subs as $sub) {
            if ((int) $sub->data_priority !== $n) {
                $sub->data_priority = $n;
                $sub->save();
            }
            $n++;
        }
    }

    /**
     * MARKER-POSTED-CODE — the distributor the request is about."""
s = s.replace(old, new)

# ---------------------------------------------------------------- read path
old = """        // Lowest number first, so the screen reads in the order it resolves.
        usort($boxes, fn ($a, $b) => $a['priority'] <=> $b['priority']);"""
assert s.count(old) == 1, ('read path', s.count(old))
new = """        // MARKER-PRIORITY-FIX — make the stored numbers say what the order
        // is, then read them back. Ties break on code so the result is
        // stable rather than whatever the database returns today.
        usort($boxes, fn ($a, $b) => [$a['priority'], $a['code']] <=> [$b['priority'], $b['code']]);
        $this->renumber($this->orderedSubs());
        foreach ($boxes as $k => $box) {
            $boxes[$k]['priority'] = $k + 1;
        }"""
s = s.replace(old, new)

# ---------------------------------------------------------------- reorder
old = """        $subs = TenantDistributorCatalogSubscription::where('tenant_id', tenant()->id)
            ->orderBy('data_priority')->orderBy('distributor_code')->get();

        $i = $subs->search(fn ($s) => $s->distributor_code === $code);"""
assert s.count(old) == 1, ('reorder query', s.count(old))
new = """        $subs = $this->orderedSubs();

        $i = $subs->search(fn ($s) => $s->distributor_code === $code);"""
s = s.replace(old, new)

old = """        $me    = $subs[$i];
        $other = $subs[$j];

        $mine   = (int) $me->data_priority;
        $theirs = (int) $other->data_priority;

        if ($mine === $theirs) {
            $me->data_priority = $data['direction'] === 'up'
                ? max(1, $theirs - 1)
                : min(99, $theirs + 1);
        } else {
            $me->data_priority    = $theirs;
            $other->data_priority = $mine;
            $other->save();
        }
        $me->save();

        return back();"""
assert s.count(old) == 1, ('swap block', s.count(old))
new = """        // MARKER-PRIORITY-FIX — move it in the list, then renumber the whole
        // list 1..N. Swapping the two stored values kept the order right but
        // left the numbers arbitrary (two defaults both at 50, or a 1 beside
        // a 50), so the stored value never stated the position.
        $list = $subs->values()->all();
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];

        $this->renumber(collect($list));

        return back();"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('priority fix ok')
DPF_0_EOF

echo
echo "distributor-priority-fix applied."
