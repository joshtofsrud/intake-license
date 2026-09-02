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
        {--force : Skip the confirmation prompt}
        {--slug=demo : Subdomain for the demo tenant (a second vertical gets its own)}
        {--name= : Display name; defaults to Intake Bike Works for the bike demo}
        {--max-rows=50000 : Abort if any single table exceeds this many rows}';

    protected $description = 'Copy + anonymise a tenant into the frozen Intake Bike Works demo template';

    private const DEMO_SUBDOMAIN = 'demo';
    private const DEMO_NAME      = 'Intake Bike Works';

    // MARKER-DEMO-RESET — resolved per run so a second vertical can be built
    // with the same command instead of a forked copy.
    private string $slug = self::DEMO_SUBDOMAIN;
    private string $demoName = self::DEMO_NAME;
    private const DEMO_EMAIL     = 'hello@intakebikeworks.example';
    private const DEMO_PHONE     = '(509) 555-0142';

    /** Tables with tenant_id that must never be copied. */
    private const EXCLUDE = '/session|debug_log|password_reset|failed_job|webhook|_export|telescope/i';

    /**
     * MARKER-DEMO-TEMPLATE-BULK — bulk derived data: enormous, regenerable, and
     * of no demo value. Distributor availability alone is six figures for a
     * shop with live syncs, and it would bloat the frozen template past what
     * the hourly restore can finish.
     */
    /**
     * MARKER-DEMO-FIXES — internal staff notes are private chatter, not demo
     * texture. Anonymising names does not make the content fit to publish.
     */
    private const PRIVATE_TABLES = '/^tenant_notes$/i';

    private const BULK = '/availability_snapshot|distributor_sync|brand_sync|sync_state|_audit_log|audit_log|email_ledger|email_send|message_ledger|traffic|search_quer|search_log|analytics|page_view|activity_log|import_row|catalog_match|catalog_identifier/i';

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
    private array $sweep     = []; // exact-match map: emails, phone forms, brand
    private array $nameSweep = []; // MARKER-DEMO-TEMPLATE-NAMES — people's names, whole words only
    private array $leakSamples = []; // real emails that must NOT survive
    private ?array $referencedTables = null; // MARKER-DEMO-TEMPLATE-BULK

    public function handle(): int
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->error('This command speaks MySQL information_schema only.');
            return self::FAILURE;
        }

        $this->slug     = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $this->option('slug'))) ?: self::DEMO_SUBDOMAIN;
        $this->demoName = trim((string) $this->option('name')) ?: ($this->slug === self::DEMO_SUBDOMAIN ? self::DEMO_NAME : ucwords(str_replace('-', ' ', $this->slug)) . ' Demo');

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
        $existing = Tenant::withTrashed()->where('subdomain', $this->slug)->first();
        if ($existing && ! $existing->is_demo) {
            $this->error("Subdomain '{$this->slug}' belongs to a real tenant — refusing.");
            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Rebuild the demo template from '{$src->name}'? The current demo tenant and template are replaced.")) {
            return self::SUCCESS;
        }

        $tables = $this->discoverTables();
        $this->info('Tenant-scoped tables: ' . count($tables));
        $this->line('Customers table: ' . $this->customersTable()); // MARKER-DEMO-TEMPLATE-CUSTTABLE

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
            $row['name']          = $this->demoName;
            $row['subdomain']     = $this->slug;
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
                // MARKER-DEMO-WORDMARK — the wordmark, and the right one per surface:
                // logo.svg has light text (dark backgrounds), logo-dark.svg is its twin.
                if ($col === 'logo_url')       $row[$col] = '/logo-dark.svg';
                if ($col === 'logo_light_url') $row[$col] = '/logo.svg';
                if ($col === 'favicon_url')    $row[$col] = '/favicon.svg';
                // sizes are pixel heights tuned for a square mark; a 168x36
                // wordmark at 80px would be ~370px wide
                if ($col === 'logo_size_admin')   $row[$col] = 26;
                if ($col === 'logo_size_booking') $row[$col] = 28;
            }
            // sms stays off however the columns are named
            if (array_key_exists('sms_enabled', $row)) $row['sms_enabled'] = 0;
            // MARKER-DEMO-TEMPLATE-UNIQUE — globally unique, not credential-shaped
            if (array_key_exists('sms_from_number', $row)) $row['sms_from_number'] = null;
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
            $this->sweep[$src->name] = $this->demoName;
            $this->sweep[$fromSub . '.'] = $this->slug . '.';

            // ---- 3. copy ----------------------------------------------
            $meta = [];
            $cap  = (int) $this->option('max-rows');

            // MARKER-DEMO-TEMPLATE-UNIQUE — every new uuid is known BEFORE the
            // first insert, so FK columns can be rewritten inline and composite
            // uniques over FK pairs never collide with the source's rows.
            $this->line('Mapping ids…');
            foreach ($tables as $t) {
                $m = $this->tableMeta($t);
                if ($m['pk'] === 'id' && $m['pkIsUuid']) {
                    foreach (DB::table($t)->where('tenant_id', $src->id)->pluck('id') as $oldId) {
                        $this->uuidMap[strtolower((string) $oldId)] = (string) Str::uuid();
                    }
                }
            }
            $this->line('  ' . count($this->uuidMap) . ' ids mapped');
            foreach ($tables as $t) {
                $meta[$t] = $this->tableMeta($t);
                // MARKER-DEMO-TEMPLATE-BULK — count first: the operator sees which
                // table is running BEFORE the wait, not after it.
                $expect = DB::table($t)->where('tenant_id', $src->id)->count();
                if ($expect === 0) continue;
                if ($expect > $cap) {
                    throw new \RuntimeException(
                        "{$t} has {$expect} rows for this tenant, over the {$cap} cap. " .
                        "Add it to the BULK pattern if it's derived data, or raise --max-rows deliberately.");
                }
                $this->output->write(sprintf('  %-42s %6d rows … ', $t, $expect));
                $n = $this->copyTable($t, $src->id, $demoId, $meta[$t]);
                $this->line('done');
            }

            // ---- 4. remap FKs + uuid sweep ----------------------------
            foreach ($tables as $t) {
                $this->remapTable($t, $demoId, $meta[$t]);
            }

            // ---- 5. anonymise -----------------------------------------
            $this->scrubPrivateContent($demoId); // MARKER-DEMO-FIXES
            $this->demoBranding($demoId);        // MARKER-DEMO-FIXES
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
                    $new = $this->scrub($v, true, null);
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
        $this->info("Template frozen at storage/app/demo/{$this->slug}/. demo:reset restores it hourly.");
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
        $all = array_values(array_filter(
            array_map(fn ($r) => $r->t, $rows),
            fn ($t) => ! preg_match(self::EXCLUDE, $t) && $t !== 'tenants'
        ));
        // MARKER-DEMO-TEMPLATE-BULK — say out loud what is being left behind
        $skipped = array_values(array_filter($all, fn ($t) => preg_match(self::BULK, $t)));
        if ($skipped) {
            $this->line('Skipping bulk/derived tables (regenerable, not demo data):');
            foreach ($skipped as $t) $this->line('  - ' . $t);
        }
        // MARKER-DEMO-FIXES
        $private = array_values(array_filter($all, fn ($t) => preg_match(self::PRIVATE_TABLES, $t)));
        if ($private) {
            $this->line('Skipping private staff content (never suitable for a public demo):');
            foreach ($private as $t) $this->line('  - ' . $t);
        }
        return array_values(array_filter($all, fn ($t) => ! preg_match(self::BULK, $t) && ! preg_match(self::PRIVATE_TABLES, $t)));
    }

    /** MARKER-DEMO-TEMPLATE-UNIQUE — uuid-shaped FK columns on this table. */
    private function uuidFkCols(array $meta): array
    {
        $out = [];
        foreach ($meta['cols'] as $col => $type) {
            if ($col === 'tenant_id' || $col === 'id') continue;
            if (preg_match(self::SECRET_COLS, $col)) continue;
            if (! in_array($type, ['char', 'varchar'], true)) continue;
            if (isset($meta['fks'][$col]) || preg_match('/_id$|_uuid$/', $col)) $out[] = $col;
        }
        return $out;
    }

    /**
     * MARKER-DEMO-TEMPLATE-UNIQUE — single-column UNIQUE string columns whose
     * index does not include tenant_id: copying them verbatim collides with
     * the tenant we copied from. Tokens, codes, public slugs.
     */
    private function unscopedUniqueCols(string $table, array $meta): array
    {
        $rows = DB::select(
            "SELECT INDEX_NAME i, COLUMN_NAME c FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND NON_UNIQUE = 0
             ORDER BY INDEX_NAME, SEQ_IN_INDEX", [DB::getDatabaseName(), $table]);
        $byIndex = [];
        foreach ($rows as $r) $byIndex[$r->i][] = $r->c;

        $out = [];
        foreach ($byIndex as $index => $cols) {
            if (count($cols) !== 1) continue;              // composites are handled by inline FK remap
            $col = $cols[0];
            if ($col === 'id' || $col === $meta['pk'] || $index === 'PRIMARY') continue;
            if ($col === 'tenant_id') continue;
            if (! in_array($meta['cols'][$col] ?? '', ['char', 'varchar'], true)) continue;
            $out[] = $col;
        }
        return $out;
    }

    /**
     * MARKER-DEMO-TEMPLATE-BULK — does anything actually point at this table's
     * ids? If not, the per-row insertGetId map is dead weight and the copy can
     * batch like every other table.
     */
    private function isReferenced(string $table): bool
    {
        if (! isset($this->referencedTables)) {
            $rows = DB::select(
                "SELECT DISTINCT REFERENCED_TABLE_NAME t FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL", [DB::getDatabaseName()]);
            $this->referencedTables = array_flip(array_map(fn ($r) => $r->t, $rows));
        }
        return isset($this->referencedTables[$table]);
    }

    /** @return array{cols: array<string,string>, pk: ?string, pkIsUuid: bool, fks: array<string,string>} */
    private function tableMeta(string $table): array
    {
        $db = DB::getDatabaseName();
        $cols = []; $nullable = []; $defaults = []; $lengths = []; // MARKER-DEMO-TEMPLATE-NAMES
        foreach (DB::select(
            "SELECT COLUMN_NAME c, DATA_TYPE d, IS_NULLABLE n, COLUMN_DEFAULT df, CHARACTER_MAXIMUM_LENGTH len
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?", [$db, $table]) as $r) {
            $cols[$r->c]     = $r->d;
            $lengths[$r->c]  = $r->len ? (int) $r->len : null;
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
        return ['cols' => $cols, 'lengths' => $lengths, 'nullable' => $nullable, 'defaults' => $defaults, 'pk' => $pk, 'pkIsUuid' => $pkIsUuid, 'fks' => $fks];
    }

    // ================= copy =================

    private function copyTable(string $table, string $srcId, string $demoId, array $meta): int
    {
        // cursor(), not chunk(): offset pagination without a unique order
        // (pivot tables have none) can skip or repeat rows.
        $n = 0; $insert = [];
        $uuidCols   = $this->uuidFkCols($meta);            // MARKER-DEMO-TEMPLATE-UNIQUE
        $uniqueCols = $this->unscopedUniqueCols($table, $meta);
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
            // MARKER-DEMO-TEMPLATE-UNIQUE — rewrite FK uuids inline, then any
            // tenant-unscoped unique string, before this row reaches an index
            foreach ($uuidCols as $col) {
                $v = $row[$col] ?? null;
                if ($v !== null && isset($this->uuidMap[strtolower((string) $v)])) {
                    $row[$col] = $this->uuidMap[strtolower((string) $v)];
                }
            }
            foreach ($uniqueCols as $col) {
                $v = $row[$col] ?? null;
                if ($v !== null && $v !== '') {
                    $len = max(8, min(64, mb_strlen((string) $v)));
                    $row[$col] = substr(Str::random($len), 0, $len);
                }
            }
            if ($meta['pk'] === 'id' && $meta['pkIsUuid']) {
                $row['id'] = $this->uuidMap[strtolower($row['id'])] ?? (string) Str::uuid();
                $insert[] = $row;
            } elseif ($meta['pk'] === 'id' && $this->isReferenced($table)) {
                // something FKs to these ids, so the map has to be built row by row
                $old = $row['id'];
                unset($row['id']);
                $newId = DB::table($table)->insertGetId($row);
                $this->intMap["{$table}:{$old}"] = $newId;
            } elseif ($meta['pk'] === 'id') {
                unset($row['id']); // MARKER-DEMO-TEMPLATE-BULK — nothing points here; batch it
                $insert[] = $row;
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

    /**
     * MARKER-DEMO-TEMPLATE-UNIQUE — uuid FKs are rewritten inline during the
     * copy now, so only int FKs (whose ids don't exist until insert time) need
     * a second pass.
     */
    private function remapTable(string $table, string $demoId, array $meta): void
    {
        foreach ($meta['cols'] as $col => $type) {
            if ($col === 'tenant_id' || $col === 'id') continue;
            if (in_array($type, ['char', 'varchar'], true)) continue;
            if (isset($meta['fks'][$col])) {
                $this->remapIntFk($table, $demoId, $col, $meta['fks'][$col]);
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

    /**
     * MARKER-DEMO-TEMPLATE-CUSTTABLE — the shop's customers. App\Models\Customer
     * is the licensing-level model ('customers', no tenant_id); the tenant one
     * is App\Models\Tenant\TenantCustomer. Checked against the schema so a
     * wrong name fails at the top of the run, not after the copy.
     */
    private function customersTable(): string
    {
        $table = (new \App\Models\Tenant\TenantCustomer)->getTable();
        if (! \Illuminate\Support\Facades\Schema::hasColumn($table, 'tenant_id')) {
            throw new \RuntimeException("Customer table '{$table}' has no tenant_id — the anonymiser would miss every customer.");
        }
        return $table;
    }

    /**
     * MARKER-DEMO-FIXES — internal-note messages carry the same private chatter
     * tenant_notes did. Dropped rather than rewritten: there is no safe way to
     * decide which internal remark is fit for strangers to read.
     */
    private function scrubPrivateContent(string $demoId): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('tenant_messages')) return;
        $threadIds = DB::table('tenant_threads')->where('tenant_id', $demoId)->pluck('id');
        if ($threadIds->isEmpty()) return;
        $n = 0;
        foreach ($threadIds->chunk(300) as $chunk) {
            $q = DB::table('tenant_messages')->whereIn('thread_id', $chunk);
            if (\Illuminate\Support\Facades\Schema::hasColumn('tenant_messages', 'kind')) {
                $q->where(function ($w) {
                    $w->where('kind', 'internal_note')->orWhere('direction', 'system');
                });
            } else {
                $q->where('direction', 'system');
            }
            $n += $q->delete();
        }
        $this->info("private internal notes removed: {$n}");
    }

    /**
     * MARKER-DEMO-FIXES — the nav and footer sections carry logo_size from the
     * source shop, so a hand change inside the demo was wiped by the next
     * restore. The wordmark is wide; small is the size that fits.
     */
    private function demoBranding(string $demoId): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('tenant_page_sections')) return;
        $n = 0;
        foreach (DB::table('tenant_page_sections')->where('tenant_id', $demoId)
            ->whereIn('section_type', ['nav', 'footer', 'shell_nav', 'shell_footer'])->get() as $row) {
            $content = json_decode((string) $row->content, true);
            if (! is_array($content)) continue;
            $content['logo_size'] = 'small';
            DB::table('tenant_page_sections')->where('id', $row->id)
                ->update(['content' => json_encode($content)]);
            $n++;
        }
        $this->info("nav/footer logo size set to small: {$n} section(s)");
    }

    private function anonymiseCustomers(string $demoId): void
    {
        $table = $this->customersTable();
        $i = 0; $seen = 0;
        DB::table($table)->where('tenant_id', $demoId)->orderBy('id')
            ->chunkById(500, function ($rows) use (&$i, &$seen, $table) {
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
                            if (mb_strlen($v) >= 4) $this->nameSweep[$v] = $new; // MARKER-DEMO-TEMPLATE-NAMES
                            $upd[$col] = $new;
                        }
                    }
                    if (! empty($old['email'])) { $this->sweep[$old['email']] = $email; if ($seen < 40) { $this->leakSamples[] = $old['email']; $seen++; } }
                    // MARKER-DEMO-TEMPLATE-PHONE — the same number appears in notes
                    // and messages in whatever shape someone typed it; map them all
                    // to this customer's fake number.
                    if (! empty($old['phone'])) {
                        foreach ($this->phoneForms((string) $old['phone']) as $form) {
                            $this->sweep[$form] = $phone;
                        }
                    }
                    $upd['email'] = empty($old['email']) ? null : $email;
                    $upd['phone'] = empty($old['phone']) ? null : $phone;
                    foreach (['address', 'address_line1', 'street'] as $col) {
                        if (array_key_exists($col, $old) && ! empty($old[$col])) $upd[$col] = (100 + ($i % 4800)) . ' N Demo St';
                    }
                    foreach (['notes'] as $col) {
                        if (array_key_exists($col, $old)) $upd[$col] = $old[$col]; // swept later
                    }
                    unset($upd['notes']);
                    DB::table($table)->where('id', $c->id)->update($upd);
                    $i++;
                }
            });
        $this->info("customers anonymised: {$i}");
    }

    /**
     * MARKER-DEMO-TEMPLATE-PHONE — common written forms of one 10-digit number:
     * (509) 555-1234 · 509-555-1234 · 509.555.1234 · 5095551234 · +1 509 555 1234
     */
    private function phoneForms(string $raw): array
    {
        $d = preg_replace('/[^0-9]/', '', $raw);
        if (strlen($d) === 11 && str_starts_with($d, '1')) $d = substr($d, 1);
        if (strlen($d) !== 10) return [$raw];
        $a = substr($d, 0, 3); $b = substr($d, 3, 3); $c = substr($d, 6, 4);
        return array_values(array_unique([
            $raw,
            "({$a}) {$b}-{$c}", "({$a}){$b}-{$c}", "{$a}-{$b}-{$c}", "{$a}.{$b}.{$c}",
            "{$a} {$b} {$c}", "{$a}{$b}{$c}",
            "+1{$a}{$b}{$c}", "+1 {$a} {$b} {$c}", "+1-{$a}-{$b}-{$c}", "1-{$a}-{$b}-{$c}",
        ]));
    }

    private function anonymiseStaff(string $demoId): void
    {
        $names = ['Sam Demo', 'Alex Wrench', 'Jamie Spoke', 'Toni Chain', 'Devon True', 'Robin Crank'];
        $i = 0;
        foreach (DB::table('tenant_users')->where('tenant_id', $demoId)->orderBy('created_at')->get() as $u) {
            $name  = $names[$i % count($names)] . ($i >= count($names) ? ' ' . ($i + 1) : '');
            $email = 'staff' . ($i + 1) . '@intakebikeworks.example';
            if (mb_strlen(trim((string) $u->name)) >= 4) $this->nameSweep[trim($u->name)] = $name; // MARKER-DEMO-TEMPLATE-NAMES
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

        // MARKER-DEMO-TEMPLATE-NAMES — catalog/product text: people's names do not
        // belong there, and a false hit corrupts the demo's most visible data
        $namesHere = ! preg_match('/inventory|catalog|order_item|sale_item|product|vendor|distributor|pricing/i', $table);
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
                    $new = $this->scrub((string) $v, $namesHere && in_array($col, $proseCols, true), $meta['lengths'][$col] ?? null);
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
                    $new = $this->scrub((string) $v, $namesHere && in_array($col, $proseCols, true), $meta['lengths'][$col] ?? null);
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

    private function scrub(string $v, bool $names = true, ?int $maxLen = null): string
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
        // MARKER-DEMO-TEMPLATE-NAMES — whole words only: a name that sits inside
        // a product word ("Maxx" in "Maxxis") must never be rewritten.
        if ($names && $this->nameSweep) {
            $new = preg_replace_callback(
                '/[\p{L}][\p{L}\'.-]*/u',
                fn ($m) => $this->nameSweep[$m[0]] ?? $m[0],
                $new);
        }
        // MARKER-DEMO-TEMPLATE-PHONE — identities BEFORE the catch-all, or each
        // customer's own fake number gets flattened into the fallback. Cheap on
        // short columns too, so it is no longer gated on $prose.
        if ($this->sweep) {
            $new = strtr($new, $this->sweep);
        }
        // whatever is left belongs to nobody in the table — but never touch a
        // number the identity map just wrote
        $new = preg_replace_callback(
            '/(?<!\d)\(?\+?1?[\s.\-)]{0,2}\d{3}[\s.\-)]{1,2}\d{3}[\s.\-]\d{4}(?!\d)/',
            fn ($m) => str_contains(preg_replace('/[^0-9]/', '', $m[0]), '509555') ? $m[0] : self::DEMO_PHONE,
            $new);
        // MARKER-DEMO-TEMPLATE-NAMES — a replacement never overflows its column
        if ($maxLen !== null && mb_strlen($new) > $maxLen) $new = mb_substr($new, 0, $maxLen);
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
        $dir   = 'demo/' . $this->slug; // MARKER-DEMO-RESET
        $local->deleteDirectory($dir);
        $local->makeDirectory($dir);

        $fh = fopen(storage_path('app/' . $dir . '/template.jsonl'), 'w');
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
            $local->put($dir . '/files/' . substr($file, strlen('tenants/' . $demoId) + 1), $public->get($file));
        }

        // MARKER-DEMO-RESET — activity per calendar week, so the reset can anchor
        // the demo on a genuinely busy one instead of whatever week it was built.
        $weeks = $this->weekActivity($demoId);
        arsort($weeks);
        $busiest = array_key_first($weeks);

        $local->put($dir . '/manifest.json', json_encode([
            'tenant_id'    => $demoId,
            'subdomain'    => $this->slug,
            'name'         => $this->demoName,
            'built_at'     => now()->toIso8601String(),
            'tables'       => array_keys(array_filter($counts)),
            'row_counts'   => array_filter($counts),
            'weeks'        => $weeks,
            'busiest_week' => $busiest,
        ], JSON_PRETTY_PRINT));
        if ($busiest) {
            $this->info("busiest week in the template: {$busiest} ({$weeks[$busiest]} events)");
        }
        $this->info('frozen: ' . array_sum($counts) . ' rows across ' . count(array_filter($counts)) . ' tables');
    }

    /**
     * MARKER-DEMO-RESET — count dated activity per week (Monday key). Used to
     * pick, and later to let someone choose, which week the demo sits in.
     */
    private function weekActivity(string $demoId): array
    {
        $sources = [
            'tenant_appointments' => 'starts_at',
            'tenant_sales'        => 'created_at',
            'tenant_deliveries'   => 'created_at',
        ];
        $weeks = [];
        foreach ($sources as $table => $col) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)
                || ! \Illuminate\Support\Facades\Schema::hasColumn($table, $col)) continue;
            foreach (DB::table($table)->where('tenant_id', $demoId)->whereNotNull($col)->pluck($col) as $v) {
                try {
                    $k = \Carbon\CarbonImmutable::parse($v)->startOfWeek()->format('Y-m-d');
                } catch (\Throwable) { continue; }
                $weeks[$k] = ($weeks[$k] ?? 0) + 1;
            }
        }
        return $weeks;
    }

    private function leakCheck(string $demoId, array $tables): int
    {
        // MARKER-DEMO-TEMPLATE-CUSTOMERS — no samples means the anonymiser never
        // saw a customer with an email. That is a broken run, not a clean one.
        if (! $this->leakSamples) {
            $count = DB::table($this->customersTable())->where('tenant_id', $demoId)->count();
            if ($count > 0) {
                $this->error("Leak check cannot run: {$count} customers copied but none were anonymised.");
                return $count;
            }
            $this->warn('Leak check skipped: the source tenant has no customers with email addresses.');
            return 0;
        }
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
