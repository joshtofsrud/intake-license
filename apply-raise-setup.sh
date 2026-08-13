#!/usr/bin/env bash
set -euo pipefail

# apply-raise-setup.sh — MARKER-RAISE-SETUP
# Makes the raise configurable instead of hardcoded: cap/target/round in the database,
# message templates editable, PDFs uploaded through master admin, and a second nav surface.

echo "==> checking repo root"
test -f artisan || { echo "run this from the intake-license repo root"; exit 1; }

PROVIDER=app/Providers/Filament/AdminPanelProvider.php
grep -q "MARKER-RAISE-PORTAL" routes/web.php || { echo "apply-raise-portal.sh must be applied first"; exit 1; }

if grep -q "MARKER-RAISE-SETUP" "$PROVIDER"; then
  echo "MARKER-RAISE-SETUP already registered — nothing to do."
  exit 0
fi

echo "==> creating directories"
mkdir -p app/Filament/Pages app/Models database/migrations resources/views/filament/pages storage/app/invest

echo "==> migration"
cat > database/migrations/2026_08_12_220000_create_raise_setup_tables.php <<'MIGEOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-RAISE-SETUP
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raise_message_templates', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('subject');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('invest_documents', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('label');
            $table->string('path');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invest_documents');
        Schema::dropIfExists('raise_message_templates');
    }
};
MIGEOF

echo "==> models"
cat > app/Models/RaiseMessageTemplate.php <<'TPLEOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-RAISE-SETUP
class RaiseMessageTemplate extends Model
{
    protected $primaryKey = 'key';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = ['key', 'subject', 'body'];

    /** DB overrides win; anything not customised falls back to the shipped config. */
    public static function merged(): array
    {
        $templates = config('investor_messages', []);

        foreach (static::all() as $row) {
            $templates[$row->key] = array_merge(
                $templates[$row->key] ?? ['label' => $row->key, 'auto' => false],
                ['subject' => $row->subject, 'body' => $row->body]
            );
        }

        return $templates;
    }
}
TPLEOF

cat > app/Models/InvestDocument.php <<'DOCEOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-RAISE-SETUP
class InvestDocument extends Model
{
    protected $fillable = ['slug', 'label', 'path', 'sort', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('id');
    }

    public function exists(): bool
    {
        return is_file(storage_path('app/' . $this->path));
    }
}
DOCEOF

echo "==> raise setup page"
cat > app/Filament/Pages/RaiseSetup.php <<'PAGEOF'
<?php

namespace App\Filament\Pages;

use App\Models\InvestDocument;
use App\Models\Investor;
use App\Models\RaiseMessageTemplate;
use App\Models\RaiseSetting;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;

// MARKER-RAISE-SETUP
class RaiseSetup extends Page
{
    use WithFileUploads;

    protected static ?string $navigationIcon  = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationLabel = 'Raise setup';
    protected static ?string $navigationGroup = 'Raise';
    protected static ?string $title           = 'Raise setup';
    protected static ?int    $navigationSort  = 91;

    protected static string $view = 'filament.pages.raise-setup';

    // round
    public string $roundName  = '';
    public string $instrument = '';
    public $cap;
    public $target;
    public string $senderName = '';
    public string $roundStatus = 'open';

    // wire + compliance
    public string $wireBank      = '';
    public string $wireAccount   = '';
    public string $wireRouting   = '';
    public string $wireReference = '';
    public string $formDFiledAt  = '';
    public string $blueSkyNotes  = '';

    // template editor
    public string $templateKey  = '';
    public string $templateSubject = '';
    public string $templateBody    = '';

    // document upload
    public $upload;
    public string $uploadLabel = '';

    public function mount(): void
    {
        $this->roundName   = (string) RaiseSetting::get('round_name', 'Intake — pre-seed');
        $this->instrument  = (string) RaiseSetting::get('instrument', 'Post-money SAFE');
        $this->cap         = (int) RaiseSetting::get('cap', (string) Investor::CAP);
        $this->target      = (int) RaiseSetting::get('target', (string) Investor::TARGET);
        $this->senderName  = (string) RaiseSetting::get('sender_name', 'Josh');
        $this->roundStatus = (string) RaiseSetting::get('round_status', 'open');

        $this->wireBank      = (string) RaiseSetting::get('wire_bank');
        $this->wireAccount   = (string) RaiseSetting::get('wire_account');
        $this->wireRouting   = (string) RaiseSetting::get('wire_routing');
        $this->wireReference = (string) RaiseSetting::get('wire_reference');
        $this->formDFiledAt  = (string) RaiseSetting::get('form_d_filed_at');
        $this->blueSkyNotes  = (string) RaiseSetting::get('blue_sky_notes');
    }

    public function saveRound(): void
    {
        $this->validate([
            'roundName'  => ['required', 'string', 'max:120'],
            'instrument' => ['nullable', 'string', 'max:120'],
            'cap'        => ['required', 'numeric', 'min:1'],
            'target'     => ['required', 'numeric', 'min:1'],
            'senderName' => ['nullable', 'string', 'max:120'],
        ]);

        RaiseSetting::put('round_name', $this->roundName);
        RaiseSetting::put('instrument', $this->instrument ?: null);
        RaiseSetting::put('cap', (string) (int) $this->cap);
        RaiseSetting::put('target', (string) (int) $this->target);
        RaiseSetting::put('sender_name', $this->senderName ?: null);
        RaiseSetting::put('round_status', $this->roundStatus);

        Notification::make()
            ->title('Round saved')
            ->body('Every percentage recalculates against the new cap.')
            ->success()
            ->send();
    }

    public function saveCompliance(): void
    {
        RaiseSetting::put('wire_bank', $this->wireBank ?: null);
        RaiseSetting::put('wire_account', $this->wireAccount ?: null);
        RaiseSetting::put('wire_routing', $this->wireRouting ?: null);
        RaiseSetting::put('wire_reference', $this->wireReference ?: null);
        RaiseSetting::put('form_d_filed_at', $this->formDFiledAt ?: null);
        RaiseSetting::put('blue_sky_notes', $this->blueSkyNotes ?: null);

        Notification::make()->title('Wire and compliance details saved')->success()->send();
    }

    public function editTemplate(string $key): void
    {
        $template = RaiseMessageTemplate::merged()[$key] ?? null;

        if (! $template) {
            return;
        }

        $this->templateKey     = $key;
        $this->templateSubject = $template['subject'] ?? '';
        $this->templateBody    = $template['body'] ?? '';
    }

    public function saveTemplate(): void
    {
        $this->validate([
            'templateKey'     => ['required', 'string'],
            'templateSubject' => ['required', 'string', 'max:190'],
            'templateBody'    => ['required', 'string'],
        ]);

        RaiseMessageTemplate::updateOrCreate(
            ['key' => $this->templateKey],
            ['subject' => $this->templateSubject, 'body' => $this->templateBody]
        );

        Notification::make()->title('Template saved')->success()->send();
    }

    public function resetTemplate(): void
    {
        RaiseMessageTemplate::where('key', $this->templateKey)->delete();

        $this->editTemplate($this->templateKey);

        Notification::make()->title('Template reset to the shipped wording')->send();
    }

    public function closeTemplate(): void
    {
        $this->reset(['templateKey', 'templateSubject', 'templateBody']);
    }

    public function uploadDocument(): void
    {
        $this->validate([
            'upload'      => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'uploadLabel' => ['required', 'string', 'max:120'],
        ]);

        $slug = Str::slug($this->uploadLabel) ?: 'document-' . time();

        // Same slug replaces the file rather than piling up copies.
        $existing = InvestDocument::where('slug', $slug)->first();
        $filename = $slug . '.pdf';

        $this->upload->storeAs('invest', $filename);

        InvestDocument::updateOrCreate(
            ['slug' => $slug],
            [
                'label'     => $this->uploadLabel,
                'path'      => 'invest/' . $filename,
                'is_active' => $existing?->is_active ?? true,
            ]
        );

        $this->reset(['upload', 'uploadLabel']);

        Notification::make()->title('Document uploaded')->success()->send();
    }

    public function toggleDocument(int $id): void
    {
        $doc = InvestDocument::findOrFail($id);
        $doc->forceFill(['is_active' => ! $doc->is_active])->save();
    }

    public function deleteDocument(int $id): void
    {
        $doc = InvestDocument::findOrFail($id);

        $path = storage_path('app/' . $doc->path);
        if (is_file($path)) {
            @unlink($path);
        }

        $doc->delete();

        Notification::make()->title('Document removed')->send();
    }

    public function getViewData(): array
    {
        return [
            'templates' => RaiseMessageTemplate::merged(),
            'overrides' => RaiseMessageTemplate::pluck('key')->all(),
            'documents' => InvestDocument::orderBy('sort')->orderBy('id')->get(),
            'committed' => (int) Investor::query()->whereNull('declined_at')->sum('amount'),
        ];
    }
}
PAGEOF

cat > resources/views/filament/pages/raise-setup.blade.php <<'VIEWEOF'
<x-filament-panels::page>
<!-- MARKER-RAISE-SETUP -->

@php
    $usd = fn ($n) => '$' . number_format((int) $n);
@endphp

<div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">The round</div>
    <div class="grid gap-3 md:grid-cols-3">
        <label class="block"><span class="text-xs text-gray-500">Round name</span>
            <input wire:model="roundName" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Instrument</span>
            <input wire:model="instrument" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Status</span>
            <select wire:model="roundStatus" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10">
                <option value="draft">Draft</option>
                <option value="open">Open</option>
                <option value="closed">Closed</option>
            </select></label>
        <label class="block"><span class="text-xs text-gray-500">Cap (post-money)</span>
            <input wire:model="cap" type="number" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Target raise</span>
            <input wire:model="target" type="number" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block"><span class="text-xs text-gray-500">Emails signed by</span>
            <input wire:model="senderName" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
    </div>
    <div class="mt-3 flex items-center gap-4">
        <x-filament::button wire:click="saveRound">Save round</x-filament::button>
        <span class="text-xs text-gray-500">
            At this cap, {{ $usd($target) }} is
            {{ (int) $cap > 0 ? number_format($target / $cap * 100, 2) : '0' }}% of the company.
            {{ $usd($committed) }} committed so far.
        </span>
    </div>
    @error('cap')    <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
    @error('target') <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
</div>

<div class="mt-6 rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Wire details and compliance</div>
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
        <div class="flex items-end"><x-filament::button wire:click="saveCompliance">Save</x-filament::button></div>
    </div>
    <p class="mt-3 text-xs text-gray-500">
        Set the wire details before marking anyone signed, or that email goes out with the bank line blank.
        Form D and blue-sky rows record what you say you filed. Intake files nothing with the SEC or any state.
    </p>
</div>

<div class="mt-6 rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Documents on the invitation page</div>

    <div class="grid gap-3 md:grid-cols-3">
        <label class="block md:col-span-1"><span class="text-xs text-gray-500">Label</span>
            <input wire:model="uploadLabel" placeholder="Investment opportunity" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
        <label class="block md:col-span-1"><span class="text-xs text-gray-500">PDF</span>
            <input type="file" wire:model="upload" accept="application/pdf" class="mt-1 w-full text-sm"></label>
        <div class="flex items-end"><x-filament::button wire:click="uploadDocument">Upload</x-filament::button></div>
    </div>
    @error('upload')      <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
    @error('uploadLabel') <p class="text-sm text-danger-600 mt-2">{{ $message }}</p> @enderror
    <div wire:loading wire:target="upload" class="text-xs text-gray-500 mt-2">Uploading…</div>

    @if ($documents->isNotEmpty())
    <table class="w-full text-sm mt-4">
        <thead class="text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="text-left p-2">Document</th>
                <th class="text-left p-2">Link</th>
                <th class="text-right p-2">Actions</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($documents as $doc)
            <tr class="border-t border-gray-100 dark:border-white/5 {{ $doc->is_active ? '' : 'opacity-50' }}">
                <td class="p-2">
                    <div class="font-medium">{{ $doc->label }}</div>
                    <div class="text-xs text-gray-500">
                        {{ $doc->path }}
                        @unless ($doc->exists())
                            <span class="text-danger-600">— file missing on disk</span>
                        @endunless
                    </div>
                </td>
                <td class="p-2 font-mono text-xs">/invest/&lt;token&gt;/doc/{{ $doc->slug }}</td>
                <td class="p-2 text-right whitespace-nowrap">
                    <x-filament::button size="xs" color="gray" wire:click="toggleDocument({{ $doc->id }})">
                        {{ $doc->is_active ? 'Hide' : 'Show' }}
                    </x-filament::button>
                    <x-filament::button size="xs" color="danger"
                        wire:click="deleteDocument({{ $doc->id }})"
                        wire:confirm="Delete {{ $doc->label }}? The file is removed from disk.">Delete</x-filament::button>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @else
        <p class="mt-3 text-xs text-gray-500">Nothing uploaded yet, so the invitation page has no downloads.</p>
    @endif
</div>

<div class="mt-6 rounded-xl border border-gray-200 dark:border-white/10 p-4">
    <div class="text-xs uppercase tracking-wide text-gray-500 mb-3">Messages</div>

    @if ($templateKey)
        <div class="grid gap-3">
            <label class="block"><span class="text-xs text-gray-500">Subject</span>
                <input wire:model="templateSubject" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10"></label>
            <label class="block"><span class="text-xs text-gray-500">Body</span>
                <textarea wire:model="templateBody" rows="12" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 font-mono text-xs"></textarea></label>
            <div class="flex gap-2">
                <x-filament::button wire:click="saveTemplate">Save</x-filament::button>
                <x-filament::button color="gray" wire:click="resetTemplate"
                    wire:confirm="Discard your wording and go back to the shipped version?">Reset</x-filament::button>
                <x-filament::button color="gray" wire:click="closeTemplate">Close</x-filament::button>
            </div>
            <p class="text-xs text-gray-500">
                Placeholders: &#123;name&#125; &#123;amount&#125; &#123;percent&#125; &#123;cap&#125; &#123;portal&#125;
                &#123;bank&#125; &#123;account&#125; &#123;routing&#125; &#123;reference&#125; &#123;sender&#125;
            </p>
        </div>
    @else
        <table class="w-full text-sm">
            <tbody>
            @foreach ($templates as $key => $template)
                <tr class="border-t border-gray-100 dark:border-white/5">
                    <td class="p-2">
                        <div class="font-medium">{{ $template['label'] ?? $key }}</div>
                        <div class="text-xs text-gray-500">{{ $template['subject'] ?? '' }}</div>
                    </td>
                    <td class="p-2 text-xs text-gray-500">
                        {{ ($template['auto'] ?? false) ? 'Automatic' : 'Manual' }}
                        {{ in_array($key, $overrides, true) ? ' · edited' : '' }}
                    </td>
                    <td class="p-2 text-right">
                        <x-filament::button size="xs" color="gray" wire:click="editTemplate('{{ $key }}')">Edit</x-filament::button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

</x-filament-panels::page>
VIEWEOF

echo "==> rewiring cap/target, templates and document serving"
python3 - <<'PYEOF'
import io, sys

def edit(path, pairs):
    src = io.open(path, encoding="utf-8").read()
    for old, new in pairs:
        n = src.count(old)
        if n != 1:
            sys.exit("ABORT %s: anchor found %d times: %r" % (path, n, old[:70]))
        src = src.replace(old, new, 1)
    io.open(path, "w", encoding="utf-8").write(src)
    print("   patched", path)

# 1. Investor: constants become defaults behind settings-backed accessors.
edit("app/Models/Investor.php", [(
    "    public const CAP    = 1000000;\n    public const TARGET = 100000;",
    "    // MARKER-RAISE-SETUP — constants are now DEFAULTS; the live values come from raise_settings.\n"
    "    public const CAP    = 1000000;\n    public const TARGET = 100000;\n\n"
    "    public static function cap(): int\n    {\n"
    "        return (int) (RaiseSetting::get('cap') ?: self::CAP);\n    }\n\n"
    "    public static function target(): int\n    {\n"
    "        return (int) (RaiseSetting::get('target') ?: self::TARGET);\n    }"
), (
    "        return self::CAP > 0 ? round($this->amount / self::CAP * 100, 2) : 0.0;",
    "        $cap = self::cap();\n\n        return $cap > 0 ? round($this->amount / $cap * 100, 2) : 0.0;"
), (
    "use Illuminate\\Database\\Eloquent\\Model;",
    "use Illuminate\\Database\\Eloquent\\Model;"
)])

# 2. Everywhere that read the constants now reads the settings.
edit("app/Filament/Pages/Raise.php", [
    ("            'target'     => Investor::TARGET,\n            'cap'        => Investor::CAP,",
     "            'target'     => Investor::target(),\n            'cap'        => Investor::cap(),"),
])
edit("app/Filament/Pages/InvestorRecord.php", [
    ("            'cap'       => Investor::CAP,", "            'cap'       => Investor::cap(),"),
])
edit("app/Http/Controllers/InvestorPortalController.php", [
    ("            'cap'       => Investor::CAP,", "            'cap'       => Investor::cap(),"),
])

# 3. Messenger: DB templates win, {cap} follows the setting.
edit("app/Services/InvestorMessenger.php", [
    ("        return config('investor_messages', []);",
     "        return \\App\\Models\\RaiseMessageTemplate::merged();"),
    ("            '{cap}'       => '$' . number_format(Investor::CAP),",
     "            '{cap}'       => '$' . number_format(Investor::cap()),"),
])

# 4. Invitation page serves uploaded documents, with the shipped map as fallback.
edit("app/Http/Controllers/InvestController.php", [
    ("        abort_unless(isset($files[$doc]), 404);\n\n        $path = storage_path('app/' . $files[$doc]);",
     "        // MARKER-RAISE-SETUP — uploaded documents win over the shipped filenames.\n"
     "        $uploaded = \\App\\Models\\InvestDocument::where('slug', $doc)->where('is_active', true)->first();\n\n"
     "        abort_unless($uploaded || isset($files[$doc]), 404);\n\n"
     "        $path = storage_path('app/' . ($uploaded->path ?? $files[$doc]));"),
])

# 5. The Raise page points at the setup surface instead of holding its own settings card.
view = "resources/views/filament/pages/raise.blade.php"
src = io.open(view, encoding="utf-8").read()
start = src.index('<!-- MARKER-RAISE-RECORDS -->')
end   = src.index('</x-filament-panels::page>')
pointer = ('<!-- MARKER-RAISE-SETUP -->\n'
           '<div class="mt-6 rounded-xl border border-gray-200 dark:border-white/10 p-4">\n'
           '    <div class="text-xs uppercase tracking-wide text-gray-500 mb-1">Round settings</div>\n'
           '    <p class="text-sm text-gray-500">Cap, target, wire details, documents and message wording live on\n'
           '        <a href="{{ \\App\\Filament\\Pages\\RaiseSetup::getUrl() }}" class="underline">Raise setup</a>.</p>\n'
           '</div>\n\n')
io.open(view, "w", encoding="utf-8").write(src[:start] + pointer + src[end:])
print("   patched", view)

# 6. Register the page and group the two surfaces together.
p = "app/Providers/Filament/AdminPanelProvider.php"
src = io.open(p, encoding="utf-8").read()
anchor = "\\App\\Filament\\Pages\\InvestorRecord::class, // MARKER-RAISE-RECORDS"
assert src.count(anchor) == 1, "InvestorRecord anchor missing — aborting"
src = src.replace(anchor, anchor + "\n                \\App\\Filament\\Pages\\RaiseSetup::class, // MARKER-RAISE-SETUP", 1)
io.open(p, "w", encoding="utf-8").write(src)
print("   registered RaiseSetup::class")
PYEOF

python3 - <<'PYEOF'
import io
src = io.open("app/Filament/Pages/Raise.php", encoding="utf-8").read()
src = src.replace("protected static ?string $navigationGroup = 'Platform';",
                  "protected static ?string $navigationGroup = 'Raise';", 1)
io.open("app/Filament/Pages/Raise.php", "w", encoding="utf-8").write(src)
print("   Raise moved into its own nav group")
PYEOF

grep -q "MARKER-RAISE-SETUP" "$PROVIDER" || { echo "REGISTRATION FAILED — page would 404"; exit 1; }

echo ""
echo "MARKER-RAISE-SETUP applied."
echo "  new: raise_message_templates + invest_documents tables, RaiseSetup page, two models"
echo "  changed: Investor (cap/target from settings), Raise, InvestorRecord, portal controller,"
echo "           InvestorMessenger (DB templates), InvestController (uploaded PDFs)"
echo ""
echo "After deploy: php artisan filament:cache-components"
echo "Then Raise > Raise setup: set the cap and target, upload the PDFs, edit any message."
