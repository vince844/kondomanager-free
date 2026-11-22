<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\SchedaDelCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * «Apri il piano», «Apri» nel seguito delle delibere: le righe del riepilogo e del passo 6 conoscono la scheda con il tipo e l'id, e questa rotta porta alla
 * sua pagina (il piano nel suo esercizio, la gestione nell'esercizio più recente, il rendiconto del suo esercizio, la fattura, il fondo, le rate scadute di
 * chi ha l'accordo, le dispense, il recupero dei crediti, la comunicazione). La scheda è del condominio della rotta, altrimenti 404; le pagine di arrivo
 * controllano i loro permessi.
 */
class ApriLaSchedaController extends Controller
{
    public function __invoke(Request $request, Condominio $condominio, string $tipo, int $id): RedirectResponse
    {
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $scheda = $this->assicuraLaScheda($condominio, $tipo, $id);

        return redirect(self::indirizzo($condominio, $tipo, $scheda) ?? abort(404));
    }

    /** La scheda è del condominio dell'indirizzo (`SchedaDelCondominio::cerca` lo controlla); altrimenti 404. */
    private function assicuraLaScheda(Condominio $condominio, string $tipo, int $id): Model
    {
        return SchedaDelCondominio::cerca($condominio, $tipo, $id) ?? abort(404);
    }

    /** L'indirizzo della pagina della scheda, o null se la scheda non ne ha una. */
    public static function indirizzo(Condominio $condominio, string $tipo, Model $scheda): ?string
    {
        $c = (int) $condominio->id;
        $id = (int) $scheda->getKey();
        [$rotta, $parametri] = match ($tipo) {
            'piano_rate' => ['admin.gestionale.esercizi.piani-rate.show', ['condominio' => $c, 'esercizio' => (int) $scheda->getAttribute('esercizio_id'),
                'pianoRate' => $id]],
            'gestione' => ['admin.gestionale.esercizi.gestioni.edit', ['condominio' => $c,
                'esercizio' => (int) DB::table('esercizio_gestione')->where('gestione_id', $id)->max('esercizio_id'), 'gestione' => $id]],
            'rendiconto_versione' => ['admin.gestionale.esercizi.rendiconto', ['condominio' => $c, 'esercizio' => (int) $scheda->getAttribute('esercizio_id')]],
            'fattura_passiva' => ['admin.gestionale.fatture.show', ['condominio' => $c, 'fattura' => $id]],
            'cassa' => ['admin.gestionale.casse.edit', ['condominio' => $c, 'cassa' => $id]],
            'accordo_rientro' => ['admin.gestionale.rate-scadute.index', ['condominio' => $c, 'anagrafica_id' => (int) $scheda->getAttribute('anagrafica_id')]],
            'dispensa_sei_mesi' => ['admin.gestionale.dispense.index', ['condominio' => $c]],
            'regola_recupero' => ['admin.gestionale.recupero-crediti.index', ['condominio' => $c]],
            'comunicazione' => ['admin.comunicazioni.show', ['comunicazione' => $id]],
            default => [null, []],
        };
        if ($rotta === null || ! Route::has($rotta) || in_array(0, $parametri, true)) {
            return null;
        }

        return route($rotta, $parametri);
    }
}
