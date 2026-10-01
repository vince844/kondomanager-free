<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Enums\TipoMovimentoContabile;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestionale\Rata;
use App\Models\Evento;
use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Services\Gestionale\InboxService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;

/**
 * Controller responsible for handling the reversal (storno) of an installment collection (incasso rata).
 * 
 * This controller prepares the necessary data and delegates the actual accounting reversal 
 * to the StornoIncassoRateAction. It also handles the restoration of the users' installment schedule 
 * (scadenziario) and resurrects any related global control tasks for the administrator.
 */
class StornoIncassoController extends Controller
{
    use HandleFlashMessages;

    /**
     * Handle the incoming request to reverse an installment collection.
     *
     * @param \Illuminate\Http\Request $request The incoming HTTP request.
     * @param \App\Models\Condominio $condominio The condominium context.
     * @param string|int $scrittura The ID of the accounting entry (ScritturaContabile) to reverse.
     * @param \App\Actions\Gestionale\Movimenti\StornoIncassoRateAction $action The action that performs the ledger reversal.
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException If the entry does not belong to the condominium.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the accounting entry cannot be found.
     */
    public function __invoke(Request $request, Condominio $condominio, string|int $scrittura, StornoIncassoRateAction $action) 
    {
        $scrittura = ScritturaContabile::findOrFail($scrittura);

        // 1. Sicurezza: verifichiamo che la scrittura appartenga al condominio corrente
        if ((int) $scrittura->condominio_id !== (int) $condominio->id) {
            abort(403, 'Azione non autorizzata su questo condominio.');
        }

        // {scrittura} arriva grezzo dalla rotta (nessun binding per tipo_movimento):
        // senza questo controllo, l'id di una scrittura di QUALSIASI altro tipo
        // (es. un giroconto) verrebbe accettato e stornato come se fosse un incasso.
        // Dalla 1.11.0-beta.31 (B2, S6) anche il rimborso di un credito passa da qui: la stessa `rettifica`
        // rovescia le righe e la pivot, e il credito torna disponibile sulla quota.
        if (! in_array($scrittura->tipo_movimento, [TipoMovimentoContabile::INCASSO_RATA, TipoMovimentoContabile::RIMBORSO_CONDOMINO], true)) {
            abort(403, 'Questa scrittura non è un incasso rata né un rimborso di credito: lo storno non è applicabile.');
        }

        if ($scrittura->stato === 'annullata') {
            return back();
        }

        // 2. EAGER LOAD COMPLETO: Il controller prepara tutti i dati necessari per la vista e per l'Action
        $scrittura->load(['figlie.righe', 'figlie.quotePagate', 'righe', 'quotePagate']);

        // 3. Le coppie (rata, persona) che l'incasso aveva toccato, lette PRIMA dello storno: dopo, le quote sono di nuovo
        // quelle di prima, e lo storno deve riallineare i promemoria di quelle persone e di nessun'altra. Prima si
        // prendevano tutte le rate per tutte le persone, e con «Versato da» (1.11.0-beta.40) si ricalcolava anche il
        // promemoria di chi l'incasso non aveva toccato, sovrascrivendo uno «reported» (reperto S8 della Fase 1-bis).
        $eventiRata = app(\App\Services\Gestionale\EventiRataCondomino::class);
        $coppie = $eventiRata->coppieToccate($scrittura);
        $rateIds = $coppie->pluck(0)->unique()->values()->all();

        // 4. ESEGUIAMO L'AZIONE DI STORNO (Che inverte sia padre che figlie)
        $action->execute($scrittura, $condominio);

        // 5. AGGIORNIAMO LO SCADENZIARIO UTENTI
        $eventiRata->allineaPagato($coppie);

        // 6. SMART TASK REVIVER: la «verifica incassi» di una rata si riapre solo se dopo lo storno la rata non è più
        // saldata, quota per quota — la stessa regola che la chiude (S7, S8).
        if (!empty($rateIds)) {
            foreach (Rata::whereIn('id', $rateIds)->get() as $rata) {
                if ($eventiRata->rataSaldata($rata)) {
                    continue;
                }

                Evento::where('meta->type', 'controllo_incassi')
                    ->where(function($q) use ($rata) {
                        $q->where('meta->context->rata_id', (int) $rata->id)
                          ->orWhere('meta->context->rata_id', (string) $rata->id);
                    })
                    ->where('is_completed', true) // Solo se era già stato completato
                    ->update([
                        'is_completed' => false,
                        'completed_at' => null,
                    ]);
            }
            // Puliamo la cache della inbox dell'amministratore per far ricomparire il badge rosso
            InboxService::clearAdminCache();
        }

        return back()->with($this->flashSuccess('Storno completato e scadenziario utenti aggiornato.'));
    }
}