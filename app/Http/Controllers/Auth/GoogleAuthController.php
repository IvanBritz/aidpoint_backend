<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            return redirect($this->frontendUrl('/login?social=google&error=oauth'));
        }

        if (!$googleUser || !$googleUser->getEmail()) {
            return redirect($this->frontendUrl('/login?social=google&error=no-email'));
        }

        // Only allow login for existing accounts (avoid complex provisioning)
        $user = User::where('email', $googleUser->getEmail())->first();

        if (!$user) {
            // Optionally, you could auto-provision here. For now redirect to register with prefill.
            $prefill = urlencode($googleUser->getEmail());
            return redirect($this->frontendUrl('/register?email=' . $prefill));
        }

        // Log the user in (session-based, Sanctum will see the session cookie)
        Auth::login($user, true);

        return redirect($this->frontendUrl('/dashboard'));
    }

    private function frontendUrl(string $path): string
    {
        $base = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));
        return rtrim($base, '/') . $path;
    }
}