<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $data = $r->validate(['email' => ['required', 'email'], 'password' => ['required', 'string', 'max:200']]);
        if (! Auth::attempt($data)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        $r->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }
}
