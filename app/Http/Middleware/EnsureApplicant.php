<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApplicant
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== Role::Applicant) {
            return redirect()->route('filament.admin.pages.dashboard');
        }

        return $next($request);
    }
}
