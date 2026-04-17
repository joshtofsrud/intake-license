<?php

namespace App\Listeners;

use App\Services\DebugLogService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Listens for Laravel's built-in auth events on BOTH guards (web = master
 * admin, tenant = shop staff) and routes them to the 'auth' channel of
 * the debug panel.
 *
 * Subscriber pattern so we only register once and cover every event.
 */
class LogAuthEvents
{
    public function __construct(protected DebugLogService $log) {}

    public function handleLogin(Login $event): void
    {
        $user  = $event->user;
        $email = $user->email ?? '(no email)';
        $guard = $event->guard ?? 'web';

        $this->log->auth('login', "Login ($guard): $email", [
            'guard'   => $guard,
            'user_id' => (string) $user->getKey(),
        ], 'info');
    }

    public function handleLogout(Logout $event): void
    {
        $user  = $event->user;
        if (! $user) return;

        $email = $user->email ?? '(no email)';
        $guard = $event->guard ?? 'web';

        $this->log->auth('logout', "Logout ($guard): $email", [
            'guard'   => $guard,
            'user_id' => (string) $user->getKey(),
        ], 'debug');
    }

    public function handleFailed(Failed $event): void
    {
        $guard    = $event->guard ?? 'web';
        $attempted = $event->credentials['email'] ?? '(no email)';

        $this->log->auth('login_failed', "Failed login ($guard): $attempted", [
            'guard'            => $guard,
            'attempted_email'  => $attempted,
        ], 'warning');
    }

    public function handleLockout(Lockout $event): void
    {
        $email = $event->request->input('email') ?? '(unknown)';

        $this->log->auth('lockout', "Auth lockout: $email", [
            'attempted_email' => $email,
        ], 'warning');
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $email = $event->user->email ?? '(no email)';

        $this->log->auth('password_reset', "Password reset: $email", [
            'user_id' => (string) $event->user->getKey(),
        ], 'notice');
    }

    /**
     * Register listeners. Called from AppServiceProvider via Event::subscribe().
     */
    public function subscribe(): array
    {
        return [
            Login::class         => 'handleLogin',
            Logout::class        => 'handleLogout',
            Failed::class        => 'handleFailed',
            Lockout::class       => 'handleLockout',
            PasswordReset::class => 'handlePasswordReset',
        ];
    }
}
