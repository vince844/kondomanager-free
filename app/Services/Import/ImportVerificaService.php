<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Import;

use App\Helpers\MoneyHelper;
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
use App\Services\Import\Parser\ModelloManualeParser;
use App\Services\Import\Parser\RipartoConsuntivoParser;
use App\Traits\HasEsercizio;
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
    use HasEsercizio;

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

        // ⚠️ **Chi ha fornito che cosa.** Serve a due difetti trovati dalla revisione
        // avversariale della beta.5, che sono lo stesso difetto visto da due lati: in un lotto
        // che contiene il modello compilato a mano **e** una stampa di Danea, i lettori di Danea
        // assegnavano canonici ed esiti con un `=` secco. Misurato: caricando il modello da solo
        // l'esito dei saldi aveva un errore bloccante e `confermabile = false`; aggiungendo i due
        // file di Danea lo stesso esito tornava a zero errori e **confermabile = true**. I due
        // errori scritti a mano non erano stati risolti — erano stati cancellati.
        //
        // Il caso misto è legittimo e previsto (si esporta quel che si può e si scrive a mano il
        // resto), ma è legittimo solo finché i file si **completano**. Due file che portano lo
        // stesso livello sono un conflitto, e va detto, non risolto in silenzio prendendo l'ultimo.
        $fonteCanonico = [];

        foreach ($batch->files as $file) {
            $tipo = $file->report_type === null ? null : ReportType::tryFrom($file->report_type);

            if ($tipo === null || ! $tipo->importabile()) {
                continue;
            }

            // ⚠️ **Il modello manuale non passa di qui.** `foglio()` sceglie **un** foglio e
            // scarta gli altri quattro: è la strada giusta per una stampa di Danea, dove il
            // report è uno, ed è quella sbagliata per un file che è un report solo *scritto su
            // cinque fogli*. Va intercettato prima della scelta, non dopo.
            if ($tipo === ReportType::ModelloManuale) {
                $this->leggiModello($file, $canonici, $fonteCanonico, $esiti, $letture, $immobiliRicchi);

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
                ReportType::RipartoConsuntivo => $this->leggiRiparto($foglioLetto, $rigaIntestazione, $canonici, $fonteCanonico, $esiti, $letture, $file),
                ReportType::ElencoUnita => $this->leggiElencoUnita($foglioLetto, $rigaIntestazione, $canonici, $fonteCanonico, $esiti, $letture, $file, $immobiliRicchi),
                ReportType::AnagraficaMillesimi => $this->leggiMillesimi($foglioLetto, $rigaIntestazione, $canonici, $fonteCanonico, $esiti, $letture, $file, $immobiliCompatti),
                // Fino alla 1.11.0-beta.4 finiva nel `default`, cioè `leggiSoloBanner()`: se ne
                // leggeva la testata e si buttava il contenuto, perché non esisteva un livello
                // in cui i capitoli potessero atterrare.
                ReportType::BilancioConsuntivo => $this->leggiBilancio($foglioLetto, $rigaIntestazione, $canonici, $fonteCanonico, $esiti, $letture, $file),
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

        // Coda 759 (1) e (2): i saldi che la conferma non scriverebbe si dicono qui, prima, e non dopo con l'importazione a metà. Sono
        // «da decidere» e non errori perché c'è una scelta (decisione 707 (2)): correggere il file e ricaricarlo, oppure «Importa senza
        // i saldi» nella schermata di conferma. Così la verifica lascia arrivare a quella schermata, e la conferma piena resta chiusa.
        foreach ($this->saldiCheNonEntrano($canonici) as $blocco) {
            $esiti[LivelloSaldi::CHIAVE] = ($esiti[LivelloSaldi::CHIAVE] ?? new EsitoVerifica(0))->con($blocco['rilievo']);
        }

        // ⚠️ **Le cose che esistono già in archivio sono decisioni come le altre** (1.12.0-beta.14). Le calcolava solo l'anteprima
        // (`AnteprimaImport::collisioni()`), e la schermata teneva spento il pulsante finché restavano aperte; ma `confermabile`, che è il
        // controllo del server, non le vedeva: una conferma scritta a mano, o partita da una pagina caricata prima che una persona del
        // file entrasse in archivio, scriveva condominio ed esercizio e si fermava a «Persone». Qui diventano rilievi da decidere, con la
        // chiave della decisione: la stessa ricerca, la stessa chiave, e la decisione presa le toglie (`togliDecisioniPrese()`).
        foreach ((new AnteprimaImport)->collisioni($canonici)['voci'] as $voce) {
            $livello = $voce['cosa'] === 'persona' ? LivelloSoggetti::CHIAVE : LivelloCondominio::CHIAVE;
            $esiti[$livello] = ($esiti[$livello] ?? new EsitoVerifica(1))->con($this->rilievoDellaCollisione($voce));
        }

        $esiti = $this->togliDecisioniPrese($batch, $esiti);

        return compact('canonici', 'esiti', 'letture', 'dichiaratoDaiFile');
    }

    /** I codici delle cose che esistono già in archivio: gli stessi che i livelli danno scrivendo, quando la decisione manca. */
    public const CODICI_DELLE_COSE_CHE_ESISTONO = ['condominio.duplicato', 'esercizi.duplicato', 'soggetto.duplicato'];

    /**
     * Il rilievo di una cosa che esiste già, per la verifica: la si decide nella schermata di conferma.
     *
     * @param  array{chiave_decisione: string, cosa: string, nome: string, esistente: string, motivo: string}  $voce  da `AnteprimaImport::collisioni()`
     */
    private function rilievoDellaCollisione(array $voce): Rilievo
    {
        $come = match ($voce['motivo']) {
            RicercaEsistenti::PER_CODICE_FISCALE => 'con lo stesso codice fiscale',
            RicercaEsistenti::PER_PERIODO => 'sullo stesso periodo',
            default => 'con lo stesso nome',
        };

        return match ($voce['cosa']) {
            'esercizio' => Rilievo::daDecidere(
                'esercizi.duplicato',
                sprintf('In archivio c\'è già l\'esercizio «%s», %s.', $voce['esistente'], $come),
                'Nella schermata di conferma scegli «Usa quello che c\'è già»: due esercizi sullo stesso periodo sdoppierebbero i saldi.',
                chiaveDecisione: $voce['chiave_decisione'],
            ),
            'persona' => Rilievo::daDecidere(
                'soggetto.duplicato',
                sprintf('«%s» c\'è già in archivio, %s.', $voce['nome'], $come),
                'Lo decidi nella schermata di conferma: «Unisci» aggiorna la persona che hai in archivio con i dati del file, «Lascia '
                .'com\'è» la tiene com\'è e ci collega comunque le unità.',
                chiaveDecisione: $voce['chiave_decisione'],
            ),
            default => Rilievo::daDecidere(
                'condominio.duplicato',
                sprintf('In archivio c\'è già «%s», %s.', $voce['esistente'], $come),
                'Lo decidi nella schermata di conferma: «Unisci» aggiorna il condominio che hai in archivio con i dati del file, «Lascia '
                .'com\'è» lo tiene com\'è e ci collega unità, tabelle e saldi.',
                chiaveDecisione: $voce['chiave_decisione'],
            ),
        };
    }

    /** I saldi intestati a persone che nessun file porta (manca l'Elenco unità, o il foglio delle persone è vuoto). */
    public const SALDI_SENZA_PERSONE = 'saldi.senza_persone';

    /** Lo scarto fra la somma dei saldi e il totale scritto nel riparto: lo stesso codice del blocco di `LivelloSaldi`. */
    public const SALDI_NON_QUADRANO = 'saldi.non_quadrano';

    /** Un saldo su un'unità che i file non hanno, o che nei file corrisponde a più unità. */
    public const SALDI_UNITA_SCONOSCIUTA = 'saldi.unita_sconosciuta';

    /** Un saldo intestato a un nome che non è fra le persone dei file. */
    public const SALDI_PERSONA_SCONOSCIUTA = 'saldi.persona_sconosciuta';

    /**
     * Perché i saldi non entrerebbero, detto **prima** della conferma (Coda 759 (1) e (2), decisione 707 (2)).
     *
     * Sono i casi in cui `LivelloSaldi::commit()` si ferma dopo che gli altri livelli sono già entrati, cioè l'importazione a
     * metà: i saldi intestati a persone che non arrivano da nessun file (`saldi.nessuna_persona_importata` del livello), le righe su
     * un'unità o a un nome che i file non hanno (`LivelloSaldi::righeSenzaDestinatario()`, la stessa ricerca del livello: dalla
     * 1.12.0-beta.14, il nome scritto male nel foglio dei saldi era il caso più comune e passava) e lo scarto
     * con il totale del riparto (`LivelloSaldi::verificaQuadratura()`, la stessa regola e le stesse cifre). Per ognuno: la frase corta
     * accanto al pulsante spento (`motivo`), la strada per correggerlo (`strada`), la frase dell'esito con «Importa senza i saldi»
     * (`esito`) e il rilievo della verifica.
     *
     * @param  array<string, mixed>  $canonici  dopo le decisioni: dividere un nome doppio cambia i saldi
     * @return list<array{codice: string, motivo: string, strada: string, esito: string, rilievo: Rilievo}>
     */
    public function saldiCheNonEntrano(array $canonici): array
    {
        $saldi = $canonici[LivelloSaldi::CHIAVE] ?? null;

        if (! $saldi instanceof CanonicalSaldiApertura) {
            return [];
        }

        $blocchi = [];
        $delModello = $saldi->fonte === 'foglio dei saldi';
        $intestati = count(array_filter($saldi->righe, fn (CanonicalSaldo $s) => ! $s->isSolidale()));

        if ($intestati > 0 && ($canonici[LivelloSoggetti::CHIAVE] ?? []) === []) {
            $quanti = $intestati === 1 ? '1 saldo è intestato' : $intestati.' saldi sono intestati';
            $oppure = ' Oppure, nella schermata di conferma, importa tutto il resto senza i saldi: li scriverai tu.';

            $blocchi[] = $delModello ? [
                'codice' => self::SALDI_SENZA_PERSONE,
                'motivo' => 'Il foglio «2 persone» del modello è vuoto: i saldi sono intestati a persone che non ci sono.',
                'strada' => 'Compila il foglio «2 persone» e ricarica il file.',
                'esito' => 'Il foglio «2 persone» del modello era vuoto.',
                'rilievo' => Rilievo::daDecidere(
                    self::SALDI_SENZA_PERSONE,
                    $quanti.' a una persona, ma il foglio «2 persone» del modello è vuoto.',
                    'Compila il foglio e ricarica il file; se quelle posizioni seguono la casa e non la persona, lascia vuota la colonna '
                    .'«persona» e diventano saldi solidali sull\'unità.'.$oppure,
                ),
            ] : [
                'codice' => self::SALDI_SENZA_PERSONE,
                'motivo' => 'Manca l\'Elenco unità: i saldi del riparto sono intestati a persone che arrivano da quel file.',
                'strada' => 'Carica l\'Elenco unità insieme agli altri file e ricaricali.',
                'esito' => 'Mancava l\'Elenco unità, con le persone a cui sono intestati.',
                'rilievo' => Rilievo::daDecidere(
                    self::SALDI_SENZA_PERSONE,
                    $quanti.' a una persona, ma nessuno dei file porta le persone: manca l\'Elenco unità.',
                    'Caricalo insieme agli altri file e riprova: il riparto porta i nomi, ma le persone arrivano dall\'Elenco unità.'.$oppure,
                ),
            ];
        }

        // Le righe una per una, come le cercherà il livello: solo se le persone ci sono (senza, lo dice il blocco qui sopra) e se le unità
        // arrivano dai file (senza, il livello dei saldi si salta con quello delle unità).
        $unita = $canonici[LivelloUnita::CHIAVE] ?? [];

        if ($blocchi === [] && $unita !== []) {
            $righe = (new LivelloSaldi)->righeSenzaDestinatario($saldi, $unita, $canonici[LivelloSoggetti::CHIAVE] ?? []);
            $senzaUnita = array_values(array_filter($righe, fn (array $r) => $r['codice'] !== 'saldi.soggetto_non_trovato'));
            $senzaPersona = array_values(array_filter($righe, fn (array $r) => $r['codice'] === 'saldi.soggetto_non_trovato'));

            if ($senzaUnita !== []) {
                $blocchi[] = $this->bloccoDelleUnita($senzaUnita, $delModello);
            }

            if ($senzaPersona !== []) {
                $blocchi[] = $this->bloccoDellePersone(array_column($senzaPersona, 'saldo'), $delModello);
            }
        }

        $quadratura = (new LivelloSaldi)->verificaQuadratura($saldi);

        if ($quadratura !== null) {
            $scarto = MoneyHelper::format(abs((int) $saldi->scartoCents()));

            $blocchi[] = [
                'codice' => self::SALDI_NON_QUADRANO,
                'motivo' => sprintf('Lo scarto è di %s: la somma dei saldi letti non torna con il totale scritto nel riparto.', $scarto),
                'strada' => 'Controlla nel file le righe dei saldi (una cella cambiata, una riga in più o in meno) e ricaricalo.',
                'esito' => sprintf('Lo scarto con il totale del riparto era di %s.', $scarto),
                'rilievo' => Rilievo::daDecidere(
                    self::SALDI_NON_QUADRANO,
                    $quadratura->messaggio,
                    'I saldi non entrano finché il totale non torna: un saldo sbagliato si trascina in ogni riparto e non si nota più. '
                    .'Controlla di aver esportato il riparto completo, senza filtri e senza righe cancellate a mano, e ricaricalo. '
                    .'Oppure, nella schermata di conferma, importa tutto il resto senza i saldi: li scriverai tu.',
                ),
            ];
        }

        return $blocchi;
    }

    /**
     * I saldi su unità che i file non hanno (o che corrispondono a più unità): il riparto di Danea porta solo il progressivo.
     *
     * @param  list<array{saldo: CanonicalSaldo, codice: string}>  $righe
     * @return array{codice: string, motivo: string, strada: string, esito: string, rilievo: Rilievo}
     */
    private function bloccoDelleUnita(array $righe, bool $delModello): array
    {
        $elenco = $this->elencoTraVirgolette(array_values(array_unique(array_map(fn (array $r) => $r['saldo']->immobileRef, $righe))));
        $ambigue = array_filter($righe, fn (array $r) => $r['codice'] === 'saldi.unita_ambigua') !== [];
        $dove = $delModello ? 'Nel foglio «4 saldi»' : 'Nel riparto';
        $uno = count($righe) === 1;
        $quale = $uno
            ? ($ambigue ? 'che nei file non trovo, o che corrisponde a più unità (più palazzine)' : 'che non è fra quelle dei file')
            : ($ambigue ? 'che nei file non trovo, o che corrispondono a più unità (più palazzine)' : 'che non sono fra quelle dei file');

        return [
            'codice' => self::SALDI_UNITA_SCONOSCIUTA,
            'motivo' => $uno
                ? sprintf('%s c\'è un saldo sull\'unità %s, %s.', $dove, $elenco, $quale)
                : sprintf('%s ci sono %d saldi su unità %s: %s.', $dove, count($righe), $quale, $elenco),
            'strada' => $delModello
                ? 'Scrivi nel foglio «4 saldi» la stessa sigla del foglio delle unità e ricarica il file.'
                : 'Esporta di nuovo il riparto e l\'Elenco unità insieme, dallo stesso condominio e dallo stesso esercizio, e ricaricali.',
            'esito' => $uno
                ? sprintf('%s c\'era un saldo sull\'unità %s, %s.', $dove, $elenco, $quale)
                : sprintf('%s c\'erano %d saldi su unità %s: %s.', $dove, count($righe), $quale, $elenco),
            'rilievo' => Rilievo::daDecidere(
                self::SALDI_UNITA_SCONOSCIUTA,
                $uno
                    ? sprintf('%s%s, c\'è un saldo sull\'unità «%s», %s.', $dove, $this->allaRiga($righe[0]['saldo']), $righe[0]['saldo']->immobileRef, $quale)
                    : sprintf('%s ci sono %d saldi su unità %s: %s.', $dove, count($righe), $quale,
                        implode(', ', array_map(fn (array $r) => '«'.$r['saldo']->immobileRef.'»'.$this->laRiga($r['saldo']), $righe))),
                ($delModello
                    ? 'La sigla va scritta identica al foglio delle unità.'
                    : 'Il riparto e l\'Elenco unità devono venire dallo stesso condominio e dallo stesso esercizio; con più palazzine il '
                    .'riparto, che porta solo il numero dell\'unità, non dice di quale si tratta: importa i saldi una palazzina alla volta.')
                .' Oppure, nella schermata di conferma, importa tutto il resto senza i saldi: li scriverai tu.',
                $uno ? $righe[0]['saldo']->rigaSorgente : null,
            ),
        ];
    }

    /**
     * I saldi intestati a nomi che non sono fra le persone dei file: nel modello, il nome scritto diverso dal foglio «2 persone».
     *
     * @param  list<CanonicalSaldo>  $righe
     * @return array{codice: string, motivo: string, strada: string, esito: string, rilievo: Rilievo}
     */
    private function bloccoDellePersone(array $righe, bool $delModello): array
    {
        $nomi = $this->elencoTraVirgolette(array_values(array_unique(array_map(fn (CanonicalSaldo $s) => (string) $s->soggettoNome, $righe))));
        $fra = $delModello ? 'nel foglio «2 persone»' : 'fra le persone dell\'Elenco unità';
        $dove = $delModello ? 'Nel foglio «4 saldi»' : 'Nel riparto';
        $uno = count($righe) === 1;

        return [
            'codice' => self::SALDI_PERSONA_SCONOSCIUTA,
            'motivo' => $uno
                ? sprintf('Il saldo di %s è intestato a un nome che non è %s.', $nomi, $fra)
                : sprintf('%d saldi sono intestati a nomi che non sono %s: %s.', count($righe), $fra, $nomi),
            'strada' => $delModello
                ? 'Scrivi nel foglio «4 saldi» i nomi come nel foglio «2 persone» e ricarica il file.'
                : 'Esporta di nuovo il riparto e l\'Elenco unità insieme, dallo stesso condominio, e ricaricali.',
            'esito' => $uno
                ? sprintf('%s c\'era un saldo intestato a %s, che non è %s.', $dove, $nomi, $fra)
                : sprintf('%s c\'erano %d saldi intestati a nomi che non sono %s: %s.', $dove, count($righe), $fra, $nomi),
            'rilievo' => Rilievo::daDecidere(
                self::SALDI_PERSONA_SCONOSCIUTA,
                $uno
                    ? sprintf('%s%s, il saldo è intestato a «%s», che non è %s.', $dove, $this->allaRiga($righe[0]), $righe[0]->soggettoNome, $fra)
                    : sprintf('%s ci sono %d saldi intestati a nomi che non sono %s: %s.', $dove, count($righe), $fra,
                        implode(', ', array_map(fn (CanonicalSaldo $s) => '«'.$s->soggettoNome.'»'.$this->laRiga($s), $righe))),
                ($delModello
                    ? 'Il nome va scritto come nel foglio «2 persone» (maiuscole e spazi non contano). Se quella posizione segue la casa e non '
                    .'la persona, lascia vuota la colonna «persona»: diventa un saldo solidale sull\'unità.'
                    : 'Il riparto e l\'Elenco unità devono venire dallo stesso condominio e dallo stesso momento: con una vendita in mezzo, il '
                    .'riparto nomina chi l\'Elenco unità non ha più.')
                .' Oppure, nella schermata di conferma, importa tutto il resto senza i saldi: li scriverai tu.',
                $uno ? $righe[0]->rigaSorgente : null,
            ),
        ];
    }

    /** @param  list<string>  $voci */
    private function elencoTraVirgolette(array $voci): string
    {
        $mostrate = array_map(fn (string $v) => '«'.$v.'»', array_slice($voci, 0, 5));

        return implode(', ', $mostrate).(count($voci) > 5 ? ' e altri '.(count($voci) - 5) : '');
    }

    private function laRiga(CanonicalSaldo $saldo): string
    {
        return $saldo->rigaSorgente === null ? '' : ' (riga '.$saldo->rigaSorgente.')';
    }

    private function allaRiga(CanonicalSaldo $saldo): string
    {
        return $saldo->rigaSorgente === null ? '' : ', riga '.$saldo->rigaSorgente;
    }

    /**
     * Che cosa non entra con «Importa senza i saldi»: l'elenco che la schermata di conferma mostra accanto al pulsante.
     *
     * @param  array<string, mixed>  $canonici
     * @return list<array{cosa: string, dettaglio: string}>
     */
    public function nonEntraSenzaISaldi(array $canonici): array
    {
        $saldi = $canonici[LivelloSaldi::CHIAVE] ?? null;

        if (! $saldi instanceof CanonicalSaldiApertura) {
            return [];
        }

        $righe = count($saldi->righe);
        $voci = [[
            'cosa' => 'Saldi di apertura',
            'dettaglio' => sprintf('%d %s dal %s', $righe, $righe === 1 ? 'posizione' : 'posizioni', $saldi->fonte ?? 'riparto consuntivo'),
        ]];

        // Le persone non entrano comunque, con o senza i saldi: si dice lo stesso, perché è la metà dell'importazione che manca.
        if (($canonici[LivelloSoggetti::CHIAVE] ?? []) === []) {
            $voci[] = [
                'cosa' => 'Persone e chi possiede cosa',
                'dettaglio' => $saldi->fonte === 'foglio dei saldi'
                    ? 'il foglio «2 persone» del modello è vuoto'
                    : 'non sono nei tuoi file: arrivano dall\'Elenco unità',
            ];
        }

        return $voci;
    }

    /**
     * Gli esercizi che resteranno aperti dopo la conferma, quando sono più d'uno (Coda 646, decisione 707 (4)).
     *
     * Succede importando l'esercizio nuovo in un condominio che ne ha ancora uno aperto: l'esercizio importato nasce aperto
     * (`LivelloEsercizi`), e le pagine non lasciano aprirne un secondo. La schermata di conferma lo dice prima: incassi, pagamenti e
     * saldi registrati dopo vanno nell'esercizio aperto più recente (`HasEsercizio::esercizioApertoPiuRecente()`, la stessa regola
     * e lo stesso ordine), le rate nell'esercizio del loro piano. `null` con un esercizio aperto solo.
     *
     * @param  array<string, mixed>  $canonici
     * @return array{nomi: list<string>, piu_recente: string, titolo: string, testo: string}|null
     */
    public function eserciziApertiDopo(array $canonici): ?array
    {
        $condominio = $canonici[LivelloCondominio::CHIAVE] ?? null;

        if (! $condominio instanceof CanonicalCondominio) {
            return null;
        }

        $ricerca = new RicercaEsistenti;
        $esistente = $ricerca->condominio($condominio);

        // Un condominio nuovo nasce con l'esercizio del file e basta.
        if ($esistente === null) {
            return null;
        }

        $nome = fn (?string $n) => $n !== null && trim($n) !== '' ? $n : 'senza nome';

        $aperti = Esercizio::query()
            ->where('condominio_id', $esistente->id)
            ->where('stato', 'aperto')
            ->get(['id', 'nome', 'data_inizio'])
            ->map(fn (Esercizio $e) => ['nome' => $nome($e->nome), 'dal' => $e->data_inizio?->toDateString() ?? '', 'id' => (int) $e->id])
            ->all();

        $importato = $canonici[LivelloEsercizi::CHIAVE] ?? null;

        // Lo straordinario entra in una gestione dell'esercizio che c'è, e un esercizio sullo stesso periodo si riusa: nessuno dei due
        // apre un esercizio in più. L'esercizio nuovo è scritto dopo gli altri, quindi a parità di data è il più recente.
        if ($importato instanceof CanonicalEsercizio && ! $importato->eStraordinario() && $ricerca->esercizio($importato, $esistente) === null) {
            $aperti[] = ['nome' => $nome($importato->etichetta), 'dal' => $importato->dataInizio->toDateString(), 'id' => PHP_INT_MAX];
        }

        if (count($aperti) < 2) {
            return null;
        }

        usort($aperti, fn (array $a, array $b) => [$a['dal'], $a['id']] <=> [$b['dal'], $b['id']]);

        $nomi = array_column($aperti, 'nome');
        $piuRecente = $nomi[array_key_last($nomi)];
        $altri = array_slice($nomi, 0, -1);
        $tra = fn (array $n) => count($n) === 1
            ? '«'.$n[0].'»'
            : implode(', ', array_map(fn ($x) => '«'.$x.'»', array_slice($n, 0, -1))).' e «'.$n[array_key_last($n)].'»';
        $quanti = [2 => 'due', 3 => 'tre', 4 => 'quattro'][count($nomi)] ?? (string) count($nomi);

        return [
            'nomi' => $nomi,
            'piu_recente' => $piuRecente,
            'titolo' => sprintf('Resteranno aperti %s esercizi: %s.', $quanti, $tra($nomi)),
            'testo' => sprintf(
                'Incassi, pagamenti e saldi che registri da qui in poi vanno nel «%s»; le rate di ogni piano vanno nell\'esercizio del suo '
                .'piano. Quando hai finito con %s, %s.',
                $piuRecente,
                count($altri) === 1 ? 'il «'.$altri[0].'»' : implode(', ', array_map(fn ($x) => 'il «'.$x.'»', array_slice($altri, 0, -1))).' e il «'.$altri[array_key_last($altri)].'»',
                count($altri) === 1 ? 'chiudilo' : 'chiudili',
            ),
        ];
    }

    /**
     * Registra un canonico dicendo **chi l'ha fornito**, e non lascia che il secondo cancelli il primo.
     *
     * Il primo file che porta un livello se lo tiene. Il secondo non sovrascrive: apre un errore
     * che nomina entrambi i file e il dato conteso, così l'amministratore toglie quello di troppo
     * invece di scoprire fra un mese quale dei due era finito in archivio.
     *
     * @param  array<string, mixed>  $canonici
     * @param  array<string, string>  $fonti
     * @param  array<string, EsitoVerifica>  $esiti
     */
    private function registraCanonico(
        array &$canonici,
        array &$fonti,
        array &$esiti,
        string $chiave,
        mixed $valore,
        ImportFile $file,
        string $etichetta,
    ): void {
        if ($valore === null || $valore === []) {
            return;
        }

        if (! isset($canonici[$chiave])) {
            $canonici[$chiave] = $valore;
            $fonti[$chiave] = $file->nome_originale;

            return;
        }

        if (($fonti[$chiave] ?? null) === $file->nome_originale) {
            return;
        }

        $esiti[$chiave] = ($esiti[$chiave] ?? new EsitoVerifica(0))
            ->con(Rilievo::errore(
                'import.due_file_stesso_dato',
                sprintf(
                    'Due file portano %s: «%s» e «%s».',
                    $etichetta,
                    $fonti[$chiave] ?? 'un altro file',
                    $file->nome_originale,
                ),
                'Uso il primo e ignoro il secondo, ma quale dei due sia quello giusto non lo posso '
                .'sapere io. Togli dal caricamento quello di troppo e riprova: i file caricati '
                .'possono completarsi a vicenda, non contraddirsi.',
            ));
    }

    /**
     * Somma un esito a quello che c'è già, invece di sostituirlo.
     *
     * ⚠️ Sostituire cancellava i rilievi del file letto prima — **compresi quelli bloccanti** — e
     * il pulsante di conferma si sbloccava da solo. Vale in tutte le direzioni: non era il modello
     * a essere fragile, era la regola a essere scritta in un posto solo.
     *
     * @param  array<string, EsitoVerifica>  $esiti
     */
    private function fondiEsito(array &$esiti, string $chiave, EsitoVerifica $esito): void
    {
        $esiti[$chiave] = isset($esiti[$chiave])
            ? new EsitoVerifica(
                $esiti[$chiave]->righeTotali + $esito->righeTotali,
                [...$esiti[$chiave]->rilievi, ...$esito->rilievi],
            )
            : $esito;
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
     *
     * Dalla 1.12.0-beta.14 (Coda 646, decisione 707 (4)) è la regola sola del prodotto, `HasEsercizio::esercizioApertoPiuRecente()`:
     * con due esercizi aperti, il più recente. Prima qui c'era già il più recente, e altrove il primo trovato.
     */
    public static function esercizioApertoDi(int $condominioId): ?Esercizio
    {
        return self::esercizioApertoPiuRecente($condominioId);
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
        // `CreateEsercizioRequest` e da `CondominioService::createEsercizioForCondominio()`; con due
        // aperti (l'importazione ne lascia due), il più recente (Coda 646, decisione 707 (4)).
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
                    // I dati della persona del modello Excel (Coda 769) restano al primo, come il codice fiscale e i recapiti: il numero del documento è unico a schema.
                    emailSecondaria: $i === 0 ? $originale->emailSecondaria : null,
                    pecComunicataIl: $i === 0 ? $originale->pecComunicataIl : null,
                    mezzoConvocazione: $i === 0 ? $originale->mezzoConvocazione : null,
                    cellulare: $i === 0 ? $originale->cellulare : null,
                    dataNascita: $i === 0 ? $originale->dataNascita : null,
                    luogoNascita: $i === 0 ? $originale->luogoNascita : null,
                    tipologiaDocumento: $i === 0 ? $originale->tipologiaDocumento : null,
                    numeroDocumento: $i === 0 ? $originale->numeroDocumento : null,
                    scadenzaDocumento: $i === 0 ? $originale->scadenzaDocumento : null,
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

                foreach ($nuove as $i => $ref) {
                    $aggiornate[] = new CanonicalTitolarita(
                        immobileRef: $t->immobileRef,
                        soggettoRef: $ref,
                        ruolo: $t->ruolo,
                        // Il saldo **non** si spezza: resta sul primo. Dividerlo a metà sarebbe
                        // inventare una ripartizione che il file non dichiara, su denaro altrui.
                        saldoPrecedenteCents: $ref === $nuove[0] ? $t->saldoPrecedenteCents : null,
                        quota: PartiUguali::di(count($nuove))[$i],
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
                        // Ogni campo non ripetuto in una ricostruzione torna al suo default, ed è
                        // il modo in cui `fonte` era già sparita una volta. Oggi la causale non si
                        // vede lo stesso su queste righe — il testo del nome diviso ha la
                        // precedenza — ma perderla qui significa perderla anche per la fusione
                        // solidale a valle, che la userebbe.
                        causale: $r->causale,
                        esercizioOrigine: $r->esercizioOrigine,
                    );
                }

                $canonici[LivelloSaldi::CHIAVE] = new CanonicalSaldiApertura(
                    righe: $righe,
                    totaleRiferimentoCents: $saldi->totaleRiferimentoCents,
                    arrotondamentiCents: $saldi->arrotondamentiCents,
                    // Si ricostruisce l'oggetto, quindi ogni campo non ripetuto qui torna al suo
                    // default: la fonte sparirebbe e l'anteprima ricomincerebbe a dire «riparto».
                    fonte: $saldi->fonte,
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
    private function leggiRiparto(Foglio $foglio, int $riga, array &$canonici, array &$fonti, array &$esiti, array &$letture, ImportFile $file): void
    {
        $banner = (new BannerParser)->estrai($foglio);
        $this->registraBanner($banner, $canonici, $esiti);

        $saldi = (new RipartoConsuntivoParser)->estrai($foglio, $riga);

        $this->registraCanonico($canonici, $fonti, $esiti, LivelloSaldi::CHIAVE, new CanonicalSaldiApertura(
            righe: $saldi['saldi'],
            totaleRiferimentoCents: $saldi['totale_riferimento_cents'],
            arrotondamentiCents: $saldi['arrotondamenti_cents'],
            fonte: 'riparto consuntivo',
        ), $file, 'i saldi di apertura');

        $this->fondiEsito($esiti, LivelloSaldi::CHIAVE, $saldi['esito']);
        $letture[] = ['file' => $file->nome_originale, 'tipo' => 'Saldi di apertura', 'righe' => $saldi['esito']->righeTotali];
    }

    /**
     * @param  array<string, mixed>  $canonici
     * @param  array<string, EsitoVerifica>  $esiti
     * @param  list<array{file: string, tipo: string, righe: int}>  $letture
     * @param  array<string, mixed>  $immobili
     */
    private function leggiElencoUnita(Foglio $foglio, int $riga, array &$canonici, array &$fonti, array &$esiti, array &$letture, ImportFile $file, array &$immobili): void
    {
        $letto = (new ElencoUnitaParser)->estrai($foglio, $riga);

        $this->registraCanonico($canonici, $fonti, $esiti, LivelloSoggetti::CHIAVE, $letto['soggetti'], $file, 'le persone');
        $this->registraCanonico($canonici, $fonti, $esiti, LivelloTitolarita::CHIAVE, $letto['titolarita'], $file, 'chi possiede cosa');
        $immobili = [...$immobili, ...$letto['immobili']];

        $this->fondiEsito($esiti, LivelloSoggetti::CHIAVE, $letto['esito']);
        $letture[] = ['file' => $file->nome_originale, 'tipo' => 'Persone, unità e titolarità', 'righe' => $letto['esito']->righeTotali];
    }

    /**
     * @param  array<string, mixed>  $canonici
     * @param  array<string, EsitoVerifica>  $esiti
     * @param  list<array{file: string, tipo: string, righe: int}>  $letture
     * @param  array<string, mixed>  $immobili
     */
    private function leggiMillesimi(Foglio $foglio, int $riga, array &$canonici, array &$fonti, array &$esiti, array &$letture, ImportFile $file, array &$immobili): void
    {
        $letto = (new AnagraficaMillesimiParser)->estrai($foglio, $riga);

        $this->registraCanonico($canonici, $fonti, $esiti, LivelloTabelle::CHIAVE, $letto['tabelle'], $file, 'le tabelle millesimali');
        $immobili = [...$immobili, ...$letto['immobili']];

        $this->fondiEsito($esiti, LivelloTabelle::CHIAVE, $letto['esito']);
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
        array &$fonti,
        array &$esiti,
        array &$letture,
        ImportFile $file,
    ): void {
        $this->leggiSoloBanner($foglio, $canonici, $esiti);

        $letto = (new BilancioConsuntivoParser)->estrai($foglio, $rigaIntestazione);

        $this->registraCanonico($canonici, $fonti, $esiti, LivelloCapitoli::CHIAVE, $letto['struttura'], $file, 'i capitoli di spesa');
        $this->fondiEsito($esiti, LivelloCapitoli::CHIAVE, $letto['esito']);

        $letture[] = [
            'file' => $file->nome_originale,
            'tipo' => 'Capitoli di spesa',
            'righe' => $letto['esito']->righeTotali,
        ];
    }

    /**
     * Il modello compilato a mano: **un file, cinque fogli, tutti i canonici in un colpo solo**.
     *
     * ## Perché i suoi esiti si fondono invece di sovrascrivere
     *
     * Un lotto può contenere il modello **e** una stampa di Danea: capita a chi ha esportato
     * quello che poteva e ha scritto a mano il resto, che è esattamente il caso per cui il
     * modello esiste. Assegnare `$esiti[...] = ` cancellerebbe in silenzio i rilievi già letti
     * dall'altro file, e con essi le decisioni ancora da prendere — cioè il pulsante di conferma
     * si sbloccherebbe da solo. Si usa `con()`, che li accumula.
     *
     * I **canonici** invece non si fondono: il primo che arriva vince, come per il condominio
     * nella testata. Fondere due elenchi di persone provenienti da due file scritti in momenti
     * diversi è una cosa che nessuno può fare al posto dell'amministratore.
     *
     * @param  array<string, mixed>  $canonici
     * @param  array<string, EsitoVerifica>  $esiti
     * @param  list<array{file: string, tipo: string, righe: int}>  $letture
     * @param  array<string, mixed>  $immobili
     */
    private function leggiModello(ImportFile $file, array &$canonici, array &$fonti, array &$esiti, array &$letture, array &$immobili): void
    {
        $percorso = Storage::disk(ImportUploadService::DISCO)->path($file->percorso);

        try {
            $fogli = $this->reader->leggi($percorso);
        } catch (RuntimeException) {
            $esiti['file:'.$file->id] = new EsitoVerifica(0, [Rilievo::errore(
                'file.illeggibile',
                sprintf('Non riesco a rileggere «%s».', $file->nome_originale),
                'Ricaricalo: potrebbe essere stato spostato o danneggiato dopo il caricamento.',
            )]);

            return;
        }

        $letto = (new ModelloManualeParser)->estrai($fogli);

        // Il condominio passa dalla stessa porta della testata delle stampe: è là che vive il
        // controllo «due file di due condomìni diversi», e il modello non ne è esente.
        $this->registraBanner([
            'condominio' => $letto['condominio'],
            'esercizio' => $letto['esercizio'],
            'esito' => new EsitoVerifica(0),
        ], $canonici, $esiti);

        foreach ($letto['esiti'] as $chiave => $esito) {
            $this->fondiEsito($esiti, $chiave, $esito);
        }

        $immobili = [...$immobili, ...$letto['immobili']];

        foreach ([
            LivelloSoggetti::CHIAVE => [$letto['soggetti'], 'le persone'],
            LivelloTitolarita::CHIAVE => [$letto['titolarita'], 'chi possiede cosa'],
            LivelloTabelle::CHIAVE => [$letto['tabelle'], 'le tabelle millesimali'],
            LivelloSaldi::CHIAVE => [$letto['saldi'], 'i saldi di apertura'],
        ] as $chiave => [$valore, $etichetta]) {
            $this->registraCanonico($canonici, $fonti, $esiti, $chiave, $valore, $file, $etichetta);
        }

        foreach ($letto['letture'] as $lettura) {
            $letture[] = ['file' => $file->nome_originale, ...$lettura];
        }
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
