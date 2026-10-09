<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives the admin area its own session: a different cookie name, scoped to
 * /admin, stored in its own table. A customer session cookie is therefore
 * never read on /admin, and the admin cookie is never sent to customer pages.
 *
 * Runs first in the web group, before the session starts.
 */
class UseAdminSession
{
    /** @var array{cookie: string, path: string, table: string}|null customer session settings, captured once */
    private static ?array $customer = null;

    public function __construct(private SessionManager $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        self::$customer ??= [
            'cookie' => (string) config('session.cookie'),
            'path' => (string) config('session.path'),
            'table' => (string) config('session.table'),
        ];

        $settings = self::isAdminRequest($request) ? config('nadabo.admin_session') : self::$customer;

        if (config('session.cookie') !== $settings['cookie']) {
            config([
                'session.cookie' => $settings['cookie'],
                'session.path' => $settings['path'],
                'session.table' => $settings['table'],
            ]);

            // Rebuild the session store (and its container binding) with the settings above.
            $this->sessions->forgetDrivers();
            app()->forgetInstance('session.store');
        }

        return $next($request);
    }

    public static function isAdminRequest(Request $request): bool
    {
        return $request->is('admin', 'admin/*');
    }
}
