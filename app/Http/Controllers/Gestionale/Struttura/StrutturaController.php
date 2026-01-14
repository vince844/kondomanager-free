<?php

namespace App\Http\Controllers\Gestionale\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Inertia\Inertia;
use Inertia\Response;

class StrutturaController extends Controller
{
    use HasCondomini, HasEsercizio;

    public function index(Condominio $condominio): Response
    {

        $condomini = $this->getCondomini();

        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/struttura/Struttura', [
            'condominio' => new CondominioResource($condominio),
            'condomini'  => $condomini,
            'esercizio'  => $esercizio
        ]);
    }

}
