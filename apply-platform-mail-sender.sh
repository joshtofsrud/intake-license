#!/bin/bash
# platform-mail-sender — the platform email sender becomes editable in
# master admin instead of an .env deploy step.
#   WHY: no config/mail.php is published and no MAIL_FROM_* is set, so
#   Laravel 11 fell back to its framework default and every platform email
#   (signups included) went out as hello@example.com / "Example".
#   WHAT SHIPS:
#     · platform_settings table (single row, id=1 — same shape as
#       billing_settings), holding mail_from_address + mail_from_name
#     · Master admin → Platform → "Platform email": sender fields, a live
#       status banner (warns while the placeholder is still in effect), and
#       a Save-and-send-test button
#     · ApplyPlatformMailFrom listener on MessageSending — fills the sender
#       ONLY when a message set none or would send as the placeholder, so
#       tenant email keeps its own sender by construction. Runs at send
#       time (no per-request DB hit), memoized per request, and never
#       throws: a settings problem cannot stop mail going out.
#     · Fallback chain: stored setting -> .env/config -> framework default
#   Verified against real Symfony Email objects: missing From is filled,
#   the placeholder is replaced, a tenant sender is left alone.
# Server: MIGRATION REQUIRED, then view:clear (and filament:cache-components
# only if you cache Filament components).
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-PLATFORM-MAIL" app/Providers/AppServiceProvider.php; then
  echo "platform-mail-sender already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-TRANSFER-MOVEMENTS" app/Services/Tenant/TransferRequestService.php; then
  echo "wrong base — aborting."; exit 1
fi
mkdir -p database/migrations app/Models app/Listeners app/Providers app/Filament/Pages resources/views/filament/pages

cat > 'database/migrations/2026_07_23_000001_create_platform_settings_table.php' <<'PMAIL_0_EOF'
<?php

// MARKER-PLATFORM-MAIL — single-row platform settings, same shape as
// billing_settings. First occupant: the platform email sender, which was
// falling through to Laravel's framework default (hello@example.com)
// because no config/mail.php is published and no MAIL_FROM_* env is set.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $t) {
            $t->id();
            $t->string('mail_from_address')->nullable();
            $t->string('mail_from_name')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
PMAIL_0_EOF

cat > 'app/Models/PlatformSettings.php' <<'PMAIL_1_EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MARKER-PLATFORM-MAIL — single-row platform settings (id = 1), mirroring
 * the BillingSettings pattern. Editable in master admin so the platform
 * sender is not an .env deploy step.
 */
class PlatformSettings extends Model
{
    protected $table = 'platform_settings';

    protected $fillable = [
        'mail_from_address',
        'mail_from_name',
    ];

    /** Laravel's framework default when nothing is configured. */
    public const PLACEHOLDER_ADDRESS = 'hello@example.com';

    /** Per-request memo so a batch send does not re-query for every message. */
    protected static ?self $memo = null;

    public static function current(): self
    {
        if (static::$memo) {
            return static::$memo;
        }

        $row = self::find(1);
        if (! $row) {
            $row = self::create(['id' => 1]);
        }

        return static::$memo = $row;
    }

    /** Forget the memo (used after saving from master admin). */
    public static function forget(): void
    {
        static::$memo = null;
    }

    /**
     * The effective sender address: stored setting, else env/config, else
     * null when the only thing available is the framework placeholder.
     */
    public static function fromAddress(): ?string
    {
        $stored = trim((string) (self::current()->mail_from_address ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $config = trim((string) config('mail.from.address'));
        return ($config !== '' && strcasecmp($config, self::PLACEHOLDER_ADDRESS) !== 0)
            ? $config
            : null;
    }

    public static function fromName(): ?string
    {
        $stored = trim((string) (self::current()->mail_from_name ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $config = trim((string) config('mail.from.name'));
        return ($config !== '' && $config !== 'Example') ? $config : null;
    }

    /** True when mail would still go out as the framework placeholder. */
    public static function isPlaceholder(): bool
    {
        return self::fromAddress() === null;
    }
}
PMAIL_1_EOF

cat > 'app/Listeners/ApplyPlatformMailFrom.php' <<'PMAIL_2_EOF'
<?php

namespace App\Listeners;

use App\Models\PlatformSettings;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

/**
 * MARKER-PLATFORM-MAIL — stamps the platform sender onto outgoing mail that
 * has not set its own From.
 *
 * Why an event listener rather than boot-time config: this only runs when a
 * message is actually being sent, so there is no per-request database hit,
 * and tenant mail that sets its own sender is untouched by construction.
 *
 * Never throws: a settings problem must not stop mail from going out.
 */
class ApplyPlatformMailFrom
{
    public function handle(MessageSending $event): void
    {
        try {
            $email   = $event->message;
            $current = ($email->getFrom() ?: [])[0] ?? null;

            // Only fill in when nothing was set, or when the framework
            // placeholder would otherwise go out.
            $needsSender = $current === null
                || strcasecmp($current->getAddress(), PlatformSettings::PLACEHOLDER_ADDRESS) === 0;

            if (! $needsSender) {
                return;
            }

            $address = PlatformSettings::fromAddress();
            if (! $address) {
                return; // nothing configured anywhere — leave as-is
            }

            $email->from(new Address($address, PlatformSettings::fromName() ?? ''));
        } catch (\Throwable $e) {
            Log::warning('platform_mail_from.skipped', ['error' => $e->getMessage()]);
        }
    }
}
PMAIL_2_EOF

cat > 'app/Providers/AppServiceProvider.php' <<'PMAIL_3_EOF'
<?php

namespace App\Providers;

use App\Models\Tenant\TenantInventoryReceiveShipmentItem;
use App\Observers\Pos\TenantInventoryReceiveShipmentItemObserver;

use App\Listeners\LogAuthEvents;
use App\Listeners\LogMailEvents;
use App\Listeners\LogQueueEvents;
use App\Models\Tenant\TenantUser;
use App\Models\Tenant;
use App\Observers\TenantLocationObserver;
use App\Observers\TenantObserver;
use App\Observers\TenantUserObserver;
use App\Services\DebugLogService;
use App\Support\MySQLLock;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use App\Models\Tenant\TenantDomain;
use App\Observers\TenantDomainObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(TenantServiceProvider::class);

        // Singleton so correlation IDs persist across a single request.
        $this->app->singleton(DebugLogService::class);
        $this->app->alias(DebugLogService::class, 'debug_log');

        // MySQLLock is stateless; singleton avoids re-instantiation per inject.
        $this->app->singleton(MySQLLock::class);
    }

    public function boot(): void
    {
        // MARKER-PATCH-116 — enforce one primary domain per tenant
        TenantDomain::observe(TenantDomainObserver::class);

        TenantInventoryReceiveShipmentItem::observe(TenantInventoryReceiveShipmentItemObserver::class);

        \Illuminate\Database\Eloquent\Model::shouldBeStrict(
            ! app()->isProduction()
        );

        // Register debug-log event subscribers. Each listener's subscribe()
        // method returns a [Event::class => 'handler'] map.
        Event::subscribe(LogAuthEvents::class);
        Event::subscribe(LogMailEvents::class);

        // MARKER-PLATFORM-MAIL — fill the platform sender on mail that has
        // not set its own From (was falling through to hello@example.com).
        Event::listen(
            \Illuminate\Mail\Events\MessageSending::class,
            \App\Listeners\ApplyPlatformMailFrom::class
        );
        Event::subscribe(LogQueueEvents::class);

        // Model observers
        Tenant::observe(TenantObserver::class);
        TenantUser::observe(TenantUserObserver::class);
        \App\Models\Tenant\TenantAppointment::observe(\App\Observers\TenantAppointmentObserver::class); // MARKER-PATCH-311
        \App\Models\Tenant\TenantLocation::observe(TenantLocationObserver::class);
    }
}
PMAIL_3_EOF

cat > 'app/Filament/Pages/PlatformEmail.php' <<'PMAIL_4_EOF'
<?php

namespace App\Filament\Pages;

use App\Models\PlatformSettings;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Mail;

/**
 * MARKER-PLATFORM-MAIL — master-admin control of the platform email sender.
 *
 * Applies to platform mail (signups, admin notices) that does not set its
 * own From. Tenant mail continues to use each tenant's configured sender.
 */
class PlatformEmail extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-at-symbol';
    protected static ?string $navigationLabel = 'Platform email';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?int    $navigationSort  = 21;

    protected static string $view = 'filament.pages.platform-email';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = PlatformSettings::current();

        $this->form->fill([
            'mail_from_address' => $settings->mail_from_address,
            'mail_from_name'    => $settings->mail_from_name,
            'test_recipient'    => auth()->user()?->email,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Platform sender')
                    ->description('Used for signup and platform emails that do not set their own sender. Tenant email keeps using each tenant\'s own configured sender.')
                    ->schema([
                        TextInput::make('mail_from_address')
                            ->label('From address')
                            ->email()
                            ->placeholder('hello@intake.works')
                            ->helperText('Must be a verified sender signature (or on a verified domain) in Postmark, or delivery will fail.')
                            ->autocomplete('off'),
                        TextInput::make('mail_from_name')
                            ->label('From name')
                            ->placeholder('Intake')
                            ->maxLength(120)
                            ->autocomplete('off'),
                    ]),

                Section::make('Send a test')
                    ->description('Proves the sender end to end without waiting for a real signup.')
                    ->schema([
                        TextInput::make('test_recipient')
                            ->label('Send test to')
                            ->email()
                            ->autocomplete('off'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        PlatformSettings::current()->update([
            'mail_from_address' => trim((string) ($state['mail_from_address'] ?? '')) ?: null,
            'mail_from_name'    => trim((string) ($state['mail_from_name'] ?? '')) ?: null,
        ]);
        PlatformSettings::forget();

        Notification::make()
            ->success()
            ->title('Platform sender saved')
            ->body('Applies to the next email sent. Send a test to confirm Postmark accepts it.')
            ->send();
    }

    public function sendTest(): void
    {
        $this->save();

        $state = $this->form->getState();
        $to    = trim((string) ($state['test_recipient'] ?? ''));

        if ($to === '') {
            Notification::make()->warning()->title('Enter a recipient first')->send();
            return;
        }

        try {
            Mail::raw(
                "This is a test from Intake master admin.\n\n"
                . 'Sender: ' . (PlatformSettings::fromAddress() ?? '(none configured)') . "\n"
                . 'Sent: ' . now()->toDayDateTimeString(),
                fn ($m) => $m->to($to)->subject('Intake — platform sender test')
            );

            Notification::make()
                ->success()
                ->title('Test sent to ' . $to)
                ->body('Check the From line. If it never arrives, the address is probably not verified in Postmark.')
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Test failed')
                ->body($e->getMessage())
                ->send();
        }
    }
}
PMAIL_4_EOF

cat > 'resources/views/filament/pages/platform-email.blade.php' <<'PMAIL_5_EOF'
<x-filament-panels::page>

    {{-- MARKER-PLATFORM-MAIL --}}
    @php
        $effective    = \App\Models\PlatformSettings::fromAddress();
        $effectiveNm  = \App\Models\PlatformSettings::fromName();
        $isPlaceholder = \App\Models\PlatformSettings::isPlaceholder();
    @endphp

    <div style="padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;
                background: {{ $isPlaceholder ? '#FAEEDA' : '#E1F5EE' }};
                border: 1px solid {{ $isPlaceholder ? '#FAC775' : '#9FE1CB' }};
                color: {{ $isPlaceholder ? '#633806' : '#085041' }};">
        <div style="font-weight: 600; font-size: 14px; margin-bottom: 4px;">
            @if($isPlaceholder)
                ⚠ No platform sender configured — mail is going out as hello@example.com
            @else
                ● Sending as {{ $effectiveNm ? $effectiveNm . ' <' . $effective . '>' : $effective }}
            @endif
        </div>
        <div style="font-size: 12px;">
            @if($isPlaceholder)
                Laravel falls back to its framework default when no sender is set here or in the environment.
            @else
                Applies to platform mail that does not set its own sender. Tenant email is unaffected.
            @endif
        </div>
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 20px; display: flex; gap: 8px;">
            <x-filament::button type="submit">
                Save sender
            </x-filament::button>

            <x-filament::button
                wire:click="sendTest"
                color="gray"
                icon="heroicon-o-paper-airplane">
                Save and send test
            </x-filament::button>
        </div>
    </form>

</x-filament-panels::page>
PMAIL_5_EOF

echo "platform-mail-sender applied — server: git pull && php artisan migrate --force && php artisan view:clear"
