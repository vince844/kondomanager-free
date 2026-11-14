<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\ControlliDelRendiconto;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use App\Services\PDF\PdfService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\PdfRigheStampabili;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\PDF\GruppoCarta;

/**
 * La pagina Stato patrimoniale — punto 3 della sequenza di docs/registri_contabili.md, decisioni
 * D16–D20. Tre sezioni (situazione alla data, riepilogo finanziario dell'esercizio, controlli come
 * equazioni) più la vista sulla liquidità (D11) e il risultato di gestione (D18).
 *
 * Il controller non calcola niente: legge `StatoPatrimonialePaginaService::costruisci()` e la
 * stessa struttura va a schermo e in stampa. Nessun filtro: la data è quella dell'esercizio (D16),
 * e un selettore libero di data non esiste in questa beta — dichiarato in pagina.
 */
class StatoPatrimonialeController extends Controller
{
    use HandleFlashMessages, HasCondomini, PdfRigheStampabili;

    /**
     * Il tetto della stampa: le fatture aperte sono la parte che cresce. Misurato l'08/11/2026 con richieste vere alla rotta, su un condominio con
     * N fatture aperte, il picco del processo con 2G, la stampa con l'esito di tutti i controlli del rendiconto in testa (che leggono anche il riepilogo
     * e le posizioni): 1.000 fatture 64,5 MB; 3.000 76,5; 6.000 96,5 (10,2 s). Da cui 58 MB di base e 0,0065 MB a fattura, con il margine dell'85%
     * delle altre stampe ({@see PdfRigheStampabili}): 7.815 fatture aperte con 128M, 24.553 con 256M. I controlli costano anche per le persone, che il
     * tetto non conta: 1.000 persone × 12 rate e nessuna fattura 76,5 MB; 3.000 × 12 112,5 MB (con 128M si stampa).
     */
    private const BASE_MB_STAMPA = 58;
    /** Il costo di una fattura aperta in stampa: lo usa anche il fascicolo, per la memoria del totale. */
    public const MB_PER_FATTURA = 0.0065;

    /** Il `memory_limit` che vale per il tetto; nullo, quello del processo. Una prova lo fissa: il processo non si abbassa sotto quello che usa. */
    private static ?string $limiteMemoria = null;

    /** Quante fatture aperte la stampa regge con un `memory_limit` dato. La usa anche il fascicolo del rendiconto. */
    public static function fattureStampabiliCon($limite): int
    {
        return self::righeStampabiliCon($limite, self::BASE_MB_STAMPA, self::MB_PER_FATTURA, 0);
    }

    public function __construct(private readonly StatoPatrimonialePaginaService $pagina) {}

    public function index(Condominio $condominio, Esercizio $esercizio): Response
    {
        if ($esercizio->condominio_id !== $condominio->id) {
            abort(403, 'L\'esercizio non appartiene a questo condominio.');
        }

        return Inertia::render('gestionale/movimenti/statoPatrimoniale/Show', [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $esercizio,
            'esercizi' => $condominio->esercizi()->orderByDesc('data_inizio')->get(),
            'pagina' => $this->pagina->costruisci($condominio, $esercizio),
        ]);
    }

    /** Stessa carta del registro (§7-quinquies): vista standalone, font propri, in verticale. La usa anche il fascicolo del rendiconto. */
    public const OPZIONI_STAMPA = ['default_font' => 'inter', 'orientation' => 'P', 'margin_top' => 22, 'margin_bottom' => 20, 'margin_header' => 8, 'margin_footer' => 8];

    /** Le parti che la stampa sa fare da sola: la situazione (§ 1–4), la cassa (§ 5–6), i controlli (§ 7). Senza, la stampa intera. */
    public const PARTI = ['situazione', 'cassa', 'controlli'];

    /**
     * La stampa: intera, o una parte (`?parte=situazione|cassa|controlli`). In testa l'esito di tutti i controlli del rendiconto, con la riga fissa; la
     * parte «cassa» non li stampa, e non li legge (il riepilogo per competenza e le posizioni pesano con migliaia di condòmini).
     */
    public function stampa(Request $request, Condominio $condominio, Esercizio $esercizio, PdfService $pdfService, ControlliDelRendiconto $controlli,
        ConguaglioDelRendiconto $conguaglio)
    {
        if ($esercizio->condominio_id !== $condominio->id) {
            abort(403, 'L\'esercizio non appartiene a questo condominio.');
        }
        $parte = in_array($request->query('parte'), self::PARTI, true) ? $request->query('parte') : null;

        $pagina = $this->pagina->costruisci($condominio, $esercizio);
        $fatture = in_array($parte, [null, 'situazione'], true) ? count($pagina['debiti']['fatture']) : 0;
        if ($fatture > self::fattureStampabiliCon(self::$limiteMemoria ?? ini_get('memory_limit'))) {
            return redirect()->route('admin.gestionale.esercizi.stato-patrimoniale.index', [$condominio->id, $esercizio->id])->with($this->flashError(
                'La situazione ha '.$fatture.' fatture aperte: più di quante il server riesce a stampare con la memoria che ha. Chiedi a chi gestisce il server di alzare il limite di memoria di PHP.'
            ));
        }

        // I quattro controlli del conguaglio leggono il consuntivo per persona: la stampa dei controlli lo legge sempre, come la scheda; la stampa intera e
        // la situazione sotto la soglia della Sintesi, e sopra li dicono da leggere nella scheda «Controlli».
        $letto = $parte === 'controlli' || ($parte !== 'cassa' && $conguaglio->peso($esercizio) <= ConguaglioDelRendiconto::soglia()) ? $conguaglio->leggi($esercizio) : null;
        $c = $parte === 'cassa' ? null : $controlli->perEsercizio($condominio, $esercizio, null, [], $pagina, null, [], $letto);
        $mpdf = $pdfService->generate('pdf.gestionale.stato_patrimoniale', [
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'pagina' => $pagina,
            'parte' => $parte,
            'controlli' => $c['controlli'] ?? null,
            'esito' => $c['esito'] ?? null,
            'anno_a_cavallo' => $c['anno_a_cavallo'] ?? null,
            // Fotografia calcolata, non atto: senza firma come il registro e il giornale (D20).
            // L'atto sottoscritto è il rendiconto (1.12).
            'senza_firma' => true,
        ], self::OPZIONI_STAMPA);

        $nomeFile = PdfService::nomeFile(['situazione' => 'situazione-patrimoniale', 'cassa' => 'entrate-uscite-per-cassa', 'controlli' => 'controlli-rendiconto'][$parte] ?? 'stato-patrimoniale',
            $condominio->nome, $esercizio->data_inizio->format('Y'), 'al-'.$pagina['data']);

        return response($mpdf->Output($nomeFile, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nomeFile.'"');
    }
}
