<?php

namespace App\Http\Controllers\Comuni;

use App\Http\Controllers\Controller;
use App\Models\Comune;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La ricerca dietro il pulsante accanto al campo del comune.
 *
 * ⚠️ **Risponde sempre con la data della fonte, anche quando non trova niente.** Non è un ornamento:
 * è la condizione a cui l'aiuto è stato accettato. Un elenco che non dice a quando è aggiornato
 * invecchia in silenzio e chi lo legge si fida lo stesso — e il codice catastale di un comune fuso
 * è un dato sbagliato che il programma ha suggerito.
 */
class CercaComuniController extends Controller
{
    /** Quanti risultati bastano a scegliere senza scaricare mezza tabella nel browser. */
    private const MASSIMO = 20;

    /** Sotto questa lunghezza non si cerca: «a» da solo restituirebbe centinaia di comuni. */
    private const MINIMO_CARATTERI = 2;

    public function __invoke(Request $request): JsonResponse
    {
        $grezzo = $request->query('q', '');

        // ⚠️ `?q[]=Roma` è un indirizzo che chiunque può comporre, e `(string) $array` fa scattare un
        // warning PHP che Laravel rilancia come eccezione: **500 invece di una lista vuota**.
        $testo = is_string($grezzo) ? trim($grezzo) : '';

        $trovati = mb_strlen($testo) < self::MINIMO_CARATTERI
            ? null
            : Comune::cerca($testo);

        $totale = $trovati?->count() ?? 0;

        $comuni = $trovati
            ? $trovati->ordinaPerRilevanza($testo)->limit(self::MASSIMO)->get()
            : collect();

        $fonte = Comune::max('fonte_al');

        return response()->json([
            'comuni' => $comuni->map(fn (Comune $c) => [
                'codice_catasto' => $c->codice_catasto,
                'nome'           => $c->nome,
                // La provincia non è un ornamento: cinque denominazioni sono ripetute su comuni
                // diversi (Samone, Livo, Peglio, Castro, San Teodoro) e senza di lei si sceglie a
                // caso fra due codici catastali.
                'provincia'      => $c->provincia,
                'sigla'          => $c->sigla,
                'altra_lingua'   => $c->nome_altra_lingua,
            ])->values(),

            // Il totale **prima** del taglio a venti. Senza, chi cerca «Castel» vede venti nomi su
            // 193 e non ha modo di sapere che ne mancano 173: cerca «Castelvetro», non lo trova, e
            // conclude che non esista.
            'totale' => $totale,

            // Presa dalla tabella e non dal file: dopo un aggiornamento con `--da` le due potrebbero
            // non coincidere, e quella vera è quella che l'utente sta interrogando.
            // `max()` è un aggregato e **salta il cast del model**: senza `Carbon` torna
            // «2026-02-21 00:00:00», cioè una data con un'ora che non significa niente.
            'aggiornato_al' => $fonte ? \Carbon\Carbon::parse($fonte)->toDateString() : null,
        ]);
    }
}
