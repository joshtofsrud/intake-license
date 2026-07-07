#!/usr/bin/env bash
# apply-sales-campaigns.sh — Sales campaigns (channels) + pipeline quoting.
# Adds the sales_channels (campaigns) resource and the prospect quote engine.
#
# PRICING SOURCES (no new price list — single source of truth):
#   tiers   -> config('intake.plan_prices')      (cents; custom/$0 excluded)
#   add-ons -> `addons` DB table                 (price_cents, included_in_plans)
# An add-on already included in the chosen tier prices at +$0, mirroring
# FeatureAccessService logic.
#
# PREREQUISITE: apply-sales-channel.sh must already be applied — this script
# edits files it created and anchors on lines it added to AdminPanelProvider.
#
# Run from the repo root:  bash apply-sales-campaigns.sh
# Idempotent: guarded on MARKER-CAMPAIGNS-QUOTE in app/Models/SalesProspect.php.
set -euo pipefail

[ -f artisan ] || { echo "ERROR: run from the Laravel repo root (artisan not found)."; exit 1; }
[ -f app/Models/SalesProspect.php ] || { echo "ERROR: run apply-sales-channel.sh first (base package missing)."; exit 1; }
if grep -q MARKER-CAMPAIGNS-QUOTE app/Models/SalesProspect.php; then
  echo "apply-sales-campaigns.sh: already applied — skipping."; exit 0
fi

echo "Applying campaigns + quoting …"

# ─────────────────────────────────────────────────────────────────────────────
# migration: sales_channels  (MARKER-CAMPAIGNS-CORE)
# ─────────────────────────────────────────────────────────────────────────────
cat > database/migrations/2026_07_07_000001_create_sales_channels_table.php <<'EOF'
<?php
// MARKER-CAMPAIGNS-CORE — Campaign/channel definitions per vertical.
// A channel carries the categories, business types, qualification criteria,
// and outreach playbook for one vertical (bike shops, salons, grooming...).
// Prospects belong to a channel; the pipeline mechanics (SalesProspect::STAGES)
// stay system-wide — a channel's `playbook` is display guidance, not new states.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_channels', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->string('slug', 140)->unique();
            $t->string('status', 20)->default('draft');   // active | draft | stub
            $t->json('categories')->nullable();            // ["Sales","Rental","Service"]
            $t->json('business_types')->nullable();        // ["Full-service shop", ...]
            $t->json('criteria')->nullable();              // [{label, note}, ...]
            $t->json('playbook')->nullable();              // ["Prospect","Verify",...] display-only
            $t->string('best_ask', 255)->nullable();
            $t->string('generated_by', 40)->nullable();    // null | 'claude'
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_channels');
    }
};
EOF
echo "  wrote migration create_sales_channels_table"

# ─────────────────────────────────────────────────────────────────────────────
# migration: prospect channel + quote fields  (MARKER-CAMPAIGNS-QUOTE)
# ─────────────────────────────────────────────────────────────────────────────
cat > database/migrations/2026_07_07_000002_add_channel_and_quote_to_sales_prospects.php <<'EOF'
<?php
// MARKER-CAMPAIGNS-QUOTE — Prospects join a channel and carry a built quote.
// quote_monthly is a snapshot-on-write (whole dollars) derived at save time
// from config('intake.plan_prices') + the addons table (design principle 13),
// so list/funnel reads never re-price.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospects', 'channel_id')) {
                $t->uuid('channel_id')->nullable()->after('id');
            }
            if (! Schema::hasColumn('sales_prospects', 'categories')) {
                $t->json('categories')->nullable()->after('type');
            }
            if (! Schema::hasColumn('sales_prospects', 'quote_tier')) {
                $t->string('quote_tier', 40)->nullable()->after('lead_score');
            }
            if (! Schema::hasColumn('sales_prospects', 'quote_addons')) {
                $t->json('quote_addons')->nullable()->after('quote_tier');   // addon codes
            }
            if (! Schema::hasColumn('sales_prospects', 'quote_monthly')) {
                $t->unsignedInteger('quote_monthly')->nullable()->after('quote_addons');
            }
        });

        Schema::table('sales_prospects', function (Blueprint $t) {
            try {
                $t->foreign('channel_id', 'sales_prospects_channel_fk')
                  ->references('id')->on('sales_channels')->nullOnDelete();
            } catch (\Throwable $e) { /* already present */ }
            try { $t->index('channel_id', 'sales_prospects_channel_index'); } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            try { $t->dropForeign('sales_prospects_channel_fk'); } catch (\Throwable $e) {}
            try { $t->dropIndex('sales_prospects_channel_index'); } catch (\Throwable $e) {}
            foreach (['channel_id', 'categories', 'quote_tier', 'quote_addons', 'quote_monthly'] as $col) {
                if (Schema::hasColumn('sales_prospects', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
EOF
echo "  wrote migration add_channel_and_quote_to_sales_prospects"

# ─────────────────────────────────────────────────────────────────────────────
# app/Models/SalesChannel.php  (MARKER-CAMPAIGNS-CORE)
# ─────────────────────────────────────────────────────────────────────────────
cat > app/Models/SalesChannel.php <<'EOF'
<?php
// MARKER-CAMPAIGNS-CORE

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A sales campaign/channel: one vertical's targeting + playbook definition.
 * Prospects keep their stage when a channel's criteria change — the channel
 * describes how to sell, the prospect records where the deal is.
 */
class SalesChannel extends Model
{
    use HasUuids;

    protected $table = 'sales_channels';

    protected $fillable = [
        'name', 'slug', 'status', 'categories', 'business_types',
        'criteria', 'playbook', 'best_ask', 'generated_by', 'notes',
    ];

    protected $casts = [
        'categories'     => 'array',
        'business_types' => 'array',
        'criteria'       => 'array',
        'playbook'       => 'array',
    ];

    public const STATUSES = [
        'active' => 'Active',
        'draft'  => 'Draft',
        'stub'   => 'Stub',
    ];

    /** Default playbook labels for a fresh channel — display guidance only. */
    public const DEFAULT_PLAYBOOK = ['Prospect', 'Verify', 'Contact', 'Demo', 'Trial', 'Won'];

    public function prospects(): HasMany
    {
        return $this->hasMany(SalesProspect::class, 'channel_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            if (blank($c->slug)) {
                $c->slug = Str::slug($c->name);
            }
        });
    }
}
EOF
echo "  wrote app/Models/SalesChannel.php"

# ─────────────────────────────────────────────────────────────────────────────
# Filament: SalesChannelResource + pages  (MARKER-CAMPAIGNS-CORE)
# ─────────────────────────────────────────────────────────────────────────────
mkdir -p app/Filament/Resources/SalesChannelResource/Pages
cat > app/Filament/Resources/SalesChannelResource.php <<'EOF'
<?php
// MARKER-CAMPAIGNS-CORE

namespace App\Filament\Resources;

use App\Filament\Resources\SalesChannelResource\Pages;
use App\Models\SalesChannel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesChannelResource extends Resource
{
    protected static ?string $model = SalesChannel::class;

    protected static ?string $navigationIcon  = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int    $navigationSort  = 1;
    protected static ?string $navigationLabel = 'Campaigns';
    protected static ?string $modelLabel      = 'campaign';
    protected static ?string $slug            = 'sales/campaigns';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Channel')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(120),
                Forms\Components\Select::make('status')
                    ->options(SalesChannel::STATUSES)->default('draft')
                    ->native(false)->required(),
                Forms\Components\TextInput::make('best_ask')
                    ->label('Best first ask')->maxLength(255)->columnSpanFull()
                    ->placeholder('e.g. 15-min owner demo at the shop'),
            ]),

            Forms\Components\Section::make('Targeting')->columns(2)->schema([
                Forms\Components\TagsInput::make('categories')
                    ->label('Categories handled')
                    ->placeholder('Sales / Rental / Service')
                    ->suggestions(['Sales', 'Rental', 'Service', 'Mobile', 'Retail']),
                Forms\Components\TagsInput::make('business_types')
                    ->label('Business types')
                    ->placeholder('Full-service shop, Mobile service…'),
            ]),

            Forms\Components\Section::make('Qualification criteria')->schema([
                Forms\Components\Repeater::make('criteria')
                    ->hiddenLabel()
                    ->schema([
                        Forms\Components\TextInput::make('label')->required()->maxLength(80),
                        Forms\Components\TextInput::make('note')->maxLength(180),
                    ])->columns(2)->defaultItems(0)->reorderable()
                    ->addActionLabel('Add criterion'),
            ])->collapsed(),

            Forms\Components\Section::make('Playbook')->columns(2)->collapsed()->schema([
                Forms\Components\TagsInput::make('playbook')
                    ->label('Stage labels (display guidance)')
                    ->default(SalesChannel::DEFAULT_PLAYBOOK)
                    ->helperText('Pipeline mechanics stay system-wide; this is the channel\'s outreach language.'),
                Forms\Components\Textarea::make('notes')->rows(3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $q) => $q->withCount('prospects'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()->sortable()->weight('semibold')
                    ->description(fn (SalesChannel $r) => $r->best_ask),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SalesChannel::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success',
                        'draft'  => 'warning',
                        default  => 'gray',
                    }),
                Tables\Columns\TextColumn::make('categories')
                    ->badge()->separator(',')->toggleable(),
                Tables\Columns\TextColumn::make('prospects_count')
                    ->label('Prospects')->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('generated_by')
                    ->label('Origin')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state) => $state === 'claude' ? '✦ Claude draft' : 'Manual')
                    ->placeholder('Manual'),
                Tables\Columns\TextColumn::make('updated_at')->since()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(SalesChannel::STATUSES),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSalesChannels::route('/'),
            'create' => Pages\CreateSalesChannel::route('/create'),
            'edit'   => Pages\EditSalesChannel::route('/{record}/edit'),
        ];
    }
}
EOF

cat > app/Filament/Resources/SalesChannelResource/Pages/ListSalesChannels.php <<'EOF'
<?php
// MARKER-CAMPAIGNS-CORE

namespace App\Filament\Resources\SalesChannelResource\Pages;

use App\Filament\Resources\SalesChannelResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSalesChannels extends ListRecords
{
    protected static string $resource = SalesChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
EOF

cat > app/Filament/Resources/SalesChannelResource/Pages/CreateSalesChannel.php <<'EOF'
<?php
// MARKER-CAMPAIGNS-CORE

namespace App\Filament\Resources\SalesChannelResource\Pages;

use App\Filament\Resources\SalesChannelResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesChannel extends CreateRecord
{
    protected static string $resource = SalesChannelResource::class;
}
EOF

cat > app/Filament/Resources/SalesChannelResource/Pages/EditSalesChannel.php <<'EOF'
<?php
// MARKER-CAMPAIGNS-CORE

namespace App\Filament\Resources\SalesChannelResource\Pages;

use App\Filament\Resources\SalesChannelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSalesChannel extends EditRecord
{
    protected static string $resource = SalesChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
EOF
echo "  wrote SalesChannelResource + 3 pages"

# ─────────────────────────────────────────────────────────────────────────────
# seeder  (MARKER-CAMPAIGNS-SEEDER)
# ─────────────────────────────────────────────────────────────────────────────
cat > database/seeders/SalesChannelSeeder.php <<'EOF'
<?php
// MARKER-CAMPAIGNS-SEEDER — Bike shops channel (active) + Salons (draft).
// Idempotent on slug. Also folds all channel-less prospects into the bike
// channel, so the existing WA/national book lands in the right campaign.

namespace Database\Seeders;

use App\Models\SalesChannel;
use App\Models\SalesProspect;
use Illuminate\Database\Seeder;

class SalesChannelSeeder extends Seeder
{
    public function run(): void
    {
        $bike = SalesChannel::firstOrCreate(['slug' => 'bike-shops'], [
            'name' => 'Bike shops',
            'status' => 'active',
            'categories' => ['Sales', 'Rental', 'Service'],
            'business_types' => ['Full-service shop', 'Service-only', 'Mobile service', 'Rental / resort', 'Boutique / custom'],
            'criteria' => [
                ['label' => 'Service revenue share', 'note' => 'Books repair work — Intake\'s core wedge'],
                ['label' => 'Owner-operated', 'note' => 'Decision-maker reachable at the counter'],
                ['label' => 'Software pain', 'note' => 'On paper, spreadsheets, or legacy POS'],
                ['label' => 'Rental exposure', 'note' => 'Fleet management is a strong add-on hook'],
            ],
            'playbook' => SalesChannel::DEFAULT_PLAYBOOK,
            'best_ask' => '15-min owner demo at the shop',
        ]);

        SalesChannel::firstOrCreate(['slug' => 'salons'], [
            'name' => 'Salons',
            'status' => 'draft',
            'categories' => ['Service', 'Retail'],
            'business_types' => ['Full-service salon', 'Barber shop', 'Booth-rental suite', 'Spa hybrid'],
            'criteria' => [
                ['label' => 'Chair count 3+', 'note' => 'Enough volume for scheduling pain'],
                ['label' => 'Booth rental mix', 'note' => 'Rent tracking maps to rental module'],
                ['label' => 'Retail shelf', 'note' => 'Product sales use inventory + POS'],
                ['label' => 'Walk-in heavy', 'note' => 'Needs the register + capacity tools'],
            ],
            'playbook' => SalesChannel::DEFAULT_PLAYBOOK,
            'best_ask' => 'Demo between appointments, mid-week',
        ]);

        $folded = SalesProspect::whereNull('channel_id')->update(['channel_id' => $bike->id]);
        $this->command?->info("Channels seeded. Folded {$folded} channel-less prospects into Bike shops.");
    }
}
EOF
echo "  wrote database/seeders/SalesChannelSeeder.php"

# ─────────────────────────────────────────────────────────────────────────────
# Anchored edits: SalesProspect model, SalesProspectResource, AdminPanelProvider
# ─────────────────────────────────────────────────────────────────────────────
python3 - <<'PYEOF'
def rd(p):
    with open(p, encoding="utf-8") as f: return f.read()
def wr(p, s):
    with open(p, "w", encoding="utf-8") as f: f.write(s)
def edit(p, old, new):
    s = rd(p); n = s.count(old)
    assert n == 1, f"ANCHOR count={n} in {p} (expected 1) for: {old[:60]!r}"
    wr(p, s.replace(old, new, 1)); print(f"  edited {p}")

M = "app/Models/SalesProspect.php"

# import: DB facade for addon pricing lookups
edit(M,
"use Illuminate\\Database\\Eloquent\\Relations\\HasMany;",
"use Illuminate\\Database\\Eloquent\\Relations\\HasMany;\nuse Illuminate\\Support\\Facades\\DB;")

# fillable: channel + quote fields
edit(M,
"        'tenant_id',\n        'owner_contact'",
"        'tenant_id',\n        'channel_id', 'categories', 'quote_tier', 'quote_addons', 'quote_monthly',\n        'owner_contact'")

# casts
edit(M,
"        'rating_count'      => 'integer',\n",
"        'rating_count'      => 'integer',\n        'categories'        => 'array',\n        'quote_addons'      => 'array',\n        'quote_monthly'     => 'integer',\n")

# relations + quote engine after tenant()
edit(M,
"""    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
""",
"""    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // MARKER-CAMPAIGNS-QUOTE — channel + quote
    /** Reference rate until the agencies/commission build owns this. */
    public const COMMISSION_YEAR1 = 0.25;

    public function channel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'channel_id');
    }

    /**
     * Quote = tier base + add-ons, in whole dollars. Prices come from the
     * platform's real sources: config('intake.plan_prices') (cents) and the
     * `addons` table. An add-on whose included_in_plans covers the chosen
     * tier prices at +$0 — same rule FeatureAccessService applies.
     */
    public function computeQuoteMonthly(): ?int
    {
        if (! $this->quote_tier) {
            return null;
        }
        $plans = config('intake.plan_prices', []);
        if (! isset($plans[$this->quote_tier])) {
            return null;
        }
        $sum = (int) round(((int) $plans[$this->quote_tier]) / 100);

        $selected = (array) $this->quote_addons;
        if ($selected !== []) {
            $rows = DB::table('addons')
                ->whereIn('code', $selected)
                ->get(['code', 'price_cents', 'included_in_plans']);
            foreach ($rows as $a) {
                $included = in_array(
                    $this->quote_tier,
                    (array) json_decode($a->included_in_plans ?? '[]', true),
                    true
                );
                if (! $included) {
                    $sum += (int) round(((int) $a->price_cents) / 100);
                }
            }
        }
        return $sum;
    }

    protected static function booted(): void
    {
        // Snapshot-on-write: quote_monthly is always derived, never hand-set.
        static::saving(function (self $p) {
            $p->quote_monthly = $p->computeQuoteMonthly();
        });
    }
""")

R = "app/Filament/Resources/SalesProspectResource.php"

# form: channel + categories in the Qualification section, before the stage select
edit(R,
"                Forms\\Components\\Select::make('stage')\n",
"""                Forms\\Components\\Select::make('channel_id')
                    ->label('Channel')
                    ->options(fn () => \\App\\Models\\SalesChannel::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->native(false)->placeholder('\u2014 channel \u2014'),
                Forms\\Components\\TagsInput::make('categories')
                    ->label('Categories handled')
                    ->placeholder('Sales / Rental / Service')
                    ->suggestions(['Sales', 'Rental', 'Service', 'Mobile', 'Retail']),
                Forms\\Components\\Select::make('stage')
""")

# form: Quote section before Contact — priced from plan_prices + addons table
edit(R,
"            Forms\\Components\\Section::make('Contact')",
"""            Forms\\Components\\Section::make('Quote')->columns(2)->collapsed()
                ->description('Proposed subscription \u2014 tier + add-ons. Priced from plan_prices and the addons table; monthly total snapshots on save.')
                ->schema([
                    Forms\\Components\\Select::make('quote_tier')
                        ->label('Base tier')->live()->native(false)
                        ->options(fn () => collect(config('intake.plan_prices', []))
                            ->filter(fn ($cents) => (int) $cents > 0)
                            ->map(fn ($cents, $key) => ucfirst($key) . ' \u2014 $' . number_format($cents / 100) . '/mo')
                            ->all())
                        ->placeholder('\u2014 no quote yet \u2014'),
                    Forms\\Components\\CheckboxList::make('quote_addons')
                        ->label('Add-ons')->live()
                        ->options(function (\\Filament\\Forms\\Get $get) {
                            $tier = $get('quote_tier');
                            return \\Illuminate\\Support\\Facades\\DB::table('addons')
                                ->where('status', 'active')
                                ->orderBy('sort_order')
                                ->get(['code', 'name', 'price_cents', 'included_in_plans'])
                                ->mapWithKeys(function ($a) use ($tier) {
                                    $included = $tier && in_array($tier, (array) json_decode($a->included_in_plans ?? '[]', true), true);
                                    $label = $a->name . ($included
                                        ? ' \u2014 included in tier'
                                        : ' (+$' . number_format($a->price_cents / 100) . '/mo)');
                                    return [$a->code => $label];
                                })->all();
                        }),
                    Forms\\Components\\Placeholder::make('quote_total')
                        ->label('Proposed monthly')->columnSpanFull()
                        ->content(function (\\Filament\\Forms\\Get $get) {
                            $plans = config('intake.plan_prices', []);
                            $tier  = $get('quote_tier');
                            if (! $tier || empty($plans[$tier])) {
                                return '\u2014';
                            }
                            $sum = (int) round(((int) $plans[$tier]) / 100);
                            $selected = (array) $get('quote_addons');
                            if ($selected !== []) {
                                $rows = \\Illuminate\\Support\\Facades\\DB::table('addons')
                                    ->whereIn('code', $selected)
                                    ->get(['code', 'price_cents', 'included_in_plans']);
                                foreach ($rows as $a) {
                                    $included = in_array($tier, (array) json_decode($a->included_in_plans ?? '[]', true), true);
                                    if (! $included) {
                                        $sum += (int) round(((int) $a->price_cents) / 100);
                                    }
                                }
                            }
                            $rate = SalesProspect::COMMISSION_YEAR1;
                            return '$' . number_format($sum) . '/mo  \u00b7  yr-1 commission @ ' . ($rate * 100) . '% \u2248 $' . number_format($sum * $rate, 2) . '/mo';
                        }),
                ]),

            Forms\\Components\\Section::make('Contact')""")

# table: quote column before lead_score
edit(R,
"                Tables\\Columns\\TextColumn::make('lead_score')",
"""                Tables\\Columns\\TextColumn::make('quote_monthly')
                    ->label('Quote')->alignEnd()->sortable()->toggleable()
                    ->formatStateUsing(fn ($state, SalesProspect $r) => $state
                        ? '$' . number_format($state) . '/mo' . (count((array) $r->quote_addons) ? ' \u00b7 ' . count((array) $r->quote_addons) . ' add-on' . (count((array) $r->quote_addons) > 1 ? 's' : '') : '')
                        : null)
                    ->placeholder('\u2014'),

                Tables\\Columns\\TextColumn::make('lead_score')""")

# table: channel filter before loop filter
edit(R,
"                Tables\\Filters\\SelectFilter::make('loop')->options(SalesProspect::LOOPS),",
"""                Tables\\Filters\\SelectFilter::make('channel_id')
                    ->label('Channel')
                    ->options(fn () => \\App\\Models\\SalesChannel::query()->orderBy('name')->pluck('name', 'id')->all()),
                Tables\\Filters\\SelectFilter::make('loop')->options(SalesProspect::LOOPS),""")

# panel registration — anchors on lines the base package added
P = "app/Providers/Filament/AdminPanelProvider.php"
s = rd(P)
if "MARKER-CAMPAIGNS-REGISTER" in s:
    print("  panel registration already applied — skipping.")
else:
    edit(P,
    "use App\\Filament\\Resources\\SalesProspectResource;",
    "use App\\Filament\\Resources\\SalesChannelResource; // MARKER-CAMPAIGNS-REGISTER\nuse App\\Filament\\Resources\\SalesProspectResource;")
    edit(P,
    "                SalesProspectResource::class,",
    "                SalesChannelResource::class, // MARKER-CAMPAIGNS-REGISTER\n                SalesProspectResource::class,")

print("All anchored edits applied.")
PYEOF

echo ""
echo "Done. Next:"
echo "  composer dump-autoload && php artisan migrate --force && php artisan db:seed --class=SalesChannelSeeder && php artisan filament:cache-components && php artisan optimize:clear"
