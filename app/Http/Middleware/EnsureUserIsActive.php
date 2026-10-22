<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->status !== 'active') {
            return response()->json(['message' => 'هذا الحساب معطل. تواصل مع مدير النظام.'], 401);
        }

        return $next($request);
    }
}
