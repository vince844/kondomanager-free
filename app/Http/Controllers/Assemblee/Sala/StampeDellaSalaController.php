<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee\Sala;

use App\Http\Controllers\Controller;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\Sala\FoglioDellaSala;
use App\Services\Assemblee\Sala\ProspettoDellaSala;
use App\Services\PDF\PdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Le due stampe della sala: **il foglio della sala** («Gestisce le assemblee», dalla prima spedizione fino alla chiusura), per tenere la sala sulla carta;
 * **le presenze e i voti** («Vede le assemblee», da quando la sala si è aperta o la prima convocazione è deserta), il prospetto che il verbale richiama.
 * In ogni metodo la guardia del condominio dell'assemblea; prima del tempo la stampa non c'è (404). La stampa del foglio ne scrive l'ora (`foglio_stampato_il`);
 * a sala aperta il foglio si stampa anche compilato con quello che c'è (`?compilato=1`).
 */
class StampeDellaSalaController extends Controller
{
    /** Il foglio della sala. */
    public function foglio(Request $request, Condominio $condominio, Assemblea $assemblea, FoglioDellaSala $foglio): Response
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        abort_unless(FoglioDellaSala::siStampa($assemblea), 404);
        // «Stampa il foglio con quello che c'è» (`?compilato=1`): solo a sala aperta.
        $compilato = $request->boolean('compilato');
        abort_if($compilato && ! FoglioDellaSala::siStampaCompilato($assemblea), 404);
        $pdf = $foglio->pdf($assemblea, $compilato);
        $foglio->stampato($assemblea);

        return self::pdf($pdf, PdfService::nomeFile($compilato ? 'foglio-della-sala-compilato' : 'foglio-della-sala', (string) $condominio->nome,
            $assemblea->data?->format('Y-m-d')));
    }

    /** Le presenze e i voti. */
    public function prospetto(Request $request, Condominio $condominio, Assemblea $assemblea, ProspettoDellaSala $prospetto): Response
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        abort_unless(ProspettoDellaSala::siStampa($assemblea), 404);

        return self::pdf($prospetto->pdf($assemblea), PdfService::nomeFile('presenze-e-voti', (string) $condominio->nome, $assemblea->data?->format('Y-m-d')));
    }

    private static function pdf(string $contenuto, string $nome): Response
    {
        return response($contenuto, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $nome . '"']);
    }
}
