<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * "Passwort ändern" for the signed-in user.
 */
class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('profile.edit');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $current = (string) $request->input('current');
        $password = (string) $request->input('password');

        $errors = new MessageBag;
        if (! Hash::check($current, $user->password)) {
            $errors->add('current', 'Das aktuelle Passwort ist falsch.');
        }
        if (strlen($password) < 10) {
            $errors->add('password', 'Mindestens 10 Zeichen.');
        } elseif ($password !== (string) $request->input('password2')) {
            $errors->add('password2', 'Die Passwörter stimmen nicht überein.');
        }

        if ($errors->isNotEmpty()) {
            return redirect()->route('profile.edit')->withErrors($errors);
        }

        // A new password ends every other login: a fresh remember token invalidates all
        // "angemeldet bleiben" cookies, and the user's other sessions are deleted.
        $user->forceFill(['password' => $password]);
        $user->setRememberToken(Str::random(60));
        $user->save();
        $request->session()->regenerate(true);
        static::deleteOtherSessions($user->getAuthIdentifier(), $request->session()->getId());

        return redirect()->route('profile.edit')->with('success', 'Passwort geändert.');
    }

    /** Remove all stored sessions of this user except the current one (database driver). */
    public static function deleteOtherSessions(int|string $userId, string $currentId): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }
        DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->where('id', '!=', $currentId)
            ->delete();
    }
}
