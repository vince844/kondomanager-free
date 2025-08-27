<?php

namespace App\Http\Controllers\Gestionale\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Resources\Gestionale\Palazzine\PalazzinaResource;
use App\Models\Condominio;
use App\Models\Palazzina;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StrutturaController extends Controller
{
    public function index(Condominio $condominio): Response
    {

          // eager load palazzine (and optionally their scale)
            $condominio->load('palazzine.scale');

            return Inertia::render('gestionale/struttura/Struttura', [
                'condominio' => $condominio,
                'palazzine' => PalazzinaResource::collection($condominio->palazzine)->resolve(), 
                'meta' => [
                    'total' => $condominio->palazzine->count(),
                    // add pagination data if needed
                ],
            ]);
    }

    public function storePalazzina(Request $request, Condominio $condominio)
    {
        $request->validate(['nome' => 'required|string|max:255']);

        $condominio->palazzine()->create([
            'nome' => $request->nome
        ]);

        return redirect()->back()->with('success', 'Palazzina creata');
    }

    public function storeScala(Request $request, Palazzina $palazzina)
    {
        $request->validate(['nome' => 'required|string|max:255']);

        $palazzina->scale()->create([
            'nome' => $request->nome
        ]);

        return redirect()->back()->with('success', 'Scala creata');
    }
}
