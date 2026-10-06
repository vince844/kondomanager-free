<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ModuloFirmato;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Le due rotte sono firmate (`routes/web.php`): la firma la verifica il middleware `signed` prima di
 * arrivare qui, e un link scaduto o manomesso non entra. L'`id` della query è quindi quello del link
 * mandato per email, e l'utente si carica da lì; l'email del corpo non conta.
 */
class NewUserPasswordController extends Controller
{
    public function showResetForm(Request $request)
    {
        $user = User::findOrFail($request->query('id'));

        if ($rifiuto = $this->linkNonPiuBuono($user, $request)) {
            return $rifiuto;
        }

        return inertia('auth/NewUserCreatePassword', [
            'email'  => $user->email,
            // Il modulo si manda a questo stesso URL, firma compresa: il `POST` chiede la stessa prova.
            'azione' => ModuloFirmato::azione($request),
        ]);
    }

    public function reset(Request $request)
    {
        $user = User::findOrFail($request->query('id'));

        if ($rifiuto = $this->linkNonPiuBuono($user, $request)) {
            return $rifiuto;
        }

        $request->validate([
            'password' => 'required|min:8|confirmed',
        ]);

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        $user->sendEmailVerificationNotification();

        return redirect()->route('login')
            ->with('status', __('notifications.new_user_created.password_set'));
    }

    /**
     * Il link serve una volta sola, e solo per l'account com'era quando è partito. Con la password
     * già impostata è stato usato. Con un'impronta diversa da quella di adesso la riga dell'utente è
     * cambiata dopo l'invio (email o nome corretti, «reinvia», sospensione, verifica:
     * `User::improntaPrimoAccesso()`; ruoli, permessi e anagrafica no), e chi ha il link in mano non
     * deve poter scegliere la password: serve un nuovo invio. I rifiuti vanno nell'`avviso` del
     * login, non nello `status`, che è verde ed è per le conferme.
     */
    private function linkNonPiuBuono(User $user, Request $request): ?RedirectResponse
    {
        if ($user->password) {
            return redirect()->route('login')
                ->with('avviso', __('notifications.new_user_created.password_already_set'));
        }

        if (! hash_equals($user->improntaPrimoAccesso(), (string) $request->query('hash'))) {
            return redirect()->route('login')
                ->with('avviso', __('notifications.new_user_created.link_expired'));
        }

        return null;
    }
}
