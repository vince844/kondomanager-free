<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Services\Gestionale\Debiti\ElencoRateScadute;
use App\Services\Gestionale\Debiti\SeiMesi;
use App\Services\PDF\PdfService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use App\Traits\PaginaElenco;
use App\Traits\PdfRigheStampabili;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use App\Services\PDF\GruppoCarta;

/**
 * La pagina «Rate scadute» del condominio e la sua stampa. Una riga per persona, dalla lettura comune dei debiti; i filtri nell'indirizzo, e
 * mai ricordati, come nelle altre pagine ({@see PaginaElenco}): in un elenco di chi deve soldi un filtro dimenticato toglie una persona dalle
 * lettere. Chi rientra senza filtri nell'indirizzo vede tutte le persone; le porte (la Tesoreria, l'estratto conto) aprono l'elenco con i
 * soli parametri che portano. Si ricordano le righe per pagina.
 *
 * Le rotte stanno nel gruppo del gestionale, dietro il pannello, mai fra le rotte del portale. La selezione della stampa è una lista di
 * persone, o «tutte le N dei filtri» che il server rilegge; una persona che non è di questo condominio fa 404 per tutta la richiesta.
 */
class RateScaduteController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio, PaginaElenco, PdfRigheStampabili;

    /**
     * Il tetto della stampa, misurato su questo modello e non preso dal Libro Giornale: la base e il costo per persona, con lo stesso
     * margine dell'85% delle altre stampe ({@see PdfRigheStampabili}).
     *
     * Misurato il 07/11/2026 con richieste vere alla rotta della stampa, su un condominio di N persone con una rata scaduta ciascuna, il
     * picco della memoria del processo (`memory_get_peak_usage(true)`) con `memory_limit = 128M`: 3.000 persone 104,6 MB; 3.500 115,0 MB;
     * 4.000 123,4 MB; 5.000 fatal error dentro mPDF. Da cui circa 48 MB di base e 0,0188 MB a persona: 3.234 persone con 128M, 9.021 con
     * 256M (provati: 3.234 persone con 128M, 109,7 MB; 9.021 con 256M, 215,6 MB e 27 secondi). Con la tabella sola e gli stili in ogni
     * cella la stessa stampa, con 12 rate a persona, moriva fra 300 e 1.000 persone con 128M e fra 1.000 e 3.000 con 256M.
     *
     * Il costo è per persona e non per rata perché la vista riceve le righe senza il dettaglio delle rate. La lettura dei debiti che viene
     * prima è quella della pagina: con 1.000 persone e 12 rate scadute ciascuna arriva a circa 126 MB con 128M, per la pagina come per la
     * stampa, e oltre muore anche la pagina (un limite della lettura, non della stampa).
     */
    private const BASE_MB_STAMPA = 48;
    private const MB_PER_PERSONA = 0.0188;

    /** Il `memory_limit` che vale per il tetto; nullo, quello del processo. Una prova lo fissa: il processo non si abbassa sotto quello che usa. */
    private static ?string $limiteMemoria = null;

    public function __construct(private readonly ElencoRateScadute $elenco) {}

    /** La persona di una porta (l'estratto conto) è di questo condominio, o la richiesta intera è un 404, come la selezione della stampa. */
    private function assicuraPersonaDellaPorta(Condominio $condominio, ?int $anagraficaId): void
    {
        abort_if($anagraficaId !== null && ! Anagrafica::whereKey($anagraficaId)->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominio->id))->exists(), 404);
    }

    /** Quante persone la stampa regge con un `memory_limit` dato: la formula delle altre stampe, con la misura di questa. */
    private static function personeStampabiliCon($limite): int
    {
        return self::righeStampabiliCon($limite, self::BASE_MB_STAMPA, self::MB_PER_PERSONA, 0);
    }

    public function index(Request $request, Condominio $condominio)
    {
        $filtri = $this->elenco->filtriNormalizzati($request->only(ElencoRateScadute::CHIAVI));
        $this->assicuraPersonaDellaPorta($condominio, $filtri['anagrafica_id']);
        $righe = $this->elenco->righe($condominio, $filtri);

        $perPagina = $this->righePerPagina($request);
        $pagina = self::paginaDentro(LengthAwarePaginator::resolveCurrentPage(), $righe->count(), $perPagina);
        $paginate = new LengthAwarePaginator($righe->forPage($pagina, $perPagina)->values(), $righe->count(), $perPagina, $pagina, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);
        $esercizio = $this->getEsercizioCorrente($condominio);
        $oggi = DateHelper::oggiUtente();

        return Inertia::render('gestionale/movimenti/rateScadute/RateScadute', [
            'condominio' => ['id' => $condominio->id, 'nome' => $condominio->nome],
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $esercizio,
            'righe' => [
                'data' => $paginate->items(),
                'meta' => [
                    'current_page' => $paginate->currentPage(),
                    'last_page' => $paginate->lastPage(),
                    'per_page' => $paginate->perPage(),
                    'total' => $paginate->total(),
                    'from' => $paginate->firstItem(),
                    'to' => $paginate->lastItem(),
                ],
            ],
            // Il totale di tutte le righe dei filtri, non della pagina: lo scaduto lordo, senza togliere il credito.
            'totale' => (int) $righe->sum('importo'),
            'persone_totali' => $righe->count(),
            'filtri' => $filtri,
            'oggi' => $oggi,
            'non_emesse' => $filtri['vista'] === ElencoRateScadute::VISTA_SCADUTE ? $this->elenco->nonEmesse($condominio, $filtri['alla'], $filtri['gestione_id']) : null,
            'gestioni' => $condominio->gestioni()->orderBy('nome')->get(['id', 'nome']),
            'persona_della_porta' => $filtri['anagrafica_id'] === null ? null : Anagrafica::whereKey($filtri['anagrafica_id'])->value('nome'),
            'testo_norma' => SeiMesi::TESTO_NORMA,
            'frase_festivo' => SeiMesi::FRASE_FESTIVO,
            'frase_sei_mesi' => SeiMesi::FRASE_PIU_SEI_MESI,
            // Le lettere per email da rifare (non partite, e la persona ha ancora qualcosa da versare) e quelle in coda da più di un'ora: contate
            // come lettere, non come righe (una lettera a due indirizzi è una).
            'lettere_da_riprovare' => app(\App\Services\Gestionale\Lettere\EmissioneLettere::class)->lettereDaRiprovare($condominio),
            'lettere_in_coda_da_tempo' => \App\Models\Gestionale\RegistroInvio::where('condominio_id', $condominio->id)
                ->whereIn('tipo', \App\Models\Gestionale\RegistroInvio::TIPI_LETTERE)->where('stato', \App\Models\Gestionale\RegistroInvio::STATO_IN_CODA)
                ->where('emesso_il', '<', now()->subHour())->get(['id', 'dati->lettera as lettera'])->map(fn ($r) => $r->lettera ?? 'riga-' . $r->id)->unique()->count(),
        ]);
    }

    /**
     * La stampa dell'elenco: un PDF orizzontale, una riga per persona, con la data dell'elenco nel titolo, il totale, la riga delle rate non
     * emesse e, in calce, a chi va. Senza firma: è una lettura dei dati, non un atto. Un GET: non scrive niente.
     */
    public function stampa(Request $request, Condominio $condominio, PdfService $pdf)
    {
        $filtri = $this->elenco->filtriNormalizzati($request->only(ElencoRateScadute::CHIAVI));
        $this->assicuraPersonaDellaPorta($condominio, $filtri['anagrafica_id']);
        $solo = null;
        if (! $request->boolean('tutte') && $request->has('persone')) {
            $solo = array_values(array_unique(array_map('intval', array_filter((array) $request->input('persone'), 'is_numeric'))));
            $delCondominio = Anagrafica::whereIn('id', $solo ?: [0])->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominio->id))->count();
            abort_unless($solo !== [] && $delCondominio === count($solo), 404);
        }
        // La stampa scrive il segno dell'accordo in una riga, senza la parte versata oltre il capitale: quella è nella pagina.
        $righe = $this->elenco->righe($condominio, $filtri, $solo, false);

        if ($righe->count() > self::personeStampabiliCon(self::$limiteMemoria ?? ini_get('memory_limit'))) {
            // Si torna all'elenco con gli stessi filtri: i filtri non si ricordano, e senza l'indirizzo si perderebbero.
            $stessi = array_filter($request->only(ElencoRateScadute::CHIAVI), fn ($v) => $v !== null && $v !== '');

            return redirect()->route('admin.gestionale.rate-scadute.index', ['condominio' => $condominio->id] + $stessi)->with($this->flashError(
                'L\'elenco ha ' . $righe->count() . ' persone: più di quante il server riesce a stampare. Restringi i filtri e stampalo in più parti.'
            ));
        }
        // La vista scrive una riga per persona e non il dettaglio delle rate: senza, la memoria della resa dipende dalle persone e non
        // da quante rate ha ognuno.
        $righe = $righe->map(fn (array $r) => array_diff_key($r, ['rate' => true]));

        $alla = Carbon::parse($filtri['alla']);
        $inScadenza = $filtri['vista'] === ElencoRateScadute::VISTA_IN_SCADENZA;
        $titolo = $inScadenza ? "Rate in scadenza nei prossimi {$filtri['giorni']} giorni, dal " . $alla->format('d/m/Y') : 'Rate scadute al ' . $alla->format('d/m/Y');
        $mpdf = $pdf->generate('pdf.gestionale.rate_scadute', [
            'gruppo_carta' => GruppoCarta::REGISTRI_E_ELENCHI,
            'condominio' => $condominio,
            'titolo' => $titolo,
            'titolo_stampa' => $titolo,
            'righe' => $righe,
            'in_scadenza' => $inScadenza,
            'oggi' => $filtri['alla'] === DateHelper::oggiUtente(),
            'totale' => (int) $righe->sum('importo'),
            'non_emesse' => $inScadenza ? null : $this->elenco->nonEmesse($condominio, $filtri['alla'], $filtri['gestione_id']),
            'selezione' => $solo !== null,
            'senza_firma' => true,
        ], [
            'orientation' => 'L',
            // 38 come il libro giornale: la riga del titolo fa la testata più alta, e a 32 il suo filetto (a 35,4 mm) attraversava il titolo del
            // corpo.
            'margin_top' => 38,
        ]);

        $nome = PdfService::nomeFile($inScadenza ? 'rate-in-scadenza' : 'rate-scadute', $condominio->nome, $alla->format('Y-m-d'));

        return response($mpdf->Output($nome, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . $nome . '"');
    }
}
