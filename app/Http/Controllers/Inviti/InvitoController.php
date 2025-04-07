<?php

namespace App\Http\Controllers\Inviti;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inviti\InvitoResource;
use App\Models\Condominio;
use App\Models\Invito;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Notifications\InviteUserNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;

class InvitoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('inviti/ElencoInviti', [
            'inviti' => InvitoResource::collection(Invito::all())
        ]); 
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('inviti/NuovoInvito',[
            'buildings' => Condominio::all()
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {

            DB::beginTransaction();

            $request->validate([
                'emails' => 'required|array|min:1',  
                'emails.*' => 'email|unique:inviti,email',  
                'buildings' => 'required|array', 
            ]);

            // Loop through each email and create an invite
            foreach ($request->emails as $email) {
                
                $invito = Invito::create([
                    'email' => $email,
                    'building_codes' => $request->buildings,
                    'expires_at' => Carbon::now()->addMinutes(60),
                ]);

                $invito->notify(new InviteUserNotification($invito));

            }

            DB::commit();

            return to_route('inviti.index')->with([
                'message' => [ 
                    'type'    => 'success',
                    'message' => "Il nuovo invito è stato inviato con successo!"
                ]
            ]);

        } catch (Exception $e) {
            
            DB::rollback();

            Log::error('Error sending invite: ' . $e->getMessage());

            return to_route('inviti.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante l'invio dell'invito!"
                ]
            ]);
        }

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Invito $inviti)
    {
        $inviti->delete();

        return back()->with([
            'message' => [ 
                'type'    => 'success',
                'message' => "L'invito è stato eliminato con successo"
            ]
        ]);
    }
}
