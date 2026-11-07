<?php

namespace App\Http\Controllers\Gestionale\Saldi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Saldi\DichiaraOrigineSaldiRequest;
use App\Models\Condominio;
use App\Models\Saldo;
use App\Services\Gestionale\Debiti\OriginePregresso;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * «Dichiara l'origine» del pregresso, su uno o più saldi (in blocco, dalla pagina dei saldi): scrive la voce, l'esercizio, chi e quando. Non
 * tocca l'importo né il lucchetto. Ogni saldo della richiesta deve essere un saldo di un condòmino di questo condominio, altrimenti 404 per
 * tutta la richiesta, come le ricevute: niente si scrive a metà.
 *
 * L'origine cambia la fascia, i giorni e le date dei sei mesi di rate già emesse (la lettura la legge dal saldo, attraverso la quota): per
 * questo resta chi l'ha dichiarata e quando.
 */
class DichiaraOrigineSaldiController extends Controller
{
    use HandleFlashMessages;

    public function __invoke(DichiaraOrigineSaldiRequest $request, Condominio $condominio): RedirectResponse
    {
        $ids = array_values(array_unique(array_map('intval', (array) $request->validated('saldi'))));
        $saldi = Saldo::query()->whereIn('id', $ids)->where('condominio_id', $condominio->id)->whereNull('fornitore_id')->pluck('id')->all();
        abort_unless(count($saldi) === count($ids), 404);

        $origine = (string) $request->validated('origine');
        $dichiarata = $origine === OriginePregresso::NON_DICHIARATA ? null : $origine;

        DB::transaction(function () use ($ids, $dichiarata, $request) {
            Saldo::query()->whereIn('id', $ids)->update([
                'origine_dichiarata'    => $dichiarata,
                'esercizio_origine_id'  => $dichiarata === OriginePregresso::ESERCIZIO ? (int) $request->validated('esercizio_origine_id') : null,
                'origine_dichiarata_da' => $request->user()?->id,
                'origine_dichiarata_il' => now(),
                'updated_at'            => now(),
            ]);
        });

        $quanti = count($ids);

        // Togliere l'origine non è dichiararla: il messaggio dice che cosa è successo.
        if ($dichiarata === null) {
            return back()->with($this->flashSuccess($quanti === 1
                ? 'Origine tolta: il saldo torna «non dichiarata». L\'importo non cambia.'
                : "Origine tolta da {$quanti} saldi: tornano «non dichiarata». Gli importi non cambiano."));
        }

        return back()->with($this->flashSuccess($quanti === 1
            ? 'Origine del saldo dichiarata: l\'importo non cambia.'
            : "Origine dichiarata per {$quanti} saldi: gli importi non cambiano."));
    }
}
