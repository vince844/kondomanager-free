<?php

namespace App\Http\Controllers\Anagrafiche;

use App\Http\Controllers\Controller;
use App\Models\Anagrafica;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * La tendina «Destinatari» dei moduli di comunicazioni, scadenze in agenda e documenti dell'archivio, nel pannello.
 *
 * Le sei pagine che la usano (`ComunicazioniNew.vue`, `ComunicazioniEdit.vue`, `EventiNew.vue`, `EventiEdit.vue`,
 * `DocumentiNew.vue`, `DocumentiEdit.vue`) leggono di ogni persona soltanto `id` e `nome`: la risposta porta quei due
 * campi, e la chiede chi apre una di quelle pagine (`AnagraficaPolicy::sceglieDestinatari`, Coda 402). Non è la
 * rubrica, quindi non chiede «Visualizza anagrafiche».
 *
 * I palazzi chiesti non si restringono a quelli di chi chiede: nel pannello le sei pagine offrono tutti i condomìni.
 */
class FetchAnagraficheController extends Controller
{
    public function fetchAnagrafiche(Request $request): JsonResponse
    {
        Gate::authorize('sceglieDestinatari', Anagrafica::class);

        $condomini_ids = $request->get('condomini_ids', []);

        $anagrafiche = Anagrafica::query()
            ->whereHas('condomini', function ($query) use ($condomini_ids) {
                $query->whereIn('condomini.id', $condomini_ids);
            })
            ->get(['anagrafiche.id', 'anagrafiche.nome'])
            ->map(fn (Anagrafica $anagrafica) => ['id' => $anagrafica->id, 'nome' => $anagrafica->nome])
            ->values();

        return response()->json($anagrafiche);
    }
}
