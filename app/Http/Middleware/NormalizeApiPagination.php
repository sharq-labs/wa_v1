<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeApiPagination
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query->has('per_page')) {
            $raw = $request->query('per_page');
            $perPage = filter_var($raw, FILTER_VALIDATE_INT);

            // Individual endpoints still enforce their own upper bounds. This
            // middleware guarantees paginator constructors never receive zero,
            // negative, or non-integer values that can trigger runtime errors.
            $request->query->set('per_page', max(1, $perPage === false ? 1 : $perPage));
        }

        return $next($request);
    }
}
