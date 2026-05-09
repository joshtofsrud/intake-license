#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# Intake — Roadmap schema + UI improvements
# Adds: tier (1-4), shipped_on (date), target_month (date)
# Updates: RoadmapEntry model, Filament resource form/table, importer parser,
#          sample YAML
#
# Public-page render: deferred to a follow-up. This patch ONLY touches admin
# surfaces and the schema.
#
# Usage on Mac (run from project root):
#   bash intake-roadmap-schema-patch.sh
#
# Idempotent: re-running is safe. Each step checks before writing.
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail
[ -f artisan ] || { echo "ABORT: not a Laravel root"; exit 1; }
[ -f app/Models/RoadmapEntry.php ] || { echo "ABORT: RoadmapEntry model missing"; exit 1; }
[ -f app/Filament/Resources/RoadmapEntryResource.php ] || { echo "ABORT: RoadmapEntryResource missing"; exit 1; }

# ──────────────────────────────────────────────────────────────────────────────
# 1. New migration — add columns. Backfill shipped_on for existing shipped rows.
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Creating migration"

MIGRATION_PATH="database/migrations/2026_05_09_000002_add_tier_dates_to_roadmap_entries.php"

if [ -f "$MIGRATION_PATH" ]; then
  echo "    skip: migration already exists at $MIGRATION_PATH"
else
  cat > "$MIGRATION_PATH" <<'PHP_FILE'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap richness pass:
 *   - tier (1-4)               — mirrors internal Tier 1-4 framework
 *   - shipped_on (date)        — when shipped status actually happened
 *   - target_month (date)      — first-of-month for "Targeting July 2026" copy
 *
 * `rough_timeframe` stays for backward compat + items that genuinely don't have
 * a target month ("considering" items, "when X" framings).
 *
 * Backfill: any existing row with status='shipped' gets shipped_on = created_at::date
 * as a best-guess. Manual cleanup welcome but not required.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('roadmap_entries', function (Blueprint $t) {
            $t->unsignedTinyInteger('tier')->nullable()->after('status');
            $t->date('shipped_on')->nullable()->after('rough_timeframe');
            $t->date('target_month')->nullable()->after('shipped_on');

            $t->index(['status', 'tier']);
            $t->index('shipped_on');
        });

        // One-time best-guess backfill: shipped rows get shipped_on from created_at.
        DB::statement("
            UPDATE roadmap_entries
               SET shipped_on = DATE(created_at)
             WHERE status = 'shipped'
               AND shipped_on IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('roadmap_entries', function (Blueprint $t) {
            $t->dropIndex(['status', 'tier']);
            $t->dropIndex(['shipped_on']);
            $t->dropColumn(['tier', 'shipped_on', 'target_month']);
        });
    }
};
PHP_FILE
  echo "    wrote: $MIGRATION_PATH"
fi

# ──────────────────────────────────────────────────────────────────────────────
# 2. Update RoadmapEntry model — fillable, casts, helper methods
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Updating RoadmapEntry model"

python3 <<'PY'
from pathlib import Path
p = Path("app/Models/RoadmapEntry.php")
s = p.read_text()

# Idempotency check.
if "displayTimeframe" in s:
    print("    skip: model already updated")
else:
    old = """    protected $fillable = [
        'status', 'title', 'category', 'body',
        'rough_timeframe', 'display_order', 'is_published',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'is_published'  => 'boolean',
    ];

    public function scopePublished($q) { return $q->where('is_published', true); }

    public const STATUSES = [
        'shipped'      => 'Shipped',
        'in_progress'  => 'In progress',
        'next_up'      => 'Next up',
        'considering'  => 'Considering',
    ];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucwords(str_replace('_', ' ', $this->status));
    }
}"""

    new = """    protected $fillable = [
        'status', 'tier', 'title', 'category', 'body',
        'rough_timeframe', 'shipped_on', 'target_month',
        'display_order', 'is_published',
    ];

    protected $casts = [
        'tier'          => 'integer',
        'shipped_on'    => 'date',
        'target_month'  => 'date',
        'display_order' => 'integer',
        'is_published'  => 'boolean',
    ];

    public function scopePublished($q) { return $q->where('is_published', true); }

    public const STATUSES = [
        'shipped'      => 'Shipped',
        'in_progress'  => 'In progress',
        'next_up'      => 'Next up',
        'considering'  => 'Considering',
    ];

    public const TIERS = [
        1 => 'T1 — Launch blockers',
        2 => 'T2 — Engagement',
        3 => 'T3 — Onboarding',
        4 => 'T4 — Growth',
    ];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucwords(str_replace('_', ' ', $this->status));
    }

    public function tierLabel(): ?string
    {
        return $this->tier ? (self::TIERS[$this->tier] ?? null) : null;
    }

    /**
     * Public-friendly timeframe string. Prefers shipped date, then target month,
     * then rough_timeframe. Returns null if nothing is set.
     */
    public function displayTimeframe(): ?string
    {
        if ($this->status === 'shipped' && $this->shipped_on) {
            return $this->shipped_on->format('M j, Y');
        }
        if ($this->target_month) {
            return 'Targeting ' . $this->target_month->format('F Y');
        }
        return $this->rough_timeframe;
    }
}"""

    assert s.count(old) == 1, f"ABORT: model pattern matched {s.count(old)} times"
    p.write_text(s.replace(old, new))
    print("    patched: RoadmapEntry.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# 3. Update RoadmapEntryResource — form fields + table columns + sort order
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Updating RoadmapEntryResource (Filament)"

python3 <<'PY'
from pathlib import Path
p = Path("app/Filament/Resources/RoadmapEntryResource.php")
s = p.read_text()

if "target_month" in s:
    print("    skip: resource already updated")
else:
    # Replace the form schema
    old_form = """    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\\Components\\Section::make('Entry')
                ->schema([
                    Forms\\Components\\Select::make('status')
                        ->required()
                        ->options(RoadmapEntry::STATUSES)
                        ->default('next_up')
                        ->native(false),

                    Forms\\Components\\TextInput::make('title')
                        ->required()
                        ->maxLength(191),

                    Forms\\Components\\Select::make('category')
                        ->options([
                            'Calendar' => 'Calendar',
                            'Booking'  => 'Booking',
                            'Stripe'   => 'Stripe',
                            'Customer' => 'Customer',
                            'Workflow' => 'Workflow',
                            'Mobile'   => 'Mobile',
                            'Polish'   => 'Polish',
                        ])
                        ->placeholder('Optional category tag'),

                    Forms\\Components\\Textarea::make('body')
                        ->required()
                        ->rows(5)
                        ->helperText('Public-friendly framing. What this means for the shop, not internal scope details.'),

                    Forms\\Components\\TextInput::make('rough_timeframe')
                        ->maxLength(64)
                        ->placeholder('this week / Q2 / when X')
                        ->helperText('Loose timing. Skip if you don\\'t want to commit. Never give a hard date for unshipped work.'),

                    Forms\\Components\\TextInput::make('display_order')
                        ->numeric()
                        ->default(0)
                        ->helperText('Manual sort within a status. Lower numbers come first.'),

                    Forms\\Components\\Toggle::make('is_published')
                        ->helperText('Visitors only see published entries.'),
                ]),
        ]);
    }"""

    new_form = """    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\\Components\\Section::make('Entry')
                ->schema([
                    Forms\\Components\\Grid::make(2)->schema([
                        Forms\\Components\\Select::make('status')
                            ->required()
                            ->options(RoadmapEntry::STATUSES)
                            ->default('next_up')
                            ->live()
                            ->native(false),

                        Forms\\Components\\Select::make('tier')
                            ->options(RoadmapEntry::TIERS)
                            ->placeholder('— uncategorized —')
                            ->native(false)
                            ->helperText('Internal grouping. Hidden from public roadmap.'),
                    ]),

                    Forms\\Components\\TextInput::make('title')
                        ->required()
                        ->maxLength(191),

                    Forms\\Components\\Select::make('category')
                        ->options([
                            'Calendar' => 'Calendar',
                            'Booking'  => 'Booking',
                            'Stripe'   => 'Stripe',
                            'Customer' => 'Customer',
                            'Workflow' => 'Workflow',
                            'Mobile'   => 'Mobile',
                            'Polish'   => 'Polish',
                        ])
                        ->placeholder('Optional category tag'),

                    Forms\\Components\\Textarea::make('body')
                        ->required()
                        ->rows(5)
                        ->helperText('Public-friendly framing. What this means for the shop, not internal scope details.'),

                    Forms\\Components\\Grid::make(2)->schema([
                        Forms\\Components\\DatePicker::make('shipped_on')
                            ->label('Shipped on')
                            ->native(false)
                            ->visible(fn (\\Filament\\Forms\\Get $get) => $get('status') === 'shipped')
                            ->helperText('When this actually shipped. Required for shipped items.'),

                        Forms\\Components\\DatePicker::make('target_month')
                            ->label('Target month')
                            ->native(false)
                            ->displayFormat('F Y')
                            ->format('Y-m-01')
                            ->visible(fn (\\Filament\\Forms\\Get $get) => $get('status') !== 'shipped')
                            ->helperText('Pick any date in the target month. Displays as "July 2026" publicly.'),
                    ]),

                    Forms\\Components\\TextInput::make('rough_timeframe')
                        ->label('Rough timeframe (fallback)')
                        ->maxLength(64)
                        ->placeholder('soon / when X / no timeline')
                        ->helperText('Used when target month is too committal. Most useful on "considering" items.'),

                    Forms\\Components\\TextInput::make('display_order')
                        ->numeric()
                        ->default(0)
                        ->helperText('Manual sort within a status. Lower numbers come first.'),

                    Forms\\Components\\Toggle::make('is_published')
                        ->helperText('Visitors only see published entries.'),
                ]),
        ]);
    }"""

    assert s.count(old_form) == 1, f"ABORT: form pattern matched {s.count(old_form)} times"
    s = s.replace(old_form, new_form)

    # Replace the table — add tier column, replace timeframe column with smarter display,
    # add status-aware sort logic via ->modifyQueryUsing.
    old_table = """    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\\Columns\\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => RoadmapEntry::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match($state) {
                        'shipped'      => 'success',
                        'in_progress'  => 'warning',
                        'next_up'      => 'info',
                        'considering'  => 'gray',
                        default        => 'gray',
                    }),
                Tables\\Columns\\TextColumn::make('title')->searchable()->limit(60),
                Tables\\Columns\\TextColumn::make('category')->badge(),
                Tables\\Columns\\TextColumn::make('rough_timeframe')->label('Timing'),
                Tables\\Columns\\TextColumn::make('display_order')->label('Order')->sortable(),
                Tables\\Columns\\ToggleColumn::make('is_published')->label('Pub'),
            ])
            ->defaultSort('display_order')"""

    new_table = """    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\\Columns\\TextColumn::make('tier')
                    ->label('Tier')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? \"T{$state}\" : '—')
                    ->color(fn ($state) => match($state) {
                        1 => 'info',
                        2 => 'success',
                        3 => 'warning',
                        4 => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\\Columns\\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => RoadmapEntry::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match($state) {
                        'shipped'      => 'success',
                        'in_progress'  => 'warning',
                        'next_up'      => 'info',
                        'considering'  => 'gray',
                        default        => 'gray',
                    }),
                Tables\\Columns\\TextColumn::make('title')->searchable()->limit(60),
                Tables\\Columns\\TextColumn::make('category')->badge(),
                Tables\\Columns\\TextColumn::make('display_timeframe')
                    ->label('When')
                    ->state(fn (RoadmapEntry $record) => $record->displayTimeframe() ?? '—'),
                Tables\\Columns\\TextColumn::make('display_order')->label('Order')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\\Columns\\ToggleColumn::make('is_published')->label('Pub'),
            ])
            ->defaultSort('display_order')
            ->modifyQueryUsing(function (\\Illuminate\\Database\\Eloquent\\Builder $query) {
                // Status-aware multi-key ordering:
                //   shipped       → newest ship first
                //   in_progress   → target_month ascending
                //   next_up       → tier ASC, then target_month, then display_order
                //   considering   → display_order
                return $query->orderByRaw("
                    FIELD(status, 'in_progress', 'next_up', 'considering', 'shipped'),
                    CASE WHEN status = 'shipped'   THEN shipped_on   END DESC,
                    CASE WHEN status = 'next_up'   THEN tier         END ASC,
                    CASE WHEN status IN ('in_progress','next_up') THEN target_month END ASC,
                    display_order ASC
                ");
            })"""

    assert s.count(old_table) == 1, f"ABORT: table pattern matched {s.count(old_table)} times"
    s = s.replace(old_table, new_table)

    # Add tier filter alongside the existing filters.
    old_filters = """            ->filters([
                Tables\\Filters\\TernaryFilter::make('is_published'),
                Tables\\Filters\\SelectFilter::make('status')->options(RoadmapEntry::STATUSES),
            ])"""

    new_filters = """            ->filters([
                Tables\\Filters\\TernaryFilter::make('is_published'),
                Tables\\Filters\\SelectFilter::make('status')->options(RoadmapEntry::STATUSES),
                Tables\\Filters\\SelectFilter::make('tier')->options(RoadmapEntry::TIERS),
            ])"""

    assert s.count(old_filters) == 1, f"ABORT: filters pattern matched {s.count(old_filters)} times"
    s = s.replace(old_filters, new_filters)

    p.write_text(s)
    print("    patched: RoadmapEntryResource.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# 4. Update importer parser — accept new fields
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Updating ChangelogRoadmapImporter parser"

python3 <<'PY'
from pathlib import Path
import re
p = Path("app/Services/Platform/ChangelogRoadmapImporter.php")
s = p.read_text()

if "target_month" in s:
    print("    skip: importer already updated")
else:
    old = """        $incoming = [
            'status'          => $status,
            'title'           => Str::limit((string) $entry['title'], 191, ''),
            'category'        => isset($entry['category']) ? (string) $entry['category'] : null,
            'body'            => (string) $entry['body'],
            'rough_timeframe' => isset($entry['timeframe']) ? Str::limit((string) $entry['timeframe'], 64, '') : null,
            'display_order'   => (int) ($entry['order'] ?? 0),
            'is_published'    => false,
        ];"""

    new = """        // Optional date fields — same int/DateTime/string defense as changelog.
        $shippedOn = $this->coerceDate($entry['shipped_on'] ?? null);
        $targetMonth = $this->coerceDate($entry['target_month'] ?? null);
        // Force target_month to first-of-month for clean grouping.
        if ($targetMonth) {
            $targetMonth = Carbon::parse($targetMonth)->startOfMonth()->toDateString();
        }

        // Optional tier (1-4)
        $tier = isset($entry['tier']) ? (int) $entry['tier'] : null;
        if ($tier !== null && ($tier < 1 || $tier > 4)) {
            return ['bucket' => 'errors', 'payload' => [
                'line'    => null,
                'message' => \"Entry #{$entryNum}: invalid tier '{$entry['tier']}'. Must be 1-4.\",
                'raw'     => $entry,
            ]];
        }

        // Shipped items must have a shipped_on date.
        if ($status === 'shipped' && ! $shippedOn) {
            return ['bucket' => 'errors', 'payload' => [
                'line'    => null,
                'message' => \"Entry #{$entryNum}: status='shipped' requires shipped_on date.\",
                'raw'     => $entry,
            ]];
        }

        $incoming = [
            'status'          => $status,
            'tier'            => $tier,
            'title'           => Str::limit((string) $entry['title'], 191, ''),
            'category'        => isset($entry['category']) ? (string) $entry['category'] : null,
            'body'            => (string) $entry['body'],
            'rough_timeframe' => isset($entry['timeframe']) ? Str::limit((string) $entry['timeframe'], 64, '') : null,
            'shipped_on'      => $shippedOn,
            'target_month'    => $targetMonth,
            'display_order'   => (int) ($entry['order'] ?? 0),
            'is_published'    => false,
        ];"""

    assert s.count(old) == 1, f"ABORT: importer build-incoming pattern matched {s.count(old)} times"
    s = s.replace(old, new)

    # Update diff comparison to include new fields.
    old_diff = """        $diff = $this->diff($existing, $incoming, ['category', 'body', 'rough_timeframe', 'display_order']);"""
    new_diff = """        $diff = $this->diff($existing, $incoming, ['tier', 'category', 'body', 'rough_timeframe', 'shipped_on', 'target_month', 'display_order']);"""
    assert s.count(old_diff) == 1, f"ABORT: diff pattern matched {s.count(old_diff)} times"
    s = s.replace(old_diff, new_diff)

    # Update existing-row capture to include new fields so the diff can compare them.
    old_existing = """        $existing = [
            'id'              => $existingModel->id,
            'status'          => $existingModel->status,
            'title'           => $existingModel->title,
            'category'        => $existingModel->category,
            'body'            => $existingModel->body,
            'rough_timeframe' => $existingModel->rough_timeframe,
            'display_order'   => $existingModel->display_order,
            'is_published'    => $existingModel->is_published,
        ];"""

    new_existing = """        $existing = [
            'id'              => $existingModel->id,
            'status'          => $existingModel->status,
            'tier'            => $existingModel->tier,
            'title'           => $existingModel->title,
            'category'        => $existingModel->category,
            'body'            => $existingModel->body,
            'rough_timeframe' => $existingModel->rough_timeframe,
            'shipped_on'      => $existingModel->shipped_on?->toDateString(),
            'target_month'    => $existingModel->target_month?->toDateString(),
            'display_order'   => $existingModel->display_order,
            'is_published'    => $existingModel->is_published,
        ];"""

    assert s.count(old_existing) == 1, f"ABORT: existing-row pattern matched {s.count(old_existing)} times"
    s = s.replace(old_existing, new_existing)

    # Add the coerceDate helper just before the diff() helper.
    old_diff_helper = """    private function diff(array $existing, array $incoming, array $compareKeys): array"""
    new_diff_helper = """    /** Coerce YAML int (epoch), DateTime, or string into a YYYY-MM-DD or null. */
    private function coerceDate($raw): ?string
    {
        if ($raw === null || $raw === '') return null;
        try {
            if ($raw instanceof \\DateTimeInterface) {
                return Carbon::instance($raw)->toDateString();
            }
            if (is_int($raw)) {
                return Carbon::createFromTimestampUTC($raw)->toDateString();
            }
            return Carbon::parse((string) $raw)->toDateString();
        } catch (\\Throwable $e) {
            return null;
        }
    }

    private function diff(array $existing, array $incoming, array $compareKeys): array"""

    assert s.count(old_diff_helper) == 1, f"ABORT: diff-helper anchor matched {s.count(old_diff_helper)} times"
    s = s.replace(old_diff_helper, new_diff_helper)

    p.write_text(s)
    print("    patched: ChangelogRoadmapImporter.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# 5. Update sample roadmap YAML to use new fields
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Refreshing sample roadmap YAML"

cat > storage/changelog-import-samples/roadmap-2026-05-09.yml <<'YAML_FILE'
# Sample roadmap import file.
# Upload via Master Admin → Roadmap → Import from file.
#
# Required: status, title, body
# Optional: tier (1-4), category, timeframe, order, shipped_on, target_month
# Valid statuses: shipped, in_progress, next_up, considering
# Tier 1 = launch blockers · Tier 2 = engagement · Tier 3 = onboarding · Tier 4 = growth

entries:
  # Shipped — May 9 work
  - status: shipped
    tier: 1
    title: Payment ledger architecture
    category: Stripe
    shipped_on: '2026-05-09'
    body: |
      Every dollar through the register, every dollar gets a ledger row.
      New tenant_appointment_payments table tracks deposits, balance
      payments, refunds, and overage refunds. Future-proofed for Stripe
      Connect partial payments via the tax_locked flag.

  - status: shipped
    tier: 4
    title: Sale detail modal
    category: Workflow
    shipped_on: '2026-05-09'
    body: |
      Click any sale row in register history or customer activity to see
      details — line items, totals, payment method, refund button.

  - status: shipped
    tier: 4
    title: Settings page restyle
    category: Polish
    shipped_on: '2026-05-09'
    body: |
      Six-tab unified page (Business, Branding, Communication, Account,
      Appearance, Payments) replaces the old multi-page split.

  - status: shipped
    tier: 4
    title: Products & Add-ons on appointments
    category: Workflow
    shipped_on: '2026-05-09'
    body: |
      Service appointments can now bill parts from inventory. Each line
      shows stock impact. Inventory commits on Completed status.

  # In progress
  - status: in_progress
    tier: 1
    title: Stripe Connect onboarding & deposits
    category: Stripe
    target_month: '2026-05-01'
    order: 1
    body: |
      Connect account onboarding for tenants. Webhook for partial-payment
      deposits arriving with their own tax allocation. Reconciliation. The
      actual last-step launch blocker.

  # Next up
  - status: next_up
    tier: 1
    title: Refund modal wiring
    category: Stripe
    timeframe: ~30 min
    order: 1
    body: |
      Wire the existing sale-detail modal's "Refund this sale" button to
      AppointmentPaymentService::refund(). Creates a refund ledger row
      tied to the original payment.

  - status: next_up
    tier: 1
    title: Trial → paid conversion flow
    category: Stripe
    target_month: '2026-06-01'
    order: 2
    body: |
      14-day free trial, no card required at signup. Conversion banners on
      day 14 and day 2. Stripe checkout on convert.

  - status: next_up
    tier: 2
    title: Abandoned booking recovery
    category: Customer
    target_month: '2026-06-01'
    order: 3
    body: |
      Auto-reach out to customers who started a booking but didn't
      complete it. Pre-built email sequences per industry.

  # Considering — explicit non-commitment
  - status: considering
    tier: 4
    title: Native iOS/Android apps
    timeframe: only if PWA demand signals warrant
    body: |
      Building native apps is expensive. PWA delivers 85% of native UX at
      5% of the cost. We'll only build native if PWA usage is high and shops
      repeatedly request it past 250 paying customers.
YAML_FILE

echo "    wrote: storage/changelog-import-samples/roadmap-2026-05-09.yml"

# ──────────────────────────────────────────────────────────────────────────────
# Lint everything we touched
# ──────────────────────────────────────────────────────────────────────────────
echo ""
echo "==> Linting modified PHP files"
for f in \
  "$MIGRATION_PATH" \
  app/Models/RoadmapEntry.php \
  app/Filament/Resources/RoadmapEntryResource.php \
  app/Services/Platform/ChangelogRoadmapImporter.php; do
  if command -v php >/dev/null 2>&1; then
    php -l "$f"
  else
    echo "    (php not available — skipping lint of $f)"
  fi
done

echo ""
echo "==> Patch complete."
echo ""
echo "Files modified/created:"
echo "  $MIGRATION_PATH"
echo "  app/Models/RoadmapEntry.php"
echo "  app/Filament/Resources/RoadmapEntryResource.php"
echo "  app/Services/Platform/ChangelogRoadmapImporter.php"
echo "  storage/changelog-import-samples/roadmap-2026-05-09.yml"
echo ""
echo "Next steps:"
echo "  git add -A && git commit -m 'Roadmap: tier + shipped_on + target_month + smarter sort'"
echo "  git push"
echo ""
echo "Then on the server:"
echo "  cd /var/www/intake && git pull"
echo "  php artisan migrate --force"
echo "  php artisan optimize:clear"
echo "  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm"
