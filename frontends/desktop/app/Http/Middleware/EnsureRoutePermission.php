<?php

namespace App\Http\Middleware;

use App\Support\DesktopNavigation;
use App\Support\DesktopSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRoutePermission
{
    public function handle(Request $request, Closure $next, string $module, string $action = 'visualizar'): Response
    {
        $actions = array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode('|', $action)
        )));

        if ($actions === []) {
            $actions = ['visualizar'];
        }

        foreach ($actions as $candidate) {
            if (DesktopSession::can($module, $candidate)) {
                return $next($request);
            }
        }

        $targetRoute = DesktopNavigation::firstAllowedRouteName();

        if ($request->route()?->getName() === $targetRoute) {
            abort(403, 'Você não tem permissão para acessar este recurso.');
        }

        return redirect()
            ->route($targetRoute)
            ->with('error', 'Você não tem permissão para acessar este recurso.');
    }
}
