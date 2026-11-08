<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * First run: create the first account. Only reachable while no user exists.
 *
 * In Docker the entrypoint writes a one-time setup code (proxapps:setup-token) to
 * storage/app/private/setup.token and prints it in the container log; while that file
 * exists the code must be entered here, so nobody else on the network can claim the
 * admin account between "docker compose up" and the first login. Without the file
 * (local development) the page works without a code.
 */
class SetupController extends Controller
{
    /** Unambiguous characters (no 0/O, 1/I/L) - the code is read from a log and typed in. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function tokenPath(): string
    {
        return storage_path('app/private/setup.token');
    }

    /**
     * The current setup code; null when none is required. An empty string means the file
     * exists but is empty or unreadable - then no code matches (fail closed).
     */
    public static function currentToken(): ?string
    {
        $path = static::tokenPath();
        if (! is_file($path)) {
            return null;
        }

        return trim((string) @file_get_contents($path));
    }

    /**
     * Create the setup code if no account exists yet (and remove a stale one otherwise).
     * Returns the code to print, or null when an account already exists.
     */
    public static function ensureToken(): ?string
    {
        $path = static::tokenPath();
        if (User::query()->exists()) {
            if (is_file($path)) {
                @unlink($path);
            }

            return null;
        }
        $existing = static::currentToken();
        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        $chars = '';
        for ($i = 0; $i < 20; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $token = implode('-', str_split($chars, 5));

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $old = umask(0077);
        try {
            file_put_contents($path, $token."\n");
        } finally {
            umask($old);
        }
        chmod($path, 0600);

        return $token;
    }

    /** Compare case- and whitespace-insensitively ("abcde-fghij …" is fine). */
    public static function normalizeToken(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }

    public function show(): View|RedirectResponse
    {
        if (User::query()->exists()) {
            return redirect()->route('login');
        }

        return view('auth.setup', ['needsCode' => static::currentToken() !== null]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (User::query()->exists()) {
            return redirect()->route('login');
        }

        $data = [
            'username' => trim((string) $request->input('username')),
            'password' => (string) $request->input('password'),
            'password2' => (string) $request->input('password2'),
        ];

        $validator = Validator::make($data, [
            'username' => ['regex:/^[A-Za-z0-9._@-]{3,64}$/'],
            'password' => ['min:10'],
            'password2' => ['same:password'],
        ], [
            'username.regex' => '3–64 Zeichen: Buchstaben, Ziffern, . _ @ -',
            'password.min' => 'Mindestens 10 Zeichen.',
            'password2.same' => 'Die Passwörter stimmen nicht überein.',
        ]);

        $token = static::currentToken();
        $validator->after(function ($validator) use ($token, $request) {
            if ($token !== null && ($token === ''
                || ! hash_equals(static::normalizeToken($token), static::normalizeToken((string) $request->input('code'))))) {
                $validator->errors()->add('code', 'Der Einrichtungscode stimmt nicht. Er steht im Container-Log (docker logs proxapps).');
            }
        });

        if ($validator->fails()) {
            $errors = $validator->errors();
            // pyref: the confirmation is only reported once the password itself is long enough.
            if ($errors->has('password')) {
                $errors->forget('password2');
            }

            return redirect()->route('setup')->withErrors($errors)->withInput(['username' => $data['username']]);
        }

        // Check and create in one write transaction: two parallel requests cannot both
        // create an account (SQLite runs it as BEGIN IMMEDIATE, see config/database.php).
        $user = DB::transaction(function () use ($data) {
            if (User::query()->exists()) {
                return null;
            }

            return User::query()->create(['username' => $data['username'], 'password' => $data['password']]);
        });
        if ($user === null) {
            return redirect()->route('login');
        }

        if (is_file(static::tokenPath())) {
            @unlink(static::tokenPath());
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('connections.create')
            ->with('success', 'Konto angelegt. Verbinde jetzt deinen Proxmox-Server.');
    }
}
