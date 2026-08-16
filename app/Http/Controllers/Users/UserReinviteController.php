<?php

namespace App\Http\Controllers\Users;

use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\NewUserEmailNotification;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

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
        // Reinvitare azzera la password del destinatario: è a tutti gli effetti una modifica
        // dell'utente, e va autorizzata come tale.
        Gate::authorize('update', User::class);

        $user = User::where('email', $email)->first();

        // ⚠️ La guardia sta **fuori** dal `try`, e non è pignoleria: il `catch (\Exception)` qui
        // sotto cattura anche `HttpException`, quindi un `abort(403)` scritto dentro tornerebbe
        // all'utente come un messaggio d'errore generico invece che come un divieto. È lo stesso
        // difetto — il rifiuto travestito da guasto — già pagato nelle beta.43, .48 e .49.
        //
        // Reinvitare **è** un modo di estromettere: la password del destinatario viene azzerata
        // subito sotto. Chi non può concedere un ruolo privilegiato non deve poter chiudere fuori
        // chi ce l'ha, altrimenti la regola sui ruoli si aggira di lato.
        if ($user
            && $user->hasAnyRole(RoleEnum::privilegiati())
            && ! Auth::user()?->hasRole(RoleEnum::AMMINISTRATORE->value)) {
            abort(403, __('policies.edit_users'));
        }

        try {

            $user = $user ?? User::where('email', $email)->firstOrFail();

            // Svuota la password fittizia (se presente) per i vecchi utenti inviati prima della modifica
            $user->update(['password' => null]);

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
