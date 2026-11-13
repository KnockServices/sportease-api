<?php

namespace App\Http\Middleware;

use App\Services\Branch;
use Closure;
use Illuminate\Http\Request;

/** Binds the request's working branch (see App\Services\Branch). */
class ResolveBranch
{
    public function handle(Request $request, Closure $next)
    {
        app()->instance('branch.id', Branch::resolve($request));

        return $next($request);
    }
}
