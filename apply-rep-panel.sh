#!/usr/bin/env bash
# apply-rep-panel.sh — The /rep panel + rep logins (Team & access pattern).
#
# Adds:
#   Invite flow: master admin invites a rep from the agency's Reps tab ->
#     Postmark email with tokenized setup link -> rep sets their own password
#     -> user created with is_admin=false, linked to sales_reps.user_id.
#   /rep Filament panel: reps log in with the same users table; canAccessPanel
#     gates by panel — reps can never reach /admin, admins aren't in /rep.
#   Rep-scoped Prospects resource: a principal sees the whole agency's book,
#     a rep sees their own. Creating a prospect IS deal registration — it
#     auto-attributes to the rep + agency. Quote builder shows THEIR agency's
#     year-1 rate. Activity log included.
#
# NOT here (chunk 3): the commission ledger.
#
# PREREQUISITE: apply-sales-agencies.sh applied.
# Run from the repo root:  bash apply-rep-panel.sh
# Idempotent: guarded on MARKER-REPPANEL-GATE in app/Models/User.php.
set -euo pipefail

[ -f artisan ] || { echo "ERROR: run from the Laravel repo root."; exit 1; }
grep -q MARKER-AGENCIES-ATTR app/Models/SalesProspect.php 2>/dev/null || { echo "ERROR: run apply-sales-agencies.sh first."; exit 1; }
if grep -q MARKER-REPPANEL-GATE app/Models/User.php; then
  echo "apply-rep-panel.sh: already applied — skipping."; exit 0
fi

echo "Applying rep panel + invites …"

# ─────────────────────────────────────────────────────────────────────────────
# migration: invite fields on sales_reps  (MARKER-REPPANEL-INVITE)
# ─────────────────────────────────────────────────────────────────────────────
cat > database/migrations/2026_07_07_000005_add_invite_fields_to_sales_reps.php <<'EOF'
<?php
// MARKER-REPPANEL-INVITE — tokenized setup-link invites (Team & access pattern).
// Token is stored sha256-hashed; the raw token only exists in the email link.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_reps', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_reps', 'invite_token')) {
                $t->string('invite_token', 64)->nullable()->index()->after('user_id');
            }
            if (! Schema::hasColumn('sales_reps', 'invited_at')) {
                $t->timestamp('invited_at')->nullable()->after('invite_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_reps', function (Blueprint $t) {
            foreach (['invite_token', 'invited_at'] as $col) {
                if (Schema::hasColumn('sales_reps', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
EOF
echo "  wrote migration add_invite_fields_to_sales_reps"

# ─────────────────────────────────────────────────────────────────────────────
# Rep panel provider  (MARKER-REPPANEL-PANEL)
# ─────────────────────────────────────────────────────────────────────────────
cat > app/Providers/Filament/RepPanelProvider.php <<'EOF'
<?php
// MARKER-REPPANEL-PANEL — the /rep panel. Same users table, same 'web' guard;
// isolation comes from canAccessPanel (panel-aware) + this panel only
// registering rep-scoped resources. Reps physically cannot reach Tenants,
// Licensing, or Distribution because those resources don't exist here.

namespace App\Providers\Filament;

use App\Filament\Rep\Resources\RepProspectResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class RepPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('rep')
            ->path('rep')
            ->login()
            ->brandName('Intake · Rep')
            ->colors(['primary' => Color::Sky])
            ->darkMode(true)
            ->resources([
                RepProspectResource::class,
            ])
            ->pages([
                Dashboard::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->authGuard('web');
    }
}
EOF
echo "  wrote RepPanelProvider"

# ─────────────────────────────────────────────────────────────────────────────
# Rep-scoped Prospects resource  (MARKER-REPPANEL-RESOURCE)
# ─────────────────────────────────────────────────────────────────────────────
mkdir -p app/Filament/Rep/Resources/RepProspectResource/Pages
cat > app/Filament/Rep/Resources/RepProspectResource.php <<'EOF'
<?php
// MARKER-REPPANEL-RESOURCE — Prospects, scoped to the signed-in rep.
// Principal -> whole agency book. Rep -> their own prospects.
// Creating a prospect here IS deal registration: attribution is stamped
// automatically and cannot be pointed at another agency.

namespace App\Filament\Rep\Resources;

use App\Filament\Rep\Resources\RepProspectResource\Pages;
use App\Models\SalesProspect;
use App\Models\SalesRep;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RepProspectResource extends Resource
{
    protected static ?string $model = SalesProspect::class;

    protected static ?string $navigationIcon  = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = 'My prospects';
    protected static ?string $modelLabel      = 'prospect';
    protected static ?string $slug            = 'prospects';

    /** The signed-in user's rep record (memoized per request). */
    public static function currentRep(): ?SalesRep
    {
        static $rep = false;
        if ($rep === false) {
            $rep = SalesRep::with('agency')->where('user_id', auth()->id())->first();
        }
        return $rep ?: null;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $rep = static::currentRep();

        if (! $rep) {
            return $query->whereRaw('1 = 0');
        }

        return $rep->role === 'principal'
            ? $query->where('agency_id', $rep->agency_id)
            : $query->where('sales_rep_id', $rep->id);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Shop')->columns(2)->schema([
                Forms\Components\TextInput::make('shop')->required()->maxLength(191),
                Forms\Components\TextInput::make('city')->maxLength(120),
                Forms\Components\TextInput::make('state')->maxLength(64)->placeholder('WA / OR / ID…'),
                Forms\Components\Select::make('stage')
                    ->options(SalesProspect::STAGES)->default('prospect')
                    ->native(false)->required(),
            ]),

            Forms\Components\Section::make('Contact')->columns(2)->schema([
                Forms\Components\TextInput::make('owner_contact')->label('Owner / contact')->maxLength(191),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(64),
                Forms\Components\TextInput::make('email')->email()->maxLength(191),
                Forms\Components\TextInput::make('website')->url()->maxLength(255),
            ]),

            Forms\Components\Section::make('Next step')->columns(2)->schema([
                Forms\Components\TextInput::make('next_action')->maxLength(191),
                Forms\Components\DatePicker::make('next_action_on')->native(false),
                Forms\Components\Textarea::make('notes')->rows(3)->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Quote')->columns(2)->collapsed()
                ->description('Proposed subscription. Priced from live plan/add-on pricing; total snapshots on save.')
                ->schema([
                    Forms\Components\Select::make('quote_tier')
                        ->label('Base tier')->live()->native(false)
                        ->options(fn () => collect(config('intake.plan_prices', []))
                            ->filter(fn ($cents) => (int) $cents > 0)
                            ->map(fn ($cents, $key) => ucfirst($key) . ' — $' . number_format($cents / 100) . '/mo')
                            ->all())
                        ->placeholder('— no quote yet —'),
                    Forms\Components\CheckboxList::make('quote_addons')
                        ->label('Add-ons')->live()
                        ->options(function (\Filament\Forms\Get $get) {
                            $tier = $get('quote_tier');
                            return DB::table('addons')
                                ->where('status', 'active')
                                ->orderBy('sort_order')
                                ->get(['code', 'name', 'price_cents', 'included_in_plans'])
                                ->mapWithKeys(function ($a) use ($tier) {
                                    $included = $tier && in_array($tier, (array) json_decode($a->included_in_plans ?? '[]', true), true);
                                    $label = $a->name . ($included
                                        ? ' — included in tier'
                                        : ' (+$' . number_format($a->price_cents / 100) . '/mo)');
                                    return [$a->code => $label];
                                })->all();
                        }),
                    Forms\Components\Placeholder::make('quote_total')
                        ->label('Proposed monthly')->columnSpanFull()
                        ->content(function (\Filament\Forms\Get $get) {
                            $plans = config('intake.plan_prices', []);
                            $tier  = $get('quote_tier');
                            if (! $tier || empty($plans[$tier])) {
                                return '—';
                            }
                            $sum = (int) round(((int) $plans[$tier]) / 100);
                            $selected = (array) $get('quote_addons');
                            if ($selected !== []) {
                                $rows = DB::table('addons')
                                    ->whereIn('code', $selected)
                                    ->get(['code', 'price_cents', 'included_in_plans']);
                                foreach ($rows as $a) {
                                    $included = in_array($tier, (array) json_decode($a->included_in_plans ?? '[]', true), true);
                                    if (! $included) {
                                        $sum += (int) round(((int) $a->price_cents) / 100);
                                    }
                                }
                            }
                            $rep  = static::currentRep();
                            $rate = $rep?->agency?->commission_year1 !== null
                                ? (float) $rep->agency->commission_year1
                                : SalesProspect::COMMISSION_YEAR1;
                            return '$' . number_format($sum) . '/mo  ·  your yr-1 commission @ ' . rtrim(rtrim(number_format($rate * 100, 2), '0'), '.') . '% ≈ $' . number_format($sum * $rate, 2) . '/mo';
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('shop')
                    ->searchable()->sortable()->weight('semibold')->limit(40)
                    ->description(fn (SalesProspect $r) => trim(($r->city ?? '') . ($r->state ? ", {$r->state}" : ''), ', ') ?: null),
                Tables\Columns\TextColumn::make('rep.name')
                    ->label('Rep')->toggleable()
                    ->visible(fn () => static::currentRep()?->role === 'principal'),
                Tables\Columns\TextColumn::make('stage')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SalesProspect::STAGES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'won'   => 'success',
                        'trial' => 'info',
                        'lost'  => 'danger',
                        'demo_booked', 'demo_done' => 'primary',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('quote_monthly')
                    ->label('Quote')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => $state ? '$' . number_format($state) . '/mo' : null)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('next_action_on')
                    ->label('Next action')->date()->sortable()
                    ->description(fn (SalesProspect $r) => $r->next_action)
                    ->color(fn (SalesProspect $r) => $r->next_action_on && $r->next_action_on->isPast() ? 'danger' : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('stage')->options(SalesProspect::STAGES),
                Tables\Filters\Filter::make('due')
                    ->label('Due / overdue')
                    ->query(fn (Builder $query) => $query->whereNotNull('next_action_on')->whereDate('next_action_on', '<=', now()))
                    ->toggle(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('next_action_on', 'asc');
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\SalesProspectResource\RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRepProspects::route('/'),
            'create' => Pages\CreateRepProspect::route('/create'),
            'edit'   => Pages\EditRepProspect::route('/{record}/edit'),
        ];
    }
}
EOF

cat > app/Filament/Rep/Resources/RepProspectResource/Pages/ListRepProspects.php <<'EOF'
<?php
// MARKER-REPPANEL-RESOURCE

namespace App\Filament\Rep\Resources\RepProspectResource\Pages;

use App\Filament\Rep\Resources\RepProspectResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRepProspects extends ListRecords
{
    protected static string $resource = RepProspectResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Register prospect')];
    }
}
EOF

cat > app/Filament/Rep/Resources/RepProspectResource/Pages/CreateRepProspect.php <<'EOF'
<?php
// MARKER-REPPANEL-RESOURCE — creating a prospect IS deal registration.

namespace App\Filament\Rep\Resources\RepProspectResource\Pages;

use App\Filament\Rep\Resources\RepProspectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRepProspect extends CreateRecord
{
    protected static string $resource = RepProspectResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $rep = RepProspectResource::currentRep();

        $data['agency_id']    = $rep?->agency_id;
        $data['sales_rep_id'] = $rep?->id;
        $data['verified']     = false;
        $data['source']       = 'Rep registration';

        return $data;
    }
}
EOF

cat > app/Filament/Rep/Resources/RepProspectResource/Pages/EditRepProspect.php <<'EOF'
<?php
// MARKER-REPPANEL-RESOURCE

namespace App\Filament\Rep\Resources\RepProspectResource\Pages;

use App\Filament\Rep\Resources\RepProspectResource;
use Filament\Resources\Pages\EditRecord;

class EditRepProspect extends EditRecord
{
    protected static string $resource = RepProspectResource::class;
}
EOF
echo "  wrote RepProspectResource + 3 pages"

# ─────────────────────────────────────────────────────────────────────────────
# Rep setup controller + view  (MARKER-REPPANEL-SETUP)
# ─────────────────────────────────────────────────────────────────────────────
cat > app/Http/Controllers/RepSetupController.php <<'EOF'
<?php
// MARKER-REPPANEL-SETUP — public tokenized setup page (Team & access pattern).
// The raw token lives only in the email link; DB stores its sha256.

namespace App\Http\Controllers;

use App\Models\SalesRep;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class RepSetupController extends Controller
{
    private function repForToken(string $token): ?SalesRep
    {
        $rep = SalesRep::where('invite_token', hash('sha256', $token))
            ->whereNull('user_id')
            ->first();

        if (! $rep || ! $rep->invited_at || $rep->invited_at->lt(now()->subDays(7))) {
            return null;
        }
        return $rep;
    }

    public function show(string $token)
    {
        $rep = $this->repForToken($token);
        abort_unless($rep, 404);

        return view('rep.setup', ['rep' => $rep, 'token' => $token]);
    }

    public function store(Request $request, string $token)
    {
        $rep = $this->repForToken($token);
        abort_unless($rep, 404);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (User::where('email', $rep->email)->exists()) {
            return back()->withErrors(['password' => 'An account with this email already exists. Contact Intake support.']);
        }

        $user = User::create([
            'name'     => $rep->name,
            'email'    => $rep->email,
            'password' => $data['password'],   // 'hashed' cast on the model
            'is_admin' => false,               // never the master admin panel
        ]);

        $rep->forceFill([
            'user_id'      => $user->id,
            'invite_token' => null,
            'invited_at'   => null,
        ])->save();

        return redirect('/rep')->with('status', 'Account created — sign in below.');
    }
}
EOF

mkdir -p resources/views/rep
cat > resources/views/rep/setup.blade.php <<'EOF'
{{-- MARKER-REPPANEL-SETUP — standalone rep account setup page --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up your Intake rep account</title>
<style>
body{margin:0;background:#0a0c11;color:#e7e9f0;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:grid;place-items:center;min-height:100vh}
.card{width:380px;max-width:92vw;background:#12151d;border:1px solid #262c39;border-radius:14px;padding:26px}
h1{font-size:18px;margin:0 0 4px;letter-spacing:-.01em}
.sub{color:#69707f;font-size:13px;margin-bottom:20px}
label{display:block;font-size:12.5px;color:#9aa3b4;margin:0 0 6px;font-weight:600}
input{width:100%;box-sizing:border-box;background:#171b25;border:1px solid #313847;border-radius:9px;padding:10px 12px;color:#e7e9f0;font-size:14px;margin-bottom:14px}
input:focus{outline:none;border-color:#38bdf8}
input[disabled]{opacity:.6}
button{width:100%;background:#0284c7;border:none;color:#fff;font-weight:700;font-size:14px;padding:11px;border-radius:9px;cursor:pointer}
button:hover{background:#0369a1}
.err{background:rgba(251,113,133,.1);border:1px solid rgba(251,113,133,.4);color:#fda4af;border-radius:9px;padding:9px 12px;font-size:12.5px;margin-bottom:14px}
</style>
</head>
<body>
<div class="card">
  <h1>Welcome, {{ $rep->name }}</h1>
  <div class="sub">{{ $rep->agency?->name }} · set a password to access your Intake rep dashboard.</div>

  @if ($errors->any())
    <div class="err">{{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ url('/rep-setup/' . $token) }}">
    @csrf
    <label>Email</label>
    <input type="email" value="{{ $rep->email }}" disabled>
    <label>Password</label>
    <input type="password" name="password" required minlength="8" autofocus>
    <label>Confirm password</label>
    <input type="password" name="password_confirmation" required minlength="8">
    <button type="submit">Create account</button>
  </form>
</div>
</body>
</html>
EOF
echo "  wrote RepSetupController + setup view"

# ─────────────────────────────────────────────────────────────────────────────
# Anchored edits: User gate, SalesRep model, RepsRelationManager invite action,
# routes, provider registration
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

# --- User: panel-aware gate + salesRep relation ---
U = "app/Models/User.php"
edit(U,
"""    public function canAccessPanel(Panel $panel): bool
    {
        // Bootstrap admin from env is always allowed""",
"""    // MARKER-REPPANEL-GATE — the /rep panel admits linked reps ONLY, and rep
    // accounts (is_admin=false) can never pass the admin checks below.
    public function salesRep()
    {
        return $this->hasOne(\\App\\Models\\SalesRep::class, 'user_id');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'rep') {
            return $this->salesRep()->where('status', 'active')->exists();
        }

        // Bootstrap admin from env is always allowed""")

# --- SalesRep: invite fields fillable + user relation + casts ---
S = "app/Models/SalesRep.php"
edit(S,
"    protected $fillable = [\n        'agency_id', 'name', 'role', 'email', 'phone', 'user_id', 'status',\n    ];",
"""    protected $fillable = [
        'agency_id', 'name', 'role', 'email', 'phone', 'user_id', 'status',
        'invite_token', 'invited_at', // MARKER-REPPANEL-INVITE
    ];

    protected $casts = [
        'invited_at' => 'datetime',
    ];""")
edit(S,
"""    public function agency(): BelongsTo
    {
        return $this->belongsTo(SalesAgency::class, 'agency_id');
    }""",
"""    public function agency(): BelongsTo
    {
        return $this->belongsTo(SalesAgency::class, 'agency_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }""")

# --- RepsRelationManager: invite action + login status column ---
RM = "app/Filament/Resources/SalesAgencyResource/RelationManagers/RepsRelationManager.php"
edit(RM,
"use Filament\\Tables;\nuse Filament\\Tables\\Table;",
"""use Filament\\Notifications\\Notification;
use Filament\\Tables;
use Filament\\Tables\\Table;
use Illuminate\\Support\\Facades\\Mail;
use Illuminate\\Support\\Str;""")
edit(RM,
"""            ->actions([
                Tables\\Actions\\EditAction::make(),
                Tables\\Actions\\DeleteAction::make(),
            ]);""",
"""            ->actions([
                // MARKER-REPPANEL-INVITE — Team & access pattern: tokenized setup link
                Tables\\Actions\\Action::make('invite')
                    ->label(fn (SalesRep $record) => $record->invited_at ? 'Resend invite' : 'Invite')
                    ->icon('heroicon-o-envelope')
                    ->visible(fn (SalesRep $record) => filled($record->email) && $record->user_id === null)
                    ->requiresConfirmation()
                    ->modalDescription(fn (SalesRep $record) => "Send a setup link to {$record->email}? The link is valid for 7 days.")
                    ->action(function (SalesRep $record) {
                        $token = Str::random(48);
                        $record->forceFill([
                            'invite_token' => hash('sha256', $token),
                            'invited_at'   => now(),
                        ])->save();

                        $url  = url('/rep-setup/' . $token);
                        $name = e($record->name);
                        $agency = e($record->agency?->name ?? 'Intake');
                        Mail::html(
                            "<p>Hi {$name},</p>" .
                            "<p>You've been invited to the <strong>Intake rep dashboard</strong> for {$agency}. " .
                            "Set your password to get started:</p>" .
                            "<p><a href=\\"{$url}\\">{$url}</a></p>" .
                            "<p>This link is valid for 7 days.</p>",
                            function ($message) use ($record) {
                                $message->to($record->email)->subject('Set up your Intake rep account');
                            }
                        );

                        Notification::make()->title("Invite sent to {$record->email}")->success()->send();
                    }),
                Tables\\Actions\\EditAction::make(),
                Tables\\Actions\\DeleteAction::make(),
            ]);""")
edit(RM,
"""                Tables\\Columns\\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'gray'),""",
"""                Tables\\Columns\\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'gray'),
                Tables\\Columns\\TextColumn::make('user_id')
                    ->label('Login')
                    ->formatStateUsing(fn () => 'Active')
                    ->badge()->color('success')
                    ->placeholder(fn (SalesRep $record) => $record->invited_at ? 'Invited ' . $record->invited_at->diffForHumans() : 'Not invited'),""")

# --- routes: public setup endpoints (append, guarded) ---
W = "routes/web.php"
s = rd(W)
if "MARKER-REPPANEL-SETUP" in s:
    print("  routes already applied — skipping.")
else:
    s += """

// MARKER-REPPANEL-SETUP — public rep account setup (tokenized, 7-day expiry)
Route::get('/rep-setup/{token}', [\\App\\Http\\Controllers\\RepSetupController::class, 'show']);
Route::post('/rep-setup/{token}', [\\App\\Http\\Controllers\\RepSetupController::class, 'store']);
"""
    wr(W, s)
    print("  edited routes/web.php")

# --- provider registration ---
B = "bootstrap/providers.php"
edit(B,
"    App\\Providers\\Filament\\AdminPanelProvider::class,",
"    App\\Providers\\Filament\\AdminPanelProvider::class,\n    App\\Providers\\Filament\\RepPanelProvider::class, // MARKER-REPPANEL-PANEL")

print("All anchored edits applied.")
PYEOF

echo ""
echo "Done. Next:"
echo "  composer dump-autoload && php artisan migrate --force && php artisan filament:cache-components && php artisan optimize:clear"
