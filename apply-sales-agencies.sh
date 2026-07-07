#!/usr/bin/env bash
# apply-sales-agencies.sh — Rep agencies: schema + attribution + master-admin resource.
#
# Adds:
#   sales_agencies / sales_reps tables (per-agency commission rates live here)
#   agency_id + sales_rep_id attribution on sales_prospects (deal registration)
#   SalesAgencyResource (Sales nav group) with a Reps relation manager
#   SalesAgencySeeder — Modus Sport Group + Alex G (principal), Alex, Adam, Nick, Jordan
#
# Deliberately NOT here (chunk 2): the /rep login panel and the commission ledger.
#
# PREREQUISITE: apply-sales-channel.sh + apply-sales-campaigns.sh applied.
# Run from the repo root:  bash apply-sales-agencies.sh
# Idempotent: guarded on MARKER-AGENCIES-ATTR in app/Models/SalesProspect.php.
set -euo pipefail

[ -f artisan ] || { echo "ERROR: run from the Laravel repo root."; exit 1; }
grep -q MARKER-CAMPAIGNS-QUOTE app/Models/SalesProspect.php 2>/dev/null || { echo "ERROR: run apply-sales-campaigns.sh first."; exit 1; }
if grep -q MARKER-AGENCIES-ATTR app/Models/SalesProspect.php; then
  echo "apply-sales-agencies.sh: already applied — skipping."; exit 0
fi

echo "Applying agencies + attribution …"

# ─────────────────────────────────────────────────────────────────────────────
# migration: sales_agencies + sales_reps  (MARKER-AGENCIES-CORE)
# ─────────────────────────────────────────────────────────────────────────────
cat > database/migrations/2026_07_07_000003_create_sales_agencies_and_reps.php <<'EOF'
<?php
// MARKER-AGENCIES-CORE — Rep agencies and their reps.
// Commission rates live PER AGENCY (not a global constant) so different groups
// can carry different terms. The ledger build (chunk 2) reads these.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_agencies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->string('slug', 140)->unique();
            $t->string('status', 20)->default('onboarding');   // active | onboarding | paused
            $t->decimal('commission_year1', 5, 4)->default(0.2500);   // of collected revenue, account age < 12mo
            $t->decimal('commission_residual', 5, 4)->default(0.1000); // account age >= 12mo
            $t->boolean('deal_registration')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index('status');
        });

        Schema::create('sales_reps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('agency_id');
            $t->string('name', 120);
            $t->string('role', 20)->default('rep');            // principal | rep
            $t->string('email', 191)->nullable();
            $t->string('phone', 64)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();     // /rep panel login, wired in chunk 2
            $t->string('status', 20)->default('active');       // active | inactive
            $t->timestamps();

            $t->foreign('agency_id', 'sales_reps_agency_fk')
              ->references('id')->on('sales_agencies')->cascadeOnDelete();
            $t->index(['agency_id', 'status']);
        });

        Schema::table('sales_reps', function (Blueprint $t) {
            try {
                $t->foreign('user_id', 'sales_reps_user_fk')
                  ->references('id')->on('users')->nullOnDelete();
            } catch (\Throwable $e) { /* users table shape differs — link stays soft */ }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_reps');
        Schema::dropIfExists('sales_agencies');
    }
};
EOF
echo "  wrote migration create_sales_agencies_and_reps"

# ─────────────────────────────────────────────────────────────────────────────
# migration: prospect attribution  (MARKER-AGENCIES-ATTR)
# ─────────────────────────────────────────────────────────────────────────────
cat > database/migrations/2026_07_07_000004_add_attribution_to_sales_prospects.php <<'EOF'
<?php
// MARKER-AGENCIES-ATTR — Deal registration: which agency/rep owns a prospect.
// Attribution lives on the prospect and follows it through conversion —
// a won prospect's tenant_id + agency_id is the commission join (chunk 2).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospects', 'agency_id')) {
                $t->uuid('agency_id')->nullable()->after('channel_id');
            }
            if (! Schema::hasColumn('sales_prospects', 'sales_rep_id')) {
                $t->uuid('sales_rep_id')->nullable()->after('agency_id');
            }
        });

        Schema::table('sales_prospects', function (Blueprint $t) {
            try {
                $t->foreign('agency_id', 'sales_prospects_agency_fk')
                  ->references('id')->on('sales_agencies')->nullOnDelete();
            } catch (\Throwable $e) {}
            try {
                $t->foreign('sales_rep_id', 'sales_prospects_rep_fk')
                  ->references('id')->on('sales_reps')->nullOnDelete();
            } catch (\Throwable $e) {}
            try { $t->index('agency_id', 'sales_prospects_agency_index'); } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            try { $t->dropForeign('sales_prospects_agency_fk'); } catch (\Throwable $e) {}
            try { $t->dropForeign('sales_prospects_rep_fk'); } catch (\Throwable $e) {}
            try { $t->dropIndex('sales_prospects_agency_index'); } catch (\Throwable $e) {}
            foreach (['agency_id', 'sales_rep_id'] as $col) {
                if (Schema::hasColumn('sales_prospects', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
EOF
echo "  wrote migration add_attribution_to_sales_prospects"

# ─────────────────────────────────────────────────────────────────────────────
# models  (MARKER-AGENCIES-CORE)
# ─────────────────────────────────────────────────────────────────────────────
cat > app/Models/SalesAgency.php <<'EOF'
<?php
// MARKER-AGENCIES-CORE

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A rep group selling Intake. Commission terms live here (per agency, not
 * global). Attribution flows: agency -> prospect -> tenant, and the ledger
 * (chunk 2) accrues on collected revenue against these rates.
 */
class SalesAgency extends Model
{
    use HasUuids;

    protected $table = 'sales_agencies';

    protected $fillable = [
        'name', 'slug', 'status', 'commission_year1', 'commission_residual',
        'deal_registration', 'notes',
    ];

    protected $casts = [
        'commission_year1'    => 'decimal:4',
        'commission_residual' => 'decimal:4',
        'deal_registration'   => 'boolean',
    ];

    public const STATUSES = [
        'active'     => 'Active',
        'onboarding' => 'Onboarding',
        'paused'     => 'Paused',
    ];

    public function reps(): HasMany
    {
        return $this->hasMany(SalesRep::class, 'agency_id');
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(SalesProspect::class, 'agency_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            if (blank($a->slug)) {
                $a->slug = Str::slug($a->name);
            }
        });
    }
}
EOF

cat > app/Models/SalesRep.php <<'EOF'
<?php
// MARKER-AGENCIES-CORE

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesRep extends Model
{
    use HasUuids;

    protected $table = 'sales_reps';

    protected $fillable = [
        'agency_id', 'name', 'role', 'email', 'phone', 'user_id', 'status',
    ];

    public const ROLES = [
        'principal' => 'Principal',
        'rep'       => 'Rep',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(SalesAgency::class, 'agency_id');
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(SalesProspect::class, 'sales_rep_id');
    }
}
EOF
echo "  wrote SalesAgency + SalesRep models"

# ─────────────────────────────────────────────────────────────────────────────
# Filament: SalesAgencyResource + pages + reps relation manager
# ─────────────────────────────────────────────────────────────────────────────
mkdir -p app/Filament/Resources/SalesAgencyResource/Pages
mkdir -p app/Filament/Resources/SalesAgencyResource/RelationManagers
cat > app/Filament/Resources/SalesAgencyResource.php <<'EOF'
<?php
// MARKER-AGENCIES-CORE

namespace App\Filament\Resources;

use App\Filament\Resources\SalesAgencyResource\Pages;
use App\Filament\Resources\SalesAgencyResource\RelationManagers;
use App\Models\SalesAgency;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesAgencyResource extends Resource
{
    protected static ?string $model = SalesAgency::class;

    protected static ?string $navigationIcon  = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int    $navigationSort  = 3;
    protected static ?string $navigationLabel = 'Reps & agencies';
    protected static ?string $modelLabel      = 'agency';
    protected static ?string $slug            = 'sales/agencies';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Agency')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(120),
                Forms\Components\Select::make('status')
                    ->options(SalesAgency::STATUSES)->default('onboarding')
                    ->native(false)->required(),
                Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Commission terms')->columns(3)
                ->description('Rates apply to collected revenue only. The ledger (next build) accrues against these.')
                ->schema([
                    Forms\Components\TextInput::make('commission_year1')
                        ->label('Year 1 rate')->numeric()->step('0.0001')
                        ->minValue(0)->maxValue(1)->default(0.25)
                        ->helperText('0.25 = 25% while the account is under 12 months old'),
                    Forms\Components\TextInput::make('commission_residual')
                        ->label('Residual rate')->numeric()->step('0.0001')
                        ->minValue(0)->maxValue(1)->default(0.10)
                        ->helperText('0.10 = 10% from month 13 onward'),
                    Forms\Components\Toggle::make('deal_registration')
                        ->label('Deal registration')->default(true)->inline(false)
                        ->helperText('Prospect attribution is exclusive to this agency'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount([
                    'reps',
                    'prospects',
                    'prospects as tenants_count' => fn (Builder $builder) => $builder->whereNotNull('tenant_id'),
                ]))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()->sortable()->weight('semibold')
                    ->description(fn (SalesAgency $r) => number_format($r->commission_year1 * 100, 0) . '% yr-1 → ' . number_format($r->commission_residual * 100, 0) . '% residual'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SalesAgency::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active'     => 'success',
                        'onboarding' => 'warning',
                        default      => 'gray',
                    }),
                Tables\Columns\TextColumn::make('reps_count')
                    ->label('Reps')->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('prospects_count')
                    ->label('Prospects')->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('tenants_count')
                    ->label('Tenants')->alignEnd()->sortable()
                    ->color('success')->weight('semibold'),
                Tables\Columns\IconColumn::make('deal_registration')
                    ->label('Deal reg')->boolean()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(SalesAgency::STATUSES),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\RepsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSalesAgencies::route('/'),
            'create' => Pages\CreateSalesAgency::route('/create'),
            'edit'   => Pages\EditSalesAgency::route('/{record}/edit'),
        ];
    }
}
EOF

cat > app/Filament/Resources/SalesAgencyResource/RelationManagers/RepsRelationManager.php <<'EOF'
<?php
// MARKER-AGENCIES-CORE

namespace App\Filament\Resources\SalesAgencyResource\RelationManagers;

use App\Models\SalesRep;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class RepsRelationManager extends RelationManager
{
    protected static string $relationship = 'reps';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(120),
            Forms\Components\Select::make('role')
                ->options(SalesRep::ROLES)->default('rep')->native(false)->required(),
            Forms\Components\TextInput::make('email')->email()->maxLength(191),
            Forms\Components\TextInput::make('phone')->tel()->maxLength(64),
            Forms\Components\Select::make('status')
                ->options(['active' => 'Active', 'inactive' => 'Inactive'])
                ->default('active')->native(false),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->weight('semibold')->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SalesRep::ROLES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'principal' ? 'primary' : 'gray'),
                Tables\Columns\TextColumn::make('email')->placeholder('—'),
                Tables\Columns\TextColumn::make('phone')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('prospects_count')
                    ->counts('prospects')->label('Prospects')->alignEnd(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
EOF

cat > app/Filament/Resources/SalesAgencyResource/Pages/ListSalesAgencies.php <<'EOF'
<?php
// MARKER-AGENCIES-CORE

namespace App\Filament\Resources\SalesAgencyResource\Pages;

use App\Filament\Resources\SalesAgencyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSalesAgencies extends ListRecords
{
    protected static string $resource = SalesAgencyResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
EOF

cat > app/Filament/Resources/SalesAgencyResource/Pages/CreateSalesAgency.php <<'EOF'
<?php
// MARKER-AGENCIES-CORE

namespace App\Filament\Resources\SalesAgencyResource\Pages;

use App\Filament\Resources\SalesAgencyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesAgency extends CreateRecord
{
    protected static string $resource = SalesAgencyResource::class;
}
EOF

cat > app/Filament/Resources/SalesAgencyResource/Pages/EditSalesAgency.php <<'EOF'
<?php
// MARKER-AGENCIES-CORE

namespace App\Filament\Resources\SalesAgencyResource\Pages;

use App\Filament\Resources\SalesAgencyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSalesAgency extends EditRecord
{
    protected static string $resource = SalesAgencyResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
EOF
echo "  wrote SalesAgencyResource + pages + RepsRelationManager"

# ─────────────────────────────────────────────────────────────────────────────
# seeder  (MARKER-AGENCIES-SEEDER)
# ─────────────────────────────────────────────────────────────────────────────
cat > database/seeders/SalesAgencySeeder.php <<'EOF'
<?php
// MARKER-AGENCIES-SEEDER — Modus Sport Group + roster. Idempotent.

namespace Database\Seeders;

use App\Models\SalesAgency;
use App\Models\SalesRep;
use Illuminate\Database\Seeder;

class SalesAgencySeeder extends Seeder
{
    public function run(): void
    {
        $modus = SalesAgency::firstOrCreate(['slug' => 'modus-sport-group'], [
            'name'                => 'Modus Sport Group',
            'status'              => 'onboarding',
            'commission_year1'    => 0.25,
            'commission_residual' => 0.10,
            'deal_registration'   => true,
        ]);

        foreach ([
            ['Alex G',  'principal'],
            ['Alex',    'rep'],
            ['Adam',    'rep'],
            ['Nick',    'rep'],
            ['Jordan',  'rep'],
        ] as [$name, $role]) {
            SalesRep::firstOrCreate(
                ['agency_id' => $modus->id, 'name' => $name],
                ['role' => $role, 'status' => 'active'],
            );
        }

        $this->command?->info('Modus Sport Group seeded with 5 reps.');
    }
}
EOF
echo "  wrote database/seeders/SalesAgencySeeder.php"

# ─────────────────────────────────────────────────────────────────────────────
# Anchored edits
# ─────────────────────────────────────────────────────────────────────────────
python3 - <<'PYEOF'
def rd(p):
    with open(p, encoding="utf-8") as f: return f.read()
def wr(p, s):
    with open(p, "w", encoding="utf-8") as f: f.write(s)
def edit(p, old, new):
    s = rd(p); n = s.count(old)
    assert n == 1, f"ANCHOR count={n} in {p} (expected 1) for: {old[:70]!r}"
    wr(p, s.replace(old, new, 1)); print(f"  edited {p}")

M = "app/Models/SalesProspect.php"

# fillable: attribution fields after channel/quote line
edit(M,
"        'channel_id', 'categories', 'quote_tier', 'quote_addons', 'quote_monthly',",
"        'channel_id', 'categories', 'quote_tier', 'quote_addons', 'quote_monthly',\n        'agency_id', 'sales_rep_id', // MARKER-AGENCIES-ATTR")

# relations after channel()
edit(M,
"""    public function channel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'channel_id');
    }
""",
"""    public function channel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'channel_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(SalesAgency::class, 'agency_id');
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(SalesRep::class, 'sales_rep_id');
    }
""")

R = "app/Filament/Resources/SalesProspectResource.php"

# form: agency + rep selects after the channel select
edit(R,
"""                Forms\\Components\\Select::make('channel_id')
                    ->label('Channel')
                    ->options(fn () => \\App\\Models\\SalesChannel::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->native(false)->placeholder('\u2014 channel \u2014'),""",
"""                Forms\\Components\\Select::make('channel_id')
                    ->label('Channel')
                    ->options(fn () => \\App\\Models\\SalesChannel::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->native(false)->placeholder('\u2014 channel \u2014'),
                Forms\\Components\\Select::make('agency_id')
                    ->label('Agency')->live()
                    ->options(fn () => \\App\\Models\\SalesAgency::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->native(false)->placeholder('\u2014 house \u2014'),
                Forms\\Components\\Select::make('sales_rep_id')
                    ->label('Rep')
                    ->options(fn (\\Filament\\Forms\\Get $get) => $get('agency_id')
                        ? \\App\\Models\\SalesRep::query()->where('agency_id', $get('agency_id'))->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->native(false)->placeholder('\u2014 unassigned \u2014'),""")

# table: rep column before the stage column
edit(R,
"                Tables\\Columns\\TextColumn::make('stage')",
"""                Tables\\Columns\\TextColumn::make('rep.name')
                    ->label('Rep')->toggleable()->placeholder('house')
                    ->description(fn (SalesProspect $r) => $r->agency?->name),

                Tables\\Columns\\TextColumn::make('stage')""")

# filter: agency after channel filter
edit(R,
"""                Tables\\Filters\\SelectFilter::make('channel_id')
                    ->label('Channel')
                    ->options(fn () => \\App\\Models\\SalesChannel::query()->orderBy('name')->pluck('name', 'id')->all()),""",
"""                Tables\\Filters\\SelectFilter::make('channel_id')
                    ->label('Channel')
                    ->options(fn () => \\App\\Models\\SalesChannel::query()->orderBy('name')->pluck('name', 'id')->all()),
                Tables\\Filters\\SelectFilter::make('agency_id')
                    ->label('Agency')
                    ->options(fn () => \\App\\Models\\SalesAgency::query()->orderBy('name')->pluck('name', 'id')->all()),""")

# panel registration
P = "app/Providers/Filament/AdminPanelProvider.php"
s = rd(P)
if "MARKER-AGENCIES-REGISTER" in s:
    print("  panel registration already applied — skipping.")
else:
    edit(P,
    "use App\\Filament\\Resources\\SalesChannelResource; // MARKER-CAMPAIGNS-REGISTER",
    "use App\\Filament\\Resources\\SalesAgencyResource; // MARKER-AGENCIES-REGISTER\nuse App\\Filament\\Resources\\SalesChannelResource; // MARKER-CAMPAIGNS-REGISTER")
    edit(P,
    "                SalesChannelResource::class, // MARKER-CAMPAIGNS-REGISTER",
    "                SalesChannelResource::class, // MARKER-CAMPAIGNS-REGISTER\n                SalesAgencyResource::class, // MARKER-AGENCIES-REGISTER")

print("All anchored edits applied.")
PYEOF

echo ""
echo "Done. Next:"
echo "  composer dump-autoload && php artisan migrate --force && php artisan db:seed --class=SalesAgencySeeder && php artisan filament:cache-components && php artisan optimize:clear"
