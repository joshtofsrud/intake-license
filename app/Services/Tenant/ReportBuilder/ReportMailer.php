<?php

namespace App\Services\Tenant\ReportBuilder;

use App\Models\Tenant;
use App\Services\EmailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Emails a saved report, and checks report alerts. (MARKER-REPORT-DELIVERY)
 *
 * A report is always run as the person who owns it, with exactly the access
 * they have, so an email never shows more than its owner could see. Reports
 * that read distributor stock are only emailed when master admin allows it.
 */
class ReportMailer
{
    public const EMAIL_ROWS = 25;

    public function __construct(private readonly Tenant $tenant) {}

    private function mail(): EmailService
    {
        return app()->makeWith(EmailService::class, ['tenant' => $this->tenant]);
    }

    private function owner(object $report): ?object
    {
        return DB::table('tenant_users')->where('tenant_id', $this->tenant->id)->where('id', $report->user_id)->first();
    }

    private function engineFor(?object $owner): ReportEngine
    {
        return new ReportEngine($this->tenant, $owner && in_array($owner->role ?? 'staff', ['owner', 'manager'], true));
    }

    /** Why this report can't be emailed right now, or null when it can. */
    public function blocked(object $report): ?string
    {
        $owner = $this->owner($report);
        if (!$owner) return 'The person who built this report is no longer on the team.';
        $engine = $this->engineFor($owner);
        $raw = (array) json_decode($report->config, true);
        if (($raw['ds'] ?? '') && !isset($engine->catalog()[$raw['ds'] === 'inventory' ? 'inv' : $raw['ds']])) {
            return 'This report\'s data isn\'t available to the person who built it.';
        }
        $cfg = $engine->clean($raw);
        if ($cfg['mode'] === 'timeline') return 'A customer timeline can\'t be emailed.';
        if ($engine->usesDistributor($cfg) && !ReportCatalog::velocity()['email']) {
            return 'Intake doesn\'t allow distributor figures in emails yet.';
        }
        return null;
    }

    /** @return array{sent: int, skipped: ?string} */
    public function send(object $report, array $delivery, array $to, bool $test = false): array
    {
        if ($why = $this->blocked($report)) return ['sent' => 0, 'skipped' => $why];
        $owner = $this->owner($report);
        $engine = $this->engineFor($owner);
        $cfg = $engine->clean((array) json_decode($report->config, true));
        $t = $engine->table($cfg);
        if ($t['count'] === 0 && !empty($delivery['skip_empty']) && !$test) {
            return ['sent' => 0, 'skipped' => 'No rows, so no email (as set).'];
        }

        [$from, $to2] = $engine->periods()->range($cfg['range']);
        $snap = $engine->catalog()[$cfg['ds']]['snap'] ?? false;
        $period = $snap ? 'As of ' . $engine->periods()->today()->format('M j, Y') : $cfg['range'] . ' · ' . $from->format('M j') . ' – ' . $to2->format('M j, Y');
        $link = $this->tenant->urlTo('admin/reports/custom', ['open' => $report->id]);
        $body = $this->bodyHtml($report->name, $period, $this->summary($engine, $cfg, $t), $t, $link, $delivery, $owner->name ?? 'Someone', !empty($delivery['attach_csv']));
        $subject = ($test ? '[Test] ' : '') . $report->name . ' — ' . ($snap ? 'as of ' . $engine->periods()->today()->format('M j') : strtolower($cfg['range']));
        $html = ($this->mail())->renderHtml($body);
        $csv = !empty($delivery['attach_csv']) ? $engine->csv($cfg) : null;
        $file = Str::slug($report->name) . '-' . $engine->periods()->today()->format('Ymd') . '.csv';

        $sent = 0;
        $svc = $this->mail();
        foreach ($to as $addr) {
            if ($svc->sendRenderedWithFile('report_schedule', $addr, $subject, $html, $csv, $file, 'text/csv')) $sent++;
        }
        return ['sent' => $sent, 'skipped' => null];
    }

    private function summary(ReportEngine $engine, array $cfg, array $t): string
    {
        if (!$t['rows'] || $t['pivot']) return '';
        $type = $t['types'][1];
        $name = mb_strtolower($t['head'][1]);
        $txt = '';
        if ($t['total'] && $t['total'][1] !== null) {
            $txt .= ucfirst($name) . ' ' . (in_array($type, ['$', 'n', 'h'], true) ? 'came to ' : 'was ') . '<b>' . e(ReportEngine::format($t['total'][1], $type)) . '</b>';
            $pt = $engine->cellValue($cfg, $cfg['measures'][0], $t['prevTotals'] ?? []);
            if ($cfg['compare'] !== 'off' && $pt) {
                $ch = ($t['total'][1] - $pt) / abs($pt) * 100;
                $txt .= ', ' . ($ch >= 0 ? 'up ' : 'down ') . '<b>' . number_format(abs($ch), 1) . '%</b> ' . ($cfg['compare'] === 'yoy' ? 'on last year' : 'on the period before');
            }
            $txt .= '. ';
        }
        $isTime = in_array($cfg['dim'], Periods::TIME_DIMS, true);
        if (!$isTime && count($t['rows']) > 1 && $t['rows'][0][1] !== null) {
            $txt .= '<b>' . e($t['rows'][0][0]) . '</b> ' . ($cfg['sortDir'] === 'asc' ? 'was lowest' : 'led') . ' at ' . e(ReportEngine::format($t['rows'][0][1], $type)) . '.';
        }
        return $txt;
    }

    private function table(array $t, int $max): string
    {
        $th = 'text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;font-weight:600;padding:7px 6px;border-bottom:1px solid #d8dbe0';
        $td = 'padding:8px 6px;border-bottom:1px solid #eef0f2;font-size:13px';
        $h = '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums"><tr>';
        foreach ($t['head'] as $i => $c) $h .= '<th style="' . $th . ($i ? ';text-align:right' : '') . '">' . e($c) . '</th>';
        $h .= '</tr>';
        foreach (array_slice($t['rows'], 0, $max) as $r) {
            $h .= '<tr>';
            foreach ($r as $i => $v) {
                $h .= '<td style="' . $td . ($i ? ';text-align:right;white-space:nowrap' : '') . '">' . e($i ? ReportEngine::format($v, $t['types'][$i]) : $v) . '</td>';
            }
            $h .= '</tr>';
        }
        if ($t['total']) {
            $h .= '<tr>';
            foreach ($t['total'] as $i => $v) {
                $h .= '<td style="padding:8px 6px;font-weight:600;border-top:1px solid #d8dbe0;font-size:13px' . ($i ? ';text-align:right;white-space:nowrap' : '') . '">' . e($i ? ReportEngine::format($v, $t['types'][$i]) : $v) . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</table>';
    }

    private function bodyHtml(string $name, string $period, string $summary, array $t, string $link, array $d, string $ownerName, bool $csv): string
    {
        $h = '<h1 style="font-size:20px;margin:0 0 2px;font-weight:600;color:#1c1f23">' . e($name) . '</h1>'
           . '<div style="font-size:12.5px;color:#6b7280;margin-bottom:14px">' . e($period) . '</div>';
        if ($summary) $h .= '<p style="margin:0 0 16px;font-size:14px;color:#1c1f23">' . $summary . '</p>';
        if ($t['count'] === 0) {
            $h .= '<p style="margin:0 0 16px;font-size:14px;color:#6b7280">Nothing matched this report this time.</p>';
        } else {
            $h .= $this->table($t, self::EMAIL_ROWS);
            $more = $t['count'] - self::EMAIL_ROWS;
            if ($more > 0) {
                $h .= '<div style="font-size:12px;color:#6b7280;margin:10px 0 0">+ ' . $more . ' more row' . ($more === 1 ? '' : 's') . ($csv ? ' in the attached CSV, and all' : ' — all') . ' in Intake</div>';
            }
        }
        $h .= '<p style="margin:20px 0 16px"><a href="' . e($link) . '" style="display:inline-block;background:#1c1f23;color:#ffffff;padding:10px 18px;border-radius:7px;font-weight:600;font-size:13.5px;text-decoration:none">Open in Intake</a></p>';
        $h .= '<p style="font-size:11.5px;color:#6b7280;line-height:1.6;margin:8px 0 0;border-top:1px solid #e6e8eb;padding-top:12px">You\'re getting this because ' . e($ownerName)
            . ' set <b>' . e($name) . '</b> to email ' . e(mb_strtolower(ReportSchedule::label($d))) . '. Numbers are as of when it was sent. '
            . '<a href="' . e($link) . '" style="color:#6b7280">Change or stop this email</a></p>';
        return $h;
    }

    /**
     * Check one report's alert. Emails the owner when the measure crosses the
     * line (once per change, not every night).
     *
     * @return array{state: array, sent: bool, value: ?float}
     */
    public function checkAlert(object $report, array $d): array
    {
        $state = (array) json_decode((string) ($d['alert_state'] ?? ''), true);
        if ($this->blocked($report)) return ['state' => $state, 'sent' => false, 'value' => null];
        $owner = $this->owner($report);
        $engine = $this->engineFor($owner);
        $cfg = $engine->clean((array) json_decode($report->config, true));
        $m = (string) ($d['alert_measure'] ?? '');
        if (!in_array($m, $cfg['measures'], true)) $m = $cfg['measures'][0] ?? '';
        $flat = $cfg; $flat['dim2'] = ''; $flat['compare'] = 'off'; $flat['limit'] = 0;
        $res = $engine->rows($flat);
        $v = $engine->cellValue($flat, $m, $res['totals']);
        if ($v === null) return ['state' => $state, 'sent' => false, 'value' => null];

        $x = (float) ($d['alert_value'] ?? 0);
        $op = $d['alert_op'] ?? 'below';
        $hit = false;
        $was = $state['hit'] ?? false;
        if ($op === 'change') {
            $base = $state['base'] ?? null;
            if ($base === null) {
                $state['base'] = $v;  // first check sets the starting point
            } elseif ($base != 0 && abs($v - $base) / abs($base) * 100 > $x) {
                $hit = true;
                $state['base'] = $v;  // the next alert measures from here
                $was = false;
            }
        } else {
            $hit = $op === 'above' ? $v > $x : $v < $x;
        }
        $state['hit'] = $hit;
        $state['last'] = $v;

        $sent = false;
        if ($hit && !$was && !empty($owner->email)) {
            [$ml, $mt] = $engine->measureDef($cfg, $m);
            $what = match ($op) {
                'above'  => 'above the ' . ReportEngine::format($x, $mt) . ' you asked to be told about',
                'change' => 'a change of more than ' . rtrim(rtrim(number_format($x, 1), '0'), '.') . '% since the last alert',
                default  => 'below the ' . ReportEngine::format($x, $mt) . ' you asked to be told about',
            };
            $link = $this->tenant->urlTo('admin/reports/custom', ['open' => $report->id]);
            $t = $engine->table($flat);
            $t['rows'] = array_slice($t['rows'], 0, 8);
            $body = '<h1 style="font-size:20px;margin:0 0 2px;font-weight:600;color:#1c1f23">' . e($report->name) . '</h1>'
                . '<div style="font-size:12.5px;color:#6b7280;margin-bottom:14px">Checked ' . e($engine->periods()->today()->format('M j, Y')) . ' · you get one email per change</div>'
                . '<div style="padding:6px 0 14px"><div style="font-size:38px;font-weight:600;letter-spacing:-.02em;color:#b3352f;line-height:1.1">' . e(ReportEngine::format($v, $mt)) . '</div>'
                . '<div style="font-size:12.5px;color:#6b7280">' . e($ml) . ' · ' . e(mb_strtolower($cfg['range'])) . '</div></div>'
                . '<p style="margin:0 0 16px;font-size:14px;color:#1c1f23">' . e($ml) . ' is <b>' . e(ReportEngine::format($v, $mt)) . '</b>, ' . e($what) . '.</p>'
                . ($t['count'] ? $this->table($t, 8) : '')
                . '<p style="margin:20px 0 16px"><a href="' . e($link) . '" style="display:inline-block;background:#1c1f23;color:#ffffff;padding:10px 18px;border-radius:7px;font-weight:600;font-size:13.5px;text-decoration:none">Open in Intake</a></p>'
                . '<p style="font-size:11.5px;color:#6b7280;line-height:1.6;margin:8px 0 0;border-top:1px solid #e6e8eb;padding-top:12px">Alerts go to the person who owns the report. <a href="' . e($link) . '" style="color:#6b7280">Change or stop this alert</a></p>';
            $svc = $this->mail();
            $sent = $svc->sendRenderedWithFile('report_schedule_alert', $owner->email, 'Alert: ' . $report->name, $svc->renderHtml($body), null, null, null);
        }
        return ['state' => $state, 'sent' => $sent, 'value' => $v];
    }
}
