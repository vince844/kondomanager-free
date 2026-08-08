<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Models\ImportFile;
use App\Services\Import\AnteprimaImport;
use App\Services\Import\Controlli\ControlliPostImport;
use App\Services\Import\EsitoVerifica;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportRunner;
use App\Services\Import\ImportUploadService;
use App\Services\Import\ImportVerificaService;
use App\Services\Import\Rilievo;
use App\Services\Import\Severita;
use App\Services\Import\ReportType;
use App\Services\Import\SpreadsheetReader;
use App\Services\PDF\PdfService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'importazione dati — le schermate S1 e S2 del §14.1.
 *
 * ## Perché non sta sotto `/gestionale/{condominio}`
 *
 * Perché l'import **precede** il condominio: il primo file che si carica è spesso quello che lo
 * crea. Tutto il gruppo del gestionale ha in testa un condominio esistente e due middleware che
 * pretendono esercizio e piano dei conti — condizioni che qui, per definizione, non valgono
 * ancora. L'import vive a livello di studio, come l'elenco condomìni.
 */
class ImportController extends Controller
{
    use HandleFlashMessages;

    public function __construct(
        private readonly ImportUploadService $upload,
        private readonly ImportVerificaService $verifica,
        private readonly AnteprimaImport $anteprima,
        private readonly ImportRunner $runner,
    ) {}

    /**
     * S1 — «Da dove arrivi?»
     *
     * Mostra anche il lotto interrotto, se c'è: è la prima cosa che l'amministratore deve
     * vedere, perché è l'unica che gli ricorda che ha del lavoro a metà.
     */
    public function index(): Response
    {
        $interrotto = ImportBatch::query()
            ->whereIn('stato', [ImportBatch::STATO_IN_CORSO, ImportBatch::STATO_PARZIALE])
            ->latest()
            ->first();

        return Inertia::render('import/ImportHub', [
            'interrotto' => $interrotto === null ? null : [
                'uuid' => $interrotto->uuid,
                'condominio' => $interrotto->condominio?->nome,
                'livello_corrente' => $interrotto->livello_corrente,
                // Formattata qui e non nel browser: una data ISO che il client deve
                // interpretare è un modo di sbagliare fuso orario per niente.
                'iniziata_il' => $interrotto->created_at?->format('d/m/Y H:i'),
                'file' => $interrotto->files()->count(),
                'posizione' => $this->posizione($interrotto->livello_corrente),
                'livelli_totali' => count(ImportRunner::livelli()),
                // Un lotto «parziale» ha già scritto qualcosa: scartarlo chiude la sessione,
                // non annulla l'import. Dirlo qui è l'unico modo perché «Scarta» non sembri
                // un pulsante che disfa.
                'ha_scritto' => $interrotto->stato === ImportBatch::STATO_PARZIALE,
            ],
            'formati' => SpreadsheetReader::ESTENSIONI_AMMESSE,
            // Il limite si dichiara **prima** del caricamento, non si scopre dopo (§7).
            'dimensione_massima_mb' => (int) round(SpreadsheetReader::DIMENSIONE_MASSIMA_BYTE / 1024 / 1024),
        ]);
    }

    /**
     * Il rapporto in PDF — il documento che si allega, si archivia, si consegna.
     *
     * Finora il rapporto viveva **solo dentro la sua pagina**: chi doveva darne conto al collega,
     * allo studio o all'assemblea non aveva niente in mano. È anche il deliverable del servizio
     * di migrazione assistita (§20.5): senza un file, «l'abbiamo fatta noi» resta una parola.
     *
     * Porta anche i controlli con lo **stato di oggi**, non quello del giorno dell'import: un
     * rapporto stampato a fine lavori deve dire cosa resta davvero, non cosa restava allora.
     */
    public function rapportoPdf(string $uuid, PdfService $pdf, ControlliPostImport $controlli)
    {
        $batch = ImportBatch::where('uuid', $uuid)->with('condominio')->firstOrFail();

        abort_if($batch->rapporto === null, 404, 'Questa importazione non ha ancora un rapporto.');

        $livelli = $batch->rapporto['livelli'] ?? [];

        $mpdf = $pdf->generate('pdf.import.rapporto', [
            'condominio' => $batch->condominio,
            'lotto' => $batch,
            'livelli' => $livelli,
            'saldi' => $batch->rapporto['saldi'] ?? null,
            'creati' => array_sum(array_column($livelli, 'creati')),
            'controlli' => $controlli->perLotto($batch),
            // Molti messaggi cominciano già con una guillemet, e incorniciarli sempre produce
            // un doppio paio che a stampa sembra un errore di composizione.
            'virgolette' => fn (string $t) => str_starts_with($t, '«')
                ? $t
                : '«'.$t.'»',
            'etichetteStato' => [
                'aperto' => 'da fare',
                'risolto' => 'sistemato',
                'superato' => 'non più modificabile',
                'spuntato' => 'controllato',
                'messo_da_parte' => 'non pertinente',
            ],
        ]);

        $mpdf->SetHeader(($batch->condominio?->nome ?? 'Importazione').'||Rapporto di importazione');

        // `'S'` e non `'I'`: con `'I'` mpdf **scrive da solo** nell'output e restituisce una
        // stringa vuota, quindi la risposta che Laravel costruisce è una scatola vuota con
        // l'intestazione giusta. Nel browser funziona per caso, in un test no — ed è il tipo di
        // differenza che si scopre il giorno in cui qualcuno mette il PDF dietro una coda.
        return response($mpdf->Output('rapporto-importazione.pdf', 'S'))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="rapporto-importazione.pdf"');
    }

    /**
     * A che punto era arrivata, in numero: «arrivata a Tabelle (5 su 7)».
     *
     * «Arrivata a Tabelle» da solo non dice se manca poco o molto, ed è la sola informazione
     * che serve per decidere se riprendere o ricominciare.
     */
    private function posizione(?string $livello): ?int
    {
        if ($livello === null) {
            return null;
        }

        foreach (ImportRunner::livelli() as $i => $l) {
            if ($l->chiave() === $livello) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * Chiude un'importazione lasciata a metà.
     *
     * Senza questo, un lotto abbandonato resta sulla schermata d'ingresso **per sempre**: la
     * hub mostra sempre l'ultimo lotto in corso, e non c'era modo di dirle che quel lavoro non
     * si riprende. Ogni volta che si torna a importare, la prima cosa che si vede è un lavoro
     * che si è deciso di non finire.
     *
     * Scarta **la sessione**, non l'importazione: se il lotto era `parziale` qualcosa è già in
     * archivio e lì resta. Toglierlo sarebbe l'annullamento, che ha una condizione da valutare
     * e arriva con la 1.10.1 — e farlo passare per un pulsante «Scarta» sarebbe il tipo di
     * sorpresa che non si perdona.
     */
    public function scarta(string $uuid)
    {
        $batch = ImportBatch::where('uuid', $uuid)->firstOrFail();

        abort_if($batch->stato === ImportBatch::STATO_COMPLETATO, 422,
            'Questa importazione è già arrivata in fondo: non si scarta, si annulla.');

        // I file prima dello stato: se la cancellazione fallisce, il lotto resta ripescabile
        // invece di diventare un record che punta a niente.
        $this->upload->eliminaFile($batch);

        $batch->update([
            'stato' => ImportBatch::STATO_ANNULLATO,
            'annullato_at' => now(),
        ]);

        return redirect()
            ->route('import.index')
            ->with($this->flashSuccess($batch->condominio_id === null
                ? 'Importazione scartata. I file caricati sono stati cancellati.'
                : 'Importazione scartata. Quello che era già entrato in archivio resta: '
                  .'non è stato annullato niente.'));
    }

    /**
     * Riceve i file — tutti insieme — e apre il lotto.
     *
     * La dropzone accetta l'intero insieme perché il driver lavora su un **bundle** (§6): è la
     * differenza più visibile dal concorrente, che fa scegliere il livello prima di caricare e
     * ripete il ciclo dodici volte.
     */
    public function store(Request $request)
    {
        $validato = $request->validate([
            'file' => ['required', 'array', 'min:1', 'max:20'],
            'file.*' => [
                'file',
                'mimes:'.implode(',', SpreadsheetReader::ESTENSIONI_AMMESSE),
                'max:'.(int) (SpreadsheetReader::DIMENSIONE_MASSIMA_BYTE / 1024),
            ],
        ], [
            'file.*.mimes' => 'Accetto solo file :values. Se il tuo gestionale esporta in un altro formato, aprilo in Excel e salvalo come .xls.',
            'file.*.max' => 'Un file supera il limite. Esporta un esercizio alla volta, oppure scrivici.',
        ]);

        $batch = $this->upload->creaLotto(
            files: $validato['file'],
            userId: $request->user()?->id,
        );

        return redirect()
            ->route('import.riconoscimento', $batch->uuid)
            ->with($this->flashSuccess('File caricati. Controlla che li abbia riconosciuti bene.'));
    }

    /**
     * S2 — Riconoscimento: cosa credo che sia ciascun file, e cosa ne ricavo.
     */
    public function riconoscimento(string $uuid): Response
    {
        $batch = ImportBatch::where('uuid', $uuid)->with('files')->firstOrFail();

        return Inertia::render('import/ImportRiconoscimento', [
            'lotto' => [
                'uuid' => $batch->uuid,
                'stato' => $batch->stato,
            ],
            'file' => $batch->files->map(fn (ImportFile $f) => $this->descrivi($f))->all(),
            'tipi' => collect(ReportType::cases())
                ->map(fn (ReportType $t) => [
                    'valore' => $t->value,
                    'etichetta' => $t->etichettaUmana(),
                    'produce' => $t->cosaProduce(),
                    'importabile' => $t->importabile(),
                ])->all(),
            'mancanti' => $this->cosaManca($batch),
        ]);
    }

    /**
     * S3 — Verifica: i quattro contatori, i rilievi e le colonne (§10.2, §14.1).
     *
     * Legge e basta: è il «dry-run first» del §7. La schermata esiste perché fra il leggere e
     * lo scrivere ci sia un momento in cui l'amministratore può dire di no.
     */
    public function verificaFile(string $uuid): Response
    {
        $batch = ImportBatch::where('uuid', $uuid)->with('files')->firstOrFail();

        $letto = $this->verifica->verifica($batch);

        return Inertia::render('import/ImportVerifica', [
            'lotto' => ['uuid' => $batch->uuid, 'stato' => $batch->stato],
            'livelli' => $this->descriviLivelli($letto['esiti']),
            'letture' => $letto['letture'],
            'confermabile' => collect($letto['esiti'])->every(fn (EsitoVerifica $e) => $e->confermabile()),
            // Gli **errori** si correggono nel file e si ricarica; le **decisioni** si prendono
            // nella schermata dopo. Bloccare il passaggio anche sulle decisioni creava un vicolo
            // cieco: si mostravano qui e si rispondevano là, e da qui non si passava — trovato
            // camminando dentro il flusso, non da un test.
            'senza_errori' => collect($letto['esiti'])->every(fn (EsitoVerifica $e) => $e->errori() === 0),
            'passi' => $this->passi($letto),
        ]);
    }

    /**
     * Le sette tappe del §5, con lo stato di ciascuna.
     *
     * I livelli del motore sono sette, gli esiti di verifica quattro: la lettura non ha un
     * controllo separato per «unità» e «chi possiede cosa» perché nascono dalla stessa riga dello
     * stesso file. La mappa qui sotto è quel raccordo, ed è esplicita apposta — dedurla dai nomi
     * funzionerebbe finché qualcuno non aggiunge un livello.
     *
     * @param  array{esiti: array<string, EsitoVerifica>, canonici: array<string, mixed>}  $letto
     * @return list<array{chiave: string, etichetta: string, stato: string}>
     */
    private function passi(array $letto): array
    {
        $copertura = [
            'condominio' => 'condominio',
            'esercizi' => 'condominio',
            'soggetti' => 'soggetti',
            'unita' => 'soggetti',
            'titolarita' => 'soggetti',
            'tabelle' => 'tabelle',
            'saldi' => 'saldi',
        ];

        $passi = [];

        foreach (ImportRunner::livelli() as $livello) {
            $chiave = $livello->chiave();
            $esito = $letto['esiti'][$copertura[$chiave] ?? ''] ?? null;

            $passi[] = [
                'chiave' => $chiave,
                'etichetta' => $livello->etichetta(),
                'stato' => match (true) {
                    ! array_key_exists($chiave, $letto['canonici']) => 'assente',
                    $esito instanceof EsitoVerifica && $esito->errori() > 0 => 'errori',
                    default => 'pronto',
                },
            ];
        }

        return $passi;
    }

    /**
     * S4 — Anteprima e conferma: cosa sto per scrivere.
     *
     * L'ordine delle sezioni è quello del §14.1 e non è casuale: **chi possiede cosa viene
     * prima dei numeri**, perché è la cosa che rende una migrazione utile o inutile.
     */
    public function anteprima(string $uuid): Response
    {
        $batch = ImportBatch::where('uuid', $uuid)->with('files')->firstOrFail();
        $letto = $this->verifica->verifica($batch);

        return Inertia::render('import/ImportAnteprima', [
            'lotto' => ['uuid' => $batch->uuid, 'stato' => $batch->stato],
            'anteprima' => $this->anteprima->costruisci($letto['canonici']),
            'decisioni' => $batch->decisioni ?? [],
            'confermabile' => collect($letto['esiti'])->every(fn (EsitoVerifica $e) => $e->confermabile()),
        ]);
    }

    /**
     * Registra una decisione dell'utente sul lotto.
     *
     * Sul lotto e non in sessione: deve sopravvivere a un ricaricamento e alla ripresa dopo
     * un'interruzione. Un'importazione che perde le decisioni già prese le richiede tutte da
     * capo, e su quaranta unità è il momento in cui si chiude la scheda.
     */
    public function decidi(Request $request, string $uuid)
    {
        $validato = $request->validate([
            'chiave' => ['required', 'string', 'max:190'],
            'scelta' => ['required', 'string', 'in:unisci,salta,crea_nuovo,dividi,lascia'],
        ]);

        $batch = ImportBatch::where('uuid', $uuid)->firstOrFail();

        $batch->update([
            'decisioni' => [...($batch->decisioni ?? []), $validato['chiave'] => $validato['scelta']],
        ]);

        return back()->with($this->flashSuccess('Decisione registrata.'));
    }

    /**
     * Il commit: da qui in poi qualcosa entra in archivio.
     */
    public function conferma(string $uuid)
    {
        $batch = ImportBatch::where('uuid', $uuid)->with('files')->firstOrFail();
        $letto = $this->verifica->verifica($batch);

        $ctx = new ImportContext($batch);

        foreach ($letto['canonici'] as $livello => $dati) {
            $ctx->conCanonico($livello, $dati);
        }

        $esiti = $this->runner->esegui($ctx, $batch->decisioni ?? []);

        $batch->refresh();
        $batch->update(['rapporto' => $this->rapporto($esiti, $letto['esiti'], $letto['canonici'])]);

        return redirect()->route('import.esito', $batch->uuid);
    }

    /**
     * S5 — Esito: il rapporto, e se l'annullamento è possibile.
     */
    public function esito(string $uuid): Response
    {
        $batch = ImportBatch::where('uuid', $uuid)->with('condominio')->firstOrFail();

        return Inertia::render('import/ImportEsito', [
            'lotto' => [
                'uuid' => $batch->uuid,
                'stato' => $batch->stato,
                'condominio' => $batch->condominio?->nome,
                'condominio_id' => $batch->condominio_id,
                // L'indirizzo arriva dal server e non da `route()` nel browser: la rotta del
                // gestionale non è fra quelle che Ziggy espone al client, e il risultato è una
                // pagina che **non si disegna affatto** — un errore JavaScript al primo render
                // porta via tutto, non solo il pulsante. Costruirlo qui toglie la dipendenza.
                // L'indirizzo si costruisce con `route()` e non a mano: le rotte del gestionale
                // vivono dentro un gruppo con prefisso `admin`, e il percorso scritto a dita
                // («/gestionale/33») portava a un 404 — cioè l'unico ponte fra l'importatore e
                // il resto del prodotto era rotto proprio nella schermata che dice «fatto».
                'url_condominio' => $batch->condominio_id === null
                    ? null
                    : route('admin.gestionale.index', $batch->condominio_id),
                // Dove questi stessi consigli si ritrovano fra tre giorni, quando l'uuid del
                // lotto non ce l'ha più nessuno. Senza, la card «cosa conviene controllare»
                // esiste per la durata di una schermata e poi non è più raggiungibile.
                'url_controlli' => $batch->condominio_id === null
                    ? null
                    : route('admin.gestionale.controlli-import.index', $batch->condominio_id),
                'completato_at' => $batch->completato_at?->format('d/m/Y H:i'),
                // Il riferimento del lotto si vede: è quello che si cita scrivendo a noi, e
                // finora arrivava nei props senza essere reso da nessuna parte.
                'riferimento' => strtoupper(substr($batch->uuid, 0, 8)),
                'url_rapporto' => $batch->rapporto === null
                    ? null
                    : route('import.rapporto', $batch->uuid),
            ],
            'rapporto' => $batch->rapporto ?? [],
            'creati' => $batch->itemsCreati()->count(),
        ]);
    }

    /**
     * @param  array<string, \App\Services\Import\EsitoCommit>  $esiti
     * @return array<string, mixed>
     */
    /**
     * Il rapporto — e **tutti** gli avvisi, non solo metà.
     *
     * Gli avvisi nascono in due momenti: leggendo il file (i parser: «questo CF è malformato»,
     * «l'interno l'ho dedotto», «la quota sta solo nelle note») e scrivendo in archivio (i
     * livelli: «queste tabelle sono entrate scollegate»). Il rapporto prendeva solo i secondi,
     * quindi **undici codici su venti** si vedevano nella verifica e sparivano al commit — e
     * sono proprio quelli i cui rimedi dicono a parole «poi però sistema».
     *
     * Un avviso che compare prima di premere il pulsante e non dopo è un avviso che nessuno
     * rileggerà mai: nel momento in cui lo si vedeva, non c'era ancora niente da sistemare.
     *
     * @param  array<string, EsitoCommit>  $esiti  cosa è successo scrivendo
     * @param  array<string, EsitoVerifica>  $esitiLettura  cosa era emerso leggendo
     */
    private function rapporto(array $esiti, array $esitiLettura = [], array $canonici = []): array
    {
        $righe = [];

        foreach ($esiti as $livello => $esito) {
            $riga = [
                'livello' => $livello,
                'etichetta' => collect(ImportRunner::livelli())
                    ->firstWhere(fn ($l) => $l->chiave() === $livello)?->etichetta() ?? $livello,
                ...$esito->toArray(),
            ];

            // L'origine resta scritta: «l'ho letto nel tuo file» e «l'ho scoperto scrivendo»
            // sono due cose diverse, e il rimedio cambia di conseguenza.
            $riga['avvisi'] = array_map(
                fn (array $a) => $a + ['origine' => 'scrittura'],
                $riga['avvisi'],
            );

            $lettura = $esitiLettura[$livello] ?? null;

            if ($lettura instanceof EsitoVerifica) {
                foreach ($lettura->perSeverita(Severita::Avviso) as $avviso) {
                    $riga['avvisi'][] = $avviso->toArray() + ['origine' => 'lettura'];
                }
            }

            $righe[] = $riga;
        }

        // La quadratura torna anche **dopo** la scrittura, e non solo prima: è il numero che in
        // anteprima ha tolto la paura, e ritrovarlo a cose fatte è la prova che è andata davvero
        // così. Si ricostruisce qui perché dopo il commit i canonici non esistono più.
        $saldi = $this->anteprima->costruisci($canonici)['saldi'] ?? null;

        return [
            'livelli' => $righe,
            'saldi' => $saldi === null ? null : [
                'scarto' => $saldi['scarto'],
                'quadra' => $saldi['quadra'],
            ],
            'generato_il' => now()->toIso8601String(),
        ];
    }

    /**
     * I quattro contatori per livello, con i rilievi divisi per severità.
     *
     * @param  array<string, EsitoVerifica>  $esiti
     * @return list<array<string, mixed>>
     */
    private function descriviLivelli(array $esiti): array
    {
        $etichette = [
            'condominio' => 'Condominio ed esercizio',
            'soggetti' => 'Persone, unità e chi possiede cosa',
            'tabelle' => 'Tabelle millesimali',
            'saldi' => 'Saldi di apertura',
        ];

        $descritti = [];

        foreach ($esiti as $chiave => $esito) {
            $descritti[] = [
                'chiave' => $chiave,
                'etichetta' => $etichette[$chiave] ?? $chiave,
                'contatori' => [
                    'totali' => $esito->righeTotali,
                    'valide' => $esito->valide(),
                    'da_decidere' => $esito->daDecidere(),
                    'errori' => $esito->errori(),
                ],
                'confermabile' => $esito->confermabile(),
                'errori' => $this->rilievi($esito, Severita::Errore),
                'da_decidere' => $this->rilievi($esito, Severita::DaDecidere),
                'avvisi' => $this->rilievi($esito, Severita::Avviso),
            ];
        }

        return $descritti;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rilievi(EsitoVerifica $esito, Severita $severita): array
    {
        return array_map(fn (Rilievo $r) => $r->toArray(), $esito->perSeverita($severita));
    }

    /**
     * L'utente smentisce il riconoscimento: il punteggio è un suggerimento, non un verdetto.
     */
    public function forzaTipo(Request $request, string $uuid, ImportFile $file)
    {
        $validato = $request->validate([
            'report_type' => ['required', 'string', 'in:'.implode(',', array_column(ReportType::cases(), 'value'))],
        ]);

        abort_unless($file->batch->uuid === $uuid, 404);

        $file->update([
            'report_type' => $validato['report_type'],
            'tipo_forzato' => true,
        ]);

        return back()->with($this->flashSuccess('Tipo aggiornato.'));
    }

    public function escludiFile(string $uuid, ImportFile $file)
    {
        abort_unless($file->batch->uuid === $uuid, 404);

        $file->delete();

        return back()->with($this->flashSuccess('File escluso dall\'importazione.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function descrivi(ImportFile $file): array
    {
        $tipo = $file->report_type === null ? null : ReportType::tryFrom($file->report_type);

        return [
            'id' => $file->id,
            'nome' => $file->nome_originale,
            'dimensione_kb' => (int) round($file->dimensione / 1024),
            'tipo' => $tipo?->value,
            'tipo_etichetta' => $tipo?->etichettaUmana(),
            'produce' => $tipo?->cosaProduce(),
            'importabile' => $tipo?->importabile() ?? false,
            'confidenza' => $file->confidenza,
            'fiducia' => match (true) {
                $file->confidenza === null => 'nessuna',
                $file->confidenza >= 85 => 'alta',
                $file->confidenza >= 45 => 'media',
                default => 'nessuna',
            },
            'forzato' => (bool) $file->tipo_forzato,
            'foglio' => $file->colonne['foglio'] ?? null,
            'colonne_riconosciute' => $file->colonneRiconosciute(),
            'colonne_ignorate' => $file->colonneIgnorate(),
            'errore' => $file->colonne['errore'] ?? null,
        ];
    }

    /**
     * Cosa manca per proseguire — e viene **prima** di cosa c'è.
     *
     * Chi migra ha paura di perdere pezzi, non di averne troppi (§14.1). Dire «ti mancano i
     * fornitori, puoi proseguire lo stesso» è più utile di elencare i tre file che ha caricato.
     *
     * @return list<array{cosa: string, serve_per: string, bloccante: bool}>
     */
    private function cosaManca(ImportBatch $batch): array
    {
        $presenti = $batch->files->pluck('report_type')->filter()->all();

        $attesi = [
            ReportType::ElencoUnita->value => [
                'cosa' => 'l\'Elenco unità',
                'serve_per' => 'persone, unità e chi possiede cosa',
                'bloccante' => true,
            ],
            ReportType::RipartoConsuntivo->value => [
                'cosa' => 'il Riparto consuntivo',
                'serve_per' => 'i saldi di apertura e il totale su cui verificarli',
                'bloccante' => false,
            ],
            ReportType::AnagraficaMillesimi->value => [
                'cosa' => 'l\'Anagrafica con i millesimi',
                'serve_per' => 'le tabelle millesimali',
                'bloccante' => false,
            ],
        ];

        $mancanti = [];

        foreach ($attesi as $tipo => $descrizione) {
            if (! in_array($tipo, $presenti, true)) {
                $mancanti[] = $descrizione;
            }
        }

        return $mancanti;
    }
}
