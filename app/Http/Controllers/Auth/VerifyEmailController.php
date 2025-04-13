<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\RedirectHelper;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {

        $user = Auth::user();

        if ($request->user()->hasVerifiedEmail()) {

            return redirect()->intended(RedirectHelper::userHomeRoute() . '?verified=1');
        }

        if ($request->user()->markEmailAsVerified()) {
            /** @var \Illuminate\Contracts\Auth\MustVerifyEmail $user */
            $user = $request->user();
            event(new Verified($user));
        }

        return redirect()->intended(RedirectHelper::userHomeRoute() . '?verified=1');

    }
}
