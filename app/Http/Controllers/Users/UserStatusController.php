<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class UserStatusController extends Controller
{
    use HandleFlashMessages;

    /**
     * Suspend the given user by setting the `suspended_at` timestamp.
     *
     * This method:
     * - Attempts to set the `suspended_at` column to the current datetime.
     * - Returns a redirect response with a success flash message if the update is successful.
     * - Logs the error and returns a redirect response with an error flash message if an exception occurs.
     *
     * @param  \App\Models\User $user The user to be suspended.
     * @return \Illuminate\Http\RedirectResponse Redirects back with a flash message.
     *
     * @throws \Exception If the update operation fails unexpectedly.
     */
    public function suspend(User $user): RedirectResponse
    {
        try {

            $user->update(['suspended_at' => now()]);

            return back()->with(
                $this->flashSuccess(__('users.success_suspend_user'))
            );

        } catch (\Exception $e) {
            
            Log::error('Failed to suspend user: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('users.error_suspend_user'))
            );
        }
    }

    /**
     * Unsuspend the given user by clearing the `suspended_at` timestamp.
     *
     * This method:
     * - Sets the `suspended_at` column to null to mark the user as active.
     * - Returns a redirect response with a success flash message if the update succeeds.
     * - Logs any exception and returns a redirect response with an error flash message if an error occurs.
     *
     * @param  \App\Models\User  $user  The user to be unsuspended.
     * @return \Illuminate\Http\RedirectResponse Redirects back with a flash message.
     *
     * @throws \Exception If the update operation fails unexpectedly.
     */
    public function unsuspend(User $user): RedirectResponse
    {

        try {

            $user->update(['suspended_at' => null]);

            return back()->with(
                $this->flashSuccess(__('users.success_unsuspend_user'))
            );

        } catch (\Exception $e) {
            
            Log::error('Failed to unsuspend user: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('users.error_unsuspend_user'))
            );
        }

    }
}
