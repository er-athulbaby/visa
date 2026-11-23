<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class RestrictAdminIp
{
    public function handle(Request $request, Closure $next)
    {
        $allowed = config('visa.admin_ip_allowlist');

        abort_if($allowed && ! IpUtils::checkIp((string) $request->ip(), $allowed), 403);

        return $next($request);
    }
}
