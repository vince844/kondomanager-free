<?php

namespace App\Http\Controllers\Inviti;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inviti\InvitoResource;
use App\Models\Condominio;
use App\Models\Invito;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Notifications\InviteUserNotification;
use App\Traits\HandleFlashMessages;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Response;

class InvitoController extends Controller
{
    use HandleFlashMessages;

    /**
     * Display a listing of the inviti.
     *
     * This method retrieves all `Invito` records from the database and formats them 
     * using the `InvitoResource`. The result is then passed to a Vue component via Inertia.js.
     *
     * @return \Inertia\Response
     */
    public function index(): Response
    {
        return Inertia::render('inviti/ElencoInviti', [
            'inviti' => InvitoResource::collection(Invito::all())
        ]); 
    }

    /**
     * Show the form to create a new invito.
     *
     * This method returns a response that renders the 'inviti/NuovoInvito' Vue component.
     * It also passes a list of all `Condominio` records to the Vue component, which can 
     * be used to display available buildings for the invito creation form.
     *
     * @return \Inertia\Response
     */
    public function create(): Response
    {
        return Inertia::render('inviti/NuovoInvito',[
            'buildings' => Condominio::all()
        ]);
    }

    /**
     * Store a newly created inviti in the database and send an invite notification.
     *
     * This method handles the form submission to create new inviti. It validates the incoming request,
     * processes each email address, creates an `Invito` record, and sends an email invite notification.
     * A transaction is used to ensure all operations succeed or fail together.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     * 
     * @throws \Exception
     */
    public function store(Request $request): RedirectResponse
    {
        try {

            DB::beginTransaction();

            $request->validate([
                'emails'    => 'required|array|min:1',  
                'emails.*'  => 'email|unique:inviti,email',  
                'buildings' => 'required|array', 
            ]);

            // Loop through each email and create an invite
            foreach ($request->emails as $email) {
                
                $invito = Invito::create([
                    'email'          => $email,
                    'building_codes' => $request->buildings,
                    'expires_at'     => Carbon::now()->addMinutes(60),
                ]);

                $invito->notify(new InviteUserNotification($invito));

            }

            DB::commit();

            return to_route('inviti.index')->with(
                $this->flashSuccess(__('users.success_send_user_invite'))
            );

        } catch (Exception $e) {
            
            DB::rollback();

            Log::error('Error sending invite: ' . $e->getMessage());

            return to_route('utenti.index')->with(
                $this->flashError(__('users.error_send_user_invite'))
            );

        }

    }

    /**
     * Remove the specified invito from the database.
     *
     * This method attempts to delete the specified `Invito` record from the database.
     * If successful, it redirects the user back with a success message. 
     * In case of any errors during the deletion process, an error message is logged, 
     * and the user is redirected back with an error message.
     *
     * @param \App\Models\Invito $inviti
     * @return \Illuminate\Http\RedirectResponse
     * 
     * @throws \Exception
     */
    public function destroy(Invito $inviti): RedirectResponse
    {

        try {

            $inviti->delete();

            return back()->with(
                $this->flashSuccess(__('users.success_delete_user_invite'))
            );

        } catch (\Exception $e) {
            
            Log::error('Error deleting invito: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('users.error_delete_user_invite'))
            );
        }

    }
}
