#!/usr/bin/env python3
"""
Patch 133 — guard failed_jobs query in OperationalHealthWidget.

The dashboard 500'd because the failed_jobs table doesn't exist on this
install. It only gets created when an admin runs `queue:failed-table`
followed by `migrate`. Same logic as the Redis try/catch already in
queueBacklog() — degrade gracefully and let the unresolved-errors tile
surface anything actually wrong.

Idempotent.
"""
import argparse, pathlib, sys

OLD = """    protected function failedJobs(): Stat
    {
        $count = (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        return Stat::make('Failed jobs (24h)', number_format($count))
            ->description($count > 0 ? 'investigate' : 'clean')
            ->color($count > 0 ? 'danger' : 'success');
    }"""

NEW = """    // MARKER-PATCH-133 — failed_jobs table is optional; guard for its absence.
    protected function failedJobs(): Stat
    {
        $count = 0;
        $tableMissing = false;
        try {
            if (\\Illuminate\\Support\\Facades\\Schema::hasTable('failed_jobs')) {
                $count = (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
            } else {
                $tableMissing = true;
            }
        } catch (\\Throwable $e) {
            $tableMissing = true;
        }
        if ($tableMissing) {
            return Stat::make('Failed jobs (24h)', 'n/a')
                ->description('failed_jobs table not created')
                ->color('gray');
        }
        return Stat::make('Failed jobs (24h)', number_format($count))
            ->description($count > 0 ? 'investigate' : 'clean')
            ->color($count > 0 ? 'danger' : 'success');
    }"""

def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    p = pathlib.Path(a.root) / 'app' / 'Filament' / 'Widgets' / 'OperationalHealthWidget.php'
    t = p.read_text()
    if 'MARKER-PATCH-133' in t:
        print('already_applied'); return
    if OLD not in t:
        print('ERROR: anchor missing', file=sys.stderr); sys.exit(2)
    if a.apply:
        p.write_text(t.replace(OLD, NEW, 1))
        print('applied')
    else:
        print('would apply')

if __name__ == '__main__': main()
