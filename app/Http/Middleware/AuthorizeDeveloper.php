<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuthorizeDeveloper
{
    public function handle(Request $request, Closure $next)
    {
        Gate::authorize('manage', SystemSetting::class);

        return $next($request);
    }
}
