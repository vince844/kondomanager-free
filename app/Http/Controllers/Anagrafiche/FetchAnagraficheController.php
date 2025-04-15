<?php

namespace App\Http\Controllers\Anagrafiche;

use App\Http\Controllers\Controller;
use App\Models\Anagrafica;
use Illuminate\Http\Request;

class FetchAnagraficheController extends Controller
{
    public function fetchAnagrafiche(Request $request)
    {
         // Get the condomini_ids from the request
         $condomini_ids = $request->get('condomini_ids', []);

         // Fetch the anagrafiche based on the selected condomini
         $anagrafiche = Anagrafica::whereHas('condomini', function ($query) use ($condomini_ids) {
             $query->whereIn('condomini.id', $condomini_ids); // Explicitly use 'condomini.id'
         })->get();
 
         // Return the data as JSON response
         return response()->json($anagrafiche);
    }

}
