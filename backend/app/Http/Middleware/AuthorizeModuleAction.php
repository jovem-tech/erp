<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeModuleAction
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        Gate::authorize($ability);

        return $next($request);
    }
}
