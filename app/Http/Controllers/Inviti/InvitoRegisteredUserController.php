<?php

namespace App\Http\Controllers\Inviti;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class InvitoRegisteredUserController extends Controller
{
    /**
     * Show the registration form based on the invite.
     */
    public function show(Request $request)
    {

        if (!$request->hasValidSignature()) {
            abort(403);
        } 

        return inertia('auth/RegisterFromInvite', [
            'email' => $request->query('email'),
        ]);

    }
}
