<?php

namespace App\Http\Controllers\Condomini;

use App\Http\Controllers\Controller;
use App\Models\Condominio;

class FetchCondominiController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke()
    {

        $condomini = Condominio::select('id', 'nome')->orderBy('nome')->get();
        return response()->json($condomini);
        
    }
}
