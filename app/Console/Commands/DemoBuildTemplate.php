<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * MARKER-DEMO-TEMPLATE — build the frozen "Intake Bike Works" demo template
 * from a real tenant. Run rarely, by hand, when the demo should pick up a
 * fresher slice of life. The hourly job never runs this; it restores the
 * frozen output (storage/app/demo/).
 */
class DemoBuildTemplate extends Command
{
    protected $signature = 'demo:build-template
        {--from= : Source tenant subdomain (required)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Copy + anonymise a tenant into the frozen Intake Bike Works demo template';

    private const DEMO_SUBDOMAIN = 'demo';
    private const DEMO_NAME      = 'Intake Bike Works';
    private const DEMO_EMAIL     = 'hello@intakebikeworks.example';
    private const DEMO_PHONE     = '(509) 555-0142';

    /** Tables with tenant_id that must never be copied. */
    private const EXCLUDE = '/session|debug_log|password_reset|failed_job|webhook|_export|telescope/i';

    /** Columns never swept or copied verbatim into the freeze log output. */
    private const SECRET_COLS = '/password|pin_hash|remember_token|secret|api_key|_token$/i';

    /**
     * MARKER-DEMO-TEMPLATE-FIX — anything credential-shaped, by column name or
     * by key inside a JSON blob. Live Stripe keys were found riding inside
     * tenants.settings; nothing matching this may reach the demo copy.
     */
    private const CREDENTIAL = '/stripe|twilio|paypal|square|secret|api_key|access_token|auth_token|webhook|signature|_sk$|_pk$|credential|private_key/i';

    private array $uuidMap = [];   // old uuid => new uuid (every copied row, all tables)
    private array $intMap  = [];   // "table:oldId" => newId (rare bigint-PK tables)
    private array $sweep   = [];   // literal string => replacement (identities, brand)
    private array $leakSamples = []; // real emails that must NOT survive

    public function handle(): int
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->error('This command speaks MySQL information_schema only.');
            return self::FAILURE;
        }

        $fromSub = (string) $this->option('from');
        if ($fromSub === '') {
            $this->error('--from=<source subdomain> is required.');
            return self::FAILURE;
        }
        $src = Tenant::where('subdomain', $fromSub)->first();
        if (! $src) {
            $this->error("No tenant with subdomain '{$fromSub}'.");
            return self::FAILURE;
        }
        if ($src->is_demo) {
            $this->error('Source tenant is itself a demo. No.');
            return self::FAILURE;
        }
        $existing = Tenant::withTrashed()->where('subdomain', self::DEMO_SUBDOMAIN)->first();
        if ($existing && ! $existing->is_demo) {
            $this->error("Subdomain 'demo' belongs to a real tenant — refusing.");
            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Rebuild the demo template from '{$src->name}'? The current demo tenant and template are replaced.")) {
            return self::SUCCESS;
        }

        $tables = $this->discoverTables();
        $this->info('Tenant-scoped tables: ' . count($tables));

        // ---- 1. clear the old demo ------------------------------------
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            if ($existing) {
                foreach ($tables as $t) {
                    DB::table($t)->where('tenant_id', $existing->id)->delete();
                }
                DB::table('tenants')->where('id', $existing->id)->delete();
                Storage::disk('public')->deleteDirectory('tenants/' . $existing->id);
                $this->line('cleared previous demo tenant');
            }

            // ---- 2. the tenant row ------------------------------------
            $demoId = (string) Str::uuid();
            $this->uuidMap[strtolower($src->id)] = $demoId;
            $row = (array) DB::table('tenants')->where('id', $src->id)->first();
            $row['id']            = $demoId;
            $row['name']          = self::DEMO_NAME;
            $row['subdomain']     = self::DEMO_SUBDOMAIN;
            $row['custom_domain'] = null;
            $row['is_demo']       = 1;
            $row['is_active']     = 1;
            $row['deleted_at']    = null;
            $row['created_at']    = now();
            $row['updated_at']    = now();
            $tenantsMeta = $this->tableMeta('tenants'); // MARKER-DEMO-TEMPLATE-FIX
            foreach ($row as $col => $v) {
                if (preg_match(self::CREDENTIAL, $col)) {
                    $row[$col] = $this->blankSecret($col, $tenantsMeta);
                }
                if (preg_match('/^logo_url$|^logo_light_url$/', $col)) $row[$col] = '/icon.svg';
                if ($col === 'favicon_url') $row[$col] = '/favicon.svg';
            }
            // sms stays off however the columns are named
            if (array_key_exists('sms_enabled', $row)) $row['sms_enabled'] = 0;
            // MARKER-DEMO-TEMPLATE-FIX — live Stripe keys were found inside this
            // JSON; strip every credential-keyed value before the row exists.
            foreach ($row as $col => $v) {
                if (is_string($v) && ($tenantsMeta['cols'][$col] ?? '') === 'json') {
                    $decoded = json_decode($v, true);
                    if (is_array($decoded)) {
                        $row[$col] = json_encode($this->stripJsonSecrets($decoded));
                    }
                }
            }
            DB::table('tenants')->insert($row);
            $this->info("demo tenant: {$demoId}");

            // brand sweep: every mention of the source shop becomes the demo shop
            $this->sweep[$src->name] = self::DEMO_NAME;
            $this->sweep[$fromSub . '.'] = self::DEMO_SUBDOMAIN . '.';

            // ---- 3. copy ----------------------------------------------
            $meta = [];
            foreach ($tables as $t) {
                $meta[$t] = $this->tableMeta($t);
                $n = $this->copyTable($t, $src->id, $demoId, $meta[$t]);
                if ($n) $this->line(sprintf('  %-42s %5d rows', $t, $n));
            }

            // ---- 4. remap FKs + uuid sweep ----------------------------
            foreach ($tables as $t) {
                $this->remapTable($t, $demoId, $meta[$t]);
            }

            // ---- 5. anonymise -----------------------------------------
            $this->anonymiseCustomers($demoId);
            $this->anonymiseStaff($demoId);
            foreach ($tables as $t) {
                $this->sweepTable($t, $demoId, $meta[$t]);
            }
            // the tenants row isn't in $tables — its settings JSON and any
            // contact columns get the same scrub by hand
            $tRow = (array) DB::table('tenants')->where('id', $demoId)->first();
            $tUpd = [];
            foreach ($tRow as $col => $v) {
                if (is_string($v) && $v !== '' && ! preg_match(self::SECRET_COLS, $col)
                    && ! in_array($col, ['id', 'subdomain', 'name'], true)) {
                    $new = $this->scrub($v, true);
                    if ($new !== $v) $tUpd[$col] = $new;
                }
            }
            if ($tUpd) DB::table('tenants')->where('id', $demoId)->update($tUpd);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // ---- 6. media files + freeze ----------------------------------
        $this->copyMedia($src->id, $demoId);
        $this->freeze($demoId, $tables);

        // ---- leak check ----------------------------------------------
        $leaks = $this->leakCheck($demoId, $tables);
        if ($leaks > 0) {
            $this->error("LEAK CHECK FAILED: {$leaks} real address(es) still present. Template NOT safe — do not expose the demo.");
            return self::FAILURE;
        }
        $this->info('Leak check clean: 0 of ' . count($this->leakSamples) . ' sampled real emails found in the copy.');
        $this->info('Template frozen at storage/app/demo/. Patch 3 restores it hourly.');
        return self::SUCCESS;
    }

    // ================= discovery =================

    private function discoverTables(): array
    {
        $db = DB::getDatabaseName();
        $rows = DB::select(
            "SELECT TABLE_NAME t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'tenant_id'
             GROUP BY TABLE_NAME ORDER BY TABLE_NAME", [$db]);
        return array_values(array_filter(
            array_map(fn ($r) => $r->t, $rows),
            fn ($t) => ! preg_match(self::EXCLUDE, $t) && $t !== 'tenants'
        ));
    }

    /** @return array{cols: array<string,string>, pk: ?string, pkIsUuid: bool, fks: array<string,string>} */
    private function tableMeta(string $table): array
    {
        $db = DB::getDatabaseName();
        $cols = []; $nullable = []; $defaults = [];
        foreach (DB::select(
            "SELECT COLUMN_NAME c, DATA_TYPE d, IS_NULLABLE n, COLUMN_DEFAULT df
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?", [$db, $table]) as $r) {
            $cols[$r->c]     = $r->d;
            $nullable[$r->c] = ($r->n === 'YES');
            $defaults[$r->c] = $r->df;
        }
        $pk = null; $pkIsUuid = false;
        foreach (DB::select(
            "SELECT COLUMN_NAME c, DATA_TYPE d FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_KEY = 'PRI'", [$db, $table]) as $r) {
            if ($pk === null) { $pk = $r->c; $pkIsUuid = in_array($r->d, ['char', 'varchar'], true); }
            else { $pk = null; break; } // composite PK: treat as no PK
        }
        $fks = [];
        foreach (DB::select(
            "SELECT COLUMN_NAME c, REFERENCED_TABLE_NAME rt
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL", [$db, $table]) as $r) {
            $fks[$r->c] = $r->rt;
        }
        return ['cols' => $cols, 'nullable' => $nullable, 'defaults' => $defaults, 'pk' => $pk, 'pkIsUuid' => $pkIsUuid, 'fks' => $fks];
    }

    // ================= copy =================

    private function copyTable(string $table, string $srcId, string $demoId, array $meta): int
    {
        // cursor(), not chunk(): offset pagination without a unique order
        // (pivot tables have none) can skip or repeat rows.
        $n = 0; $insert = [];
        foreach (DB::table($table)->where('tenant_id', $srcId)->cursor() as $r) {
            $row = (array) $r;
            $row['tenant_id'] = $demoId;
            // MARKER-DEMO-TEMPLATE-FIX — credentials never cross into the copy
            foreach ($row as $col => $v) {
                if ($v !== null && $col !== 'tenant_id' && $col !== 'id' && preg_match(self::CREDENTIAL, $col)) {
                    $row[$col] = $this->blankSecret($col, $meta);
                } elseif (is_string($v) && ($meta['cols'][$col] ?? '') === 'json') {
                    $decoded = json_decode($v, true);
                    if (is_array($decoded)) $row[$col] = json_encode($this->stripJsonSecrets($decoded));
                }
            }
            if ($meta['pk'] === 'id' && $meta['pkIsUuid']) {
                $new = (string) Str::uuid();
                $this->uuidMap[strtolower($row['id'])] = $new;
                $row['id'] = $new;
                $insert[] = $row;
            } elseif ($meta['pk'] === 'id') {
                $old = $row['id'];
                unset($row['id']);
                $newId = DB::table($table)->insertGetId($row);
                $this->intMap["{$table}:{$old}"] = $newId;
            } else {
                $insert[] = $row;
            }
            if (count($insert) >= 200) {
                DB::table($table)->insert($insert);
                $insert = [];
            }
            $n++;
        }
        if ($insert) DB::table($table)->insert($insert);
        return $n;
    }

    private function remapTable(string $table, string $demoId, array $meta): void
    {
        $uuidCols = [];
        foreach ($meta['cols'] as $col => $type) {
            if ($col === 'tenant_id' || $col === 'id') continue;
            if (preg_match(self::SECRET_COLS, $col)) continue;
            // constraint-declared FKs and convention *_id / *_uuid columns
            if (isset($meta['fks'][$col]) || preg_match('/_id$|_uuid$/', $col)) {
                if (in_array($type, ['char', 'varchar'], true)) $uuidCols[] = $col;
                elseif (isset($meta['fks'][$col])) $this->remapIntFk($table, $demoId, $col, $meta['fks'][$col]);
            }
        }
        if (! $uuidCols) return;

        // per-value updates: no row targeting needed, so PK-less pivots work,
        // and FK columns are indexed so each update is cheap
        foreach ($uuidCols as $col) {
            $values = DB::table($table)->where('tenant_id', $demoId)
                ->whereNotNull($col)->distinct()->pluck($col);
            foreach ($values as $old) {
                $k = strtolower((string) $old);
                if (isset($this->uuidMap[$k])) {
                    DB::table($table)->where('tenant_id', $demoId)->where($col, $old)
                        ->update([$col => $this->uuidMap[$k]]);
                }
            }
        }
    }

    private function remapIntFk(string $table, string $demoId, string $col, string $refTable): void
    {
        DB::table($table)->where('tenant_id', $demoId)->whereNotNull($col)
            ->distinct()->pluck($col)
            ->each(function ($old) use ($table, $demoId, $col, $refTable) {
                $key = "{$refTable}:{$old}";
                if (isset($this->intMap[$key])) {
                    DB::table($table)->where('tenant_id', $demoId)->where($col, $old)
                        ->update([$col => $this->intMap[$key]]);
                }
            });
    }

    // ================= anonymise =================

    private const FIRST = ['Avery','Jordan','Riley','Casey','Morgan','Quinn','Rowan','Skyler','Emerson','Finley','Harper','Kendall','Logan','Marlow','Nico','Parker','Reese','Sawyer','Tatum','Wren','Blake','Charlie','Dakota','Ellis','Frankie','Hayden','Indigo','Jules','Kai','Lennon','Mica','Noel','Oakley','Peyton','Remy','Shiloh','Teagan','Vesper','Winter','Zephyr'];
    private const LAST  = ['Alder','Birchwood','Cardinal','Driftwood','Eastman','Fernhill','Granite','Hollis','Ironwood','Juniper','Kestrel','Larkspur','Merritt','Northgate','Oakhurst','Pinecrest','Quarry','Ridgeway','Sandpoint','Timberline','Underhill','Vantage','Westbrook','Yarrow','Ashford','Bristlecone','Cascade','Deerfield','Elkhorn','Foxglove','Glacier','Harborview','Inlet','Jetty','Kettle','Lakeshore','Meridian','Nightingale','Overlook','Palisade'];

    private function anonymiseCustomers(string $demoId): void
    {
        mt_srand(42);
        $i = 0; $seen = 0;
        DB::table('customers')->where('tenant_id', $demoId)->orderBy('id')
            ->chunkById(500, function ($rows) use (&$i, &$seen) {
                foreach ($rows as $c) {
                    $first = self::FIRST[$i % count(self::FIRST)];
                    $last  = self::LAST[intdiv($i, count(self::FIRST)) % count(self::LAST)] . ($i >= 1600 ? ' ' . chr(65 + ($i % 26)) : '');
                    $email = strtolower($first . '.' . str_replace(' ', '', $last) . '.' . $i . '@example.com');
                    $phone = sprintf('(509) 555-%04d', $i % 10000);
                    $upd = ['email' => null, 'phone' => null];
                    $old = (array) $c;
                    // learn the old identity for the text sweep
                    foreach (['first_name' => $first, 'last_name' => $last, 'name' => trim($first . ' ' . $last)] as $col => $new) {
                        if (array_key_exists($col, $old)) {
                            $v = trim((string) $old[$col]);
                            if (mb_strlen($v) >= 4) $this->sweep[$v] = $new;
                            $upd[$col] = $new;
                        }
                    }
                    if (! empty($old['email'])) { $this->sweep[$old['email']] = $email; if ($seen < 40) { $this->leakSamples[] = $old['email']; $seen++; } }
                    if (! empty($old['phone'])) { $this->sweep[$old['phone']] = $phone; }
                    $upd['email'] = empty($old['email']) ? null : $email;
                    $upd['phone'] = empty($old['phone']) ? null : $phone;
                    foreach (['address', 'address_line1', 'street'] as $col) {
                        if (array_key_exists($col, $old) && ! empty($old[$col])) $upd[$col] = (100 + ($i % 4800)) . ' N Demo St';
                    }
                    foreach (['notes'] as $col) {
                        if (array_key_exists($col, $old)) $upd[$col] = $old[$col]; // swept later
                    }
                    unset($upd['notes']);
                    DB::table('customers')->where('id', $c->id)->update($upd);
                    $i++;
                }
            });
        $this->info("customers anonymised: {$i}");
    }

    private function anonymiseStaff(string $demoId): void
    {
        $names = ['Sam Demo', 'Alex Wrench', 'Jamie Spoke', 'Toni Chain', 'Devon True', 'Robin Crank'];
        $i = 0;
        foreach (DB::table('tenant_users')->where('tenant_id', $demoId)->orderBy('created_at')->get() as $u) {
            $name  = $names[$i % count($names)] . ($i >= count($names) ? ' ' . ($i + 1) : '');
            $email = 'staff' . ($i + 1) . '@intakebikeworks.example';
            if (mb_strlen(trim((string) $u->name)) >= 4) $this->sweep[trim($u->name)] = $name;
            if (! empty($u->email)) { $this->sweep[$u->email] = $email; }
            DB::table('tenant_users')->where('id', $u->id)->update([
                'name'           => $name,
                'email'          => $email,
                'password'       => Hash::make(Str::random(40)),
                'remember_token' => null,
                'pin_hash'       => null,
                'pin_set_at'     => null,
                'phone'          => '(509) 555-0199',
            ]);
            $i++;
        }
        $this->info("staff anonymised: {$i}");
    }

    private function sweepTable(string $table, string $demoId, array $meta): void
    {
        $textCols = [];
        foreach ($meta['cols'] as $col => $type) {
            if (in_array($type, ['varchar', 'char', 'text', 'mediumtext', 'longtext', 'json'], true)
                && ! preg_match(self::SECRET_COLS, $col)
                && $col !== 'tenant_id' && $col !== 'id') {
                $textCols[] = $col;
            }
        }
        if (! $textCols) return;

        // prose-ish columns additionally get the names sweep; every text column
        // gets the uuid + contact scrub (cheap regex, O(1) map lookups)
        $proseCols = array_values(array_filter($textCols, function ($col) use ($meta) {
            return preg_match('/name|body|note|desc|subject|content|message|html|text|title|label|heading|caption|summary|answer|reason|comment/i', $col)
                || in_array($meta['cols'][$col], ['json', 'text', 'mediumtext', 'longtext'], true);
        }));

        $pk = $meta['pk'];
        if ($pk) {
            foreach (DB::table($table)->where('tenant_id', $demoId)->cursor() as $r) {
                $upd = [];
                foreach ($textCols as $col) {
                    $v = $r->{$col} ?? null;
                    if ($v === null || $v === '') continue;
                    $new = $this->scrub((string) $v, in_array($col, $proseCols, true));
                    if ($new !== $v) $upd[$col] = $new;
                }
                if ($upd) DB::table($table)->where($pk, $r->{$pk})->update($upd);
            }
        } else {
            // no PK to target rows by — rewrite distinct values per column
            foreach ($textCols as $col) {
                $values = DB::table($table)->where('tenant_id', $demoId)
                    ->whereNotNull($col)->distinct()->pluck($col);
                foreach ($values as $v) {
                    if ($v === '') continue;
                    $new = $this->scrub((string) $v, in_array($col, $proseCols, true));
                    if ($new !== $v) {
                        DB::table($table)->where('tenant_id', $demoId)->where($col, $v)
                            ->update([$col => $new]);
                    }
                }
            }
        }
    }

    /**
     * The full uuid map can hold one entry per copied row, and strtr() rebuilds
     * its lookup structure on every call — so: regex passes with O(1) hash
     * lookups for uuids and emails, and the small identity strtr only where
     * prose can hide a name.
     */
    /**
     * MARKER-DEMO-TEMPLATE-FIX — null a flat value that lives in a
     * credential-looking column, honoring NOT NULL via the schema default.
     */
    private function blankSecret(string $col, array $meta): mixed
    {
        if ($meta['nullable'][$col] ?? true) return null;
        $default = $meta['defaults'][$col] ?? null;
        if ($default !== null && strcasecmp($default, 'NULL') !== 0) return trim((string) $default, "'");
        $type = $meta['cols'][$col] ?? 'varchar';
        return in_array($type, ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'double', 'float'], true) ? 0 : '';
    }

    /** Recursively null values under credential-looking keys in decoded JSON. */
    private function stripJsonSecrets(mixed $node): mixed
    {
        if (! is_array($node)) return $node;
        foreach ($node as $k => $v) {
            if (is_string($k) && preg_match(self::CREDENTIAL, $k) && (is_string($v) || is_numeric($v))) {
                $node[$k] = null;
            } elseif (is_array($v)) {
                $node[$k] = $this->stripJsonSecrets($v);
            }
        }
        return $node;
    }

    private function scrub(string $v, bool $prose = false): string
    {
        // row uuids (fk stragglers, media paths/urls, page-builder json refs)
        $new = preg_replace_callback(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            fn ($m) => $this->uuidMap[strtolower($m[0])] ?? $m[0],
            $v);
        // emails: the person's own fake when we know it, a generic otherwise
        $new = preg_replace_callback(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            function ($m) {
                if (isset($this->sweep[$m[0]])) return $this->sweep[$m[0]];
                return preg_match('/@(example\.com|intakebikeworks\.example|intake\.works)$/i', $m[0]) ? $m[0] : 'visitor@example.com';
            },
            $new);
        $new = preg_replace('/(?<!\d)\(?\+?1?[\s.\-)]{0,2}\d{3}[\s.\-)]{1,2}\d{3}[\s.\-]\d{4}(?!\d)/', '(509) 555-0142', $new);
        if ($prose && $this->sweep) {
            $new = strtr($new, $this->sweep);
        }
        return $new;
    }

    // ================= files + freeze =================

    private function copyMedia(string $srcId, string $demoId): void
    {
        $disk = Storage::disk('public');
        $n = 0;
        foreach ($disk->allFiles('tenants/' . $srcId) as $file) {
            $disk->copy($file, str_replace('tenants/' . $srcId, 'tenants/' . $demoId, $file));
            $n++;
        }
        $this->info("media files copied: {$n}");
    }

    private function freeze(string $demoId, array $tables): void
    {
        $local = Storage::disk('local');
        $local->deleteDirectory('demo');
        $local->makeDirectory('demo');

        $fh = fopen(storage_path('app/demo/template.jsonl'), 'w');
        $tenantRow = (array) DB::table('tenants')->where('id', $demoId)->first();
        fwrite($fh, json_encode(['table' => 'tenants', 'row' => $tenantRow]) . "\n");
        $counts = ['tenants' => 1];
        foreach ($tables as $t) {
            $counts[$t] = 0;
            foreach (DB::table($t)->where('tenant_id', $demoId)->cursor() as $r) {
                fwrite($fh, json_encode(['table' => $t, 'row' => (array) $r]) . "\n");
                $counts[$t]++;
            }
        }
        fclose($fh);

        // frozen copy of the media dir, restored alongside the rows
        $public = Storage::disk('public');
        foreach ($public->allFiles('tenants/' . $demoId) as $file) {
            $local->put('demo/files/' . substr($file, strlen('tenants/' . $demoId) + 1), $public->get($file));
        }

        $local->put('demo/manifest.json', json_encode([
            'tenant_id'  => $demoId,
            'subdomain'  => self::DEMO_SUBDOMAIN,
            'name'       => self::DEMO_NAME,
            'built_at'   => now()->toIso8601String(),
            'tables'     => array_keys(array_filter($counts)),
            'row_counts' => array_filter($counts),
        ], JSON_PRETTY_PRINT));
        $this->info('frozen: ' . array_sum($counts) . ' rows across ' . count(array_filter($counts)) . ' tables');
    }

    private function leakCheck(string $demoId, array $tables): int
    {
        if (! $this->leakSamples) return 0;
        $hits = 0;
        foreach ($tables as $t) {
            $meta = $this->tableMeta($t);
            $textCols = array_keys(array_filter($meta['cols'], fn ($type, $col) =>
                in_array($type, ['varchar', 'char', 'text', 'mediumtext', 'longtext', 'json'], true)
                && ! preg_match(self::SECRET_COLS, $col), ARRAY_FILTER_USE_BOTH));
            if (! $textCols) continue;
            foreach (array_slice($this->leakSamples, 0, 40) as $email) {
                $q = DB::table($t)->where('tenant_id', $demoId);
                $q->where(function ($w) use ($textCols, $email) {
                    foreach ($textCols as $c) $w->orWhere($c, 'like', '%' . $email . '%');
                });
                $hits += $q->count();
            }
        }
        return $hits;
    }
}
