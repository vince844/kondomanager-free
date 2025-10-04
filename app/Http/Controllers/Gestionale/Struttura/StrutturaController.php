<?php

namespace App\Http\Controllers\Gestionale\Struttura;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Traits\HasCondomini;
use Inertia\Inertia;
use Inertia\Response;

class StrutturaController extends Controller
{
    use HasCondomini;

    public function index(Condominio $condominio): Response
    {
        $condomini = $this->getCondomini();

        return Inertia::render('gestionale/struttura/Struttura', [
            'condominio' => $condominio,
            'condomini'  => $condomini,
        ]);
    }

}
