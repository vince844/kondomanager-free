<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\FogliDelConguaglio;
use App\Services\Gestionale\InvioDeiFogli;
use App\Services\Gestionale\RiepilogoPerCompetenza;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\PdfRigheStampabili;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La scheda «Conguaglio» del rendiconto: la proposta di conguaglio per persona e unità di ogni gestione, con la sua richiesta (la lettura non si
 * somma a quella delle altre schede), le sue due stampe (il conguaglio per persona e il consuntivo per tabella) e il foglio della persona, uno per
 * uno o in un archivio .zip; l'invio per email ({@see InvioDeiFogli}: la finestra, l'invio, il rinvio dei non partiti, il PDF partito) sta in
 * {@see RendicontoConguaglioInvioController}. Sono i dati di tutti i condòmini: le rotte stanno nel gruppo del gestionale, dietro il pannello, mai fra
 * quelle del portale; l'esercizio di un altro condominio fa 404 per tutta la richiesta (`scopeBindings()`), e la guardia in ogni metodo resta come in
 * tutte le rotte annidate. Una persona che non è nella lettura dell'esercizio fa 404 (anche se esiste): chi ha venduto nell'anno ed è stato staccato
 * dal condominio ha il suo foglio.
 */
class RendicontoConguaglioController extends Controller
{
    use HandleFlashMessages, HasCondomini, PdfRigheStampabili;

    /**
     * Il tetto delle due stampe, con il margine dell'85 % delle altre stampe ({@see PdfRigheStampabili}): la base (il programma avviato, la lettura e
     * mPDF compresi) e il costo di quello che la stampa scrive. Misurato con richieste vere, fuori dalle prove: l'applicazione avviata su un database
     * SQLite su file, una richiesta GET alla rotta, il picco del processo (36,5 MB prima della richiesta), con la carta del riepilogo (le misure in
     * {@see MISURE}).
     *
     * - **Per persona**, una riga per persona e unità: 61 MB + 0,017 MB a riga. Con 128M 2.811 righe, con 256M 9.211.
     * - **Per tabella**, una riga per unità e persona con due celle per tabella, e oltre otto tabelle un altro blocco: il costo cresce con le tabelle.
     *   Si conta in celle: una riga con T tabelle vale T + 9 celle (le colonne fisse e la riga stessa pesano quanto nove tabelle), 61 MB + 0,00185 MB a
     *   cella, sopra le sei misure con 1, 8 e 16 tabelle. Con 128M 25.837 celle: 2.583 righe con una tabella, 1.519 con otto, 1.033 con sedici. Le
     *   prime misure, con una tabella sola, davano un tetto per righe che con otto tabelle lasciava partire una stampa che moriva (2.530 righe a 128M).
     */
    public const MISURE = [
        // [stampa, righe, tabelle, MB del picco, secondi]
        ['per_persona', 1100, 1, 78.5, 3.7], ['per_persona', 3300, 1, 114.5, 10.2],
        ['per_tabella', 1100, 1, 78.5, 3.1], ['per_tabella', 3300, 1, 118.5, 9.7],
        ['per_tabella', 1100, 8, 94.5, 10.0], ['per_tabella', 2200, 8, 126.9, 20.0],
        ['per_tabella', 1100, 16, 108.5, 18.3], ['per_tabella', 2200, 16, 158.8, 35.0],
    ];

    public const BASE_MB_PER_PERSONA = 61;
    public const MB_PER_RIGA_PER_PERSONA = 0.017;
    public const BASE_MB_PER_TABELLA = 61;
    public const MB_PER_CELLA_PER_TABELLA = 0.00185;
    /** Quante celle vale una riga del consuntivo per tabella oltre alle sue tabelle. */
    public const CELLE_FISSE_DELLA_RIGA = 9;

    /**
     * Il tempo, per la stima del fascicolo, dalle stesse misure e in su: per persona (10,2 − 3,7) / 2.200 a riga; per tabella a cella, il più alto delle
     * tre pendenze (con sedici tabelle (35,0 − 18,3) / 1.100 / 25 = 0,00061 s), con il margine per un numero di tabelle più alto.
     */
    public const SECONDI_PER_RIGA_PER_PERSONA = 0.0030;
    public const SECONDI_PER_CELLA_PER_TABELLA = 0.0007;

    /** Le opzioni di mPDF delle due stampe, in orizzontale sulla carta del riepilogo, come le posizioni. Le usa anche il fascicolo. */
    public const OPZIONI_STAMPA = RendicontoCondominiController::OPZIONI_STAMPA;

    /** Le opzioni di mPDF del foglio della persona, quelle della situazione patrimoniale: in verticale, in Inter. */
    public const OPZIONI_FOGLIO = StatoPatrimonialeController::OPZIONI_STAMPA;

    /** Il `memory_limit` che vale per il tetto; nullo, quello del processo. Una prova lo fissa. */
    private static ?string $limiteMemoria = null;

    public function __construct(private readonly ConguaglioDelRendiconto $conguaglio) {}

    /** Quante righe della stampa per persona la memoria regge. La usa anche il fascicolo. */
    public static function righePerPersonaStampabiliCon($limite): int
    {
        return self::righeStampabiliCon($limite, self::BASE_MB_PER_PERSONA, self::MB_PER_RIGA_PER_PERSONA, 0);
    }

    /** Quante celle della stampa per tabella la memoria regge ({@see celleDelConsuntivoPerTabella()}). La usa anche il fascicolo. */
    public static function celleStampabiliCon($limite): int
    {
        return self::righeStampabiliCon($limite, self::BASE_MB_PER_TABELLA, self::MB_PER_CELLA_PER_TABELLA, 0);
    }

    /** Le celle di una riga del consuntivo per tabella di una gestione con le sue tabelle. */
    public static function celleDellaRiga(int $tabelle): int
    {
        return $tabelle + self::CELLE_FISSE_DELLA_RIGA;
    }

    /** Le celle del consuntivo per tabella: per gestione, le righe per le celle di una riga. */
    public static function celleDelConsuntivoPerTabella(array $tabelle): int
    {
        return (int) array_sum(array_map(fn ($g) => count($g['righe']) * self::celleDellaRiga(count($g['tabelle'])), $tabelle['gestioni']));
    }

    public function index(Request $request, Condominio $condominio, Esercizio $esercizio, RiepilogoPerCompetenza $riepilogo): Response
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $letto = $this->conguaglio->leggi($esercizio);
        $scheda = $this->conguaglio->righe($condominio, $esercizio, $letto, $riepilogo->perEsercizio($condominio, $esercizio)['fuori_capitoli']);
        $fogli = app(FogliDelConguaglio::class);

        // Le righe di tutte le gestioni e quella da altre gestioni in un elenco solo, a pagine: i totali sono di tutte.
        $elenco = [];
        foreach ($scheda['gestioni'] as $g) {
            foreach ($g['righe'] as $r) {
                $elenco[] = $r + ['gestione_id' => $g['id']];
            }
        }
        foreach ($scheda['da_altre_gestioni']['righe'] as $r) {
            $elenco[] = $r + ['gestione_id' => 0];
        }
        $per = ConguaglioDelRendiconto::RIGHE_PER_PAGINA;
        $ultima = max(1, (int) ceil(count($elenco) / $per));
        $pagina = min($ultima, max(1, (int) $request->query('pagina', 1)));
        foreach ($scheda['gestioni'] as $i => $g) {
            unset($scheda['gestioni'][$i]['righe']);
        }
        unset($scheda['da_altre_gestioni']['righe']);
        $scheda['righe'] = array_slice($elenco, ($pagina - 1) * $per, $per);
        $scheda['pagine'] = ['corrente' => $pagina, 'ultima' => $ultima, 'per_pagina' => $per, 'righe' => count($elenco),
            'dopo' => max(0, count($elenco) - $pagina * $per)];

        return Inertia::render('gestionale/movimenti/rendiconto/Conguaglio', [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $esercizio,
            'esercizi' => $condominio->esercizi()->orderByDesc('data_inizio')->get(),
            'conguaglio' => $scheda,
            'frasi' => ['proposta' => ConguaglioDelRendiconto::FRASE_PROPOSTA, 'segno' => ConguaglioDelRendiconto::FRASE_SEGNO,
                'competenza' => ConguaglioDelRendiconto::FRASE_COMPETENZA, 'giorni' => ConguaglioDelRendiconto::FRASE_GIORNI,
                'centesimi' => ConguaglioDelRendiconto::FRASE_CENTESIMI, 'colonne' => ConguaglioDelRendiconto::FRASE_COLONNE,
                'non_emesse' => ConguaglioDelRendiconto::FRASE_NON_EMESSE],
            'fogli' => $fogli->conteggi($letto, $elenco, $pagina, $per) + ['tetto' => ConguaglioDelRendiconto::FOGLI_PER_RICHIESTA,
                'ricordata' => $request->session()->get(FogliDelConguaglio::chiaveScelta($condominio), 'tutte')],
            // L'ultimo invio per email del foglio di ogni persona della pagina, e quante persone ne hanno uno da rimandare.
            'invio_fogli' => $this->invioDeiFogli($condominio, $esercizio, $scheda['righe']),
        ]);
    }

    /** Il conguaglio per persona, A4 orizzontale, a blocchi di 100 righe. */
    public function stampaPerPersona(Request $request, Condominio $condominio, Esercizio $esercizio, PdfService $pdf)
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $letto = $this->conguaglio->leggi($esercizio);
        $righe = $this->conguaglio->righe($condominio, $esercizio, $letto, app(RiepilogoPerCompetenza::class)->perEsercizio($condominio, $esercizio)['fuori_capitoli'], true);
        $n = self::contaRighe($righe);
        if ($n > self::righePerPersonaStampabiliCon(self::$limiteMemoria ?? ini_get('memory_limit'))) {
            return $this->troppe($condominio, $esercizio, $n);
        }
        $mpdf = $pdf->generate('pdf.gestionale.conguaglio_per_persona', ['condominio' => $condominio, 'esercizio' => $esercizio, 'conguaglio' => $righe,
            'titolo' => 'Conguaglio per persona', 'senza_firma' => true, 'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA], self::OPZIONI_STAMPA);
        $nome = PdfService::nomeFile('conguaglio-per-persona', $condominio->nome, $esercizio->data_inizio->format('Y'), 'al-'.$letto['alla']);

        return response($mpdf->Output($nome, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nome.'"');
    }

    /** Il consuntivo per tabella, millesimi ed euro, A4 orizzontale: la gemella della stampa del preventivo per tabella. */
    public function stampaPerTabella(Request $request, Condominio $condominio, Esercizio $esercizio, PdfService $pdf)
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $letto = $this->conguaglio->leggi($esercizio, [], true);
        $tabelle = $this->conguaglio->perTabella($condominio, $esercizio, $letto);
        // Il tetto conta le celle: con molte tabelle una riga costa di più.
        if (self::celleDelConsuntivoPerTabella($tabelle) > self::celleStampabiliCon(self::$limiteMemoria ?? ini_get('memory_limit'))) {
            return $this->troppe($condominio, $esercizio, (int) array_sum(array_map(fn ($g) => count($g['righe']), $tabelle['gestioni'])),
                (int) array_sum(array_map(fn ($g) => count($g['tabelle']), $tabelle['gestioni'])));
        }
        $mpdf = $pdf->generate('pdf.gestionale.consuntivo_per_tabella', ['condominio' => $condominio, 'esercizio' => $esercizio, 'consuntivo' => $tabelle,
            'titolo' => 'Consuntivo per tabella', 'senza_firma' => true, 'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA], self::OPZIONI_STAMPA);
        $nome = PdfService::nomeFile('consuntivo-per-tabella', $condominio->nome, $esercizio->data_inizio->format('Y'), 'al-'.$letto['alla']);

        return response($mpdf->Output($nome, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nome.'"');
    }

    /** Il foglio di una persona, A4 verticale, con la carta «per la persona». */
    public function foglio(Condominio $condominio, Esercizio $esercizio, string $persona, FogliDelConguaglio $fogli)
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        // La persona si cerca nella lettura dell'esercizio (`datiDelFoglio` è null per chi non c'è): una di un altro condominio fa 404, e chi ha
        // venduto nell'anno ed è stato staccato dal condominio ha il suo foglio.
        $anagrafica = Anagrafica::findOrFail((int) $persona);
        // Con la spesa per tabella, come l'archivio: i millesimi del foglio vengono da lì.
        $letto = $this->conguaglio->leggi($esercizio, [], true);
        $dati = $fogli->datiDelFoglio($condominio, $esercizio, $letto, (int) $anagrafica->id);
        abort_if($dati === null, 404);
        $nome = PdfService::nomeFile('conguaglio', $condominio->nome, $esercizio->data_inizio->format('Y'), \Illuminate\Support\Str::slug((string) $anagrafica->nome));

        return response($fogli->pdf($condominio, $esercizio, $dati))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$nome.'"');
    }

    /** I fogli delle persone scelte in un archivio .zip, al massimo 200 per richiesta; la scelta si ricorda per chi lavora. */
    public function fogli(Request $request, Condominio $condominio, Esercizio $esercizio, FogliDelConguaglio $fogli)
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $chi = in_array($request->query('chi'), FogliDelConguaglio::SCELTE, true) ? (string) $request->query('chi') : 'tutte';
        $pagina = $request->query('pagina') !== null ? max(1, (int) $request->query('pagina')) : null;
        $request->session()->put(FogliDelConguaglio::chiaveScelta($condominio), $chi);
        try {
            $esito = $fogli->archivio($condominio, $esercizio, $chi, $pagina);
        } catch (\App\Exceptions\Gestionale\ConguaglioNonStampabileException $e) {
            return redirect()->route('admin.gestionale.esercizi.rendiconto.conguaglio', [$condominio->id, $esercizio->id] + ($pagina ? ['pagina' => $pagina] : []))
                ->with($this->flashError($e->getMessage()));
        }

        return response()->download($esito['zip'], $esito['nome'], ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /**
     * L'ultimo invio del foglio delle persone della pagina e quante persone hanno un invio da rimandare.
     *
     * @param  list<array<string,mixed>>  $righe
     */
    private function invioDeiFogli(Condominio $condominio, Esercizio $esercizio, array $righe): array
    {
        $persone = array_values(array_unique(array_map(fn ($r) => (int) $r['anagrafica_id'], array_filter($righe, fn ($r) => ! $r['subtotale']))));

        return app(InvioDeiFogli::class)->perLaScheda($condominio, $esercizio, $persone);
    }

    /** Le righe della stampa per persona: quelle delle gestioni e quella da altre gestioni. */
    public static function contaRighe(array $righe): int
    {
        return (int) array_sum(array_map(fn ($g) => count($g['righe']), $righe['gestioni'])) + count($righe['da_altre_gestioni']['righe']);
    }

    private function troppe(Condominio $condominio, Esercizio $esercizio, int $righe, ?int $tabelle = null)
    {
        $quante = $tabelle === null ? $righe.' righe' : $righe.' righe su '.$tabelle.($tabelle === 1 ? ' tabella' : ' tabelle');

        return redirect()->route('admin.gestionale.esercizi.rendiconto.conguaglio', [$condominio->id, $esercizio->id])->with($this->flashError(
            'La stampa ha '.$quante.': più di quante il server riesce a stampare con la memoria che ha. Chiedi a chi gestisce il server di alzare il limite di memoria di PHP.'
        ));
    }
}
