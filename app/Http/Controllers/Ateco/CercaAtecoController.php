<?php

namespace App\Http\Controllers\Ateco;

use App\Http\Controllers\Controller;
use App\Models\CodiceAteco;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La ricerca dietro il pulsante accanto al campo «Codice ATECO».
 *
 * ⚠️ **Risponde sempre con la revisione, anche quando non trova niente.** È la stessa condizione a
 * cui era stato accettato l'aiuto sui Comuni, tradotta in ciò che l'ATECO dichiara di sé: lì una
 * data, qui il nome della classificazione. Un elenco che non dice **da quale revisione viene**
 * invecchia in silenzio, e un codice ritirato è un dato sbagliato che il programma ha suggerito.
 *
 * ⚠️ **Si cercano solo i livelli 5 e 6** — categoria e sottocategoria. Le sezioni e le divisioni
 * sono contenitori dell'albero: offrirle significherebbe far scegliere «AGRICOLTURA, SILVICOLTURA E
 * PESCA» a chi cercava un idraulico. Restano in tabella perché servono a mostrare da dove viene il
 * codice trovato, e infatti la risposta porta la **gerarchia** di ciascun risultato.
 */
class CercaAtecoController extends Controller
{
    /** Quanti risultati bastano a scegliere senza scaricare mezza tabella nel browser. */
    private const MASSIMO = 20;

    /** Sotto questa lunghezza non si cerca: «a» da solo restituirebbe centinaia di codici. */
    private const MINIMO_CARATTERI = 2;

    public function __invoke(Request $request): JsonResponse
    {
        $grezzo = $request->query('q', '');

        // ⚠️ `?q[]=idraulico` è un indirizzo che chiunque può comporre, e `(string) $array` fa
        // scattare un warning che Laravel rilancia come eccezione: **500 invece di una lista vuota**.
        // È la stessa guardia della ricerca dei Comuni, e sta qui perché il difetto è della forma
        // della richiesta, non di quella schermata.
        $testo = is_string($grezzo) ? trim($grezzo) : '';

        $trovati = mb_strlen($testo) < self::MINIMO_CARATTERI
            ? null
            : CodiceAteco::cerca($testo)->classificabili()->dellaRevisioneCorrente();

        $totale = $trovati ? (clone $trovati)->count() : 0;

        // ⚠️ Per **rilevanza**, non per ordine dell'albero: misurato, cercando «elettric»
        // l'«Installazione di impianti elettrici» finiva alla posizione 36 su 45, cioè oltre il
        // taglio a venti — l'utente non la vedeva affatto. L'ordine della classificazione resta,
        // ma come secondo criterio.
        $codici = $trovati
            ? $trovati->ordinaPerRilevanza($testo)->limit(self::MASSIMO)->get()
            : collect();

        // I padri servono a stampare «Costruzioni › Lavori di costruzione specializzati» sotto ogni
        // risultato. Presi in **una** interrogazione invece di una per riga: venti risultati
        // avrebbero significato venti query per una riga di contesto.
        $padri = $codici->pluck('codice_padre')->filter()->unique();
        $antenati = CodiceAteco::whereIn('codice', $padri)->get()->keyBy('codice');
        $nonni = CodiceAteco::whereIn('codice', $antenati->pluck('codice_padre')->filter()->unique())
            ->get()->keyBy('codice');

        return response()->json([
            'codici' => $codici->map(function (CodiceAteco $c) use ($antenati, $nonni) {
                $padre = $c->codice_padre ? $antenati->get($c->codice_padre) : null;
                $nonno = $padre?->codice_padre ? $nonni->get($padre->codice_padre) : null;

                return [
                    'codice'    => $c->codice,
                    'titolo'    => $c->titolo,
                    'livello'   => $c->nomeLivello(),
                    // Da dove viene, in una riga: senza, due codici con titoli simili sono
                    // indistinguibili — «Costruzione di opere idrauliche» e «Installazione di
                    // impianti idraulici» stanno in due divisioni diverse.
                    'gerarchia' => self::gerarchia($c->titolo, [$nonno?->titolo, $padre?->titolo]),
                ];
            })->values(),

            // Il totale **prima** del taglio a venti: chi cerca «impianti» vede venti righe e deve
            // sapere che non sono tutte, altrimenti conclude che il suo codice non esista.
            'totale' => $totale,

            // Presa dalla tabella e non dal file: dopo un aggiornamento con `--da` le due
            // potrebbero non coincidere, e quella vera è quella che l'utente sta interrogando.
            // ⚠️ E presa **in modo deterministico**: `value()` senza ordinamento restituisce una riga
            // qualunque, e il giorno del cambio di revisione questa riga avrebbe potuto dichiarare
            // quella vecchia mentre l'elenco offriva la nuova.
            'versione' => CodiceAteco::revisioneCorrente(),
        ]);
    }

    /**
     * La catena degli antenati, senza le ripetizioni.
     *
     * ⚠️ **ISTAT ripete il titolo lungo la catena quando un livello ha un figlio solo**, e la prima
     * stesura lo stampava così com'era: «Costruzione di opere idrauliche › Costruzione di opere
     * idrauliche», e a volte la ripetizione era del titolo del risultato stesso. Una riga di
     * contesto che ripete ciò che sta scritto sopra non è contesto — è rumore, ed è l'opposto del
     * motivo per cui è stata messa. Trovato provando la ricerca a video, non dai test.
     *
     * Quindi: si tolgono i vuoti, si tolgono gli antenati che ripetono il titolo del codice, e si
     * collassano i duplicati consecutivi. Se dopo questo non resta niente, la riga **non compare**.
     */
    private static function gerarchia(string $titolo, array $antenati): ?string
    {
        $puliti = [];

        foreach ($antenati as $a) {
            if (blank($a) || $a === $titolo) {
                continue;
            }

            if (end($puliti) === $a) {
                continue;
            }

            $puliti[] = $a;
        }

        return $puliti === [] ? null : implode(' › ', $puliti);
    }
}
