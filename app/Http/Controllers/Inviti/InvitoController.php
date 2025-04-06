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

        // Validate the incoming data
        $request->validate([
            'emails' => 'required|array|min:1',  // Ensure at least one email is provided
            'emails.*' => 'email|unique:inviti,email',  // Validate each email
            'buildings' => 'required|array',  // Ensure building codes are provided
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

        return to_route('inviti.index')->with([
            'message' => [ 
                'type'    => 'success',
                'message' => "Il nuovo invito è stato inviato con successo!"
            ]
        ]);

        // Return a success message
        return back()->with('success', 'Invites sent successfully!');
    }

    /**
     * Display the specified resource.
     */
    public function show(Invito $invito)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Invito $invito)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Invito $invito)
    {
        //
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
