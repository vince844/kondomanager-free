<?php

namespace App\Http\Controllers\Gestionale\Rimborsi;

use App\Actions\Gestionale\Rimborsi\RimborsaCreditoAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Rimborsi\RimborsaCreditoRequest;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\RataQuote;
use App\Traits\HandleFlashMessages;
use App\Traits\HasEsercizio;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * «Rimborsa il credito» dall'estratto conto della persona (1.11.0-beta.31, B2 S6, voce 9).
 *
 * Il bersaglio è una **quota** a credito, non la persona: il vincolo è che la quota sia sua e di questo
 * condominio (`rate_quote.anagrafica_id` + `piani_rate.condominio_id`), e vale anche per chi è uscito —
 * la sua riga di titolarità è chiusa, non cancellata, e l'estratto conto si apre ancora.
 */
class RimborsoCreditoController extends Controller
{
    use HandleFlashMessages, HasEsercizio;

    public function store(RimborsaCreditoRequest $request, Condominio $condominio, Anagrafica $anagrafica, RimborsaCreditoAction $action): RedirectResponse
    {
        $this->assicuraAnagraficaDelCondominio($condominio, $anagrafica);

        $esercizio = $this->getEsercizioCorrente($condominio);
        if (! $esercizio) {
            return back()->with($this->flashError('Nessun esercizio aperto: il rimborso non ha dove essere registrato.'));
        }

        $quota = RataQuote::with('rata.pianoRate')->findOrFail($request->integer('rata_quote_id'));
        if ((int) $quota->anagrafica_id !== (int) $anagrafica->id || (int) ($quota->rata?->pianoRate?->condominio_id ?? 0) !== (int) $condominio->id) {
            throw ValidationException::withMessages(['rata_quote_id' => 'Il credito scelto non è di questa persona in questo condominio.']);
        }

        $cassa = Cassa::findOrFail($request->integer('cassa_id'));

        $scrittura = $action->execute(
            $condominio, $esercizio, $quota, $cassa,
            CarbonImmutable::parse($request->input('data_rimborso')),
            $request->importoCents(),
            $request->input('nota') ? trim((string) $request->input('nota')) : null,
            Auth::id(),
        );

        return back()->with($this->flashSuccess(sprintf(
            'Rimborso registrato (%s): %s a %s dalla cassa «%s». Il credito della quota si è chiuso per quell\'importo.',
            $scrittura->numero_protocollo,
            \App\Helpers\MoneyHelper::format($request->importoCents()),
            $anagrafica->nome,
            $cassa->nome,
        )));
    }

    /**
     * La persona è di questo condominio. Lo scoping delle rotte (`scopeBindings()` sul gruppo, coppia
     * anagrafica → anagrafiche) risolve `{anagrafica}` dentro il pivot `anagrafica_condominio`: chi non è nel
     * pivot riceve 404 prima di arrivare qui. Questa guardia ripete il controllo per esteso — difesa in profondità,
     * se un giorno la rotta perdesse lo scoping — e il test di sistema sulle rotte annidate la pretende. Il ramo
     * sulla riga di titolarità oggi non è raggiungibile (senza pivot il binding ferma prima): resta come seconda
     * rete. Chi ha venduto resta nel pivot (il passaggio non fa detach), perciò il rimborso lo raggiunge.
     */
    private function assicuraAnagraficaDelCondominio(Condominio $condominio, Anagrafica $anagrafica): void
    {
        $delCondominio = $anagrafica->condomini()->whereKey($condominio->id)->exists()
            || $anagrafica->immobili()->where('immobili.condominio_id', $condominio->id)->exists();

        abort_unless($delCondominio, 404);
    }
}
