<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Services\Gestionale\PosizioniDeiCondomini;
use App\Services\PDF\PdfService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\PaginaElenco;
use App\Traits\PdfRigheStampabili;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La scheda «Condòmini» del rendiconto: le posizioni per persona e unità (§ 9) e i crediti per anzianità (§ 10), con la loro richiesta (le letture non
 * si sommano a quelle delle altre schede) e le loro due stampe. Sono i dati di tutti i condòmini: le rotte stanno nel gruppo del gestionale, dietro il
 * pannello, mai fra quelle del portale; l'esercizio di un altro condominio fa 404 per tutta la richiesta: il gruppo delle rotte lo cerca dentro il
 * condominio (`scopeBindings()`), e la guardia in ogni metodo resta come in tutte le rotte annidate (`RotteAnnidateSenzaGuardiaTest`).
 *
 * I due elenchi sono a pagine, ognuno con la sua; i totali e le righe che li legano alla situazione patrimoniale sono di tutte le righe.
 */
class RendicontoCondominiController extends Controller
{
    use HandleFlashMessages, HasCondomini, PaginaElenco, PdfRigheStampabili;

    /**
     * Il tetto delle due stampe, misurato su questi modelli e non preso dal Libro Giornale: la base (la lettura dei debiti compresa) e il costo per
     * riga, con il margine dell'85% delle altre stampe ({@see PdfRigheStampabili}).
     *
     * Misurato l'08/11/2026 con richieste vere alle due rotte, su un condominio di N persone con R rate scadute ciascuna (il pregresso nella prima
     * rata, il metodo di serie), il picco del processo (`memory_get_peak_usage(true)`):
     * - i crediti per anzianità, una riga per rata: 1.000 righe 74,5 MB; 4.000 102,0; 5.000 113,9; 6.000 122,1 (26,9 s); 12.000 righe 185,5 MB con 2G
     *   (53,6 s), e con 128M fatal error dentro mPDF (un blocco solo di 37,8 MB). Da cui circa 64 MB di base e 0,0106 MB a riga: 4.226 righe con
     *   128M, 14.490 con 256M (circa 65 s, sotto i 120 del server).
     * - le posizioni, una riga per persona e unità: 1.000 righe 66,5 MB; 3.000 85,9; 5.000 109,5 (10,1 s); con 12 rate a persona la lettura sale di
     *   4 MB a 1.000 righe. Da cui 60 MB di base e 0,0108 MB a riga: 4.518 righe con 128M, 14.592 con 256M.
     * La pagina della scheda, con le due letture, a 1.000 persone × 12 rate: 96,5 MB con 128M.
     */
    private const BASE_MB_POSIZIONI = 60;
    /** I costi di una riga del § 9 e del § 10 in stampa: li usa anche il fascicolo, per la memoria del totale. */
    public const MB_PER_POSIZIONE = 0.0108;
    private const BASE_MB_CREDITI = 64;
    public const MB_PER_CREDITO = 0.0106;
    /**
     * Il tetto della pagina dei crediti per anzianità: la lettura e le righe tenute per l'ordine e i totali (la pagina ne mostra una parte). Misurato
     * l'08/11/2026 con richieste vere, la scheda con il solo § 10: 1.000 persone × 12 rate (12.000 righe) 94,5 MB; 3.000 × 12 (36.000 righe) 164,5 MB
     * con 2G, fatal error con 128M. Da cui 60 MB di base e 0,003 MB a riga: 16.266 righe con 128M, 52.533 con 256M. La lettura si ferma alla fine del
     * gruppo di persone che supera il tetto (al più 3.000 righe in più, 9 MB, dentro il margine). Il § 9 da solo: 64,5 MB a 1.000 × 12, 70,5 a 3.000 × 12.
     */
    private const BASE_MB_PAGINA_CREDITI = 60;
    private const MB_PER_RIGA_PAGINA_CREDITI = 0.003;

    /** Le opzioni di mPDF delle due stampe, in orizzontale. Le usa anche il fascicolo del rendiconto. */
    public const OPZIONI_STAMPA = ['orientation' => 'L', 'margin_top' => 32];

    /** Il `memory_limit` che vale per il tetto; nullo, quello del processo. Una prova lo fissa: il processo non si abbassa sotto quello che usa. */
    private static ?string $limiteMemoria = null;

    public function __construct(private readonly PosizioniDeiCondomini $servizio) {}

    /** Quante righe del § 9 la stampa regge con un `memory_limit` dato. La usa anche il fascicolo del rendiconto. */
    public static function posizioniStampabiliCon($limite): int
    {
        return self::righeStampabiliCon($limite, self::BASE_MB_POSIZIONI, self::MB_PER_POSIZIONE, 0);
    }

    /** Quante righe del § 10 la stampa regge con un `memory_limit` dato. La usa anche il fascicolo del rendiconto. */
    public static function creditiStampabiliCon($limite): int
    {
        return self::righeStampabiliCon($limite, self::BASE_MB_CREDITI, self::MB_PER_CREDITO, 0);
    }

    /** Quante righe del § 10 la pagina regge con un `memory_limit` dato: la lettura e le righe tenute per l'ordine e i totali. */
    private static function creditiMostrabiliCon($limite): int
    {
        return self::righeStampabiliCon($limite, self::BASE_MB_PAGINA_CREDITI, self::MB_PER_RIGA_PAGINA_CREDITI, 0);
    }

    /**
     * La scheda mostra un elenco per volta, con la sua richiesta: le posizioni (di serie) o, con `?elenco=crediti`, i crediti per anzianità. Le due letture
     * insieme, con 3.000 unità e 12 rate non pagate ciascuna, superavano i 128 MB; da sole no. Oltre le righe che la pagina regge, i crediti per anzianità
     * lasciano il posto a una frase.
     */
    public function index(Request $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $perPagina = $this->righePerPagina($request, 'rendiconto_condomini');
        $elenco = $request->query('elenco') === 'crediti' ? 'crediti' : 'posizioni';
        $posizioni = null;
        $crediti = null;
        $troppe = null;
        if ($elenco === 'posizioni') {
            $posizioni = $this->aPagine($request, $this->servizio->posizioni($condominio, $esercizio), $perPagina, 'pagina_posizioni');
        } else {
            $massimo = self::creditiMostrabiliCon(self::$limiteMemoria ?? ini_get('memory_limit'));
            $letti = $this->servizio->crediti($condominio, $esercizio, self::ordineChiesto($request), null, $massimo);
            if (isset($letti['troppe'])) {
                $troppe = 'L\'elenco ha più di '.number_format($massimo, 0, ',', '.').' rate non pagate: più di quante il server riesce a leggere per questa pagina con la memoria che ha. '
                    .'Chiedi a chi gestisce il server di alzare il limite di memoria di PHP.';
            } else {
                $crediti = $this->aPagine($request, $letti, $perPagina, 'pagina_crediti');
            }
        }

        return Inertia::render('gestionale/movimenti/rendiconto/Condomini', [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $esercizio,
            'esercizi' => $condominio->esercizi()->orderByDesc('data_inizio')->get(),
            'elenco' => $elenco,
            'posizioni' => $posizioni,
            'crediti' => $crediti,
            'troppe' => $troppe,
        ]);
    }

    public function stampaPosizioni(Request $request, Condominio $condominio, Esercizio $esercizio, PdfService $pdf)
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $posizioni = $this->servizio->posizioni($condominio, $esercizio);
        if (count($posizioni['righe']) > self::posizioniStampabiliCon(self::$limiteMemoria ?? ini_get('memory_limit'))) {
            return $this->troppe($condominio, $esercizio, count($posizioni['righe']));
        }
        $mpdf = $pdf->generate('pdf.gestionale.rendiconto_posizioni', ['condominio' => $condominio, 'esercizio' => $esercizio, 'posizioni' => $posizioni,
            'titolo' => 'Posizioni dei condòmini', 'senza_firma' => true], self::OPZIONI_STAMPA);
        $nome = PdfService::nomeFile('posizioni-condomini', $condominio->nome, $esercizio->data_inizio->format('Y'), 'al-'.$posizioni['alla']);

        return response($mpdf->Output($nome, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nome.'"');
    }

    public function stampaCrediti(Request $request, Condominio $condominio, Esercizio $esercizio, PdfService $pdf)
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        // La lettura si ferma appena supera il tetto: con migliaia di rate non pagate la memoria finirebbe prima di contarle.
        $tetto = self::creditiStampabiliCon(self::$limiteMemoria ?? ini_get('memory_limit'));
        $crediti = $this->servizio->crediti($condominio, $esercizio, self::ordineChiesto($request), null, $tetto);
        if (isset($crediti['troppe'])) {
            return $this->troppe($condominio, $esercizio, $crediti['troppe'], true);
        }
        $mpdf = $pdf->generate('pdf.gestionale.rendiconto_crediti', ['condominio' => $condominio, 'esercizio' => $esercizio, 'crediti' => $crediti,
            'titolo' => 'Crediti per anzianità', 'senza_firma' => true], self::OPZIONI_STAMPA);
        $nome = PdfService::nomeFile('crediti-per-anzianita', $condominio->nome, $esercizio->data_inizio->format('Y'), 'al-'.$crediti['alla']);

        return response($mpdf->Output($nome, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nome.'"');
    }

    /** Oltre il tetto la stampa non parte: si torna alla scheda, con il numero delle righe (lette fin lì, quando la lettura si è fermata). */
    private function troppe(Condominio $condominio, Esercizio $esercizio, int $righe, bool $almeno = false)
    {
        return redirect()->route('admin.gestionale.esercizi.rendiconto.condomini', [$condominio->id, $esercizio->id] + ($almeno ? ['elenco' => 'crediti'] : []))->with($this->flashError(
            'L\'elenco ha '.($almeno ? 'più di ' : '').$righe.' righe: più di quante il server riesce a stampare con la memoria che ha. Chiedi a chi gestisce il server di alzare il limite di memoria di PHP.'
        ));
    }

    /** L'ordine dei crediti per anzianità chiesto nell'indirizzo: una parola, o quello di serie (un elenco scritto a mano non è un ordine). */
    private static function ordineChiesto(Request $request): string
    {
        $ordine = $request->query('ordine');

        return is_string($ordine) ? $ordine : 'scaduto';
    }

    /**
     * Le righe di una pagina, con i dati per i collegamenti fra le pagine; il resto dell'array (i totali, le righe che legano) com'è.
     *
     * @param  array<string,mixed>  $dati
     * @return array<string,mixed>
     */
    private function aPagine(Request $request, array $dati, int $perPagina, string $nomePagina): array
    {
        $pagina = self::paginaDentro(LengthAwarePaginator::resolveCurrentPage($nomePagina), count($dati['righe']), $perPagina);
        $p = new LengthAwarePaginator(array_slice($dati['righe'], ($pagina - 1) * $perPagina, $perPagina), count($dati['righe']), $perPagina, $pagina,
            ['path' => $request->url(), 'query' => $request->query(), 'pageName' => $nomePagina]);
        $dati['righe'] = $p->items();
        $dati['pagine'] = ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total(),
            'from' => $p->firstItem(), 'to' => $p->lastItem(), 'nome' => $nomePagina];

        return $dati;
    }
}
