<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\RegistroModifiche;

use App\Http\Controllers\Controller;
use App\Services\RegistroModifiche\AccessoAiCasiSpeciali;
use App\Services\RegistroModifiche\OscuramentoDelRegistro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Oscura i suoi dati nel registro» (§10 del progetto, decisione 518 (2), bozza scena 4), solo per l'amministratore (la rotta ha il ruolo; qui lo si
 * ricontrolla). L'anteprima per la finestra `FinestraOscuramento.vue` (chi è, quante righe, dal … al …, le righe, le altre righe con i suoi dati) e
 * l'oscuramento, con il perché obbligatorio e le altre righe scelte. JSON: la finestra è la stessa nella «Storia» di una persona e di un fornitore.
 */
class OscuramentoController extends Controller
{
    public function anteprima(Request $request, string $soggetto_tipo, int $soggetto_id): JsonResponse
    {
        abort_unless(AccessoAiCasiSpeciali::amministratore($request->user()), 403);
        abort_unless(isset(OscuramentoDelRegistro::TIPI[$soggetto_tipo]), 404);

        return response()->json(OscuramentoDelRegistro::anteprima($soggetto_tipo, $soggetto_id));
    }

    public function oscura(Request $request, string $soggetto_tipo, int $soggetto_id): JsonResponse
    {
        abort_unless(AccessoAiCasiSpeciali::amministratore($request->user()), 403);
        abort_unless(isset(OscuramentoDelRegistro::TIPI[$soggetto_tipo]), 404);

        $dati = $request->validate([
            'perche' => ['required', 'string', 'max:2000'],
            'altre' => ['nullable', 'array', 'max:' . OscuramentoDelRegistro::MASSIMO_ALTRE],
            'altre.*' => ['integer'],
        ], ['perche.required' => 'Scrivi perché oscuri i suoi dati: per esempio «Richiesta scritta del 10/03/2027, protocollo 41».'], ['perche' => 'perché']);

        $riga = OscuramentoDelRegistro::oscura($soggetto_tipo, $soggetto_id, (string) $dati['perche'], array_map('intval', $dati['altre'] ?? []));
        $quante = count($riga->lotto['righe'] ?? []);
        $giorno = (string) ($riga->lotto['oscurato_il'] ?? '');

        return response()->json([
            'righe' => $quante,
            'oscurato_il' => $giorno,
            'riga_id' => $riga->id,
            'esito' => $quante === 1 ? "Fatto: 1 riga oscurata il {$giorno}." : "Fatto: {$quante} righe oscurate il {$giorno}.",
        ]);
    }
}
