<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\NewUserEmailNotification;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UserReinviteController extends Controller
{
    use HandleFlashMessages;

    /**
     * Reinvites a user by sending a new invitation email.
     *
     * This method:
     * - Retrieves the user from the database using the provided email address.
     * - Sends a notification (`NewUserEmailNotification`) to the user.
     * - Redirects the user back to the user list page with a success flash message if the invitation is sent successfully.
     * - Logs any errors and redirects back to the user list page with an error flash message if an exception occurs.
     *
     * @param string $email The email address of the user to be reinvited.
     * @return \Illuminate\Http\RedirectResponse Redirects back to the user index with a flash message indicating success or failure.
     *
     * @throws \Exception If the user could not be found or there was an error sending the notification.
     */
    public function reinviteUser(string $email): RedirectResponse
    {
        $user = User::where('email', $email)->first();

        // ⚠️ La guardia sta **fuori** dal `try`, e non è pignoleria: il `catch (\Exception)` qui
        // sotto cattura anche il rifiuto, quindi scritto dentro tornerebbe all'utente come un
        // messaggio d'errore generico invece che come un divieto. È lo stesso difetto — il rifiuto
        // travestito da guasto — già pagato nelle beta.43, .48 e .49.
        //
        // Reinvitare **è** un modo di estromettere: la password del destinatario viene azzerata
        // subito sotto. `UserPolicy::reinvite()` chiede «Modifica utenti» e, sul bersaglio, le
        // guardie di sospensione ed eliminazione (Coda 399): non sé stessi, non l'ultimo
        // amministratore attivo, e chi non è amministratore solo un account nella sua portata
        // (`User::haNellaPortata()`, Coda 258, decisione 134.1). Il rifiuto dice perché. Un'email che
        // non è di nessuno ha il solo permesso, e finisce nel messaggio d'errore qui sotto.
        Gate::authorize('reinvite', $user ?? User::class);

        try {

            $user = $user ?? User::where('email', $email)->firstOrFail();

            // Un `remember_token` nuovo: il cookie «ricordami» degli altri dispositivi non riconosce più
            // l'utente, e quel browser torna a essere quello di un ospite che può aprire il link nuovo.
            // Con il token di prima e la password vuota il controllo del cookie finiva in un errore a
            // ogni pagina di quel browser, compreso il link (Coda 202, punto 1).
            $user->setRememberToken(Str::random(60));

            // Svuota la password fittizia (se presente) per i vecchi utenti inviati prima della modifica
            $user->update(['password' => null]);

            // «Reinvia» rende morti i link mandati prima, anche quando la password era già vuota e
            // `update()` non ha salvato niente: l'impronta del link (`User::improntaPrimoAccesso()`)
            // segue `updated_at` (1.11.0-beta.45).
            $user->touch();

            $user->notify(new NewUserEmailNotification($user));
    
            return to_route('utenti.index')->with(
                $this->flashSuccess(__('users.success_send_user_invite'))
            );

        } catch (\Exception $e) {

            Log::error('Error reinviting user: ' . $e->getMessage());

            return to_route('utenti.index')->with(
                $this->flashError(__('users.error_send_user_invite'))
            );
        
        }
    }

}
