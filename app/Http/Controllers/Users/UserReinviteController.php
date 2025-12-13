<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\NewUserEmailNotification;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
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

        try {

            $user = User::where('email', $email)->first();
            
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
