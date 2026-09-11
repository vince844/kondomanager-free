<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Services\Gestionale\RegistroContabilitaService;
use App\Services\PDF\PdfService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\PaginaElenco;
use App\Traits\PdfRigheStampabili;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Il registro di contabilità ex art. 1130, comma 1, n. 7 c.c. — punto 1 della sequenza di
 * docs/registri_contabili.md. Legge la stessa query di `RegistroContabilitaService` (D10) che
 * varrà anche per il mastrino di un conto, non ancora costruito.
 *
 * Diversamente dal Libro Giornale, qui non c'è paginazione a livello di query: il saldo
 * progressivo è cumulativo dall'inizio dell'esercizio filtrato, quindi va calcolato sull'intero
 * risultato prima di affettarlo in pagine — altrimenti la pagina 2 partirebbe da zero.
 */
class RegistroContabilitaController extends Controller
{
    use HandleFlashMessages, HasCondomini, PaginaElenco, PdfRigheStampabili;

    public function index(Request $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        if ($esercizio->condominio_id !== $condominio->id) {
            abort(403, 'L\'esercizio non appartiene a questo condominio.');
        }

        $filtri = $this->filtriApplicati($request);
        $registro = app(RegistroContabilitaService::class)->registroConRiepilogo($esercizio, $filtri);
        $righe = $registro['righe'];

        $totaleEntrate = (int) array_sum(array_column($righe, 'entrata'));
        $totaleUscite = (int) array_sum(array_column($righe, 'uscita'));

        $perPage = $this->righePerPagina($request, predefinito: 20);
        $totale = count($righe);
        $ultimaPagina = max(1, (int) ceil($totale / $perPage));
        $paginaRichiesta = min(max((int) $request->input('page', 1), 1), $ultimaPagina);

        // Il registro è cronologico per costruzione (RegistroContabilitaService ordina prima di
        // calcolare il saldo progressivo): affettare qui non altera l'ordine, solo la porzione
        // visibile — il saldo di ogni riga resta quello calcolato sull'intero risultato filtrato.
        $paginati = array_slice($righe, ($paginaRichiesta - 1) * $perPage, $perPage);

        return Inertia::render('gestionale/movimenti/registroContabilita/List', [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $esercizio,
            'esercizi' => $condominio->esercizi()->orderByDesc('data_inizio')->get(),
            'righe' => [
                'data' => $paginati,
                'meta' => [
                    'current_page' => $paginaRichiesta,
                    'last_page' => $ultimaPagina,
                    'total' => $totale,
                    'per_page' => $perPage,
                ],
            ],
            'riepilogo' => [
                // I due totali sono dei movimenti MOSTRATI: con un filtro attivo sono un
                // parziale, e la pagina lo dichiara.
                'totale_entrate' => $totaleEntrate,
                'totale_uscite' => $totaleUscite,
                // ⚠️ **Il saldo NON è `entrate − uscite`, e non è nemmeno «l'ultima riga
                // mostrata».** È il progressivo dell'esercizio intero alla data di riferimento,
                // calcolato dal servizio — vedi `registroConRiepilogo()` per i tre difetti che
                // il calcolo sulle righe filtrate produceva. Filtrando luglio, `entrate − uscite`
                // è il netto di luglio; il saldo è quello che c'era davvero sul conto a fine
                // luglio, e porta dentro tutto l'esercizio precedente. È la cifra per cui la
                // norma vuole questo registro: «le somme a disposizione del condominio» in un
                // dato momento.
                'saldo_finale' => $registro['saldo_finale'],
                'saldo_alla_data' => $registro['data_riferimento'],
                'saldi_per_cassa' => $registro['saldi_per_cassa'],
            ],
            'filters' => $filtri,
        ]);
    }

    public function stampa(Request $request, Condominio $condominio, Esercizio $esercizio, PdfService $pdfService)
    {
        if ($esercizio->condominio_id !== $condominio->id) {
            abort(403, 'L\'esercizio non appartiene a questo condominio.');
        }

        $filtri = $this->filtriApplicati($request);
        $registro = app(RegistroContabilitaService::class)->registroConRiepilogo($esercizio, $filtri);
        $righe = $registro['righe'];

        // ⚠️ **Stessa misura del Libro Giornale, non una propria.** La Fase 1-bis di questa beta
        // ha trovato falsa l'ipotesi "ogni riga qui è più leggera": il markup ha due celle
        // multi-riga su ogni riga, non meno annidamento di libro_giornale.blade.php. Finché
        // questo template non ha una misura sua, il tetto giusto è quello già misurato — vedi
        // il docblock di `PdfRigheStampabili`.
        if (count($righe) > self::righeStampabili()) {
            return back()->with($this->flashError(
                'Il registro filtrato ha '.number_format(count($righe), 0, ',', '.').' righe: '
                .'troppe per generare un PDF su questo server senza rischiare di interrompersi a metà. '
                .'Restringi il periodo con i filtri di data e stampa il registro in più parti.'
            ));
        }

        $totaleEntrate = (int) array_sum(array_column($righe, 'entrata'));
        $totaleUscite = (int) array_sum(array_column($righe, 'uscita'));

        $mpdf = $pdfService->generate('pdf.gestionale.registro_contabilita', [
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'righe' => $righe,
            'totale_entrate' => $totaleEntrate,
            'totale_uscite' => $totaleUscite,
            // Il saldo e la sua data arrivano dal servizio, non li ricalcola la vista: con un
            // estratto senza righe la vista diceva «€ 0,00» su un conto a −370,56.
            'saldo_finale' => $registro['saldo_finale'],
            'saldo_alla_data' => $registro['data_riferimento'],
            'filtri' => [
                'data_da' => $this->dataFiltroLeggibile($filtri['data_da'] ?? null),
                'data_a' => $this->dataFiltroLeggibile($filtri['data_a'] ?? null),
                'search' => $filtri['search'] ?? null,
            ],
            // È il registro di legge, non un atto da sottoscrivere — stesso trattamento del
            // Libro Giornale (§10.5 di docs/registri_contabili.md).
            'senza_firma' => true,
            // ⚠️ **La data di annotazione sta FUORI dalla stampa, e il valore predefinito è
            // quello che conta.** D9 (docs/registri_contabili.md:279): «un registro che denuncia
            // da solo i ritardi del suo autore, consegnato in assemblea, è un'arma contro
            // l'amministratore, non uno strumento per lui». A schermo la colonna resta sempre —
            // lì serve, è la vista con cui rimediare — ma il foglio che va in assemblea porta la
            // sola data del movimento, l'unica che la norma chieda. La stessa D9 prevede
            // l'eccezione: «in stampa entra solo se l'amministratore lo chiede», ed è questo
            // parametro. `boolean()` fa sì che l'assenza valga `false`: chi arriva qui con un
            // indirizzo battuto a mano ottiene la copia d'assemblea, non quella di controllo.
            'mostra_annotazione' => $request->boolean('annotazioni'),
            // La stessa scomposizione della pagina: se il documento che finisce in assemblea
            // mostrasse solo il totale, nasconderebbe un conto scoperto proprio a chi deve
            // controllarlo.
            'saldi_per_cassa' => $registro['saldi_per_cassa'],
        ], [
            // ⚠️ La vista NON estende `pdf.base`: porta la propria carta intestata e i propri
            // header/footer di pagina, nel sistema tipografico del fac-simile (§7-quinquies).
            // Da qui i margini più stretti e il carattere di base diverso dal resto delle stampe.
            'default_font' => 'inter',
            // Orizzontale come il Libro Giornale: le nove colonne di questo registro su A4
            // verticale mandano a capo ogni descrizione tre o quattro volte, e un registro che
            // si consulta a colpo d'occhio — è la ragione per cui la norma lo vuole — non regge
            // righe alte quattro linee. Visto a video, non dedotto.
            'orientation' => 'L',
            'margin_top' => 22,
            'margin_bottom' => 20,
            'margin_header' => 8,
            'margin_footer' => 8,
        ]);

        return response($mpdf->Output('registro_contabilita.pdf', \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf');
    }

    /**
     * I filtri DAVVERO applicati, e solo quelli: sono ciò che il servizio usa, ciò che la pagina
     * rimanda indietro (da cui «(parziale)» e i campi della barra) e ciò che la stampa dichiara
     * nella fascia. Una sola normalizzazione, o le tre cose divergono.
     *
     * ⚠️ Una data non `Y-m-d` e una ricerca di soli spazi valgono «nessun filtro» qui, non a
     * valle: prima la pagina dichiarava «(parziale)» su un registro intero, e la stampa scriveva
     * «estratto parziale · ricerca: «   »» per tre spazi nella casella.
     *
     * @return array{search?:string, data_da?:string, data_a?:string}
     */
    private function filtriApplicati(Request $request): array
    {
        $search = is_string($request->input('search')) ? trim($request->input('search')) : '';

        return array_filter([
            'search' => $search !== '' ? $search : null,
            'data_da' => RegistroContabilitaService::dataValida($request->input('data_da')),
            'data_a' => RegistroContabilitaService::dataValida($request->input('data_a')),
        ], fn ($v) => $v !== null);
    }

    /** Da `Y-m-d` già validata a `d/m/Y` per la fascia della stampa. */
    private function dataFiltroLeggibile(?string $valida): ?string
    {
        return $valida === null ? null : \Carbon\Carbon::createFromFormat('Y-m-d', $valida)->format('d/m/Y');
    }
}
