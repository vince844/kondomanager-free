<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee\Seguito;

use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Assemblee\Delibera;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\Seguito\PianoStraordinario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use App\Traits\HandleFlashMessages;

/**
 * «Crea il piano straordinario» dalla cosa da fare di una delibera di lavori (decisione 622 (1)): la proposta della finestra e la finestra confermata. Con
 * «Gestisce le assemblee», come ogni gesto del seguito; la cosa è di una delibera di questa assemblea, l'assemblea di questo condominio (R13). **L'importo
 * arriva in euro come lo scrive l'amministratore e diventa centesimi una volta sola, qui.** Confermata, la pagina va al nuovo piano già compilato.
 */
class PianoStraordinarioController extends Controller
{
    use HandleFlashMessages;

    /** La proposta della finestra, presa dalla delibera. */
    public function proposta(Request $request, Condominio $condominio, Assemblea $assemblea, string $cosa): JsonResponse
    {
        return response()->json(PianoStraordinario::proposta($this->assicuraLaCosa($request, $condominio, $assemblea, $cosa)));
    }

    /** La finestra confermata: la gestione, il suo piano dei conti e la voce; poi il nuovo piano già compilato. */
    public function prepara(Request $request, Condominio $condominio, Assemblea $assemblea, string $cosa): RedirectResponse|JsonResponse
    {
        $laCosa = $this->assicuraLaCosa($request, $condominio, $assemblea, $cosa);
        $dati = $request->validate([
            'gestione_nome' => ['required', 'string', 'max:255'],
            'esercizio_id' => ['required', 'integer'],
            'data_inizio' => ['required', 'date_format:Y-m-d'],
            'data_fine' => ['required', 'date_format:Y-m-d', 'after_or_equal:data_inizio'],
            'voce_nome' => ['required', 'string', 'max:255'],
            'importo' => ['required', 'string', 'max:30', 'regex:/^[0-9.,\s€]*$/u'],
            'tabella_id' => ['required', 'integer'],
            'fornitore_id' => ['nullable', 'integer', 'exists:fornitori,id'],
        ], [
            'gestione_nome.required' => 'Scrivi il nome della gestione.',
            'voce_nome.required' => 'Scrivi il nome della voce.',
            'importo.required' => 'Scrivi l\'importo della voce.',
            'importo.regex' => 'L\'importo si scrive così: 14.500,00.',
            'tabella_id.required' => 'Scegli la tabella della voce.',
            'data_fine.after_or_equal' => 'La fine del periodo viene dopo l\'inizio.',
        ]);
        // Da euro a centesimi una volta sola, al confine.
        $dati['importo'] = MoneyHelper::toCents($dati['importo']);
        $esito = PianoStraordinario::prepara($laCosa, $dati, $request->user());
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($esito);
        }

        return redirect()->to($esito['url'])->with($this->flashSuccess($esito['frase']));
    }

    /** La cosa da fare di una delibera di questa assemblea, con «Gestisce le assemblee»; altrimenti 403 o 404. */
    private function assicuraLaCosa(Request $request, Condominio $condominio, Assemblea $assemblea, string $cosa): CosaDaFareDellaDelibera
    {
        abort_unless((int) $assemblea->condominio_id === (int) $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        abort_unless(ctype_digit($cosa), 404);

        return CosaDaFareDellaDelibera::query()->whereKey((int) $cosa)
            ->whereIn('delibera_id', Delibera::query()->where('assemblea_id', $assemblea->id)->select('id'))->first() ?? abort(404);
    }
}
