<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\HandleFlashMessages;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class UserVerifyController extends Controller
{
    use HandleFlashMessages;
    /**
     * Handle the incoming request.
     */
    public function __invoke(User $user): RedirectResponse
    {
        try {
            // Autorizzazione
            Gate::authorize('update', User::class);

            // Toggle verifica
            $user->email_verified_at = $user->email_verified_at ? null : now();
            $user->save();

            $message = $user->email_verified_at
                ? $this->flashSuccess('Utente verificato correttamente.')
                : $this->flashWarning('Verifica utente revocata.');

            return back()->with($message);

        } catch (\Throwable $e) {
            Log::error('Errore durante la verifica utente ID ' . $user->id . ': ' . $e->getMessage());

            return back()->with(
                $this->flashError('Errore durante la verifica dell\'utente. Riprova più tardi.')
            );
        }
    }

}
