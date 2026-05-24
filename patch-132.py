#!/usr/bin/env python3
"""
Patch 132 — Restructure master admin dashboard into four labelled rows.
See patch header comments for full scope.
Idempotent.
"""

import argparse
import pathlib
import sys

MARKER = 'MARKER-PATCH-132'


MIGRATION = '''<?php
// MARKER-PATCH-132

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('system_health', function (Blueprint $t) {
            $t->string('key', 64)->primary();
            $t->json('value');
            $t->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_health');
    }
};
'''

SYSTEM_HEALTH_MODEL = '''<?php
// MARKER-PATCH-132

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;

class SystemHealth extends Model
{
    protected $table       = 'system_health';
    protected $primaryKey  = 'key';
    public    $incrementing = false;
    protected $keyType     = 'string';
    public    $timestamps  = false;
    protected $fillable    = ['key', 'value', 'updated_at'];
    protected $casts       = ['value' => 'array', 'updated_at' => 'datetime'];

    public static function read(string $key): ?array
    {
        $row = static::find($key);
        return $row?->value;
    }

    public static function write(string $key, array $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now()],
        );
    }
}
'''

WP_PLUGIN_WIDGET = '''<?php
// MARKER-PATCH-132

namespace App\\Filament\\Widgets;

use App\\Models\\Activation;
use App\\Models\\License;
use Filament\\Widgets\\StatsOverviewWidget as BaseWidget;
use Filament\\Widgets\\StatsOverviewWidget\\Stat;

class WpPluginStatsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $total   = Activation::count();
        $active  = Activation::where('last_seen_at', '>=', now()->subDays(30))->count();
        $free    = Activation::whereNull('license_id')->count();
        $premium = Activation::whereNotNull('license_id')->count();
        $activeLicenses = class_exists(License::class)
            ? License::where('status', 'active')->count()
            : 0;

        return [
            Stat::make('WP installs', number_format($total))
                ->description('WordPress plugin')
                ->color('gray'),

            Stat::make('Active (30d)', number_format($active))
                ->description($total > 0 ? round(($active / max($total, 1)) * 100) . '% of installs' : 'no installs yet')
                ->color($active > 0 ? 'success' : 'gray'),

            Stat::make('Free / Premium', $free . ' / ' . $premium)
                ->description($premium > 0
                    ? round(($premium / max($total, 1)) * 100) . '% paid'
                    : 'no paid yet')
                ->color($premium > 0 ? 'success' : 'gray'),

            Stat::make('Active licenses', number_format($activeLicenses))
                ->description('valid, non-expired keys')
                ->color('gray'),
        ];
    }
}
'''

OPERATIONAL_HEALTH_WIDGET = '''<?php
// MARKER-PATCH-132

namespace App\\Filament\\Widgets;

use App\\Models\\DebugLog;
use App\\Models\\SystemHealth;
use App\\Models\\Tenant\\TenantDomain;
use Filament\\Widgets\\StatsOverviewWidget as BaseWidget;
use Filament\\Widgets\\StatsOverviewWidget\\Stat;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Redis;
use Throwable;

class OperationalHealthWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        return [
            $this->unresolvedErrors(),
            $this->slowRequests(),
            $this->failedJobs(),
            $this->queueBacklog(),
            $this->stripeWebhookFailures(),
            $this->failedLogins(),
            $this->mailSent(),
            $this->backupStatus(),
            $this->domainsNeedingAttention(),
        ];
    }

    protected function unresolvedErrors(): Stat
    {
        $unresolved = DebugLog::where('severity', 'error')->whereNull('resolved_at')->count();
        $last7      = DebugLog::where('severity', 'error')->where('created_at', '>=', now()->subDays(7))->count();
        return Stat::make('Unresolved errors', number_format($unresolved))
            ->description($last7 . ' in last 7 days')
            ->color($unresolved > 0 ? 'danger' : 'success');
    }

    protected function slowRequests(): Stat
    {
        $count = DebugLog::where('channel', 'perf')->where('created_at', '>=', now()->subDay())->count();
        return Stat::make('Slow requests (24h)', number_format($count))
            ->description('over 1500ms')
            ->color($count > 10 ? 'warning' : ($count > 0 ? 'gray' : 'success'));
    }

    protected function failedJobs(): Stat
    {
        $count = (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        return Stat::make('Failed jobs (24h)', number_format($count))
            ->description($count > 0 ? 'investigate' : 'clean')
            ->color($count > 0 ? 'danger' : 'success');
    }

    protected function queueBacklog(): Stat
    {
        $pending = 0;
        try {
            $pending = (int) Redis::llen('queues:default');
        } catch (Throwable $e) {
            // Redis unreachable; unresolvedErrors picks up the actual error.
        }
        $color = $pending > 50 ? 'danger' : ($pending > 5 ? 'warning' : 'success');
        return Stat::make('Queue backlog', number_format($pending))
            ->description($pending === 0 ? 'idle' : ($pending > 50 ? 'backed up' : 'working through it'))
            ->color($color);
    }

    protected function stripeWebhookFailures(): Stat
    {
        $count = (int) DB::table('stripe_webhook_events')
            ->whereNull('processed_at')
            ->where('created_at', '<', now()->subMinutes(5))
            ->count();
        return Stat::make('Stripe webhook failures', number_format($count))
            ->description($count > 0 ? 'unprocessed > 5 min' : 'all processed')
            ->color($count > 0 ? 'danger' : 'success');
    }

    protected function failedLogins(): Stat
    {
        $count = DebugLog::where('channel', 'auth')
            ->where('action', 'auth.login_failed')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        return Stat::make('Failed logins (24h)', number_format($count))
            ->description($count > 20 ? 'investigate' : 'normal')
            ->color($count > 20 ? 'warning' : 'gray');
    }

    protected function mailSent(): Stat
    {
        $count = DebugLog::where('channel', 'mail')
            ->where('action', 'mail.sent')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        return Stat::make('Mail sent (24h)', number_format($count))
            ->description('comes online once tenant mail is live')
            ->color('gray');
    }

    protected function backupStatus(): Stat
    {
        $h = SystemHealth::read('last_backup');
        if (! $h || empty($h['at'])) {
            return Stat::make('Backup status', 'no record')
                ->description('backup script has not reported yet')
                ->color('warning');
        }
        $ts = \\Carbon\\Carbon::parse($h['at']);
        $ageHours = $ts->diffInHours(now());
        $sizeMb   = isset($h['bytes']) ? round($h['bytes'] / 1024 / 1024, 1) . ' MB' : '?';
        $color    = $ageHours > 36 ? 'danger' : ($ageHours > 30 ? 'warning' : 'success');
        return Stat::make('Backup status', $ts->diffForHumans())
            ->description($sizeMb . ' last run')
            ->color($color);
    }

    protected function domainsNeedingAttention(): Stat
    {
        $erroredOver24h = TenantDomain::where('status', 'error')->where('updated_at', '<', now()->subDay())->count();
        $stuckVerifying = TenantDomain::stuckVerifying()->count();
        $total = $erroredOver24h + $stuckVerifying;
        return Stat::make('Domains needing attention', number_format($total))
            ->description($total === 0 ? 'all healthy' : ($erroredOver24h . ' errored, ' . $stuckVerifying . ' stuck'))
            ->color($total > 0 ? 'danger' : 'success');
    }
}
'''

BACKUP_SCRIPT_FRAGMENT = '''# MARKER-PATCH-132 — append to /usr/local/bin/intake-backup.sh
# just before exit 0. Writes the most recent run to system_health so
# the dashboard tile can read it without shelling out.
#
# Required env (from /etc/intake-backup.env once you do the credential
# rotation that is still pending):
#   MYSQL_USER, MYSQL_PASS  (db = intake)
#
# Already set elsewhere in the script:
#   BACKUP_FILE  = path to the gzipped dump just uploaded
#   START_TS     = epoch seconds when the script started

if [ -f "$BACKUP_FILE" ]; then
  SIZE_BYTES=$(stat -c "%s" "$BACKUP_FILE")
  END_TS=$(date +%s)
  DURATION=$((END_TS - START_TS))
  AT=$(date --iso-8601=seconds)
  JSON_VALUE=$(printf '{"at":"%s","bytes":%s,"duration_sec":%s}' "$AT" "$SIZE_BYTES" "$DURATION")

  mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" intake <<SQL
    INSERT INTO system_health (`key`, value, updated_at)
    VALUES ("last_backup", '$JSON_VALUE', NOW())
    ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW();
SQL
fi
'''

OLD_PSW = """        $freeInstalls = Activation::whereNull('license_id')->count();
        $premiumSites = Activation::whereNotNull('license_id')->count();

        return [
            Stat::make('Total tenants', number_format($totalTenants))
                ->description($newThisWeek . ' new this week')
                ->color('success'),

            Stat::make('Active (onboarded)', number_format($active))
                ->description($trials . ' in trial')
                ->color('primary'),

            Stat::make('Est. MRR', '$' . number_format($mrr))
                ->description('From active plans')
                ->color('warning'),

            Stat::make('WP installs', number_format($freeInstalls + $premiumSites))
                ->description($premiumSites . ' premium · ' . $freeInstalls . ' free')
                ->color('gray'),
        ];
    }"""

NEW_PSW = """        // MARKER-PATCH-132 — WP installs moved to WpPluginStatsWidget.

        return [
            Stat::make('Total tenants', number_format($totalTenants))
                ->description($newThisWeek . ' new this week')
                ->color('success'),

            Stat::make('Active (onboarded)', number_format($active))
                ->description('paying or in onboarding')
                ->color('success'),

            Stat::make('In trial', number_format($trials))
                ->description('within 14-day window')
                ->color($trials > 0 ? 'warning' : 'gray'),

            Stat::make('Est. MRR', '$' . number_format($mrr))
                ->description('from active plans')
                ->color('success'),
        ];
    }"""

OLD_PSW_USE = """use App\\Models\\Tenant;
use App\\Models\\Activation;
use Filament\\Widgets\\StatsOverviewWidget as BaseWidget;"""

NEW_PSW_USE = """use App\\Models\\Tenant;
use Filament\\Widgets\\StatsOverviewWidget as BaseWidget;"""

OLD_AP_USE = """use App\\Filament\\Widgets\\PlatformStatsWidget;
use App\\Filament\\Widgets\\CustomDomainsStatsWidget;  // MARKER-PATCH-119
use App\\Filament\\Widgets\\ServerHealthWidget;
use App\\Filament\\Widgets\\StatsOverview;"""

NEW_AP_USE = """use App\\Filament\\Widgets\\PlatformStatsWidget;
use App\\Filament\\Widgets\\CustomDomainsStatsWidget;  // MARKER-PATCH-119
use App\\Filament\\Widgets\\ServerHealthWidget;
use App\\Filament\\Widgets\\StatsOverview;
use App\\Filament\\Widgets\\OperationalHealthWidget;  // MARKER-PATCH-132
use App\\Filament\\Widgets\\WpPluginStatsWidget;       // MARKER-PATCH-132"""

OLD_AP_LIST = """                ServerHealthWidget::class,
                PlatformStatsWidget::class,
                CustomDomainsStatsWidget::class,  // MARKER-PATCH-119"""

NEW_AP_LIST = """                ServerHealthWidget::class,
                OperationalHealthWidget::class,  // MARKER-PATCH-132
                PlatformStatsWidget::class,
                WpPluginStatsWidget::class,       // MARKER-PATCH-132
                CustomDomainsStatsWidget::class,  // MARKER-PATCH-119"""


NEW_FILES = {
    'database/migrations/2026_05_24_000001_create_system_health_table.php': MIGRATION,
    'app/Models/SystemHealth.php':                                          SYSTEM_HEALTH_MODEL,
    'app/Filament/Widgets/WpPluginStatsWidget.php':                         WP_PLUGIN_WIDGET,
    'app/Filament/Widgets/OperationalHealthWidget.php':                     OPERATIONAL_HEALTH_WIDGET,
    'tools/patch-132-backup-script-fragment.sh':                            BACKUP_SCRIPT_FRAGMENT,
}

EDITS = [
    ('app/Filament/Widgets/PlatformStatsWidget.php', OLD_PSW_USE, NEW_PSW_USE, 'PlatformStatsWidget use'),
    ('app/Filament/Widgets/PlatformStatsWidget.php', OLD_PSW,     NEW_PSW,     'PlatformStatsWidget body'),
    ('app/Providers/Filament/AdminPanelProvider.php', OLD_AP_USE,  NEW_AP_USE,  'AdminPanelProvider use'),
    ('app/Providers/Filament/AdminPanelProvider.php', OLD_AP_LIST, NEW_AP_LIST, 'AdminPanelProvider widget list'),
]


def process(root, apply):
    summary = {}
    for rel, content in NEW_FILES.items():
        path = root / rel
        if path.exists() and path.read_text() == content:
            summary['file:' + rel] = 'unchanged'
            continue
        if apply:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content)
            summary['file:' + rel] = 'written'
        else:
            summary['file:' + rel] = 'would_write'

    for rel, old, new, label in EDITS:
        path = root / rel
        text = path.read_text()
        if old not in text:
            if new in text:
                summary[label] = 'already_applied'
                continue
            print('ERROR: anchor not found for ' + label + ' in ' + rel, file=sys.stderr)
            sys.exit(2)
        if text.count(old) > 1:
            print('ERROR: anchor matches multiple times for ' + label + ' in ' + rel, file=sys.stderr)
            sys.exit(2)
        if apply:
            path.write_text(text.replace(old, new, 1))
        summary[label] = 'edited' if apply else 'would_edit'
    return summary


def verify(root):
    failures = []
    for rel in NEW_FILES:
        if not (root / rel).exists():
            failures.append(rel + ' missing')
            continue
        if MARKER not in (root / rel).read_text():
            failures.append(rel + ' missing MARKER')
    psw = (root / 'app' / 'Filament' / 'Widgets' / 'PlatformStatsWidget.php').read_text()
    if "Stat::make('WP installs'" in psw:
        failures.append('PlatformStatsWidget still has WP installs tile')
    ap = (root / 'app' / 'Providers' / 'Filament' / 'AdminPanelProvider.php').read_text()
    if 'OperationalHealthWidget' not in ap:
        failures.append('OperationalHealthWidget not registered')
    if 'WpPluginStatsWidget' not in ap:
        failures.append('WpPluginStatsWidget not registered')
    return failures


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root')
    ap.add_argument('--apply', action='store_true')
    args = ap.parse_args()
    root = pathlib.Path(args.root)
    if not (root / 'routes' / 'web.php').exists():
        print('ERROR: not an intake repo', file=sys.stderr)
        sys.exit(2)
    mode = 'APPLY' if args.apply else 'DRY-RUN'
    print('=== patch-132 [' + mode + '] target=' + str(root) + ' ===\n')
    s = process(root, apply=args.apply)
    print('Summary:')
    for k, v in s.items():
        print('  ' + k + ': ' + v)
    if args.apply:
        print('\nVerifying...')
        fails = verify(root)
        if fails:
            print('\nFAIL:')
            for f in fails:
                print('  - ' + f)
            sys.exit(1)
        print('  all checks pass')
        print('\nFollow-up steps:')
        print('  1. On droplet: php artisan migrate --force')
        print('  2. Append tools/patch-132-backup-script-fragment.sh into')
        print('     /usr/local/bin/intake-backup.sh (just before exit 0).')
        print('     Backup tile reads "no record" until first nightly run.')
    else:
        print('\n(dry-run)')


if __name__ == '__main__':
    main()
