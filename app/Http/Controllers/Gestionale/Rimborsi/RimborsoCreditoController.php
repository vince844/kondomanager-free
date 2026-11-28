<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\Rimborsi;

use App\Actions\Gestionale\Rimborsi\RimborsaCreditoAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Rimborsi\RimborsaCreditoRequest;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\RataQuote;
use App\Services\Gestionale\EventiRataCondomino;
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

        // Il credito restituito cambia il netto della rata: il promemoria della persona nel portale si riallinea come dopo un incasso.
        $eventiRata = app(EventiRataCondomino::class);
        $eventiRata->allineaPagato($eventiRata->coppieToccate($scrittura));

        return back()->with($this->flashSuccess(sprintf(
            'Rimborso registrato (%s): %s a %s dalla cassa «%s». Il credito della quota si è chiuso per quell\'importo.',
            $scrittura->numero_protocollo,
            \App\Helpers\MoneyHelper::format($request->importoCents()),
            $anagrafica->nome,
            $cassa->nome,
        )));
    }

    /**
     * La persona ha o ha avuto a che fare con questo condominio, altrimenti 404. La rotta non lega `{anagrafica}` al condominio (`withoutScopedBindings`,
     * come l'estratto conto da cui parte il rimborso): chi è stato tolto dal condominio dopo una vendita esce dal pivot `anagrafica_condominio`, e il suo
     * credito si rimborsa lo stesso. La regola è quella del pagante (`Anagrafica::delCondominio`: il pivot, una riga di titolarità anche cessata su una sua
     * unità, le quote nei suoi piani); la persona di un altro condominio risponde 404.
     */
    private function assicuraAnagraficaDelCondominio(Condominio $condominio, Anagrafica $anagrafica): void
    {
        abort_unless(Anagrafica::delCondominio((int) $condominio->id)->whereKey($anagrafica->id)->exists(), 404);
    }
}
