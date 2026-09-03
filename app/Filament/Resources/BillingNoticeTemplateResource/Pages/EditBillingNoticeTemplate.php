<?php
// MARKER-BILLING-NOTICES / MARKER-BILLING-NOTICE-MAIL
namespace App\Filament\Resources\BillingNoticeTemplateResource\Pages;

use App\Filament\Resources\BillingNoticeTemplateResource;
use App\Services\Billing\BillingNoticeService;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditBillingNoticeTemplate extends EditRecord
{
    protected static string $resource = BillingNoticeTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // MARKER-BILLING-NOTICE-MAIL — see it before a shop does. Uses the
            // same send path as the real thing, so a test cannot look different
            // from what actually arrives.
            Actions\Action::make('sendTest')
                ->label('Send test')
                ->icon('heroicon-o-paper-airplane')
                ->form([
                    TextInput::make('to')
                        ->label('Send to')
                        ->email()
                        ->required()
                        ->default(Auth::guard('web')->user()?->email)
                        ->helperText('Sample values are filled in for every placeholder.'),
                ])
                ->action(function (array $data) {
                    $svc    = app(BillingNoticeService::class);
                    $tokens = $svc->sampleTokens();

                    $subject = strtr($this->record->subject, $tokens);
                    $body    = strtr($this->record->body, $tokens);

                    $ok = $svc->sendMail(
                        $data['to'],
                        '[Test] ' . $subject,
                        $body,
                        $tokens['{link}'],
                        $tokens['{shop}'],
                    );

                    $ok
                        ? Notification::make()->success()
                            ->title('Test sent to ' . $data['to'])
                            ->body('It is marked [Test] in the subject so it cannot be mistaken for a real notice.')
                            ->send()
                        : Notification::make()->danger()
                            ->title('Could not send')
                            ->body('Check the platform sending address on Platform email.')
                            ->send();
                }),
        ];
    }
}
