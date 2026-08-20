<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;


class DevLoginController extends Controller
{
    public function create(): Response
    {
        abort_unless(app()->isLocal(), 403, 'Dev login is only available in local environment.');

        $users = User::whereNotNull('password')
            ->where('email', 'like', '%@test.local')
            ->orderBy('email')
            ->get(['id', 'email', 'username']);

        return Inertia::render('Dev/Login', [
            'users' => $users,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(app()->isLocal(), 403, 'Dev login is only available in local environment.');

        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return back()->withErrors(['email' => 'Invalid credentials.']);
        }

        DB::table('users')->where('id', $user->id)->update([
            'last_login_at' => now(),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
