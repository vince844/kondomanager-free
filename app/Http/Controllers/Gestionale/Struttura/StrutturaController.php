<?php

namespace App\Http\Controllers\Gestionale\Struttura;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use Inertia\Inertia;
use Inertia\Response;

class StrutturaController extends Controller
{

    public function index(Condominio $condominio): Response
    {
        return Inertia::render('gestionale/struttura/Struttura', [
            'condominio' => $condominio,
        ]);
    }

}
