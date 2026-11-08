<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Failed attempts within DECAY seconds before logins are refused - counted both per
     * client IP and per user name, so rotating IPs does not help against one account.
     */
    public const MAX_ATTEMPTS = 8;

    public const DECAY = 600;

    public function show(): View|RedirectResponse
    {
        if (! User::query()->exists()) {
            return redirect()->route('setup');
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $username = trim((string) $request->input('username'));
        $keys = ['login:'.$request->ip(), 'login-user:'.Str::lower($username)];

        if (collect($keys)->contains(fn (string $key) => RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS))) {
            return back()->withErrors(['username' => 'Zu viele Fehlversuche. Bitte in einigen Minuten erneut versuchen.'])
                ->withInput(['username' => $username]);
        }

        $credentials = ['username' => $username, 'password' => (string) $request->input('password')];
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            foreach ($keys as $key) {
                RateLimiter::hit($key, self::DECAY);
            }
            Sleep::for(800)->milliseconds();

            return back()->withErrors(['username' => 'Benutzername oder Passwort ist falsch.'])
                ->withInput(['username' => $username]);
        }

        foreach ($keys as $key) {
            RateLimiter::clear($key);
        }
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
