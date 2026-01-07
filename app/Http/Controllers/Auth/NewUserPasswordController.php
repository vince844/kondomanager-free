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
        // Validate signed URL
        if (!$request->hasValidSignature()) {
            abort(403);
        } 

        return inertia('auth/NewUserCreatePassword', [
            'email' => $request->query('email'),
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
