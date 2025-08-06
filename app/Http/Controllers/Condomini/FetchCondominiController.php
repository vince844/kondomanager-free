<?php

namespace App\Http\Controllers\Condomini;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use Illuminate\Http\Request;

class FetchCondominiController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {

        $condomini = Condominio::select('id', 'nome')->orderBy('nome')->get();

        return response()->json($condomini);
        
    }
}
