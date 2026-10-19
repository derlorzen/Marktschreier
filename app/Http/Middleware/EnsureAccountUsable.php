<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Gesperrte Benutzer fliegen raus, wer ein Startpasswort hat, muss es zuerst ändern. */
class EnsureAccountUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->active) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->with('flash', 'Dein Zugang ist gesperrt.');
        }
        if ($user && $user->must_change_password && ! $request->routeIs('settings.account', 'logout') && ! $request->is('livewire*')) {
            return redirect()->route('settings.account');
        }

        return $next($request);
    }
}
