<?php

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ImpostazioniController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('impostazioni/impostazioni');
    }
}
