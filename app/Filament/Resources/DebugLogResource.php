<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DebugLogResource\Pages;
use App\Models\DebugLog;
use App\Models\Tenant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master admin debug panel.
 *
 * Lives at intake.works/admin/debug-logs. Shows a filterable, searchable,
 * auto-refreshing table of every interesting event on the platform:
 * requests, errors, mail, auth, impersonation, audits, webhooks, jobs.
 *
 * Each row is expandable to show full context (stack traces, payloads,
 * changes). Errors can be marked resolved. A correlation filter lets you
 * jump from an error to every log line that happened during the same
 * request.
 */
class DebugLogResource extends Resource
{
    protected static ?string $model = DebugLog::class;

    protected static ?string $navigationIcon  = 'heroicon-o-bug-ant';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?string $navigationLabel = 'Debug logs';
    protected static ?int    $navigationSort  = 99;
    protected static ?string $recordTitleAttribute = 'message';

    public static function getNavigationBadge(): ?string
    {
        // Shows count next to the menu item when there are unresolved errors in last 24h.
        $count = DebugLog::query()
            ->where('channel', 'error')
            ->where('is_resolved', false)
            ->where('created_at', '>=', now()->subDay())
            ->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    // ----------------------------------------------------------------
    // Read-only — debug logs are written by the service, never edited.
    // ----------------------------------------------------------------
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Event')
                ->schema([
                    Forms\Components\TextInput::make('channel')->disabled(),
                    Forms\Components\TextInput::make('event')->disabled(),
                    Forms\Components\TextInput::make('severity')->disabled(),
                    Forms\Components\TextInput::make('message')->disabled()->columnSpanFull(),
                ])->columns(3),

            Forms\Components\Section::make('Who & where')
                ->schema([
                    Forms\Components\TextInput::make('actor_label')->label('Actor')->disabled(),
                    Forms\Components\TextInput::make('tenant.name')->label('Tenant')->disabled(),
                    Forms\Components\TextInput::make('subject_label')->label('Subject')->disabled(),
                    Forms\Components\TextInput::make('method')->disabled(),
                    Forms\Components\TextInput::make('path')->disabled()->columnSpan(2),
                    Forms\Components\TextInput::make('host')->disabled(),
                    Forms\Components\TextInput::make('status_code')->disabled(),
                    Forms\Components\TextInput::make('duration_ms')->label('Duration (ms)')->disabled(),
                    Forms\Components\TextInput::make('ip')->disabled(),
                ])->columns(3),

            Forms\Components\Section::make('Context')
                ->schema([
                    Forms\Components\KeyValue::make('context')->disabled()->columnSpanFull(),
                ])->collapsible(),

            Forms\Components\Section::make('Resolution')
                ->visible(fn ($record) => $record?->channel === 'error')
                ->schema([
                    Forms\Components\Toggle::make('is_resolved'),
                    Forms\Components\Textarea::make('resolution_note')->rows(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('30s')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('M j g:i:s a')
                    ->description(fn (DebugLog $r) => $r->created_at?->diffForHumans())
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('channel')
                    ->colors([
                        'danger'  => ['error', 'impersonation'],
                        'warning' => ['auth', 'webhook'],
                        'info'    => ['audit'],
                        'primary' => ['mail', 'sms'],
                        'gray'    => ['request', 'job', 'api', 'system'],
                    ]),

                Tables\Columns\BadgeColumn::make('severity')
                    ->colors([
                        'danger'  => ['error', 'critical'],
                        'warning' => 'warning',
                        'info'    => 'notice',
                        'gray'    => ['debug', 'info'],
                    ]),

                Tables\Columns\TextColumn::make('message')
                    ->label('Message')
                    ->wrap()
                    ->limit(120)
                    ->tooltip(fn (DebugLog $r) => $r->message)
                    ->searchable(),

                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->placeholder('—')
                    ->description(fn (DebugLog $r) => $r->tenant?->subdomain)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('actor_label')
                    ->label('Actor')
                    ->limit(40)
                    ->tooltip(fn (DebugLog $r) => $r->actor_label)
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('path')
                    ->label('Path')
                    ->limit(40)
                    ->tooltip(fn (DebugLog $r) => $r->path)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('status_code')
                    ->label('HTTP')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('duration_ms')
                    ->label('ms')
                    ->placeholder('—')
                    ->color(fn (DebugLog $r) => $r->duration_ms > 1500 ? 'danger' : ($r->duration_ms > 500 ? 'warning' : 'gray'))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_resolved')
                    ->label('Resolved')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')
                    ->options([
                        'request'       => 'Request',
                        'error'         => 'Error',
                        'job'           => 'Job',
                        'mail'          => 'Mail',
                        'sms'           => 'SMS',
                        'auth'          => 'Auth',
                        'impersonation' => 'Impersonation',
                        'audit'         => 'Audit',
                        'webhook'       => 'Webhook',
                        'api'           => 'API',
                        'system'        => 'System',
                    ]),

                Tables\Filters\SelectFilter::make('severity')
                    ->options([
                        'critical' => 'Critical',
                        'error'    => 'Error',
                        'warning'  => 'Warning',
                        'notice'   => 'Notice',
                        'info'     => 'Info',
                        'debug'    => 'Debug',
                    ])
                    ->query(function (Builder $q, array $data) {
                        if (! $data['value']) return;
                        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];
                        $min = array_search($data['value'], $levels, true);
                        if ($min !== false) {
                            $q->whereIn('severity', array_slice($levels, $min));
                        }
                    })
                    ->label('Min severity'),

                Tables\Filters\SelectFilter::make('tenant_id')
                    ->label('Tenant')
                    ->options(fn () => Tenant::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),

                Tables\Filters\Filter::make('unresolved_errors')
                    ->label('Unresolved errors only')
                    ->query(fn (Builder $q) => $q->where('channel', 'error')->where('is_resolved', false))
                    ->toggle(),

                Tables\Filters\Filter::make('recent')
                    ->label('Last 24h')
                    ->query(fn (Builder $q) => $q->where('created_at', '>=', now()->subDay()))
                    ->toggle(),

                Tables\Filters\Filter::make('correlation_id')
                    ->form([
                        Forms\Components\TextInput::make('correlation_id')
                            ->label('Correlation ID')
                            ->placeholder('UUID'),
                    ])
                    ->query(function (Builder $q, array $data) {
                        if (! empty($data['correlation_id'])) {
                            $q->where('correlation_id', $data['correlation_id']);
                        }
                    }),

                Tables\Filters\Filter::make('fingerprint')
                    ->form([
                        Forms\Components\TextInput::make('fingerprint')
                            ->label('Error fingerprint')
                            ->placeholder('32-char hash'),
                    ])
                    ->query(function (Builder $q, array $data) {
                        if (! empty($data['fingerprint'])) {
                            $q->where('fingerprint', $data['fingerprint']);
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('trace')
                    ->label('Trace')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->visible(fn (DebugLog $r) => ! empty($r->correlation_id))
                    ->url(fn (DebugLog $r) => static::getUrl('index', [
                        'tableFilters' => ['correlation_id' => ['correlation_id' => $r->correlation_id]],
                    ])),

                Tables\Actions\Action::make('group')
                    ->label('Group')
                    ->icon('heroicon-o-squares-2x2')
                    ->color('gray')
                    ->visible(fn (DebugLog $r) => ! empty($r->fingerprint))
                    ->tooltip('See every row with this error fingerprint')
                    ->url(fn (DebugLog $r) => static::getUrl('index', [
                        'tableFilters' => ['fingerprint' => ['fingerprint' => $r->fingerprint]],
                    ])),

                Tables\Actions\Action::make('resolve')
                    ->label('Mark resolved')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (DebugLog $r) => $r->channel === 'error' && ! $r->is_resolved)
                    ->form([
                        Forms\Components\Textarea::make('note')->label('Resolution note')->rows(3),
                    ])
                    ->action(function (DebugLog $r, array $data) {
                        $r->update([
                            'is_resolved'     => true,
                            'resolved_at'     => now(),
                            'resolved_by'     => auth('web')->id(),
                            'resolution_note' => $data['note'] ?? null,
                        ]);
                        // Resolve every row with the same fingerprint — one decision
                        // closes all instances of the same underlying bug.
                        if ($r->fingerprint) {
                            DebugLog::where('fingerprint', $r->fingerprint)
                                ->where('is_resolved', false)
                                ->update([
                                    'is_resolved'     => true,
                                    'resolved_at'     => now(),
                                    'resolved_by'     => auth('web')->id(),
                                    'resolution_note' => $data['note'] ?? null,
                                ]);
                        }
                        Notification::make()->success()->title('Marked resolved')->send();
                    }),

                Tables\Actions\Action::make('reopen')
                    ->label('Reopen')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (DebugLog $r) => $r->channel === 'error' && $r->is_resolved)
                    ->action(function (DebugLog $r) {
                        $r->update([
                            'is_resolved'     => false,
                            'resolved_at'     => null,
                            'resolved_by'     => null,
                            'resolution_note' => null,
                        ]);
                        Notification::make()->warning()->title('Reopened')->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('resolve')
                        ->label('Mark resolved')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update([
                            'is_resolved' => true,
                            'resolved_at' => now(),
                            'resolved_by' => auth('web')->id(),
                        ])),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDebugLogs::route('/'),
            'view'  => Pages\ViewDebugLog::route('/{record}'),
        ];
    }

    /** Disable creation — logs are written by the service. */
    public static function canCreate(): bool
    {
        return false;
    }
}
