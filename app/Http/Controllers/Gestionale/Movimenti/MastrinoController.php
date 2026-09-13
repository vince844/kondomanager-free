<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\ContoContabile;
use App\Services\Gestionale\RegistroContabilitaService;
use App\Services\PDF\PdfService;
use App\Traits\HandleFlashMessages;
use App\Traits\PaginaElenco;
use App\Traits\PdfRigheStampabili;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Il mastrino di un conto contabile — punto 2 della sequenza di docs/registri_contabili.md,
 * decisioni D21. Seconda proiezione della query di `RegistroContabilitaService` (D10): stesse
 * righe di scrittura, ma su un conto qualunque, per data, con il riporto e il saldo nel verso
 * naturale del conto.
 *
 * Non è una voce della barra di Movimenti: è la pagina figlia dello Stato patrimoniale, che ne è
 * il punto d'ingresso (ogni riga di conto è un link qui), con una seconda porta dalle casse.
 *
 * Come il registro, niente paginazione a livello di query: il saldo progressivo parte dal
 * riporto e si calcola sul periodo intero prima di affettare in pagine.
 */
class MastrinoController extends Controller
{
    use HandleFlashMessages, PaginaElenco, PdfRigheStampabili;

    public function index(Request $request, Condominio $condominio, Esercizio $esercizio, ContoContabile $contoContabile): Response
    {
        $this->assicuraAppartenenza($condominio, $esercizio, $contoContabile);

        $servizio = app(RegistroContabilitaService::class);
        $filtri = $this->filtriApplicati($request, $servizio->periodo($esercizio));
        $mastrino = $servizio->mastrino($esercizio, $contoContabile, $filtri);
        $righe = $mastrino['righe'];

        $perPage = $this->righePerPagina($request, predefinito: 20);
        $totale = count($righe);
        $ultimaPagina = max(1, (int) ceil($totale / $perPage));
        $paginaRichiesta = min(max((int) $request->input('page', 1), 1), $ultimaPagina);
        $paginati = array_slice($righe, ($paginaRichiesta - 1) * $perPage, $perPage);

        // Le righe sorelle — la contropartita vera — solo per le righe a video: è un dettaglio
        // che si apre una riga alla volta, e chiederle per tutto il periodo sarebbe il payload
        // del Libro Giornale intero per una pagina di venti righe.
        $sorelle = $servizio->sorelle(array_values(array_unique(array_column($paginati, 'scrittura_id'))));
        foreach ($paginati as &$riga) {
            $riga['sorelle'] = array_values(array_filter(
                $sorelle[$riga['scrittura_id']] ?? [],
                fn ($s) => $s['id'] !== $riga['id']
            ));
        }
        unset($riga);

        return Inertia::render('gestionale/movimenti/mastrino/Show', [
            'condominio' => $condominio,
            // Niente elenco dei condomìni: la pagina è legata a un conto di QUESTO condominio, e
            // cambiare condominio da qui non avrebbe un bersaglio (stessa scelta di F24Show).
            'esercizio' => $esercizio,
            'esercizi' => $condominio->esercizi()->orderByDesc('data_inizio')->get(),
            'conto' => $mastrino['conto'],
            'conti' => $servizio->contiPerSelettore($esercizio),
            'periodo' => $mastrino['periodo'],
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
                'riporto' => $mastrino['riporto'],
                'riporto_al' => $mastrino['riporto_al'],
                // I due totali sono delle righe MOSTRATE: con un filtro attivo sono un parziale,
                // e la pagina lo dichiara. Il saldo no: è quello vero alla data di riferimento.
                'totale_dare' => $mastrino['totale_dare'],
                'totale_avere' => $mastrino['totale_avere'],
                'saldo_finale' => $mastrino['saldo_finale'],
                'saldo_alla_data' => $mastrino['saldo_alla_data'],
                'data_riferimento' => $mastrino['data_riferimento'],
                'totale_righe' => $mastrino['totale_righe'],
                'altri_esercizi' => $mastrino['altri_esercizi'],
                'riporto_proprie' => $mastrino['riporto_proprie'],
                'postdatate' => $mastrino['postdatate'],
                'apertura_non_registrata' => $mastrino['apertura_non_registrata'],
            ],
            'filters' => $filtri,
        ]);
    }

    public function stampa(Request $request, Condominio $condominio, Esercizio $esercizio, ContoContabile $contoContabile, PdfService $pdfService)
    {
        $this->assicuraAppartenenza($condominio, $esercizio, $contoContabile);

        $servizio = app(RegistroContabilitaService::class);
        $filtri = $this->filtriApplicati($request, $servizio->periodo($esercizio));
        $mastrino = $servizio->mastrino($esercizio, $contoContabile, $filtri);
        $righe = $mastrino['righe'];

        // Stessa misura delle altre tre stampe di questa famiglia — vedi `PdfRigheStampabili`.
        if (count($righe) > self::righeStampabili()) {
            return back()->with($this->flashError(
                'Il mastrino filtrato ha '.number_format(count($righe), 0, ',', '.').' righe: '
                .'troppe per generare un PDF su questo server senza rischiare di interrompersi a metà. '
                .'Restringi il periodo con i filtri di data e stampa il mastrino in più parti.'
            ));
        }

        $mpdf = $pdfService->generate('pdf.gestionale.mastrino', [
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'conto' => $mastrino['conto'],
            'periodo' => $mastrino['periodo'],
            'righe' => $righe,
            'riporto' => $mastrino['riporto'],
            'riporto_al' => $mastrino['riporto_al'],
            'totale_righe' => $mastrino['totale_righe'],
            'totale_dare' => $mastrino['totale_dare'],
            'totale_avere' => $mastrino['totale_avere'],
            'saldo_finale' => $mastrino['saldo_finale'],
            'saldo_alla_data' => $mastrino['saldo_alla_data'],
            'data_riferimento' => $mastrino['data_riferimento'],
            'altri_esercizi' => $mastrino['altri_esercizi'],
            'riporto_proprie' => $mastrino['riporto_proprie'],
            'postdatate' => $mastrino['postdatate'],
            'apertura_non_registrata' => $mastrino['apertura_non_registrata'],
            'filtri' => [
                'data_da' => $this->dataFiltroLeggibile($filtri['data_da'] ?? null),
                'data_a' => $this->dataFiltroLeggibile($filtri['data_a'] ?? null),
                'search' => $filtri['search'] ?? null,
            ],
            // Un registro calcolato dalle scritture, non un atto da sottoscrivere (D21.6).
            'senza_firma' => true,
        ], [
            'default_font' => 'inter',
            'orientation' => 'L',
            'margin_top' => 22,
            'margin_bottom' => 20,
            'margin_header' => 8,
            'margin_footer' => 8,
        ]);

        $nomeFile = PdfService::nomeFile(
            'mastrino-'.$contoContabile->codice,
            $condominio->nome,
            $esercizio->data_inizio->format('Y'),
            null
        );

        return response($mpdf->Output($nomeFile, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nomeFile.'"');
    }

    /**
     * Il libro mastro: tutti i mastrini dell'esercizio in un foglio, senza filtri. Chiesto da
     * Vincenzo a video: «se volessi stamparli tutti in una sola volta?».
     */
    public function stampaLibroMastro(Condominio $condominio, Esercizio $esercizio, PdfService $pdfService)
    {
        abort_unless($esercizio->condominio_id === $condominio->id, 404);

        $libro = app(RegistroContabilitaService::class)->libroMastro($esercizio);

        // Il tetto vale sul totale: un libro mastro è una tabella per conto, e mPDF le tiene tutte.
        if ($libro['righe_totali'] > self::righeStampabili()) {
            return back()->with($this->flashError(
                'Il libro mastro ha '.number_format($libro['righe_totali'], 0, ',', '.').' righe: '
                .'troppe per generare un PDF su questo server senza rischiare di interrompersi a metà. '
                .'Stampa i mastrini un conto alla volta.'
            ));
        }

        $mpdf = $pdfService->generate('pdf.gestionale.libro_mastro', [
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'periodo' => $libro['periodo'],
            'mastrini' => $libro['mastrini'],
            'righe_totali' => $libro['righe_totali'],
            'senza_firma' => true,
        ], [
            'default_font' => 'inter',
            'orientation' => 'L',
            'margin_top' => 22,
            'margin_bottom' => 20,
            'margin_header' => 8,
            'margin_footer' => 8,
        ]);

        $nomeFile = PdfService::nomeFile('libro-mastro', $condominio->nome, $esercizio->data_inizio->format('Y'), null);

        return response($mpdf->Output($nomeFile, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nomeFile.'"');
    }

    /**
     * Lo scoped binding risolve già il conto dentro il condominio dell'esercizio
     * (`Esercizio::contiContabili()`) e l'esercizio dentro il condominio: qui resta la guardia
     * scritta, che per un conto altrui risponde 404 come vuole D21.9 — non 403 con messaggio come
     * le pagine sorelle, perché un conto di un altro condominio non deve nemmeno risultare
     * esistente. E un mastro (un conto con figli) non ha un mastrino (D21.1): le righe stanno
     * sulle foglie e il suo totale è nella situazione patrimoniale — il selettore non lo elenca,
     * e l'indirizzo battuto a mano non deve aprirgli una pagina di zeri.
     */
    private function assicuraAppartenenza(Condominio $condominio, Esercizio $esercizio, ContoContabile $contoContabile): void
    {
        if ($esercizio->condominio_id !== $condominio->id || $contoContabile->condominio_id !== $condominio->id) {
            abort(404);
        }

        $haFigli = ContoContabile::query()
            ->where('parent_id', $contoContabile->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($haFigli) {
            abort(404, 'Un mastro non ha un mastrino: le righe stanno sui suoi conti figli.');
        }
    }

    /**
     * I filtri DAVVERO applicati — stessa normalizzazione del registro — più una regola propria
     * del mastrino: un filtro di date che cade **tutto fuori** dal periodo non è un filtro, è un
     * residuo (il selettore dell'esercizio in testa conserva la query string, e `?data_da=2026-03-01`
     * sopravviveva al passaggio al 2025 lasciando una tabella vuota). Si scarta, e la pagina lo
     * rimanda indietro assente: la barra dei filtri segue ciò che il server ha applicato.
     *
     * @param  array{dal:string, al:string}  $periodo
     * @return array{search?:string, data_da?:string, data_a?:string, da?:string}
     */
    private function filtriApplicati(Request $request, array $periodo): array
    {
        $search = is_string($request->input('search')) ? trim($request->input('search')) : '';
        $dataDa = RegistroContabilitaService::dataValida($request->input('data_da'));
        $dataA = RegistroContabilitaService::dataValida($request->input('data_a'));

        if ($dataDa !== null && $dataDa > $periodo['al']) {
            $dataDa = null;
        }
        if ($dataA !== null && $dataA < $periodo['dal']) {
            $dataA = null;
        }

        // Da dove si è arrivati, per il pulsante «indietro»: viaggia con i filtri così sopravvive
        // a ricerca e paginazione. Solo i valori noti; tutto il resto vale «Stato patrimoniale».
        $da = $request->input('da');

        return array_filter([
            'search' => $search !== '' ? $search : null,
            'data_da' => $dataDa,
            'data_a' => $dataA,
            'da' => in_array($da, ['casse', 'libro-giornale'], true) ? $da : null,
        ], fn ($v) => $v !== null);
    }

    private function dataFiltroLeggibile(?string $valida): ?string
    {
        return $valida === null ? null : \Carbon\Carbon::createFromFormat('Y-m-d', $valida)->format('d/m/Y');
    }
}
