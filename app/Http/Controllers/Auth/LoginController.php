<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;

class LoginController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Main');
    }

    public function redirectToGoogle(): RedirectResponse
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            Log::error('Google Auth Error: ' . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all(),
            ]);

            return redirect()->route('login')->withErrors([
                'error' => 'Google authentication failed. Please try again.',
            ]);
        }

        if (!($googleUser->user['email_verified'] ?? false)) {
            return redirect()->route('login')->withErrors([
                'error' => 'Your Google account must have a verified email address.',
            ]);
        }

        $user = User::where(function ($query) use ($googleUser) {
            $query->where('google_id', '=', $googleUser->id)
                ->orWhere('email', '=', $googleUser->email);
        })->first();

        $isNewUser = false;

        if (!$user) {

            $recentAccounts = User::where('last_ip', '=', $request->ip())->count();
            if ($recentAccounts >= 1) {
                return redirect()->route('login')->withErrors([
                    'error' => 'Only one account per IP address is allowed.',
                ]);
            }

            $user = User::create([
                'username' => $googleUser->email,
                'email' => $googleUser->email,
                'google_id' => $googleUser->id,
                'google_token' => $googleUser->token,
                'google_refresh_token' => $googleUser->refreshToken,
                'last_ip' => $request->ip(),
                'last_login_at' => now(),

            ]);

            $isNewUser = true;
        } else {
            $user->update([
                'google_id' => $googleUser->id,
                'google_token' => $googleUser->token,
                'google_refresh_token' => $googleUser->refreshToken,
                //'last_ip' => $request->ip(), //TODO remove in a few days
                'last_login_at' => now(),
            ]);
        }



        DB::transaction(function () use ($user) {
            DB::table('sessions')
                ->where('user_id', '=', $user->id)
                ->where('id', '!=', session()->getId())
                ->delete();
        });

        if ($isNewUser) {
            return redirect()->route('login')->with('success', 'Registration successful! Please sign in with Google now to enter.');
        }

         

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user) {
            $user->update([
                'last_login_at' => now(),
            ]);
        }
        $userId = $user?->id;

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($userId) {
            DB::table('sessions')
                ->where('user_id', $userId)
                ->delete();
        }

        return redirect()->route('login')->with('success', 'You have been logged out');
    }
}
