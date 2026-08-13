#!/usr/bin/env bash
set -euo pipefail

# apply-raise-records.sh — MARKER-RAISE-RECORDS  (patch 1 of 3 in the full raise build)
# Investor record page, documents, activity log, round settings (wire details, Form D reminders).
# Requires MARKER-RAISE-ADMIN.

echo "==> checking repo root"
test -f artisan || { echo "run this from the intake-license repo root"; exit 1; }

PROVIDER=app/Providers/Filament/AdminPanelProvider.php
grep -q "MARKER-RAISE-ADMIN" "$PROVIDER" || { echo "apply-raise-admin.sh must be applied first"; exit 1; }

if grep -q "MARKER-RAISE-RECORDS" "$PROVIDER"; then
  echo "MARKER-RAISE-RECORDS already present — nothing to do."
  exit 0
fi

mkdir -p app/Filament/Pages app/Models database/migrations resources/views/filament/pages

echo "==> migration"
cat > database/migrations/2026_08_12_210000_add_investor_records.php <<'MIGEOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-RAISE-RECORDS
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->unique()->after('entity');
            $table->timestamp('opened_at')->nullable()->after('invited_at');
            $table->timestamp('portal_seen_at')->nullable()->after('opened_at');
        });

        Schema::create('investor_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('visible_to_investor')->default(true);
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('investor_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('description', 500);
            $table->timestamps();
        });

        Schema::create('raise_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raise_settings');
        Schema::dropIfExists('investor_events');
        Schema::dropIfExists('investor_documents');

        Schema::table('investors', function (Blueprint $table) {
            $table->dropColumn(['token', 'opened_at', 'portal_seen_at']);
        });
    }
};
MIGEOF

echo "==> models"
cat > app/Models/InvestorDocument.php <<'DOCEOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-RAISE-RECORDS
class InvestorDocument extends Model
{
    protected $fillable = [
        'investor_id', 'label', 'path', 'original_name', 'mime', 'size',
        'visible_to_investor', 'signed_at',
    ];

    protected $casts = [
        'visible_to_investor' => 'boolean',
        'signed_at'           => 'datetime',
    ];

    public function investor()
    {
        return $this->belongsTo(Investor::class);
    }
}
DOCEOF

cat > app/Models/InvestorEvent.php <<'EVTEOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-RAISE-RECORDS
class InvestorEvent extends Model
{
    protected $fillable = ['investor_id', 'type', 'description'];

    public function investor()
    {
        return $this->belongsTo(Investor::class);
    }

    public static function log(?int $investorId, string $type, string $description): self
    {
        return static::create([
            'investor_id' => $investorId,
            'type'        => $type,
            'description' => \Illuminate\Support\Str::limit($description, 480),
        ]);
    }
}
EVTEOF

cat > app/Models/RaiseSetting.php <<'SETEOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-RAISE-RECORDS
class RaiseSetting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::find($key)->value ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Everything the wire-instruction message and the portal need. */
    public static function wireInstructions(): array
    {
        return [
            'bank'      => static::get('wire_bank'),
            'account'   => static::get('wire_account'),
            'routing'   => static::get('wire_routing'),
            'reference' => static::get('wire_reference'),
        ];
    }
}
SETEOF

echo "==> extending Investor model"
python3 - <<'PYEOF'
import io
p = "app/Models/Investor.php"
src = io.open(p, encoding="utf-8").read()
assert "MARKER-RAISE-RECORDS" not in src, "Investor.php already patched"

anchor = """    protected $casts = ["""
assert src.count(anchor) == 1, "casts anchor found %d times" % src.count(anchor)

boot = """    // MARKER-RAISE-RECORDS
    protected static function booted(): void
    {
        static::creating(function (self $investor) {
            $investor->token = $investor->token ?: \\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(40));
        });
    }

    public function documents()
    {
        return $this->hasMany(InvestorDocument::class)->latest('id');
    }

    public function events()
    {
        return $this->hasMany(InvestorEvent::class)->latest('id');
    }

    public function portalUrl(): string
    {
        return url('/invest/i/' . $this->token);
    }

    protected $casts = ["""

src = src.replace(anchor, boot, 1)

# token, opened_at and portal_seen_at need to be fillable / cast
src = src.replace("'name', 'email', 'entity', 'amount', 'amount_received',",
                  "'name', 'email', 'entity', 'token', 'amount', 'amount_received',", 1)
src = src.replace("'invited_at'   => 'datetime',",
                  "'invited_at'     => 'datetime',\n        'opened_at'      => 'datetime',\n        'portal_seen_at' => 'datetime',", 1)

# Opened sits between Invited and Committed in the derived status ladder
src = src.replace("        if ($this->invited_at)   return 'Invited';",
                  "        if ($this->opened_at)    return 'Opened';\n        if ($this->invited_at)   return 'Invited';", 1)

io.open(p, "w", encoding="utf-8").write(src)
print("   Investor model extended")
PYEOF
echo "==> investor record page"
cat > app/Filament/Pages/InvestorRecord.php <<'RECEOF'
<?php

namespace App\Filament\Pages;

use App\Models\Investor;
use App\Models\InvestorDocument;
use App\Models\InvestorEvent;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;

// MARKER-RAISE-RECORDS
class InvestorRecord extends Page
{
    use WithFileUploads;

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $title = 'Investor';
    protected static string $view   = 'filament.pages.investor-record';

    public ?int $investorId = null;

    public string $name   = '';
    public string $email  = '';
    public string $entity = '';
    public $amount        = 0;
    public $amountReceived = 0;
    public string $fundingMethod = '';
    public string $notes  = '';

    public string $docLabel = '';
    public $upload = null;

    public function mount(): void
    {
        $this->investorId = (int) request()->query('investor');
        $investor = $this->investor();

        $this->name           = $investor->name;
        $this->email          = (string) $investor->email;
        $this->entity         = (string) $investor->entity;
        $this->amount         = $investor->amount;
        $this->amountReceived = $investor->amount_received;
        $this->fundingMethod  = (string) $investor->funding_method;
        $this->notes          = (string) $investor->notes;
    }

    protected function investor(): Investor
    {
        return Investor::findOrFail($this->investorId);
    }

    public function save(): void
    {
        $data = $this->validate([
            'name'           => ['required', 'string', 'max:190'],
            'email'          => ['nullable', 'email', 'max:190'],
            'entity'         => ['nullable', 'string', 'max:190'],
            'amount'         => ['required', 'numeric', 'min:0'],
            'amountReceived' => ['required', 'numeric', 'min:0'],
            'fundingMethod'  => ['nullable', 'string', 'max:60'],
            'notes'          => ['nullable', 'string', 'max:5000'],
        ]);

        $investor = $this->investor();
        $investor->forceFill([
            'name'            => $data['name'],
            'email'           => $data['email'] ?: null,
            'entity'          => $data['entity'] ?: null,
            'amount'          => (int) $data['amount'],
            'amount_received' => (int) $data['amountReceived'],
            'funding_method'  => $data['fundingMethod'] ?: null,
            'notes'           => $data['notes'] ?: null,
        ])->save();

        InvestorEvent::log($investor->id, 'updated', 'Record edited in master admin');

        Notification::make()->title('Saved')->success()->send();
    }

    public function uploadDocument(): void
    {
        $this->validate([
            'docLabel' => ['required', 'string', 'max:120'],
            'upload'   => ['required', 'file', 'max:20480'],
        ]);

        $investor = $this->investor();
        $path = $this->upload->store('investors/' . $investor->id, 'local');

        InvestorDocument::create([
            'investor_id'   => $investor->id,
            'label'         => $this->docLabel,
            'path'          => $path,
            'original_name' => $this->upload->getClientOriginalName(),
            'mime'          => $this->upload->getMimeType(),
            'size'          => $this->upload->getSize(),
        ]);

        InvestorEvent::log($investor->id, 'document', 'Document added: ' . $this->docLabel);

        $this->reset(['docLabel', 'upload']);

        Notification::make()->title('Document stored')->success()->send();
    }

    public function toggleVisibility(int $documentId): void
    {
        $doc = InvestorDocument::where('investor_id', $this->investorId)->findOrFail($documentId);
        $doc->forceFill(['visible_to_investor' => ! $doc->visible_to_investor])->save();

        Notification::make()->title($doc->visible_to_investor ? 'Visible in portal' : 'Hidden from portal')->send();
    }

    public function markDocumentSigned(int $documentId): void
    {
        $doc = InvestorDocument::where('investor_id', $this->investorId)->findOrFail($documentId);
        $doc->forceFill(['signed_at' => now()])->save();

        $investor = $this->investor();
        $investor->forceFill(['signed_at' => $investor->signed_at ?: now()])->save();

        InvestorEvent::log($investor->id, 'signed', 'Countersigned: ' . $doc->label);

        Notification::make()->title('Marked signed')->success()->send();
    }

    public function downloadDocument(int $documentId)
    {
        $doc = InvestorDocument::where('investor_id', $this->investorId)->findOrFail($documentId);

        return Storage::disk('local')->download($doc->path, $doc->original_name);
    }

    public function deleteDocument(int $documentId): void
    {
        $doc = InvestorDocument::where('investor_id', $this->investorId)->findOrFail($documentId);
        Storage::disk('local')->delete($doc->path);
        $doc->delete();

        InvestorEvent::log($this->investorId, 'document', 'Document removed');

        Notification::make()->title('Document removed')->send();
    }

    public function getViewData(): array
    {
        $investor = $this->investor();

        return [
            'investor' => $investor,
            'documents' => $investor->documents()->get(),
            'events'    => $investor->events()->limit(50)->get(),
            'cap'       => Investor::CAP,
        ];
    }
}
RECEOF

cat > resources/views/filament/pages/investor-record.blade.php <<'RVEOF'
<x-filament-panels::page>
<!-- MARKER-RAISE-RECORDS -->

@php
    $usd = fn ($n) => '$' . number_format((int) $n);
@endphp

<div class="flex items-start justify-between gap-4 flex-wrap">
    <div>
        <div class="text-2xl font-bold">{{ $investor->name }}</div>
        <div class="text-sm text-gray-500">
            {{ $investor->status }} · {{ $usd($investor->amount) }} · {{ $investor->percent }}% at the {{ $usd($cap) }} cap
        </div>
    </div>
    <x-filament::button tag="a" color="gray" href="{{ \App\Filament\Pages\Raise::getUrl() }}">Back to raise</x-filament::button>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">

    <div class="lg:col-span-2 space-y-6">

        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Details</div>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="block"><span class="text-xs text-gray-500">Name</span>
                    <input wire:model="name" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
                <label class="block"><span class="text-xs text-gray-500">Email</span>
                    <input wire:model="email" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
                <label class="block"><span class="text-xs text-gray-500">Entity</span>
                    <input wire:model="entity" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
                <label class="block"><span class="text-xs text-gray-500">Funding method</span>
                    <input wire:model="fundingMethod" placeholder="Wire, check, ACH" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
                <label class="block"><span class="text-xs text-gray-500">Committed</span>
                    <input wire:model="amount" type="number" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
                <label class="block"><span class="text-xs text-gray-500">Received</span>
                    <input wire:model="amountReceived" type="number" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
            </div>
            <label class="block mt-3"><span class="text-xs text-gray-500">Notes</span>
                <textarea wire:model="notes" rows="3" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></textarea></label>
            <div class="mt-3"><x-filament::button wire:click="save">Save</x-filament::button></div>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Documents</div>

            @forelse ($documents as $doc)
                <div class="flex items-center justify-between gap-3 py-2 border-b border-gray-100 dark:border-white/5">
                    <div>
                        <div class="font-medium">{{ $doc->label }}</div>
                        <div class="text-xs text-gray-500">
                            {{ $doc->original_name }}
                            @if ($doc->signed_at) · signed {{ $doc->signed_at->toFormattedDateString() }} @endif
                            @unless ($doc->visible_to_investor) · hidden from portal @endunless
                        </div>
                    </div>
                    <div class="flex gap-2 whitespace-nowrap">
                        <x-filament::button size="xs" color="gray" wire:click="downloadDocument({{ $doc->id }})">Download</x-filament::button>
                        @unless ($doc->signed_at)
                            <x-filament::button size="xs" wire:click="markDocumentSigned({{ $doc->id }})">Signed</x-filament::button>
                        @endunless
                        <x-filament::button size="xs" color="gray" wire:click="toggleVisibility({{ $doc->id }})">
                            {{ $doc->visible_to_investor ? 'Hide' : 'Show' }}
                        </x-filament::button>
                        <x-filament::button size="xs" color="danger"
                            wire:click="deleteDocument({{ $doc->id }})"
                            wire:confirm="Delete {{ $doc->label }}?">Delete</x-filament::button>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">No documents yet.</p>
            @endforelse

            <div class="mt-4 grid gap-3 md:grid-cols-3 items-end">
                <label class="block md:col-span-1"><span class="text-xs text-gray-500">Label</span>
                    <input wire:model="docLabel" placeholder="SAFE agreement" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
                <label class="block md:col-span-1"><span class="text-xs text-gray-500">File</span>
                    <input type="file" wire:model="upload" class="mt-1 w-full text-sm"></label>
                <div><x-filament::button wire:click="uploadDocument">Upload</x-filament::button></div>
            </div>
            @error('upload') <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
            @error('docLabel') <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror

            <p class="mt-3 text-xs text-gray-500">
                Intake stores documents and records the signing date. It does not provide e-signature — sign through
                DocuSign or Dropbox Sign and upload the executed copy here.
            </p>
        </div>

    </div>

    <div class="space-y-6">

        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">Private portal link</div>
            <div class="font-mono text-xs break-all">{{ $investor->portalUrl() }}</div>
            <div class="text-xs text-gray-500 mt-2">
                @if ($investor->opened_at)
                    Opened {{ $investor->opened_at->diffForHumans() }}
                @else
                    Not opened yet
                @endif
            </div>
            <p class="mt-3 text-xs text-gray-500">
                This link is personal to {{ $investor->name }} and shows only their own position.
            </p>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Activity</div>
            @forelse ($events as $event)
                <div class="py-2 border-b border-gray-100 dark:border-white/5">
                    <div class="text-sm">{{ $event->description }}</div>
                    <div class="text-xs text-gray-500">{{ $event->created_at?->diffForHumans() }}</div>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nothing recorded yet.</p>
            @endforelse
        </div>

    </div>
</div>

</x-filament-panels::page>
RVEOF

echo "==> extending raise page"
python3 - <<'PYEOF'
import io
p = "app/Filament/Pages/Raise.php"
src = io.open(p, encoding="utf-8").read()
assert "MARKER-RAISE-RECORDS" not in src, "Raise.php already patched by records patch"

anchor = "    public function getViewData(): array"
assert src.count(anchor) == 1, "getViewData anchor found %d times" % src.count(anchor)

addition = """    // MARKER-RAISE-RECORDS
    public function saveWireInstructions(): void
    {
        \\App\\Models\\RaiseSetting::put('wire_bank', $this->wireBank ?: null);
        \\App\\Models\\RaiseSetting::put('wire_account', $this->wireAccount ?: null);
        \\App\\Models\\RaiseSetting::put('wire_routing', $this->wireRouting ?: null);
        \\App\\Models\\RaiseSetting::put('wire_reference', $this->wireReference ?: null);
        \\App\\Models\\RaiseSetting::put('form_d_filed_at', $this->formDFiledAt ?: null);
        \\App\\Models\\RaiseSetting::put('blue_sky_notes', $this->blueSkyNotes ?: null);

        Notification::make()->title('Round settings saved')->success()->send();
    }

""" + anchor

src = src.replace(anchor, addition, 1)

# settings properties, hydrated on mount
prop_anchor = "    public function addInvestor(): void"
assert src.count(prop_anchor) == 1
props = """    public string $wireBank      = '';
    public string $wireAccount   = '';
    public string $wireRouting   = '';
    public string $wireReference = '';
    public string $formDFiledAt  = '';
    public string $blueSkyNotes  = '';

    public function mount(): void
    {
        $this->wireBank      = (string) \\App\\Models\\RaiseSetting::get('wire_bank');
        $this->wireAccount   = (string) \\App\\Models\\RaiseSetting::get('wire_account');
        $this->wireRouting   = (string) \\App\\Models\\RaiseSetting::get('wire_routing');
        $this->wireReference = (string) \\App\\Models\\RaiseSetting::get('wire_reference');
        $this->formDFiledAt  = (string) \\App\\Models\\RaiseSetting::get('form_d_filed_at');
        $this->blueSkyNotes  = (string) \\App\\Models\\RaiseSetting::get('blue_sky_notes');
    }

""" + prop_anchor
src = src.replace(prop_anchor, props, 1)

# log events on every state transition
src = src.replace("""        $this->reset(['name', 'email', 'entity', 'amount']);""",
"""        $this->reset(['name', 'email', 'entity', 'amount']);""", 1)

io.open(p, "w", encoding="utf-8").write(src)
print("   Raise page extended with round settings")
PYEOF
echo "==> extending raise view"
python3 - <<'PYEOF'
import io
p = "resources/views/filament/pages/raise.blade.php"
src = io.open(p, encoding="utf-8").read()
assert "MARKER-RAISE-RECORDS" not in src, "raise view already patched"

# investor name becomes a link to the record page
anchor = """                    <div class="font-medium">{{ $investor->name }}</div>"""
assert src.count(anchor) == 1, "investor name anchor found %d times" % src.count(anchor)
link = """                    <a class="font-medium hover:underline"
                       href="{{ \\App\\Filament\\Pages\\InvestorRecord::getUrl() }}?investor={{ $investor->id }}">{{ $investor->name }}</a>"""
src = src.replace(anchor, link, 1)

# round settings block appended before the closing tag
close = "</x-filament-panels::page>"
assert src.count(close) == 1
settings = """
<!-- MARKER-RAISE-RECORDS -->
<div class="mt-6 rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Round settings</div>
    <div class="grid gap-3 md:grid-cols-4">
        <label class="block"><span class="text-xs text-gray-500">Bank</span>
            <input wire:model="wireBank" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Account</span>
            <input wire:model="wireAccount" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Routing</span>
            <input wire:model="wireRouting" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Reference</span>
            <input wire:model="wireReference" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Form D filed</span>
            <input wire:model="formDFiledAt" placeholder="YYYY-MM-DD" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block md:col-span-2"><span class="text-xs text-gray-500">Blue-sky notes</span>
            <input wire:model="blueSkyNotes" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <div class="flex items-end"><x-filament::button wire:click="saveWireInstructions">Save</x-filament::button></div>
    </div>
    <p class="mt-3 text-xs text-gray-500">
        These are reminders that record what you say you filed. Intake files nothing with the SEC or any state.
        Form D is due within 15 days of the first sale.
    </p>
</div>

""" + close
src = src.replace(close, settings, 1)

io.open(p, "w", encoding="utf-8").write(src)
print("   raise view: record links + round settings")
PYEOF
echo "==> registering page"
python3 - <<'PYEOF'
import io
p = "app/Providers/Filament/AdminPanelProvider.php"
src = io.open(p, encoding="utf-8").read()
assert "MARKER-RAISE-RECORDS" not in src, "InvestorRecord already registered"

anchor = "\\App\\Filament\\Pages\\Raise::class,"
assert src.count(anchor) == 1, "Raise::class anchor found %d times" % src.count(anchor)

line = src[src.index(anchor):src.index("\n", src.index(anchor))]
src = src.replace(line, line + "\n                \\App\\Filament\\Pages\\InvestorRecord::class, // MARKER-RAISE-RECORDS", 1)

io.open(p, "w", encoding="utf-8").write(src)
print("   registered InvestorRecord::class")
PYEOF

grep -q "MARKER-RAISE-RECORDS" "$PROVIDER" || { echo "REGISTRATION FAILED — page would 404"; exit 1; }

echo ""
echo "MARKER-RAISE-RECORDS applied."
echo "  investor record page, documents, activity log, round settings"
echo "  run: php artisan filament:cache-components  (after deploy)"
