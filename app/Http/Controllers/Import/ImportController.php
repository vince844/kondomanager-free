<?php

namespace App\Http\Controllers\Import;

use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\ImportBatch;
use App\Models\ImportFile;
use App\Services\Import\AnteprimaImport;
use App\Services\Import\Controlli\ControlliPostImport;
use App\Services\Import\EsitoVerifica;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportRunner;
use App\Services\Import\ImportUploadService;
use App\Services\Import\ImportVerificaService;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use App\Services\Import\Rilievo;
use App\Services\Import\Severita;
use App\Services\Import\ReportType;
use App\Services\Import\SpreadsheetReader;
use App\Services\PDF\PdfService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use App\Support\LimiteCaricamento;

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

    /**
     * Il tetto che l'importatore si dà, in megabyte.
     *
     * Vive accanto alla costante che lo definisce e non sparso nelle chiamate: la costante è in byte
     * perché serve anche a `SpreadsheetReader`, qui serve in megabyte perché è l'unità di
     * `LimiteCaricamento`.
     */
    private static function tettoImportMb(): float
    {
        return SpreadsheetReader::DIMENSIONE_MASSIMA_BYTE / 1048576;
    }

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
        $utente = request()->user();

        // ⚠️ **«L'ultimo lotto in corso» era l'ultimo di chiunque.** Questa riga è la ragione per
        // cui il buco sulla proprietà del lotto non richiedeva nemmeno di indovinare un uuid: la
        // schermata d'ingresso lo consegnava, insieme al nome del condominio altrui. Ora è il
        // proprio; l'amministratore continua a vederli tutti, che è il suo mestiere.
        $interrotto = ImportBatch::query()
            ->whereIn('stato', [ImportBatch::STATO_IN_CORSO, ImportBatch::STATO_PARZIALE])
            ->when(
                $utente !== null && ! $utente->hasRole(RoleEnum::AMMINISTRATORE->value),
                fn ($q) => $q->where(fn ($w) => $w->where('user_id', $utente->id)->orWhereNull('user_id')),
            )
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
            // Il limite si dichiara **prima** del caricamento, non si scopre dopo (§7) — e dalla
            // beta.60 è **quello vero del server**, non più solo il nostro tetto. Erano due numeri
            // diversi: la schermata annunciava 25 MB anche su uno spazio web che ne accetta 2, e
            // questa è la porta che la scheda ㊺ chiamava «la più esposta», perché l'importatore è
            // la voce di punta della 1.10.
            'dimensione_massima' => LimiteCaricamento::etichetta(self::tettoImportMb()),
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
        $batch = $this->lotto($uuid, ['condominio']);

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
     * Il lotto di **chi lo sta guardando**, o 404.
     *
     * ⚠️ **Fino al 28/08/2026 questa guardia non c'era, su nessuna delle nove porte del lotto.**
     * Tutte facevano `$this->lotto($uuid)` e nient'altro: chiunque
     * potesse importare poteva aprire, dirottare e **scartare** l'importazione a metà di un
     * collega. Non era nemmeno un uuid da indovinare — la schermata d'ingresso mostrava a tutti
     * l'ultimo lotto in corso, quello di chiunque l'avesse lasciato aperto.
     *
     * Riprodotto eseguendo: con due collaboratori, il secondo riceveva l'uuid del primo nei dati
     * della hub, gli impostava una destinazione (302, decisioni scritte), ne apriva la verifica
     * (200) e lo portava a «annullato».
     *
     * **404 e non 403**: un lotto che non è tuo non esiste, per te. Un 403 confermerebbe che
     * quell'uuid è di qualcuno, che è metà dell'informazione che non deve uscire.
     *
     * L'amministratore li vede tutti — è il suo mestiere rimettere in piedi il lavoro altrui — e
     * i lotti **senza proprietario** restano aperti a chi può importare: sono quelli caricati
     * prima che `user_id` esistesse, e murarli vorrebbe dire cancellare del lavoro vero senza
     * dirlo a nessuno.
     */
    private function lotto(string $uuid, array $con = []): ImportBatch
    {
        $batch = ImportBatch::where('uuid', $uuid)->with($con)->firstOrFail();

        $utente = request()->user();

        abort_if(
            $batch->user_id !== null
                && $utente !== null
                && (int) $batch->user_id !== (int) $utente->id
                && ! $utente->hasRole(RoleEnum::AMMINISTRATORE->value),
            404,
        );

        return $batch;
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
        $batch = $this->lotto($uuid);

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
                // Il tetto dell'importatore resta **25 MB, il suo** — un export di più esercizi è
                // grosso per natura — ma non promette mai più di quanto il server accetti.
                'max:'.LimiteCaricamento::regolaMax(self::tettoImportMb()),
            ],
        ], [
            'file.*.mimes' => 'Accetto solo file :values. Se il tuo gestionale esporta in un altro formato, aprilo in Excel e salvalo come .xls.',
            'file.*.max' => 'Un file supera il limite di '.LimiteCaricamento::etichetta(self::tettoImportMb())
                .'. Esporta un esercizio alla volta, oppure scrivici.',
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
        $batch = $this->lotto($uuid, ['files']);

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
        $batch = $this->lotto($uuid, ['files']);

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
            'destinazione' => $this->destinazioneDaScegliere($batch, $letto),
        ]);
    }

    /**
     * Cosa mostrare nella tendina «in quale condominio vanno questi dati».
     *
     * Restituisce `null` quando la scelta non serve — cioè quando i file la dichiarano da soli.
     * Offrire comunque una tendina che poi verrebbe ignorata è peggio che non offrirla: chi la
     * usa crede di aver deciso qualcosa.
     *
     * L'elenco lo si costruisce **solo** in questo caso: sono due query in più su una schermata
     * che ne fa già molte, e nel caso normale — la stampa con la testata — non servono a niente.
     */
    private function destinazioneDaScegliere(ImportBatch $batch, array $letto): ?array
    {
        if ($letto['dichiaratoDaiFile']) {
            return null;
        }

        $decisioni = $batch->decisioni ?? [];

        // ⚠️ **Chi non può scrivere nei condomìni non deve nemmeno vederne l'elenco.**
        //
        // La tendina mandava a tutti nome e codice fiscale di **ogni** condominio dell'archivio,
        // anche a un ruolo a cui l'elenco condomìni è chiuso: bastava caricare un file qualsiasi
        // per avere l'anagrafe completa degli stabili gestiti. E chi la usava senza il permesso
        // di modifica sbatteva, dopo aver scelto, in un 403 a schermo pieno — un vicolo cieco
        // costruito da noi, perché è `destinazione()` a pretendere quel permesso.
        //
        // Meglio non offrirla e dire perché: una porta chiusa spiegata è più utile di una porta
        // che si apre su un muro.
        if (Gate::denies('update', Condominio::class)) {
            return [
                'condomini' => [],
                'esercizio_aperto' => [],
                'scelto_condominio' => null,
                'senza_permesso' => true,
            ];
        }

        $condomini = Condominio::query()
            ->orderBy('nome')
            ->get(['id', 'nome', 'codice_fiscale'])
            ->map(fn (Condominio $c) => [
                'id' => $c->id,
                'nome' => $c->nome,
                'codice_fiscale' => $c->codice_fiscale,
            ])
            ->all();

        // L'esercizio **aperto** di ciascun condominio, indicizzato per condominio: non è una
        // scelta ma un fatto, e la schermata lo dichiara appena si sceglie il condominio.
        // Tutti insieme e non uno per volta perché la prima tendina cambia nel browser, e
        // ricaricare la pagina a ogni cambio farebbe perdere la posizione in una schermata lunga.
        $aperti = Esercizio::query()
            ->whereIn('condominio_id', array_column($condomini, 'id'))
            ->where('stato', 'aperto')
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'condominio_id', 'nome', 'data_inizio', 'data_fine']);

        $esercizi = [];

        foreach ($aperti as $e) {
            // `first()` come fa `HasEsercizio::getEsercizioCorrente()`: se l'invariante «al più
            // un esercizio aperto» fosse violata, la schermata deve annunciare **lo stesso** che
            // userà il motore, non un altro.
            $esercizi[(string) $e->condominio_id] ??= [
                'nome' => $e->nome !== null && $e->nome !== '' ? $e->nome : 'senza nome',
                'periodo' => $e->data_inizio?->format('d/m/Y').' – '.$e->data_fine?->format('d/m/Y'),
            ];
        }

        return [
            'condomini' => $condomini,
            'esercizio_aperto' => $esercizi,
            'scelto_condominio' => $decisioni[ImportVerificaService::DESTINAZIONE_CONDOMINIO] ?? null,
            'senza_permesso' => false,
        ];
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
        $batch = $this->lotto($uuid, ['files']);
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

        $batch = $this->lotto($uuid);

        $batch->update([
            'decisioni' => [...($batch->decisioni ?? []), $validato['chiave'] => $validato['scelta']],
        ]);

        return back()->with($this->flashSuccess('Decisione registrata.'));
    }

    /**
     * «Importa dentro **questo** condominio» — la destinazione scelta a mano.
     *
     * Non è una decisione come le altre e per questo non passa da `decidi()`: quelle sono
     * risposte a un elenco chiuso («unisci», «dividi»…), questa porta un **id**. E un id va
     * autorizzato: senza il controllo, chi può soltanto creare condomìni potrebbe scrivere le
     * unità di un condominio dentro un altro passando l'id nella richiesta — che è esattamente
     * il perimetro chiuso nella beta.66, riaperto da una porta nuova.
     *
     * **L'esercizio non è un parametro.** Lo risolve `ImportVerificaService`, prendendo quello
     * aperto del condominio scelto: è la regola di tutto il resto del prodotto, e la data di
     * inizio dell'esercizio finisce dentro ogni titolarità scritta. Lasciarlo scegliere voleva
     * dire offrire un modo di sbagliare che non dà nessun segnale.
     */
    public function destinazione(Request $request, string $uuid)
    {
        $validato = $request->validate([
            'condominio_id' => ['required', 'integer', 'exists:condomini,id'],
        ]);

        Gate::authorize('update', Condominio::findOrFail($validato['condominio_id']));

        $batch = $this->lotto($uuid);

        // ⚠️ **Le decisioni implicite della scelta precedente vanno via.**
        //
        // Cambiando destinazione restavano in archivio, e non erano inerti: `salta` su un
        // condominio scartato **zittisce** la domanda «in archivio esiste già, unisci o lascia
        // com'è?» se più avanti un file dichiara proprio quello. Misurato affiancando due lotti
        // identici: quello pulito poneva la domanda, quello con il residuo la saltava e scriveva
        // nel condominio sbagliato senza un solo rilievo.
        //
        // Si tolgono **solo** quelle dei due livelli che la destinazione decide da sé. Le altre
        // — i nomi doppi da dividere, i duplicati fra le persone — sono risposte sui **file**, e
        // cambiare il condominio di arrivo non le rende sbagliate.
        $superstiti = array_filter(
            $batch->decisioni ?? [],
            fn (string $chiave) => ! str_starts_with($chiave, LivelloCondominio::CHIAVE.':')
                && ! str_starts_with($chiave, LivelloEsercizi::CHIAVE.':'),
            ARRAY_FILTER_USE_KEY,
        );

        // Assegnato e non salvato: `decisioniImplicite()` legge le decisioni dal lotto, e le
        // servono quelle nuove — ma una scrittura sola è meglio di due.
        $batch->decisioni = [
            ...$superstiti,
            ImportVerificaService::DESTINAZIONE_CONDOMINIO => $validato['condominio_id'],
        ];

        $batch->update(['decisioni' => $this->verifica->decisioniImplicite($batch)]);

        return back()->with($this->flashSuccess('Destinazione impostata: i dati verranno importati in questo condominio.'));
    }

    /**
     * Il commit: da qui in poi qualcosa entra in archivio.
     */
    public function conferma(string $uuid)
    {
        $batch = $this->lotto($uuid, ['files']);

        // ⚠️ **Il permesso si ricontrolla qui, dove si scrive davvero.**
        //
        // `destinazione()` autorizza il momento in cui la scelta viene **registrata**; poi la
        // scelta resta scritta sul lotto, e la scrittura vera avviene qui, magari giorni dopo.
        // Fra i due momenti un permesso si può revocare — è la ragione per cui i permessi
        // esistono — e senza questo controllo l'importazione dentro un condominio esistente
        // partiva lo stesso, perché «era già stata decisa».
        $destinazione = ($batch->decisioni ?? [])[ImportVerificaService::DESTINAZIONE_CONDOMINIO] ?? null;

        if ($destinazione !== null) {
            Gate::authorize('update', Condominio::class);
        }
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
        $batch = $this->lotto($uuid, ['condominio']);

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
            // ⚠️ **Non bloccante, ma per poco.**
            //
            // È l'unica stampa fra quelle attese che porta la testata, e dalla testata arrivano
            // il condominio e l'esercizio. Per mezza giornata questa riga ha detto «bloccante»,
            // che era il modo sbagliato di descrivere un problema vero: chi in Danea non ha
            // consuntivi — perché quel condominio lo prende in gestione adesso — non poteva
            // esportarlo nemmeno volendo, e si trovava davanti a un cartello che diceva
            // «senza, non si prosegue» su un file che non esiste. Adesso la destinazione si può
            // indicare a mano nella schermata di verifica, e questa stampa torna a essere ciò
            // che è: la sola sorgente dei **saldi di apertura**, che non stanno da nessun'altra parte.
            ReportType::RipartoConsuntivo->value => [
                'cosa' => 'il Riparto consuntivo',
                // ⚠️ Non «te li chiediamo»: dell'esercizio non si chiede niente, si usa quello
                // aperto del condominio scelto. E senza testata il condominio deve **esistere
                // già**, perché da questi file non si ricavano né il nome né le date.
                'serve_per' => 'i saldi di apertura — e per creare da sé il condominio e il suo esercizio, '
                    .'che stanno nella sua testata; senza, dovrai indicare nella schermata dopo un '
                    .'condominio già in archivio, con un esercizio aperto',
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
