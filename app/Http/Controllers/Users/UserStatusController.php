<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class UserStatusController extends Controller
{
    public function suspend(User $user)
    {
        $user->update([
            'suspended_at' => Carbon::now(),
        ]);

        return back()->with([
            'message' => [ 
                'type'    => 'success',
                'message' => "L'utente è stato sospeso con successo"
            ]
        ]);
    
    }

    public function unsuspend(User $user)
    {
        $user->update([
            'suspended_at' => null,
        ]);

        return back()->with([
            'message' => [ 
                'type'    => 'success',
                'message' => "L'utente è stato riattivato con successo"
            ]
        ]);

    }
}
