<?php

namespace App\Filament\Pages;

use App\Models\PlatformPosAdapter;
use App\Models\Tenant\TenantPosConnection;
use App\Services\Pos\Adapters\PosAdapterRegistry;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * MARKER-POS-CONNECT: Intake's own app credentials for each POS adapter.
 * Shops never see these; they connect from E-commerce → Shop POS.
 */
class PosAdapters extends Page implements HasForms
{
    use \App\Support\UsesAdminNav;
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'catalog';

    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationLabel = 'POS connections';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 30;
    protected static ?string $title           = 'POS connections';

    protected static string $view = 'filament.pages.pos-adapters';

    public ?array $data = [];

    public function mount(): void
    {
        $fill = [];
        foreach (app(PosAdapterRegistry::class)->codes() as $code) {
            $row = PlatformPosAdapter::forCode($code);
            $fill[$code] = [
                'label'         => $row->label,
                'is_active'     => $row->is_active,
                'client_id'     => $row->client_id,
                'client_secret' => '',
                'scopes'        => $row->scopes,
                'authorize_url' => $row->authorize_url,
                'token_url'     => $row->token_url,
                'api_base'      => $row->api_base,
                'redirect_uri'  => $row->redirect_uri,
            ];
        }
        $this->form->fill($fill);
    }

    public function form(Form $form): Form
    {
        $sections = [];
        foreach (app(PosAdapterRegistry::class)->codes() as $code) {
            $row = PlatformPosAdapter::forCode($code);
            $shops = TenantPosConnection::where('adapter_code', $code)->count();
            $sections[] = Section::make($row->displayLabel())
                ->description(new HtmlString(
                    'Adapter <code>' . e($code) . '</code> · ' . $shops . ' shop' . ($shops === 1 ? '' : 's') . ' connected · '
                    . ($row->configured() ? 'credentials saved' : '<b>credentials missing</b>')
                ))
                ->statePath($code)
                ->columns(2)
                ->schema([
                    Toggle::make('is_active')->label('Shops can connect')
                        ->helperText('Off hides the Connect button for every shop. Shops already connected stay connected.')
                        ->columnSpanFull(),
                    TextInput::make('label')->label('Name shown to shops')->required()->maxLength(120),
                    // MARKER-POS-CALLBACK
                    TextInput::make('redirect_uri')->label('Callback URL (register this exact value with the POS)')->url()->maxLength(300)
                        ->placeholder(PlatformPosAdapter::defaultCallbackUrl())
                        ->helperText('Blank uses ' . PlatformPosAdapter::defaultCallbackUrl() . '. Must match the POS registration exactly and point at an address Intake answers on.'),
                    TextInput::make('client_id')->label('Client ID')->maxLength(500),
                    TextInput::make('client_secret')->label('Client secret')->password()->revealable()
                        ->placeholder(filled($row->client_secret) ? 'Saved — leave blank to keep' : 'Not set'),
                    TextInput::make('scopes')->label('Scopes requested')->columnSpanFull()
                        ->helperText('Space-separated. Shops approve these when they connect; changing them means shops reconnect to get the new ones.'),
                    TextInput::make('authorize_url')->label('Authorize URL')->url(),
                    TextInput::make('token_url')->label('Token URL')->url(),
                    TextInput::make('api_base')->label('API base URL')->url()->columnSpanFull(),
                ]);
        }

        return $form->schema($sections)->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        foreach (app(PosAdapterRegistry::class)->codes() as $code) {
            $in = $state[$code] ?? [];
            $row = PlatformPosAdapter::forCode($code);
            $row->fill([
                'label'         => $in['label'] ?? $row->label,
                'is_active'     => (bool) ($in['is_active'] ?? false),
                'client_id'     => $in['client_id'] ?? null,
                'scopes'        => $in['scopes'] ?? null,
                'authorize_url' => $in['authorize_url'] ?? null,
                'token_url'     => $in['token_url'] ?? null,
                'api_base'      => $in['api_base'] ?? null,
                'redirect_uri'  => $in['redirect_uri'] ?? null,
            ]);
            if (filled($in['client_secret'] ?? null)) {
                $row->client_secret = $in['client_secret'];
            }
            $row->save();

            if ($row->is_active && ! $row->configured()) {
                Notification::make()->warning()->title($row->displayLabel() . ' is switched on but missing credentials or URLs — shops won\'t see it until they are filled in.')->send();
            }
        }
        $this->mount();
        Notification::make()->success()->title('POS connections saved')->send();
    }
}
