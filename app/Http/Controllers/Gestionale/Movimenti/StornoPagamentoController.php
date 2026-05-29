<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Exceptions\Pagamenti\FiscalYearClosedException;
use App\Exceptions\Pagamenti\NessunEsercizioApertoException;
use App\Exceptions\Pagamenti\PagamentoGiaStornatoException;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\PagamentoFornitore;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

/**
 * Controller invokable per lo storno di un pagamento fornitore.
 *
 * Riceve la motivazione obbligatoria dall'utente e delega tutta la
 * logica contabile a PagamentoFornitoreService::stornaPagamento(),
 * che crea la scrittura inversa append-only (paradigma ledger immutabile).
 *
 * Flusso post-storno:
 *  - Le fatture collegate ritornano in stato 'aperta' o 'parziale'
 *  - Il saldo del conto corrente viene ripristinato
 *  - Il pagamento originale viene marcato come 'stornato'
 *  - Viene dispatchato l'evento PagamentoStornato (after commit)
 */
class StornoPagamentoController extends Controller
{
    use HandleFlashMessages;

    public function __invoke(
        Request $request,
        Condominio $condominio,
        PagamentoFornitore $pagamento,
        PagamentoFornitoreService $service
    ): RedirectResponse {
        // ── Guard: appartenenza al condominio ─────────────────────────────
        if ($pagamento->condominio_id !== $condominio->id) {
            abort(403, 'Il pagamento non appartiene a questo condominio.');
        }

        // ── Validazione input ──────────────────────────────────────────────
        $validated = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'motivo.required' => 'È obbligatorio specificare il motivo dello storno.',
            'motivo.min'      => 'Il motivo deve contenere almeno 10 caratteri.',
            'motivo.max'      => 'Il motivo non può superare 1000 caratteri.',
        ]);

        try {
            $service->stornaPagamento($pagamento, $validated['motivo']);

            Log::info("Storno pagamento #{$pagamento->id} eseguito da utente #" . Auth::id() . " — motivo: {$validated['motivo']}");

            return back()->with(
                $this->flashSuccess(
                    "Storno eseguito con successo. Le fatture collegate sono state riaperte e il saldo del conto è stato ripristinato."
                )
            );

        } catch (PagamentoGiaStornatoException $e) {
            return back()->with(
                $this->flashError('Operazione non consentita: questo pagamento è già stato stornato in precedenza.')
            );

        } catch (FiscalYearClosedException $e) {
            return back()->with(
                $this->flashError('Impossibile eseguire lo storno: nessun esercizio aperto disponibile per registrare la scrittura inversa.')
            );

        } catch (NessunEsercizioApertoException $e) {
            return back()->with(
                $this->flashError('Impossibile eseguire lo storno cross-esercizio: non esiste un esercizio corrente aperto per questo condominio.')
            );

        } catch (\Exception $e) {
            Log::error("Errore storno pagamento #{$pagamento->id}: " . $e->getMessage(), [
                'pagamento_id'  => $pagamento->id,
                'condominio_id' => $condominio->id,
                'user_id'       => Auth::id(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return back()->with(
                $this->flashError('Errore di sistema durante lo storno: ' . $e->getMessage())
            );
        }
    }
}
