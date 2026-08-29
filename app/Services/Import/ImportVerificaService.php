<?php

namespace App\Services\Import;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\ImportBatch;
use App\Services\Import\Canonical\CanonicalCondominio;
use App\Services\Import\Canonical\CanonicalEsercizio;
use App\Services\Import\Canonical\CanonicalSoggetto;
use App\Services\Import\Canonical\CanonicalTitolarita;
use App\Models\ImportFile;
use App\Services\Import\Canonical\CanonicalSaldiApertura;
use App\Services\Import\Canonical\CanonicalSaldo;
use App\Services\Import\Livelli\LivelloCapitoli;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use App\Services\Import\Livelli\LivelloSaldi;
use App\Services\Import\Livelli\LivelloSoggetti;
use App\Services\Import\Livelli\LivelloTabelle;
use App\Services\Import\Livelli\LivelloTitolarita;
use App\Services\Import\Livelli\LivelloUnita;
use App\Services\Import\Parser\AnagraficaMillesimiParser;
use App\Services\Import\Parser\BannerParser;
use App\Services\Import\Parser\BilancioConsuntivoParser;
use App\Services\Import\Parser\ElencoUnitaParser;
use App\Services\Import\Parser\RipartoConsuntivoParser;
use Illuminate\Support\Facades\Storage;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Legge i file di un lotto e ne ricava i dati canonici e i rilievi — la schermata S3 (§14.1).
 *
 * ## È il posto in cui i frammenti si fondono
 *
 * Ogni file produce un pezzo (§6): il riparto porta condominio, esercizio e saldi; l'elenco
 * unità porta persone, unità e titolarità; il compatto porta unità e tabelle. Nessuno da solo
 * basta, e le unità dei due file si sovrappongono in parte. La fusione avviene **per chiave**,
 * e il report più ricco vince sui doppioni — l'elenco unità porta piano, tipo e dati catastali
 * che il compatto non ha.
 *
 * ## Non scrive niente
 *
 * Questo servizio legge e basta. È il «dry-run first» del §7: la schermata di verifica esiste
 * proprio perché fra il leggere e lo scrivere ci sia un momento in cui l'amministratore può
 * dire di no.
 */
final class ImportVerificaService
{
    /**
     * Chiave di `import_batches.decisioni` per la destinazione scelta a mano.
     *
     * Il condominio si sceglie; l'esercizio **no**, si risolve — vedi `applicaDestinazione()`.
     */
    public const DESTINAZIONE_CONDOMINIO = 'destinazione:condominio';

    public function __construct(
        private readonly SpreadsheetReader $reader = new SpreadsheetReader,
        private readonly ReportRecognizer $riconoscitore = new ReportRecognizer,
    ) {}

    /**
     * @return array{
     *     canonici: array<string, mixed>,
     *     esiti: array<string, EsitoVerifica>,
     *     letture: list<array{file: string, tipo: string, righe: int}>,
     *     dichiaratoDaiFile: bool
     * }
     */
    public function verifica(ImportBatch $batch): array
    {
        $canonici = [];
        $esiti = [];
        $letture = [];

        // Le unità arrivano da due report diversi e si fondono: l'ordine conta, ed è per questo
        // che i frammenti si accumulano qui invece di essere assegnati direttamente.
        $immobiliCompatti = [];
        $immobiliRicchi = [];

        foreach ($batch->files as $file) {
            $tipo = $file->report_type === null ? null : ReportType::tryFrom($file->report_type);

            if ($tipo === null || ! $tipo->importabile()) {
                continue;
            }

            $foglio = $this->foglio($file, $tipo);

            if ($foglio === null) {
                $esiti['file:'.$file->id] = new EsitoVerifica(0, [Rilievo::errore(
                    'file.illeggibile',
                    sprintf('Non riesco a rileggere «%s».', $file->nome_originale),
                    'Ricaricalo: potrebbe essere stato spostato o danneggiato dopo il caricamento.',
                )]);

                continue;
            }

            [$foglioLetto, $rigaIntestazione] = $foglio;

            match ($tipo) {
                ReportType::RipartoConsuntivo => $this->leggiRiparto($foglioLetto, $rigaIntestazione, $canonici, $esiti, $letture, $file),
                ReportType::ElencoUnita => $this->leggiElencoUnita($foglioLetto, $rigaIntestazione, $canonici, $esiti, $letture, $file, $immobiliRicchi),
                ReportType::AnagraficaMillesimi => $this->leggiMillesimi($foglioLetto, $rigaIntestazione, $canonici, $esiti, $letture, $file, $immobiliCompatti),
                // Fino alla 1.11.0-beta.4 finiva nel `default`, cioè `leggiSoloBanner()`: se ne
                // leggeva la testata e si buttava il contenuto, perché non esisteva un livello
                // in cui i capitoli potessero atterrare.
                ReportType::BilancioConsuntivo => $this->leggiBilancio($foglioLetto, $rigaIntestazione, $canonici, $esiti, $letture, $file),
                // Gli altri report hanno una testata da cui ricavare condominio ed esercizio,
                // anche se il loro contenuto va nell'archivio storico che la 1.10 non ha.
                default => $this->leggiSoloBanner($foglioLetto, $canonici, $esiti),
            };
        }

        // La fusione: il compatto fa da base, il report ricco sovrascrive.
        $immobili = [...$immobiliCompatti, ...$immobiliRicchi];

        if ($immobili !== []) {
            $canonici[LivelloUnita::CHIAVE] = $immobili;
        }

        // Se il condominio lo dichiarano i **file**, la scelta manuale non si deve nemmeno
        // offrire: la schermata proporrebbe una tendina che poi verrebbe ignorata, perché la
        // testata di una stampa vince sempre sulla scelta a mano.
        $dichiaratoDaiFile = ($canonici[LivelloCondominio::CHIAVE] ?? null) !== null;

        $canonici = $this->applicaDestinazione($batch, $canonici);
        $canonici = $this->applicaDecisioni($batch, $canonici);

        // ⚠️ **Nessuno ha detto di quale condominio si tratta** — né i file né l'utente.
        //
        // Il condominio e l'esercizio si leggono dalla testata, e la testata la porta solo una
        // *stampa* esportata — il riparto consuntivo. Gli export «Import/Export tramite Excel»
        // di Danea sono elenchi puri: cominciano dalle intestazioni di colonna e non hanno
        // nessuna riga `Condominio <nome> - C. Fisc. <cf>`.
        //
        // Chi carica solo quelli arriva qui con unità, persone e tabelle lette correttamente e
        // **senza un condominio in cui metterle**. Fino al 28/08/2026 non se ne accorgeva
        // nessuno: la schermata di verifica mostrava tre avvisi innocui, e l'anteprima moriva
        // con un errore che non spiegava niente. Il rilievo giusto esisteva già — lo emette il
        // BannerParser — ma questi file il BannerParser non lo attivano mai.
        //
        // Il controllo sta **dopo** `applicaDestinazione`: se l'amministratore ha scelto lui in
        // quale condominio importare, non manca più niente e l'errore non deve comparire.
        if (($canonici[LivelloCondominio::CHIAVE] ?? null) === null) {
            $esiti[LivelloCondominio::CHIAVE] = ($esiti[LivelloCondominio::CHIAVE] ?? new EsitoVerifica(1))
                ->con(Rilievo::errore(
                    'condominio.nessun_file_lo_dichiara',
                    'Nessuno dei file caricati dice a quale condominio appartengono.',
                    'Il nome del condominio e il codice fiscale stanno nella testata delle stampe '
                    .'esportate da Danea, non negli export «Import/Export tramite Excel», che sono '
                    .'elenchi senza intestazione. Puoi indicare qui sotto in quale condominio '
                    .'importarli, oppure aggiungere una stampa che porti la testata — il '
                    .'«Consuntivo ripartizioni per unità» è quella giusta, e serve anche per i saldi.',
                ));
        }

        // ⚠️ **Il condominio scelto non ha un esercizio aperto: non entrerebbe niente.**
        //
        // `ImportRunner::esegui()` fa `return` al primo livello che non passa, ed «Esercizi» è il
        // **secondo**: cade lì e soggetti, unità, titolarità, tabelle e saldi non vengono nemmeno
        // tentati. Misurato: zero unità scritte.
        //
        // Il livello un messaggio ce l'ha — `esercizi.dati_assenti` — ma è quello sbagliato per
        // questo caso: dice «nessuno dei file dichiara il periodo» e manda a cercare una stampa
        // con la testata. Qui non è colpa dei file, e quella stampa non risolverebbe niente:
        // manca un esercizio **in archivio**, e si apre dal condominio.
        //
        // Va detto **prima**, dove c'è ancora la tendina con cui rimediare, e non dopo la
        // conferma con un'importazione che si ferma al secondo passo.
        if (! $dichiaratoDaiFile
            && ($canonici[LivelloCondominio::CHIAVE] ?? null) !== null
            && ($canonici[LivelloEsercizi::CHIAVE] ?? null) === null
        ) {
            $esiti[LivelloCondominio::CHIAVE] = ($esiti[LivelloCondominio::CHIAVE] ?? new EsitoVerifica(1))
                ->con(Rilievo::errore(
                    'esercizio.condominio_senza_esercizio_aperto',
                    sprintf(
                        '«%s» non ha un esercizio aperto: non ci si può importare niente.',
                        $canonici[LivelloCondominio::CHIAVE]->nome,
                    ),
                    'Ogni unità, persona e tabella entra dentro un esercizio, quindi senza non si '
                    .'scrive nemmeno la prima riga. Aprine uno dal condominio — «Esercizi» → «Nuovo '
                    .'esercizio» — e torna qui: i file caricati restano dove sono.',
                ));
        }

        // ⚠️ **Un esercizio straordinario non è un anno contabile: è una gestione.**
        //
        // Verificato sullo schema Firebird di Domustudio: là l'esercizio è una riga di
        // `TCONDOMINI` con date proprie e una colonna `STRAORDINARIO`, legata all'ordinario dallo
        // stesso `CONDGENID`. Da noi lo stesso concetto è una gestione `straordinaria`, e
        // `LivelloEsercizi` ce lo mette. Va annunciato **prima** della conferma, perché è una
        // scelta strutturale sul condominio di qualcun altro, non un dettaglio.
        $esercizio = $canonici[LivelloEsercizi::CHIAVE] ?? null;

        if ($esercizio !== null && $esercizio->eStraordinario()) {
            $esiti[LivelloCondominio::CHIAVE] = ($esiti[LivelloCondominio::CHIAVE] ?? new EsitoVerifica(1))
                ->con(Rilievo::avviso(
                    'esercizio.straordinario_in_gestione_dedicata',
                    sprintf('Il file è di un esercizio straordinario: «%s».', $esercizio->etichetta),
                    'Da noi ordinario e straordinario sono due gestioni dentro lo stesso esercizio, '
                    .'mentre nel tuo gestionale sono due esercizi distinti: lo metterò nella '
                    .'gestione straordinaria del condominio, senza aprire un secondo esercizio. '
                    .'Serve che l\'esercizio che copre quel periodo sia già stato importato.',
                ));
        }

        $esiti = $this->togliDecisioniPrese($batch, $esiti);

        return compact('canonici', 'esiti', 'letture', 'dichiaratoDaiFile');
    }

    /**
     * Un rilievo «da decidere» a cui l'utente ha già risposto **smette di bloccare**.
     *
     * Sembra ovvio e non lo era: le decisioni si applicavano ai dati e non ai rilievi, e il
     * pulsante di conferma restava disabilitato anche dopo che l'amministratore aveva deciso
     * tutto — senza dire perché. È il difetto peggiore di una schermata di conferma, perché
     * l'unica via d'uscita apparente è ricominciare da capo.
     *
     * @param  array<string, EsitoVerifica>  $esiti
     * @return array<string, EsitoVerifica>
     */
    private function togliDecisioniPrese(ImportBatch $batch, array $esiti): array
    {
        $decisioni = $batch->decisioni ?? [];

        if ($decisioni === []) {
            return $esiti;
        }

        $ripuliti = [];

        foreach ($esiti as $chiave => $esito) {
            $restano = array_values(array_filter(
                $esito->rilievi,
                fn (Rilievo $r) => $r->chiaveDecisione === null || ! array_key_exists($r->chiaveDecisione, $decisioni),
            ));

            $ripuliti[$chiave] = new EsitoVerifica($esito->righeTotali, $restano);
        }

        return $ripuliti;
    }

    /**
     * Applica le decisioni che l'utente ha già preso, prima che i dati arrivino ai livelli.
     *
     * Oggi ce n'è una sola e vale la pena spiegarla: **dividere in due un nome che sta in una
     * cella sola**. «ROSSI G. / BIANCHI N.» può essere due comproprietari o una società, e il
     * parser non lo decide — si limita a chiedere (§17.4, trappola 8).
     *
     * Quando l'utente risponde «dividi», qui nascono **due soggetti** e la titolarità che era
     * una diventa **due sulla stessa unità**: è la rappresentazione corretta della comproprietà
     * che il tracciato di Danea non sa esprimere.
     *
     * Il posto è questo e non il livello perché la divisione riguarda i **dati canonici**, non
     * la scrittura: se stesse nel committer, l'anteprima mostrerebbe una persona e l'archivio ne
     * conterrebbe due — cioè la schermata di conferma direbbe una cosa diversa da quella che
     * succede, che è il difetto peggiore che una conferma possa avere.
     *
     * @param  array<string, mixed>  $canonici
     * @return array<string, mixed>
     */
    /**
     * La destinazione scelta dall'amministratore: «importa dentro **questo** condominio».
     *
     * Serve a chi esporta da Danea con «Import/Export tramite Excel» invece che dalle stampe:
     * quegli export sono elenchi senza testata, quindi nessun file può dire in quale condominio
     * vadano messi. Prima della beta di questa correzione quelle persone non avevano nessuna
     * strada — e chi arriva da un altro gestionale senza un consuntivo da esportare non ne aveva
     * comunque, perché il consuntivo è l'unica stampa con la testata.
     *
     * **Non si inventa niente.** Il condominio canonico viene costruito dal record che l'utente
     * ha scelto, con il suo codice fiscale: da lì in avanti `RicercaEsistenti` lo ritrova come
     * esistente e i livelli scrivono dentro quello, esattamente come già succede a chi crea il
     * condominio a mano e poi carica file che portano il suo codice fiscale. È la stessa strada,
     * aperta a chi quel codice fiscale nei file non ce l'ha.
     *
     * La scelta vive in `decisioni` e non in una colonna nuova: è una decisione presa prima che
     * si scriva, come «dividi il nome doppio», e `import_batches.condominio_id` significa già
     * un'altra cosa — il condominio **creato** dall'importazione.
     *
     * @param  array<string, mixed>  $canonici
     * @return array<string, mixed>
     */
    /**
     * Le decisioni che la scelta della destinazione ha già preso, senza chiederle di nuovo.
     *
     * Chi indica «importa dentro Le Terrazze» ha già risposto alla domanda «in archivio c'è già
     * un condominio uguale, vuoi unirlo?»: è lo stesso record, l'ha scelto lui. Riproporla
     * nell'anteprima non è prudenza, è un'eco — e l'opzione «unisci» sarebbe pure dannosa,
     * perché riscriverebbe il condominio con i dati che vengono dal condominio stesso.
     *
     * `salta` è «lascia com'è»: tiene quello che c'è in archivio e ci collega unità, tabelle e
     * saldi. È l'unica risposta coerente con la scelta appena fatta.
     *
     * @return array<string, mixed> le decisioni del lotto, con dentro anche quelle implicite
     */
    public function decisioniImplicite(ImportBatch $batch): array
    {
        $decisioni = $batch->decisioni ?? [];
        $canonici = $this->applicaDestinazione($batch, []);

        if (isset($canonici[LivelloCondominio::CHIAVE])) {
            $decisioni[LivelloCondominio::CHIAVE.':'.$canonici[LivelloCondominio::CHIAVE]->chiave()] = 'salta';
        }

        if (isset($canonici[LivelloEsercizi::CHIAVE])) {
            $decisioni[LivelloEsercizi::CHIAVE.':'.$canonici[LivelloEsercizi::CHIAVE]->etichetta] = 'salta';
        }

        return $decisioni;
    }

    /**
     * L'esercizio **aperto** di un condominio, o `null` se non ne ha.
     *
     * Pubblico e statico perché la schermata deve poter dichiarare **prima** quale esercizio
     * verrà usato: un fatto annunciato e poi disatteso è peggio di una domanda.
     */
    public static function esercizioApertoDi(int $condominioId): ?Esercizio
    {
        return Esercizio::where('condominio_id', $condominioId)
            ->where('stato', 'aperto')
            ->orderBy('data_inizio', 'desc')
            ->first();
    }

    private function applicaDestinazione(ImportBatch $batch, array $canonici): array
    {
        $condominioId = ($batch->decisioni ?? [])[self::DESTINAZIONE_CONDOMINIO] ?? null;

        if ($condominioId === null) {
            return $canonici;
        }

        // ⚠️ **Se i file lo dichiarano, qui non si applica niente — nemmeno l'esercizio.**
        //
        // Per mezza giornata questa uscita non c'era: il condominio era protetto dalla sua
        // guardia, l'esercizio no. Con una testata che porta il condominio **senza** la riga
        // `Periodo:` — e una scelta di destinazione rimasta sul lotto da prima — uscivano
        // canonici **misti**: condominio dal file, esercizio di un altro stabile. Misurato:
        // condominio «A DAL FILE», esercizio «ESERCIZIO-DI-B». Nessun errore, nessun avviso, e
        // ogni titolarità di A nata con il periodo di B.
        //
        // La regola è una sola e vale per entrambi: **la testata di una stampa comanda**, e
        // l'esercizio appartiene al condominio in cui si sta scrivendo, non a quello che
        // l'utente aveva indicato prima di caricare il file.
        if (($canonici[LivelloCondominio::CHIAVE] ?? null) !== null) {
            return $canonici;
        }

        $c = Condominio::find($condominioId);

        if ($c === null) {
            // Il condominio scelto è stato cancellato nel frattempo. Non si inventa niente: senza
            // canonico riparte il rilievo «nessun file lo dichiara», che è la verità.
            return $canonici;
        }

        $canonici[LivelloCondominio::CHIAVE] = new CanonicalCondominio(
            nome: $c->nome,
            codiceFiscale: $c->codice_fiscale,
            indirizzo: $c->indirizzo,
            cap: $c->cap,
            comune: $c->comune,
            provincia: $c->provincia,
            idScelto: $c->id,
        );

        // ⚠️ **L'esercizio non si sceglie: si risolve.**
        //
        // Per una manciata di ore questa è stata una seconda tendina, e sarebbe stata la
        // superficie d'errore peggiore di tutto l'importatore. La data di inizio dell'esercizio
        // diventa la `data_inizio` di **ogni titolarità** scritta — misurato: importando nel
        // 2026 le righe di `anagrafica_immobile` nascono con `2026-01-01`. Puntare all'anno
        // sbagliato non produce nessun errore, nessun avviso e nessuna quadratura fuori posto:
        // scrive numeri giusti nel periodo sbagliato, e ci si accorge al primo riparto. La
        // tendina per giunta elencava **anche gli esercizi chiusi**.
        //
        // La regola è quella che usa tutto il resto del prodotto — `HasEsercizio::getEsercizioCorrente()`:
        // l'esercizio **aperto**, con l'invariante «al più uno per condominio» presidiata da
        // `CreateEsercizioRequest` e da `CondominioService::createEsercizioForCondominio()`.
        // Importare significa registrare chi possiede cosa **adesso**, e «adesso» è l'esercizio
        // aperto: non c'è una seconda risposta ragionevole da offrire.
        $e = self::esercizioApertoDi($c->id);

        if ($e !== null) {
            $canonici[LivelloEsercizi::CHIAVE] = new CanonicalEsercizio(
                etichetta: $e->nome,
                dataInizio: CarbonImmutable::parse($e->data_inizio),
                dataFine: CarbonImmutable::parse($e->data_fine),
            );
        }

        return $canonici;
    }

    private function applicaDecisioni(ImportBatch $batch, array $canonici): array
    {
        $decisioni = $batch->decisioni ?? [];

        if ($decisioni === []) {
            return $canonici;
        }

        /** @var array<string, CanonicalSoggetto> $soggetti */
        $soggetti = $canonici[LivelloSoggetti::CHIAVE] ?? [];
        /** @var list<CanonicalTitolarita> $titolarita */
        $titolarita = $canonici[LivelloTitolarita::CHIAVE] ?? [];

        foreach ($decisioni as $chiave => $scelta) {
            if ($scelta !== 'dividi' || ! str_starts_with($chiave, 'soggetto:')) {
                continue;
            }

            $chiaveSoggetto = substr($chiave, strlen('soggetto:'));
            $originale = $soggetti[$chiaveSoggetto] ?? null;

            if ($originale === null) {
                continue;
            }

            $pezzi = array_values(array_filter(array_map('trim', preg_split('#\s*/\s*#', $originale->nome) ?: [])));

            if (count($pezzi) < 2) {
                continue;
            }

            unset($soggetti[$chiaveSoggetto]);
            $nuove = [];

            foreach ($pezzi as $i => $nome) {
                // Il codice fiscale resta al **primo**: è l'unico a cui appartiene con certezza,
                // e duplicarlo violerebbe l'indice unico su `anagrafiche.codice_fiscale`.
                // Al secondo si dà una chiave costruita sul nome, che è il ripiego di sempre.
                $nuovo = new CanonicalSoggetto(
                    nome: $nome,
                    codiceFiscale: $i === 0 ? $originale->codiceFiscale : null,
                    indirizzo: $originale->indirizzo,
                    cap: $originale->cap,
                    comune: $originale->comune,
                    provincia: $originale->provincia,
                    // Anche i recapiti restano al primo: sono unici a schema, e attribuirli a
                    // entrambi farebbe fallire l'inserimento del secondo.
                    email: $i === 0 ? $originale->email : null,
                    pec: $i === 0 ? $originale->pec : null,
                    telefoni: $i === 0 ? $originale->telefoni : [],
                    note: $originale->note,
                );

                $soggetti[$nuovo->chiave()] = $nuovo;
                $nuove[] = $nuovo->chiave();
            }

            // La titolarità che era una diventa due sulla stessa unità: è la comproprietà.
            $aggiornate = [];

            foreach ($titolarita as $t) {
                if ($t->soggettoRef !== $chiaveSoggetto) {
                    $aggiornate[] = $t;

                    continue;
                }

                foreach ($nuove as $ref) {
                    $aggiornate[] = new CanonicalTitolarita(
                        immobileRef: $t->immobileRef,
                        soggettoRef: $ref,
                        ruolo: $t->ruolo,
                        // Il saldo **non** si spezza: resta sul primo. Dividerlo a metà sarebbe
                        // inventare una ripartizione che il file non dichiara, su denaro altrui.
                        saldoPrecedenteCents: $ref === $nuove[0] ? $t->saldoPrecedenteCents : null,
                        quota: round(100 / count($nuove), 2),
                        rigaSorgente: $t->rigaSorgente,
                    );
                }
            }

            $titolarita = $aggiornate;

            // ── E i saldi, che altrimenti restano orfani ──────────────────────────────────
            //
            // Il riparto continua a intestare la posizione al **nome unito**: dividendo le
            // persone, quel nome sparisce dall'archivio e il saldo non trova più nessuno. Sul
            // primo condominio vero l'effetto era un blocco secco al livello «Saldi di
            // apertura», provocato dalla decisione che l'interfaccia consiglia per prima.
            //
            // Non si spezza in due: il file non dice in che proporzione, e inventarla
            // significherebbe decidere su denaro altrui. Diventa **solidale sull'unità**, che è
            // esattamente ciò che quella posizione è — un debito di due persone insieme.
            $saldi = $canonici[LivelloSaldi::CHIAVE] ?? null;

            if ($saldi instanceof CanonicalSaldiApertura) {
                $cercato = $this->chiaveNome($originale->nome);
                $righe = [];

                foreach ($saldi->righe as $r) {
                    if ($r->soggettoNome === null || $this->chiaveNome($r->soggettoNome) !== $cercato) {
                        $righe[] = $r;

                        continue;
                    }

                    $righe[] = new CanonicalSaldo(
                        immobileRef: $r->immobileRef,
                        soggettoNome: null,
                        importoCents: $r->importoCents,
                        daTitolareCessato: $r->daTitolareCessato,
                        rigaSorgente: $r->rigaSorgente,
                        daNomeDiviso: true,
                    );
                }

                $canonici[LivelloSaldi::CHIAVE] = new CanonicalSaldiApertura(
                    righe: $righe,
                    totaleRiferimentoCents: $saldi->totaleRiferimentoCents,
                    arrotondamentiCents: $saldi->arrotondamentiCents,
                );
            }
        }

        $canonici[LivelloSoggetti::CHIAVE] = $soggetti;
        $canonici[LivelloTitolarita::CHIAVE] = $titolarita;

        return $canonici;
    }

    /**
     * La forma su cui due nomi si dicono uguali: minuscolo, spazi collassati.
     *
     * Danea scrive «ROSSI M. /  BIANCHI L.» con due spazi dopo la barra, e il confronto crudo
     * fallisce su quello.
     */
    private function chiaveNome(string $nome): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $nome)));
    }

    /**
     * @param  array<string, mixed>  $canonici
     * @param  array<string, EsitoVerifica>  $esiti
     * @param  list<array{file: string, tipo: string, righe: int}>  $letture
     */
    private function leggiRiparto(Foglio $foglio, int $riga, array &$canonici, array &$esiti, array &$letture, ImportFile $file): void
    {
        $banner = (new BannerParser)->estrai($foglio);
        $this->registraBanner($banner, $canonici, $esiti);

        $saldi = (new RipartoConsuntivoParser)->estrai($foglio, $riga);

        $canonici[LivelloSaldi::CHIAVE] = new CanonicalSaldiApertura(
            righe: $saldi['saldi'],
            totaleRiferimentoCents: $saldi['totale_riferimento_cents'],
            arrotondamentiCents: $saldi['arrotondamenti_cents'],
        );

        $esiti[LivelloSaldi::CHIAVE] = $saldi['esito'];
        $letture[] = ['file' => $file->nome_originale, 'tipo' => 'Saldi di apertura', 'righe' => $saldi['esito']->righeTotali];
    }

    /**
     * @param  array<string, mixed>  $canonici
     * @param  array<string, EsitoVerifica>  $esiti
     * @param  list<array{file: string, tipo: string, righe: int}>  $letture
     * @param  array<string, mixed>  $immobili
     */
    private function leggiElencoUnita(Foglio $foglio, int $riga, array &$canonici, array &$esiti, array &$letture, ImportFile $file, array &$immobili): void
    {
        $letto = (new ElencoUnitaParser)->estrai($foglio, $riga);

        $canonici[LivelloSoggetti::CHIAVE] = $letto['soggetti'];
        $canonici[LivelloTitolarita::CHIAVE] = $letto['titolarita'];
        $immobili = [...$immobili, ...$letto['immobili']];

        $esiti[LivelloSoggetti::CHIAVE] = $letto['esito'];
        $letture[] = ['file' => $file->nome_originale, 'tipo' => 'Persone, unità e titolarità', 'righe' => $letto['esito']->righeTotali];
    }

    /**
     * @param  array<string, mixed>  $canonici
     * @param  array<string, EsitoVerifica>  $esiti
     * @param  list<array{file: string, tipo: string, righe: int}>  $letture
     * @param  array<string, mixed>  $immobili
     */
    private function leggiMillesimi(Foglio $foglio, int $riga, array &$canonici, array &$esiti, array &$letture, ImportFile $file, array &$immobili): void
    {
        $letto = (new AnagraficaMillesimiParser)->estrai($foglio, $riga);

        $canonici[LivelloTabelle::CHIAVE] = $letto['tabelle'];
        $immobili = [...$immobili, ...$letto['immobili']];

        $esiti[LivelloTabelle::CHIAVE] = $letto['esito'];
        $letture[] = ['file' => $file->nome_originale, 'tipo' => 'Tabelle millesimali', 'righe' => $letto['esito']->righeTotali];
    }

    /**
     * @param  array<string, mixed>  $canonici
     * @param  array<string, EsitoVerifica>  $esiti
     */
    /**
     * Il bilancio consuntivo → la struttura delle spese, **e** la testata come tutti gli altri.
     *
     * Il banner si legge lo stesso: questa stampa lo porta come le sue sorelle, ed è uno dei file
     * che permettono all'importazione di sapere di quale condominio si tratta.
     */
    private function leggiBilancio(
        Foglio $foglio,
        int $rigaIntestazione,
        array &$canonici,
        array &$esiti,
        array &$letture,
        ImportFile $file,
    ): void {
        $this->leggiSoloBanner($foglio, $canonici, $esiti);

        $letto = (new BilancioConsuntivoParser)->estrai($foglio, $rigaIntestazione);

        $canonici[LivelloCapitoli::CHIAVE] = $letto['struttura'];
        $esiti[LivelloCapitoli::CHIAVE] = $letto['esito'];

        $letture[] = [
            'file' => $file->nome_originale,
            'tipo' => 'Capitoli di spesa',
            'righe' => $letto['esito']->righeTotali,
        ];
    }

    private function leggiSoloBanner(Foglio $foglio, array &$canonici, array &$esiti): void
    {
        $this->registraBanner((new BannerParser)->estrai($foglio), $canonici, $esiti);
    }

    /**
     * Il condominio e l'esercizio arrivano dalla testata di **più** stampe, e devono coincidere.
     *
     * Il primo che si legge vince; i successivi non sovrascrivono. Se dichiarassero condomìni
     * diversi sarebbe un errore grave — due export di due condomìni caricati insieme — e va
     * detto invece che risolto in silenzio prendendo l'ultimo.
     *
     * @param  array{condominio: mixed, esercizio: mixed, esito: EsitoVerifica}  $banner
     * @param  array<string, mixed>  $canonici
     * @param  array<string, EsitoVerifica>  $esiti
     */
    private function registraBanner(array $banner, array &$canonici, array &$esiti): void
    {
        if ($banner['condominio'] === null) {
            return;
        }

        $gia = $canonici[LivelloCondominio::CHIAVE] ?? null;

        if ($gia !== null) {
            if ($gia->chiave() !== $banner['condominio']->chiave()) {
                $esiti[LivelloCondominio::CHIAVE] = ($esiti[LivelloCondominio::CHIAVE] ?? new EsitoVerifica(1))
                    ->con(Rilievo::errore(
                        'condominio.file_di_condomini_diversi',
                        sprintf(
                            'I file caricati parlano di due condomìni diversi: «%s» e «%s».',
                            $gia->nome,
                            $banner['condominio']->nome,
                        ),
                        'Importa un condominio alla volta: mescolarli farebbe finire i saldi '
                        .'dell\'uno sulle unità dell\'altro. Togli i file di troppo dal caricamento.',
                    ));
            }

            return;
        }

        $canonici[LivelloCondominio::CHIAVE] = $banner['condominio'];
        $esiti[LivelloCondominio::CHIAVE] = $banner['esito'];

        if ($banner['esercizio'] !== null) {
            $canonici[LivelloEsercizi::CHIAVE] = $banner['esercizio'];
        }
    }

    /**
     * @return array{0: Foglio, 1: int}|null
     */
    private function foglio(ImportFile $file, ReportType $tipo): ?array
    {
        $percorso = Storage::disk(ImportUploadService::DISCO)->path($file->percorso);

        try {
            $fogli = $this->reader->leggi($percorso);
        } catch (RuntimeException) {
            return null;
        }

        foreach ($fogli as $foglio) {
            $esito = $this->riconoscitore->riconosci($foglio);

            if ($esito->tipo === $tipo && $esito->rigaIntestazione !== null) {
                return [$foglio, $esito->rigaIntestazione];
            }
        }

        // Il tipo è stato **forzato dall'utente**: il riconoscitore non lo confermerebbe, ma
        // l'ultima parola è sua. Si cerca comunque l'intestazione con le etichette di quel tipo.
        foreach ($fogli as $foglio) {
            $trovato = (new HeaderDetector)->trova($foglio, $tipo->etichette(), $tipo->quorum());

            if ($trovato !== null) {
                return [$foglio, $trovato['riga']];
            }
        }

        return null;
    }
}
