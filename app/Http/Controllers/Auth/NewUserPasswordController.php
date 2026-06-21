<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class NewUserPasswordController extends Controller
{
    public function showResetForm(Request $request)
    {
        $user = User::findOrFail($request->query('id'));

        // Valida la firma dell'URL per sicurezza
        if (!$request->hasValidSignature()) {
            return redirect()->route('login')
                ->with('error', __('notifications.new_user_created.link_expired'));
        }

        // Se l'utente ha già una password impostata, il link è già stato usato.
        if ($user->password) {
            return redirect()->route('login')
                ->with('status', __('notifications.new_user_created.password_already_set'));
        }

        return inertia('auth/NewUserCreatePassword', [
            'email' => $user->email,
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'email'    => 'required|email|lowercase|exists:users,email',
            'password' => 'required|min:8|confirmed',
        ]);

        $user = User::where('email', $request->email)->firstOrFail();

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        $user->sendEmailVerificationNotification();

        return redirect()->route('login')->with('success', 'Password updated. Please login.');
    }
}
