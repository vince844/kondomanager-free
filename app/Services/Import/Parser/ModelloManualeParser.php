<?php

namespace App\Services\Import\Parser;

use App\Enums\RuoloAnagraficaImmobile;
use App\Helpers\MoneyHelper;
use App\Services\Import\Canonical\CanonicalCondominio;
use App\Services\Import\Canonical\CanonicalEsercizio;
use App\Services\Import\Canonical\CanonicalImmobile;
use App\Services\Import\Canonical\CanonicalSaldiApertura;
use App\Services\Import\Canonical\CanonicalSaldo;
use App\Services\Import\Canonical\CanonicalSoggetto;
use App\Services\Import\Canonical\CanonicalTabella;
use App\Services\Import\Canonical\CanonicalTitolarita;
use App\Services\Import\EsitoVerifica;
use App\Services\Import\Foglio;
use App\Services\Import\HeaderDetector;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloSaldi;
use App\Services\Import\Livelli\LivelloSoggetti;
use App\Services\Import\Livelli\LivelloTabelle;
use App\Services\Import\Livelli\LivelloUnita;
use App\Services\Import\Rilievo;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as DataExcel;
use Throwable;

/**
 * Legge il modello compilato a mano — **un file, cinque fogli** — e ne ricava tutti i canonici.
 *
 * È l'unico parser che riceve `list<Foglio>` invece di un foglio solo, e non per comodità: un
 * file ha **un** `report_type` (`import_files.report_type` è una colonna sola) e
 * `ImportUploadService::riconosci()` tiene il foglio con il riconoscimento migliore, scartando
 * gli altri. Cinque fogli riconosciuti come cinque tipi non è una strada che questo schema
 * permette; un tipo solo, letto da un parser che li apre tutti, sì.
 *
 * ## Cosa cambia rispetto ai file di Danea
 *
 * Là i file sono **stampe di una macchina**: le colonne sono sempre quelle, i codici sempre
 * quelli (`Pr`, `Co`, `Us`), e un valore fuori posto è quasi sempre un difetto di esportazione.
 * Qui invece il file lo compila una persona, di notte, copiando da un gestionale che sta
 * chiudendo. Tre conseguenze, tutte scritte nel codice qui sotto:
 *
 * 1. **La chiave dell'unità la inventa chi compila.** Non esiste la terna
 *    `palazzina-gruppo-progressivo` di Danea: c'è una colonna `unita` con dentro «B1/1», «int. 3»,
 *    «016». Il foglio delle unità costruisce l'indice, e gli altri tre lo consultano. Una chiave
 *    che non combacia è l'errore più probabile di tutti, e infatti è quello per cui il modello
 *    ripete la stessa avvertenza su tre fogli.
 * 2. **Si tollera dicendo.** Uno spazio di troppo — «B1/ 1» invece di «B1/1» — non fa perdere la
 *    riga: si risolve sulla forma compatta e lo si scrive in un avviso. Rifiutare sarebbe
 *    difendibile ma costoso, e la rete di sicurezza c'è comunque: le somme di controllo delle
 *    tabelle e la quadratura dei saldi vedono le righe perse.
 * 3. **I ruoli si scrivono a parole.** «proprietario», non `Pr`; e si accettano i sinonimi che un
 *    amministratore usa davvero (`conduttore`, `affittuario`, `nudo proprietario`).
 *
 * ## Il segno dei saldi, che qui **non** si inverte
 *
 * ⚠️ `RipartoConsuntivoParser` inverte il segno, perché Danea stampa la posizione dal punto di
 * vista del condòmino. Il modello no: la colonna dice a chiare lettere «POSITIVO = deve al
 * condominio», che è già la convenzione di Kondomanager. Invertire «per simmetria» con l'altro
 * parser trasformerebbe ogni debito in credito senza che niente lo segnali — la quadratura non
 * se ne accorgerebbe, perché il totale di controllo su quel foglio non c'è.
 */
final class ModelloManualeParser
{
    /**
     * I cinque fogli, nell'ordine in cui si compilano.
     *
     * L'ordine conta per una cosa sola, ma decisiva: il foglio delle unità va letto **per primo**
     * perché costruisce l'indice delle chiavi che gli altri tre consultano.
     */
    private const RUOLI = ['copertina', 'unita', 'persone', 'tabelle', 'saldi'];

    /**
     * La firma con cui si riconosce un foglio **rinominato**: etichette e quorum.
     *
     * Il nome del foglio è il segnale primario — è quello che il modello scrive e che nessuno ha
     * motivo di cambiare — ma chi compila a mano rinomina, sposta e a volte ricrea il file da
     * capo copiando le colonne. Le tabelle non compaiono qui: le loro colonne le inventa
     * l'amministratore, quindi non hanno una firma. Si riconoscono dalla riga `# tabella`.
     */
    private const FIRME = [
        'copertina' => [['campo', 'valore'], 2],
        'unita' => [['unita', 'palazzina', 'interno', 'piano', 'tipo'], 3],
        'persone' => [['unita', 'nome', 'ruolo', 'indirizzo'], 3],
        'saldi' => [['unita', 'persona', 'importo', 'causale'], 3],
    ];

    /** Come si scrivono i ruoli, con i sinonimi che un amministratore usa davvero. */
    private const RUOLI_AMMESSI = [
        'proprietario' => RuoloAnagraficaImmobile::PROPRIETARIO,
        'propietario' => RuoloAnagraficaImmobile::PROPRIETARIO,
        'inquilino' => RuoloAnagraficaImmobile::INQUILINO,
        'conduttore' => RuoloAnagraficaImmobile::INQUILINO,
        'affittuario' => RuoloAnagraficaImmobile::INQUILINO,
        'usufruttuario' => RuoloAnagraficaImmobile::USUFRUTTUARIO,
        'usufruttuaria' => RuoloAnagraficaImmobile::USUFRUTTUARIO,
        'nuda proprietario' => RuoloAnagraficaImmobile::NUDA_PROPRIETA,
        'nudo proprietario' => RuoloAnagraficaImmobile::NUDA_PROPRIETA,
        'nuda proprieta' => RuoloAnagraficaImmobile::NUDA_PROPRIETA,
        'nudo proprietaria' => RuoloAnagraficaImmobile::NUDA_PROPRIETA,
    ];

    /** Le righe di servizio del foglio tabelle: cominciano con `#` e non sono dati. */
    private const PREFISSO_META = '#';

    /**
     * @param  list<Foglio>  $fogli
     * @return array{
     *     condominio: CanonicalCondominio|null,
     *     esercizio: CanonicalEsercizio|null,
     *     immobili: array<string, CanonicalImmobile>,
     *     soggetti: array<string, CanonicalSoggetto>,
     *     titolarita: list<CanonicalTitolarita>,
     *     tabelle: array<string, CanonicalTabella>,
     *     saldi: CanonicalSaldiApertura|null,
     *     esiti: array<string, EsitoVerifica>,
     *     letture: list<array{tipo: string, righe: int}>
     * }
     */
    public function estrai(array $fogli): array
    {
        [$perRuolo, $ignorati] = $this->smista($fogli);

        [$condominio, $esercizio, $esitoCopertina] = $this->copertina($perRuolo['copertina'] ?? null);

        // ⚠️ Le unità per prime, sempre: costruiscono l'indice `chiave scritta a mano → chiave
        // canonica` che persone, tabelle e saldi consultano. Invertire l'ordine farebbe fallire
        // ogni riga degli altri tre fogli senza che il codice sembri sbagliato.
        [$immobili, $indice, $esitoUnita] = $this->unita($perRuolo['unita'] ?? null);

        [$soggetti, $titolarita, $esitoPersone] = $this->persone($perRuolo['persone'] ?? null, $indice);
        [$tabelle, $esitoTabelle] = $this->tabelle($perRuolo['tabelle'] ?? null, $indice);
        [$saldi, $esitoSaldi] = $this->saldi($perRuolo['saldi'] ?? null, $indice);

        $esiti = [];
        $letture = [];

        // Un foglio lasciato in bianco **non** produce un esito vuoto: produce nessun esito, e i
        // livelli lo trattano come «quel dato non c'è», che dalla beta.5 è un salto e non un
        // muro. Un esito con zero righe direbbe invece «l'ho letto e non conteneva niente», che
        // è una frase diversa e porterebbe a un contatore «0 righe valide» in schermata.
        foreach ([
            [LivelloCondominio::CHIAVE, $esitoCopertina, 'Condominio ed esercizio'],
            [LivelloUnita::CHIAVE, $esitoUnita, 'Unità immobiliari'],
            [LivelloSoggetti::CHIAVE, $esitoPersone, 'Persone e titolarità'],
            [LivelloTabelle::CHIAVE, $esitoTabelle, 'Tabelle millesimali'],
            [LivelloSaldi::CHIAVE, $esitoSaldi, 'Saldi di apertura'],
        ] as [$chiave, $esito, $etichetta]) {
            if ($esito === null) {
                continue;
            }

            $esiti[$chiave] = $esito;
            $letture[] = ['tipo' => $etichetta, 'righe' => $esito->righeTotali];
        }

        // ⚠️ **Il condominio c'è ma l'esercizio no.** Va detto qui e non lasciato ai livelli: chi
        // dichiara il condominio nella copertina non passa mai dalla scelta della destinazione,
        // quindi nessuno risolverebbe l'esercizio al posto suo — e senza esercizio restano fuori
        // titolarità e saldi, cioè chi possiede cosa e con quale posizione aperta. Il messaggio
        // del livello («nessuno dei file dichiara il periodo») manderebbe a cercare una stampa di
        // Danea che qui non c'entra niente.
        // ⚠️ **Un foglio compilato che nessuno legge deve dirlo.** Il difetto che questa riga
        // chiude non era la perdita in sé — un foglio di appunti si ignora giustamente — ma il
        // silenzio: chi rinominava un foglio in un modo che non sapevamo ricondurre vedeva
        // l'importazione riuscire, con dentro un pezzo in meno e nessuna riga che lo dicesse.
        if ($ignorati !== []) {
            $esiti[LivelloCondominio::CHIAVE] = ($esiti[LivelloCondominio::CHIAVE] ?? new EsitoVerifica(0))
                ->con(Rilievo::avviso(
                    'modello.foglio_non_riconosciuto',
                    sprintf(
                        count($ignorati) === 1
                            ? 'Il foglio «%s» non l\'ho letto: non capisco a cosa corrisponde.'
                            : 'Questi fogli non li ho letti, perché non capisco a cosa corrispondono: «%s».',
                        implode('», «', $ignorati),
                    ),
                    'Se è un tuo foglio di appunti va benissimo così. Se invece contiene dati da '
                    .'importare, rinominalo come nel modello vuoto — «1 unita», «2 persone», '
                    .'«3 tabelle», «4 saldi» — e ricarica il file.',
                ));
        }

        if ($condominio !== null && $esercizio === null) {
            $esiti[LivelloCondominio::CHIAVE] = ($esiti[LivelloCondominio::CHIAVE] ?? new EsitoVerifica(0))
                ->con(Rilievo::errore(
                    'modello.esercizio_senza_date',
                    'Nella copertina mancano le date dell\'esercizio, oppure non sono leggibili.',
                    'Compila «data_inizio» e «data_fine» in formato gg/mm/aaaa — per esempio '
                    .'01/01/2026 e 31/12/2026. Senza, chi possiede cosa e i saldi di apertura '
                    .'restano fuori: sono le due cose che vivono dentro un esercizio.',
                    foglio: $perRuolo['copertina']?->nome,
                ));
        }

        return compact('condominio', 'esercizio', 'immobili', 'soggetti', 'titolarita', 'tabelle', 'saldi', 'esiti', 'letture');
    }

    /**
     * Le colonne che abbiamo capito, prese da **tutti e cinque** i fogli.
     *
     * ⚠️ Serve al pannello «colonne riconosciute» della schermata di riconoscimento, e senza di
     * essa quel pannello mente: `DetectionResult` descrive **un foglio solo** — la copertina — e
     * su un modello con cinque fogli compilati annunciava «3 colonne riconosciute». A chi ha
     * appena scritto a mano l'anagrafe del suo condominio, quella frase dice che stiamo leggendo
     * un ventesimo di quello che ha compilato. È lo stesso difetto già corretto una volta per
     * l'elenco unità, e la ragione per cui `colonneLette()` esiste separato da `etichette()`.
     *
     * I nomi delle tabelle millesimali ci sono dentro: sono le colonne che l'amministratore ha
     * inventato lui, quindi le prime che cercherà in quell'elenco.
     *
     * @param  list<Foglio>  $fogli
     * @return list<string>
     */
    public function colonne(array $fogli): array
    {
        $viste = [];

        foreach ($this->smista($fogli)[0] as $ruolo => $foglio) {
            $riga = $this->rigaIntestazione($ruolo, $foglio);

            if ($riga === null) {
                continue;
            }

            foreach ($foglio->riga($riga) as $cella) {
                $nome = trim((string) ($cella ?? ''));

                if ($nome !== '' && ! str_starts_with($nome, self::PREFISSO_META)) {
                    // Per chiave, non per valore: «unita» compare su quattro fogli ed è la
                    // stessa colonna: elencarla quattro volte farebbe sembrare l'elenco gonfiato.
                    $viste[HeaderDetector::normalizza($nome)] = $nome;
                }
            }
        }

        return array_values($viste);
    }

    /**
     * La riga di intestazione di un foglio, cercata con le etichette del suo ruolo.
     *
     * ⚠️ Il quorum è **2**, più basso del 3 con cui `smista()` riconosce un foglio rinominato, e
     * la differenza è voluta: là si sta decidendo *quale* foglio è, e sbagliare significa leggere
     * le persone come se fossero unità; qui il ruolo è già stabilito e si cerca solo dove
     * comincia la tabella. Pretendere lo stesso rigore farebbe perdere un foglio a cui
     * l'amministratore ha rinominato due colonne.
     */
    private function rigaIntestazione(string $ruolo, Foglio $foglio): ?int
    {
        // Le tabelle non hanno una firma — le colonne le inventa chi compila — quindi si parte
        // dalla riga grigia `# tabella`, che sta esattamente sopra l'intestazione.
        if ($ruolo === 'tabelle') {
            $meta = $this->rigaMeta($foglio, 'tabella');

            if ($meta !== null) {
                return $meta + 1;
            }

            return (new HeaderDetector)->trova($foglio, ['unita'], 1)['riga'] ?? null;
        }

        [$etichette, ] = self::FIRME[$ruolo];

        return (new HeaderDetector)->trova($foglio, $etichette, 2)['riga'] ?? null;
    }

    /**
     * Assegna a ogni foglio il suo ruolo: prima per nome, poi per firma delle colonne.
     *
     * Il primo che rivendica un ruolo se lo tiene. Un secondo foglio con lo stesso ruolo viene
     * ignorato, ed è il comportamento giusto: il caso reale è la copia di lavoro lasciata nel
     * file — «1 unita» e «1 unita (2)» — dove la seconda è quasi sempre la vecchia.
     *
     * ⚠️ **Un foglio che il nome non colloca deve comunque passare dalle firme.** Fino alla
     * revisione avversariale della beta.5 questo metodo accodava ai «rimasti» solo i fogli il cui
     * nome non conteneva **nessuna** parola di ruolo. Bastava quindi rinominare «4 saldi» in
     * «saldi per unita» — un nome più esplicito, non uno sbagliato — perché il ciclo trovasse
     * `unita` (che viene prima nell'ordine), lo trovasse già occupato dal foglio 1, e lasciasse
     * cadere il foglio **senza passarlo al riconoscimento per firma e senza un solo rilievo**.
     * Misurato: i saldi sparivano tutti, e la schermata di verifica non elencava nemmeno il
     * livello mancante. Ora chi non ottiene il ruolo al primo colpo finisce comunque fra i
     * rimasti, e chi non lo ottiene nemmeno lì viene **detto**.
     *
     * @param  list<Foglio>  $fogli
     * @return array{0: array<string, Foglio>, 1: list<string>}  i fogli per ruolo, e i nomi di
     *                                                           quelli che nessun ruolo ha preso
     */
    private function smista(array $fogli): array
    {
        $perRuolo = [];
        $rimasti = [];

        foreach ($fogli as $foglio) {
            $nome = HeaderDetector::normalizza($foglio->nome);
            $trovato = null;

            foreach (self::RUOLI as $ruolo) {
                if (str_contains($nome, $ruolo)) {
                    $trovato = $ruolo;

                    break;
                }
            }

            if ($trovato !== null && ! isset($perRuolo[$trovato])) {
                $perRuolo[$trovato] = $foglio;

                continue;
            }

            $rimasti[] = $foglio;
        }

        // I fogli rinominati: si riconoscono dalle colonne. Le tabelle per ultime, perché la loro
        // firma è la più debole — la riga `# tabella` — e non deve rubare un foglio che una firma
        // vera avrebbe preso.
        $ignorati = [];

        foreach ($rimasti as $foglio) {
            foreach (self::FIRME as $ruolo => [$etichette, $quorum]) {
                if (isset($perRuolo[$ruolo])) {
                    continue;
                }

                if ((new HeaderDetector)->trova($foglio, $etichette, $quorum) !== null) {
                    $perRuolo[$ruolo] = $foglio;

                    continue 2;
                }
            }

            if (! isset($perRuolo['tabelle']) && $this->rigaMeta($foglio, 'tabella') !== null) {
                $perRuolo['tabelle'] = $foglio;

                continue;
            }

            // Un foglio che non è vuoto e che nessun ruolo ha preso: può essere il foglio di
            // appunti dell'amministratore — legittimo — oppure un elenco compilato che non
            // sappiamo leggere. La differenza non la sappiamo fare noi, ma tacere sarebbe
            // scegliere la prima ipotesi al posto suo.
            $ignorati[] = $foglio->nome;
        }

        return [$perRuolo, $ignorati];
    }

    /**
     * La copertina: condominio ed esercizio, letti da un elenco `campo | valore`.
     *
     * @return array{0: CanonicalCondominio|null, 1: CanonicalEsercizio|null, 2: EsitoVerifica|null}
     */
    private function copertina(?Foglio $foglio): array
    {
        if ($foglio === null) {
            return [null, null, null];
        }

        if ($this->svuotato($foglio)) {
            return [null, null, null];
        }

        $riga = $this->rigaIntestazione('copertina', $foglio);

        if ($riga === null) {
            return [null, null, new EsitoVerifica(0, [Rilievo::errore(
                'modello.copertina_illeggibile',
                'Nel foglio della copertina non trovo le colonne «campo» e «valore».',
                'Riscarica il modello vuoto e ricopiaci i tuoi dati: la copertina è il foglio da '
                .'cui si capisce di quale condominio e di quale anno si tratta.',
                foglio: $foglio->nome,
            )])];
        }

        $valori = [];

        for ($i = $riga + 1; $i < $foglio->numeroRighe(); $i++) {
            $celle = $foglio->riga($i);
            $campo = HeaderDetector::normalizza((string) ($celle[0] ?? ''));

            if ($campo !== '') {
                $valori[$campo] = $celle[1] ?? null;
            }
        }

        $nome = trim((string) ($valori['condominio'] ?? ''));

        $condominio = $nome === '' ? null : new CanonicalCondominio(
            nome: $nome,
            codiceFiscale: strtoupper(trim((string) ($valori['codice fiscale'] ?? ''))) ?: null,
            indirizzo: trim((string) ($valori['indirizzo'] ?? '')) ?: null,
        );

        $inizio = $this->data($valori['data inizio'] ?? null);
        $fine = $this->data($valori['data fine'] ?? null);

        $rilievi = [];
        $esercizio = null;

        if ($inizio !== null && $fine !== null && $fine->lessThanOrEqualTo($inizio)) {
            $rilievi[] = Rilievo::errore(
                'modello.periodo_rovesciato',
                sprintf(
                    'Le date dell\'esercizio sono in ordine inverso: comincia il %s e finisce il %s.',
                    $inizio->format('d/m/Y'),
                    $fine->format('d/m/Y'),
                ),
                'Controlla «data_inizio» e «data_fine» nella copertina. Un esercizio a cavallo '
                .'d\'anno si scrive per esempio 01/11/2025 → 31/10/2026.',
                foglio: $foglio->nome,
            );
        } elseif ($inizio !== null && $fine !== null) {
            $esercizio = new CanonicalEsercizio(
                // Senza etichetta si usano gli anni, che è come la chiamerebbe chi compila:
                // «2026» se sta dentro l'anno solare, «2025/2026» se lo scavalca.
                etichetta: trim((string) ($valori['esercizio'] ?? '')) ?: (
                    $inizio->year === $fine->year
                        ? (string) $inizio->year
                        : $inizio->year.'/'.$fine->year
                ),
                dataInizio: $inizio,
                dataFine: $fine,
            );
        }

        return [$condominio, $esercizio, new EsitoVerifica(count($valori), $rilievi)];
    }

    /**
     * Le unità, e l'indice delle chiavi che tiene insieme tutto il resto.
     *
     * ## La chiave, che è la cosa fragile di questo formato
     *
     * Danea identifica un'unità con una terna di numeri; qui c'è una parola che sceglie chi
     * compila. La terna canonica si costruisce comunque, perché è ciò che il resto del prodotto
     * si aspetta — `CanonicalImmobile::chiave()` e `LivelloSaldi` ragionano su
     * `palazzina-gruppo-progressivo` — e la si costruisce così: la palazzina se c'è (altrimenti
     * `1`), la scala come gruppo (altrimenti `0`), e **la chiave scritta a mano come
     * progressivo**. Restare fedeli alla scritta dell'amministratore è ciò che permette ai
     * messaggi d'errore di nominare l'unità come la nomina lui.
     *
     * @return array{0: array<string, CanonicalImmobile>, 1: array<string, array{chiave: string, etichetta: string}>, 2: EsitoVerifica|null}
     */
    private function unita(?Foglio $foglio): array
    {
        if ($foglio === null) {
            return [[], [], null];
        }

        if ($this->svuotato($foglio)) {
            return [[], [], null];
        }

        $intestazione = $this->rigaIntestazione('unita', $foglio);

        if ($intestazione === null) {
            return [[], [], new EsitoVerifica(0, [$this->intestazioneIlleggibile($foglio, 'unita')])];
        }

        $mappa = (new HeaderDetector)->mappaColonne($foglio, $intestazione);

        $immobili = [];
        $indice = [];
        $rilievi = [];
        $righe = 0;

        for ($i = $intestazione + 1; $i < $foglio->numeroRighe(); $i++) {
            $riga = $foglio->riga($i);
            $rigaUtente = Foglio::rigaUtente($i);

            if ($this->daSaltare($riga)) {
                continue;
            }

            $etichetta = trim((string) $this->cella($riga, $mappa, 'unita'));

            if ($etichetta === '') {
                $rilievi[] = Rilievo::errore(
                    'modello.unita_senza_chiave',
                    'Questa riga non ha la sigla dell\'unità, quindi non so a cosa si riferisce.',
                    'La prima colonna è la chiave: senza, la riga non può essere collegata né alle '
                    .'persone né alle tabelle. Completala oppure togli la riga.',
                    $rigaUtente,
                    'unita',
                    $foglio->nome,
                );

                continue;
            }

            $righe++;

            $palazzina = trim((string) $this->cella($riga, $mappa, 'palazzina')) ?: '1';
            $gruppo = trim((string) $this->cella($riga, $mappa, 'scala')) ?: '0';
            $interno = trim((string) $this->cella($riga, $mappa, 'interno'));

            $immobile = new CanonicalImmobile(
                palazzina: $palazzina,
                gruppo: $gruppo,
                progressivo: $etichetta,
                piano: trim((string) $this->cella($riga, $mappa, 'piano')) ?: null,
                // `immobili.interno` è NOT NULL. Quando manca si usa la chiave, che è
                // l'identificativo che l'amministratore ha scelto per quell'unità.
                interno: $interno !== '' ? $interno : $etichetta,
                tipo: trim((string) $this->cella($riga, $mappa, 'tipo')) ?: null,
            );

            $chiaveCompatta = $this->compatta($etichetta);

            if (isset($indice[$chiaveCompatta])) {
                $rilievi[] = Rilievo::avviso(
                    'modello.unita_ripetuta',
                    sprintf('L\'unità «%s» compare più di una volta nel foglio delle unità.', $etichetta),
                    'Tengo la prima e ignoro le successive. Se sono due unità diverse, dai a '
                    .'ciascuna una sigla diversa: è quella che le distingue in tutti gli altri fogli.',
                    $rigaUtente,
                    'unita',
                    $foglio->nome,
                );

                continue;
            }

            $immobili[$immobile->chiave()] = $immobile;
            // L'etichetta si conserva **com'è scritta qui**: è il metro su cui gli altri fogli
            // vengono confrontati, e senza non si potrebbe dire «hai scritto B1/ 1 dove qui c'è
            // B1/1» — si potrebbe solo accettare in silenzio o rifiutare.
            $indice[$chiaveCompatta] = ['chiave' => $immobile->chiave(), 'etichetta' => $etichetta];
        }

        return [$immobili, $indice, new EsitoVerifica($righe, $rilievi)];
    }

    /**
     * Le persone e chi possiede cosa: una riga per **titolare**, non per unità.
     *
     * @param  array<string, array{chiave: string, etichetta: string}>  $indice
     * @return array{0: array<string, CanonicalSoggetto>, 1: list<CanonicalTitolarita>, 2: EsitoVerifica|null}
     */
    private function persone(?Foglio $foglio, array $indice): array
    {
        if ($foglio === null) {
            return [[], [], null];
        }

        if ($this->svuotato($foglio)) {
            return [[], [], null];
        }

        $intestazione = $this->rigaIntestazione('persone', $foglio);

        if ($intestazione === null) {
            return [[], [], new EsitoVerifica(0, [$this->intestazioneIlleggibile($foglio, 'persone')])];
        }

        $mappa = (new HeaderDetector)->mappaColonne($foglio, $intestazione);

        $soggetti = [];
        $titolarita = [];
        $rilievi = [];
        $righe = 0;
        /** @var array<string, float> $quotePerUnita */
        $quotePerUnita = [];
        $senzaRuolo = 0;
        $diverse = [];

        for ($i = $intestazione + 1; $i < $foglio->numeroRighe(); $i++) {
            $riga = $foglio->riga($i);
            $rigaUtente = Foglio::rigaUtente($i);

            if ($this->daSaltare($riga)) {
                continue;
            }

            $righe++;

            $immobileRef = $this->risolvi($riga, $mappa, $indice, $foglio, $rigaUtente, $rilievi, $diverse);
            $nome = trim((string) $this->cella($riga, $mappa, 'nome'));

            if ($nome === '') {
                $rilievi[] = Rilievo::errore(
                    'modello.persona_senza_nome',
                    'Questa riga non ha un nome: non posso registrare una persona senza.',
                    'Completa la colonna «nome», oppure togli la riga se l\'unità non ha titolari '
                    .'da registrare.',
                    $rigaUtente,
                    'nome',
                    $foglio->nome,
                );

                continue;
            }

            if ($immobileRef === null) {
                continue;
            }

            $ruoloScritto = HeaderDetector::normalizza((string) $this->cella($riga, $mappa, 'ruolo'));

            if ($ruoloScritto === '') {
                // ⚠️ Il default è una **affermazione sul diritto di qualcuno**, non un dettaglio
                // di comodo: dire «proprietario» di un inquilino gli attribuisce le spese
                // straordinarie. Si assume perché è il caso di gran lunga più frequente, e si
                // dice — una volta per foglio, non una per riga, altrimenti l'avviso vero
                // sparisce fra cinquanta copie di se stesso.
                $senzaRuolo++;
                $ruolo = RuoloAnagraficaImmobile::PROPRIETARIO;
            } elseif (isset(self::RUOLI_AMMESSI[$ruoloScritto])) {
                $ruolo = self::RUOLI_AMMESSI[$ruoloScritto];
            } else {
                $rilievi[] = Rilievo::errore(
                    'modello.ruolo_sconosciuto',
                    sprintf('Il ruolo «%s» non è fra quelli che riconosco.', trim((string) $this->cella($riga, $mappa, 'ruolo'))),
                    'Scrivi uno di questi: proprietario, inquilino, usufruttuario, '
                    .'nuda_proprietario. Vanno bene anche «conduttore» e «nudo proprietario».',
                    $rigaUtente,
                    'ruolo',
                    $foglio->nome,
                );

                continue;
            }

            $soggetto = new CanonicalSoggetto(
                nome: $nome,
                codiceFiscale: strtoupper(trim((string) $this->cella($riga, $mappa, 'codice fiscale'))) ?: null,
                indirizzo: trim((string) $this->cella($riga, $mappa, 'indirizzo')) ?: null,
                email: trim((string) $this->cella($riga, $mappa, 'email')) ?: null,
                telefoni: array_values(array_filter([trim((string) $this->cella($riga, $mappa, 'telefono'))])),
            );

            // La stessa persona su tre unità si scrive tre volte, ed è una sola: la riconosciamo
            // dal codice fiscale, o dal nome quando non ce l'ha.
            $soggetti[$soggetto->chiave()] ??= $soggetto;

            $quota = $this->numero($this->cella($riga, $mappa, 'quota pct'));

            if ($quota !== null) {
                $quotePerUnita[$immobileRef] = ($quotePerUnita[$immobileRef] ?? 0) + $quota;
            }

            $titolarita[] = new CanonicalTitolarita(
                immobileRef: $immobileRef,
                soggettoRef: $soggetto->chiave(),
                ruolo: $ruolo,
                quota: $quota,
                rigaSorgente: $rigaUtente,
            );
        }

        $this->avvisaSigleDiverse($diverse, $foglio, $rilievi);

        if ($senzaRuolo > 0) {
            $rilievi[] = Rilievo::avviso(
                'modello.ruolo_assente',
                sprintf('%d righe non dicono il ruolo: le registro come «proprietario».', $senzaRuolo),
                'È il caso più frequente, ma non è indifferente: a un inquilino non spettano le '
                .'spese straordinarie. Controlla la colonna «ruolo» prima di confermare.',
                colonna: 'ruolo',
                foglio: $foglio->nome,
            );
        }

        foreach ($quotePerUnita as $ref => $somma) {
            // Le quote si controllano ma non si correggono: `LivelloTitolarita` scrive quello che
            // il file dice, e una somma diversa da 100 può essere legittima (una nuda proprietà
            // accanto a un usufrutto non fa 100 di proprietà piena).
            if (abs($somma - 100) > 0.01) {
                $rilievi[] = Rilievo::avviso(
                    'modello.quote_non_fanno_cento',
                    sprintf('Le quote dell\'unità «%s» sommano a %s invece che a 100.', $this->etichettaDi($ref), rtrim(rtrim(number_format($somma, 2, ',', '.'), '0'), ',')),
                    'Le importo come sono: può essere corretto — per esempio un usufrutto accanto '
                    .'a una nuda proprietà — ma se erano due comproprietari al 50% controlla la '
                    .'colonna «quota_pct».',
                    colonna: 'quota_pct',
                    foglio: $foglio->nome,
                );
            }
        }

        return [$soggetti, $titolarita, new EsitoVerifica($righe, $rilievi)];
    }

    /**
     * Le tabelle millesimali: una colonna per tabella, quante ne servono.
     *
     * ## La somma di controllo, e perché è un avviso e non un errore
     *
     * In fondo al foglio c'è una riga `# TOTALE DI CONTROLLO`: serve ad accorgersi di una riga
     * persa mentre si copiava, che è l'errore silenzioso per eccellenza di un foglio compilato a
     * mano. Non blocca però l'importazione, per due motivi indipendenti: i millesimi **non
     * devono** sommare a 1000 perché Kondomanager calcoli bene — è verificato più volte — e
     * quella riga la scrive la stessa persona che ha copiato le altre, quindi può sbagliarsi
     * anche lei.
     *
     * @param  array<string, array{chiave: string, etichetta: string}>  $indice
     * @return array{0: array<string, CanonicalTabella>, 1: EsitoVerifica|null}
     */
    private function tabelle(?Foglio $foglio, array $indice): array
    {
        if ($foglio === null) {
            return [[], null];
        }

        if ($this->svuotato($foglio)) {
            return [[], null];
        }

        $meta = $this->rigaMeta($foglio, 'tabella');
        $rigaIntestazione = $this->rigaIntestazione('tabelle', $foglio);

        if ($rigaIntestazione === null) {
            return [[], new EsitoVerifica(0, [$this->intestazioneIlleggibile($foglio, 'tabelle')])];
        }

        // I nomi stanno sulla riga nera; dove è vuota si ricade sulla riga grigia `# tabella`,
        // che è l'unico posto in cui possiamo scrivere metadati su colonne che non conosciamo.
        $intestazione = $foglio->riga($rigaIntestazione);
        $metaRiga = $meta !== null ? $foglio->riga($meta) : [];

        $nomi = [];

        foreach ($intestazione as $indiceColonna => $cella) {
            if ($indiceColonna === 0) {
                continue;
            }

            $nome = trim((string) ($cella ?? '')) ?: trim((string) ($metaRiga[$indiceColonna] ?? ''));

            if ($nome !== '' && ! str_starts_with($nome, self::PREFISSO_META)) {
                $nomi[$indiceColonna] = $nome;
            }
        }

        if ($nomi === []) {
            return [[], new EsitoVerifica(0, [Rilievo::avviso(
                'modello.tabelle_senza_nome',
                'Il foglio delle tabelle non ha nessuna colonna con un nome di tabella.',
                'Scrivi il nome di ogni tabella nella riga di intestazione — per esempio '
                .'«proprietà generale» — e sotto i millesimi di ciascuna unità.',
                foglio: $foglio->nome,
            )])];
        }

        /** @var array<int, array<string, float>> $quote */
        $quote = array_fill_keys(array_keys($nomi), []);
        $controllo = [];
        $rilievi = [];
        $righe = 0;
        $diverse = [];

        for ($i = $rigaIntestazione + 1; $i < $foglio->numeroRighe(); $i++) {
            $riga = $foglio->riga($i);

            if ($this->daSaltare($riga)) {
                continue;
            }

            $etichetta = trim((string) ($riga[0] ?? ''));

            // ⚠️ **Solo le nostre due righe di servizio, non «tutto ciò che comincia per #».**
            //
            // Il prefisso da solo faceva sparire una riga di dati vera: un'unità chiamata «#1» —
            // e c'è chi numera così — veniva presa per una riga di totale e i suoi millesimi
            // finivano nel confronto di controllo invece che nella tabella. La sigla la sceglie
            // l'amministratore, quindi il riconoscimento non può basarsi su un carattere che lui
            // può legittimamente usare: si guardano le due parole che scriviamo noi.
            if ($this->eRigaDiServizio($etichetta)) {
                foreach ($nomi as $colonna => $_) {
                    $valore = $this->numero($riga[$colonna] ?? null);

                    if ($valore !== null) {
                        $controllo[$colonna] = $valore;
                    }
                }

                continue;
            }

            $rigaUtente = Foglio::rigaUtente($i);
            $ref = $this->risolviEtichetta($etichetta, $indice, $foglio, $rigaUtente, $rilievi, $diverse);

            if ($ref === null) {
                continue;
            }

            $righe++;

            foreach ($nomi as $colonna => $nomeTabella) {
                $grezzo = $riga[$colonna] ?? null;
                $valore = $this->numero($grezzo);

                // ⚠️ Cella vuota ≠ zero, e la differenza non è formale: `null` significa «questa
                // unità non partecipa a questa tabella» — il negozio con ingresso indipendente
                // che non paga le scale — mentre `0` significa «partecipa con zero millesimi».
                // Scrivere zero al posto di niente mette il negozio fra i partecipanti.
                if ($valore !== null) {
                    $quote[$colonna][$ref] = $valore;

                    continue;
                }

                // ⚠️ **Una cella piena che non so leggere non è una cella vuota.** «45,5 mill.»,
                // «120 mq», un trattino: `numero()` torna `null` e l'unità spariva dalla tabella
                // esattamente come se non ci partecipasse — cioè un dato scritto veniva
                // interpretato come una scelta di non partecipare, in silenzio, su millesimi che
                // decidono quanto paga ciascuno.
                if (trim((string) ($grezzo ?? '')) !== '') {
                    $rilievi[] = Rilievo::errore(
                        'modello.millesimo_non_numerico',
                        sprintf(
                            'Il millesimo «%s» della tabella «%s» non lo so leggere come numero.',
                            trim((string) $grezzo),
                            $nomeTabella,
                        ),
                        'Scrivi solo il numero, con la virgola per i decimali: «45,5». Niente '
                        .'unità di misura. Lascia la cella **vuota** se quell\'unità non '
                        .'partecipa a questa tabella: vuoto e zero non sono la stessa cosa.',
                        $rigaUtente,
                        $nomeTabella,
                        $foglio->nome,
                    );
                }
            }
        }

        $this->avvisaSigleDiverse($diverse, $foglio, $rilievi);

        $tabelle = [];

        foreach ($nomi as $colonna => $nome) {
            if ($quote[$colonna] === []) {
                continue;
            }

            // ⚠️ **Due colonne con lo stesso nome: la seconda sovrascriveva la prima in silenzio.**
            //
            // `$quote` è indicizzato per colonna e `$tabelle` per **nome**: due colonne «SCALE» —
            // due scale chiamate uguale, o una colonna duplicata e rinominata solo nella riga
            // grigia — producevano un'unica tabella, quella di destra. Misurato: una «SCALE» con
            // due partecipanti a 500 spariva, sostituita da una con un partecipante a 1000, con
            // zero rilievi. E il totale di controllo non se ne accorgeva, perché veniva
            // confrontato con la superstite.
            //
            // Vince la prima, come in `smista()`: le colonne si leggono da sinistra, e la prima è
            // quella che l'amministratore ha scritto prima.
            if (isset($tabelle[$nome])) {
                $rilievi[] = Rilievo::avviso(
                    'modello.tabelle_omonime',
                    sprintf('Due colonne del foglio delle tabelle si chiamano «%s»: tengo la prima e ignoro la seconda.', $nome),
                    'Dai un nome diverso a ciascuna — per esempio «SCALE A» e «SCALE B» — e '
                    .'ricarica il file: sono due ripartizioni diverse e devono restare due.',
                    colonna: $nome,
                    foglio: $foglio->nome,
                );

                continue;
            }

            $tabella = new CanonicalTabella($nome, $quote[$colonna]);
            $tabelle[$nome] = $tabella;

            $atteso = $controllo[$colonna] ?? null;

            if ($atteso !== null && abs($atteso - $tabella->somma()) > 0.01) {
                $rilievi[] = Rilievo::avviso(
                    'modello.totale_di_controllo_diverso',
                    sprintf(
                        'La tabella «%s» somma %s, ma il totale di controllo in fondo al foglio dice %s.',
                        $nome,
                        $this->millesimi($tabella->somma()),
                        $this->millesimi($atteso),
                    ),
                    'Di solito vuol dire che una riga è andata persa mentre si copiava. La importo '
                    .'lo stesso — i millesimi non devono per forza fare 1000 — ma vale la pena '
                    .'ricontare le righe prima di confermare.',
                    colonna: $nome,
                    foglio: $foglio->nome,
                );
            }
        }

        return [$tabelle, new EsitoVerifica($righe, $rilievi)];
    }

    /**
     * I saldi di apertura: le posizioni con cui il condominio arriva in Kondomanager.
     *
     * @param  array<string, array{chiave: string, etichetta: string}>  $indice
     * @return array{0: CanonicalSaldiApertura|null, 1: EsitoVerifica|null}
     */
    private function saldi(?Foglio $foglio, array $indice): array
    {
        if ($foglio === null) {
            return [null, null];
        }

        if ($this->svuotato($foglio)) {
            return [null, null];
        }

        $intestazione = $this->rigaIntestazione('saldi', $foglio);

        if ($intestazione === null) {
            return [null, new EsitoVerifica(0, [$this->intestazioneIlleggibile($foglio, 'saldi')])];
        }

        $mappa = (new HeaderDetector)->mappaColonne($foglio, $intestazione);

        $saldi = [];
        $rilievi = [];
        $righe = 0;
        $diverse = [];

        for ($i = $intestazione + 1; $i < $foglio->numeroRighe(); $i++) {
            $riga = $foglio->riga($i);
            $rigaUtente = Foglio::rigaUtente($i);

            if ($this->daSaltare($riga)) {
                continue;
            }

            $righe++;

            $ref = $this->risolvi($riga, $mappa, $indice, $foglio, $rigaUtente, $rilievi, $diverse);

            if ($ref === null) {
                continue;
            }

            $grezzo = $this->cella($riga, $mappa, 'importo');

            if (trim((string) $grezzo) === '') {
                $rilievi[] = Rilievo::errore(
                    'modello.saldo_senza_importo',
                    'Questa riga di saldo non ha un importo.',
                    'Scrivi l\'importo con il segno: positivo se l\'unità deve al condominio, '
                    .'negativo se è in credito. Se non c\'è niente da riportare, togli la riga.',
                    $rigaUtente,
                    'importo',
                    $foglio->nome,
                );

                continue;
            }

            // ⚠️ **L'importo si valida prima di convertirlo, e questa riga chiude il difetto più
            // grave trovato dalla revisione avversariale della beta.5.**
            //
            // Prima la cella andava dritta a `MoneyHelper::toCents()`, che non solleva mai: su
            // qualunque cosa non capisca fa `(float) $stringa` e restituisce **0**. Misurato su
            // quattro scritture che una persona produce davvero — «€ 120,50», «(45,00)»,
            // «120,50-», «n.d.» — tre saldi su quattro entravano in archivio a zero e uno con il
            // segno rovesciato, **senza un solo rilievo**. E la rete non c'è per costruzione: su
            // questo foglio il totale di controllo non esiste, quindi la quadratura non gira.
            //
            // `RipartoConsuntivoParser` la protezione ce l'aveva già (`riparto.saldo_non_numerico`):
            // il percorso nuovo l'aveva persa proprio dove il dato è meno affidabile, perché
            // scritto a mano invece che stampato da una macchina.
            $importo = $this->denaro($grezzo);

            if ($importo === null) {
                $rilievi[] = Rilievo::errore(
                    'modello.saldo_non_numerico',
                    sprintf('L\'importo «%s» non lo so leggere come un numero.', trim((string) $grezzo)),
                    'Scrivi solo le cifre, con la virgola per i decimali e il meno **davanti** se '
                    .'è un credito: «120,50» oppure «-45,00». Il simbolo € va bene, le parentesi '
                    .'e il meno in coda anche; una sigla come «n.d.» no, perché non so a quanto '
                    .'corrisponda.',
                    $rigaUtente,
                    'importo',
                    $foglio->nome,
                );

                continue;
            }

            $saldi[] = new CanonicalSaldo(
                immobileRef: $ref,
                // Vuoto = solidale sull'unità: il debito segue la casa e non chi ci abitava
                // (art. 63 disp. att. c.c.). È scritto sul foglio, ed è una scelta di chi compila.
                soggettoNome: trim((string) $this->cella($riga, $mappa, 'persona')) ?: null,
                // ⚠️ Nessuna inversione di segno, a differenza del riparto di Danea: la colonna
                // del modello dichiara «POSITIVO = deve al condominio», che è già la convenzione
                // di Kondomanager. La conversione euro→centesimi avviene qui e una volta sola.
                importoCents: MoneyHelper::toCents($importo),
                rigaSorgente: $rigaUtente,
                causale: trim((string) $this->cella($riga, $mappa, 'causale')) ?: null,
            );
        }

        $this->avvisaSigleDiverse($diverse, $foglio, $rilievi);

        if ($saldi === []) {
            return [null, new EsitoVerifica($righe, $rilievi)];
        }

        return [
            // ⚠️ `totaleRiferimentoCents: null` è deliberato: sul modello non c'è una riga di
            // totale, e non ce la mettiamo. Un totale sbagliato **blocca** l'importazione dei
            // saldi (`saldi.non_quadrano`), e su un file compilato a mano quel totale lo scrive
            // la stessa persona che ha scritto le righe. Senza, resta l'avviso «non ho potuto
            // verificare da solo che i saldi tornino», che dice la verità e non ferma nessuno.
            new CanonicalSaldiApertura(righe: $saldi, totaleRiferimentoCents: null, fonte: 'foglio dei saldi'),
            new EsitoVerifica($righe, $rilievi),
        ];
    }

    /**
     * La chiave dell'unità scritta su una riga → la chiave canonica, o `null` con il rilievo.
     *
     * @param  array<string, int>  $mappa
     * @param  array<string, array{chiave: string, etichetta: string}>  $indice
     * @param  list<Rilievo>  $rilievi
     * @param  array<string, array{0: string, 1: string}>  $diverse
     */
    private function risolvi(array $riga, array $mappa, array $indice, Foglio $foglio, int $rigaUtente, array &$rilievi, array &$diverse): ?string
    {
        return $this->risolviEtichetta(
            trim((string) $this->cella($riga, $mappa, 'unita')),
            $indice,
            $foglio,
            $rigaUtente,
            $rilievi,
            $diverse,
        );
    }

    /**
     * ## Si tollera, dicendolo
     *
     * L'uguaglianza esatta è la regola che il modello dichiara, e resta quella: si prova prima.
     * Ma «B1/ 1» invece di «B1/1» è l'errore che chi copia a mano fa davvero, e farne perdere la
     * riga sarebbe una punizione sproporzionata — per giunta su un foglio dove la riga persa non
     * si vede, perché il totale di controllo è facoltativo. Si risolve allora sulla forma
     * compatta — senza spazi, senza maiuscole — e **lo si scrive**: l'avviso nomina entrambe le
     * scritture, così chi legge capisce che il file e l'archivio non diranno la stessa cosa.
     *
     * @param  array<string, array{chiave: string, etichetta: string}>  $indice
     * @param  list<Rilievo>  $rilievi
     * @param  array<string, array{0: string, 1: string}>  $diverse  le scritture da segnalare, una volta ciascuna
     */
    private function risolviEtichetta(string $etichetta, array $indice, Foglio $foglio, int $rigaUtente, array &$rilievi, array &$diverse): ?string
    {
        if ($etichetta === '') {
            $rilievi[] = Rilievo::errore(
                'modello.riga_senza_unita',
                'Questa riga non dice a quale unità si riferisce.',
                'La prima colonna deve contenere la stessa sigla che hai usato nel foglio delle '
                .'unità. Senza, la riga non si può collegare a niente.',
                $rigaUtente,
                'unita',
                $foglio->nome,
            );

            return null;
        }

        $compatta = $this->compatta($etichetta);

        if (isset($indice[$compatta])) {
            if ($indice[$compatta]['etichetta'] !== $etichetta) {
                // Una volta per scrittura, non una per riga: chi ha copiato tutto in minuscolo
                // riceverebbe altrimenti duecento avvisi identici, e fra duecento avvisi
                // identici non si legge più quello vero.
                $diverse[$compatta] = [$etichetta, $indice[$compatta]['etichetta']];
            }

            return $indice[$compatta]['chiave'];
        }

        $rilievi[] = Rilievo::errore(
            'modello.unita_sconosciuta',
            sprintf('L\'unità «%s» non compare nel foglio delle unità.', $etichetta),
            $indice === []
                ? 'Il foglio delle unità è vuoto o non è stato letto: è quello che dà un nome a '
                .'tutte le unità, e gli altri fogli lo consultano. Compilalo per primo.'
                : 'La sigla va scritta identica al foglio delle unità. Le sigle che conosco sono: '
                .$this->elencoSigle($indice).'.',
            $rigaUtente,
            'unita',
            $foglio->nome,
        );

        return null;
    }

    /**
     * L'avviso delle sigle scritte in modo diverso: uno per foglio, con dentro tutte le coppie.
     *
     * @param  array<string, array{0: string, 1: string}>  $diverse
     * @param  list<Rilievo>  $rilievi
     */
    private function avvisaSigleDiverse(array $diverse, Foglio $foglio, array &$rilievi): void
    {
        if ($diverse === []) {
            return;
        }

        $coppie = array_map(
            fn (array $c) => sprintf('«%s» → «%s»', $c[0], $c[1]),
            array_slice(array_values($diverse), 0, 6),
        );

        if (count($diverse) > 6) {
            $coppie[] = 'e altre '.(count($diverse) - 6);
        }

        $rilievi[] = Rilievo::avviso(
            'modello.sigla_scritta_diversa',
            sprintf(
                'In questo foglio %s scritta in modo un po\' diverso dal foglio delle unità: %s.',
                count($diverse) === 1 ? 'una sigla è' : count($diverse).' sigle sono',
                implode(', ', $coppie),
            ),
            'Le ho collegate lo stesso, ignorando spazi e maiuscole. Vale la pena uniformarle: se '
            .'un domani due unità si chiamassero «A 1» e «a1», non saprei più quale è quale.',
            colonna: 'unita',
            foglio: $foglio->nome,
        );
    }

    /**
     * Questa cella è una delle **nostre** righe di servizio del foglio tabelle?
     *
     * Non «comincia per #», che è un carattere che l'amministratore può usare in una sigla: sono
     * le due scritte che mette il generatore, riconosciute per contenuto.
     */
    private function eRigaDiServizio(string $etichetta): bool
    {
        if (! str_starts_with($etichetta, self::PREFISSO_META)) {
            return false;
        }

        $normalizzata = HeaderDetector::normalizza($etichetta);

        return $normalizzata === 'tabella' || str_starts_with($normalizzata, 'totale');
    }

    /**
     * Una riga di servizio del foglio (`# tabella`, `# TOTALE DI CONTROLLO`): l'indice, o `null`.
     */
    private function rigaMeta(Foglio $foglio, string $parola): ?int
    {
        $limite = min($foglio->numeroRighe(), HeaderDetector::RIGHE_DA_ISPEZIONARE);

        for ($i = 0; $i < $limite; $i++) {
            $prima = trim((string) ($foglio->riga($i)[0] ?? ''));

            if (str_starts_with($prima, self::PREFISSO_META)
                && HeaderDetector::normalizza($prima) === $parola
            ) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Il foglio è stato **svuotato**, cioè non contiene altro che la nostra testata?
     *
     * ⚠️ Distinguerlo da «intestazione rovinata» non è pignoleria: sono due situazioni opposte e
     * meritano due risposte opposte. Chi cancella tutto il contenuto di un foglio — righe di
     * esempio comprese, e con esse l'intestazione — sta dicendo «questo dato non ce l'ho», che
     * dalla beta.5 è un **salto** e non un muro. Prima invece finiva in
     * `modello.intestazione_illeggibile`, un errore bloccante il cui rimedio è «riscarica il
     * modello vuoto»: cioè, a chi ha deliberatamente lasciato in bianco i saldi, si diceva di
     * ricominciare da capo.
     *
     * Le prime tre righe non contano: sono il titolo e le due righe gialle di guida, che il
     * generatore scrive e nessuno cancella.
     */
    private function svuotato(Foglio $foglio): bool
    {
        for ($i = 3; $i < $foglio->numeroRighe(); $i++) {
            foreach ($foglio->riga($i) as $cella) {
                if (trim((string) ($cella ?? '')) !== '') {
                    return false;
                }
            }
        }

        return true;
    }

    private function intestazioneIlleggibile(Foglio $foglio, string $ruolo): Rilievo
    {
        return Rilievo::errore(
            'modello.intestazione_illeggibile',
            sprintf('Nel foglio «%s» non trovo la riga di intestazione.', $foglio->nome),
            sprintf(
                'Riscarica il modello vuoto e ricopiaci i tuoi dati: la riga scura con i nomi '
                .'delle colonne serve a capire cosa c\'è in ognuna. Questo è il foglio dei %s.',
                $ruolo,
            ),
            foglio: $foglio->nome,
        );
    }

    /**
     * Una data, scritta come la scrive una persona o come la salva Excel.
     *
     * ⚠️ `SpreadsheetReader` legge con `formatData: false`, quindi una cella formattata come data
     * arriva come **numero seriale** (45658 = 1º gennaio 2025) e non come testo. Chi scrive la
     * data a mano in una cella testuale la manda invece così com'è. Vanno gestiti entrambi: sono
     * i due modi in cui lo stesso file arriva a seconda di come è stata compilata quella cella.
     */
    private function data(mixed $valore): ?CarbonImmutable
    {
        if ($valore === null || trim((string) $valore) === '') {
            return null;
        }

        if (is_numeric($valore)) {
            $seriale = (float) $valore;

            // ⚠️ **La soglia bassa è 20000, non 1, e il motivo è un difetto misurato.**
            //
            // Con la soglia a 1 l'anno «2025» scritto a mano nella casella `data_inizio` era un
            // seriale valido, e `excelToDateTimeObject(2025)` lo trasformava nel **17 luglio
            // 1905**. Nessun rilievo scattava: `modello.esercizio_senza_date` no, perché una data
            // era stata letta; `modello.periodo_rovesciato` nemmeno, perché il 1905 di `data_fine`
            // veniva comunque dopo. L'esercizio nasceva con due giorni del 1905 e ogni titolarità
            // e saldo di apertura ci finiva dentro.
            //
            // È anche l'errore che il modello stesso invita a fare: la nota accanto a
            // `data_inizio` parla di «2024/2025», quindi l'anno e la data si toccano proprio lì.
            //
            // 20000 è il 20 ottobre 1954: sotto quella soglia non c'è nessuna data che un
            // esercizio condominiale possa avere, e ci cadono tutti gli anni a quattro cifre.
            if ($seriale < 20000 || $seriale > 80000) {
                return null;
            }

            try {
                return CarbonImmutable::instance(DataExcel::excelToDateTimeObject($seriale))->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        $testo = trim((string) $valore);

        // Ogni formato con la sua variante **senza zeri iniziali**: «1/1/2026» è come scrive una
        // persona, e rifiutarlo sarebbe un rigore che non serve a niente.
        $formati = [
            '!d/m/Y' => ['d/m/Y', 'j/n/Y'],
            '!d-m-Y' => ['d-m-Y', 'j-n-Y'],
            '!d.m.Y' => ['d.m.Y', 'j.n.Y'],
            '!Y-m-d' => ['Y-m-d', 'Y-n-j'],
        ];

        foreach ($formati as $formato => $riletture) {
            try {
                $data = CarbonImmutable::createFromFormat($formato, $testo);
            } catch (Throwable) {
                continue;
            }

            if ($data === false) {
                continue;
            }

            // ⚠️ **`createFromFormat` è lasco e non lo dice.** «31/02/2026» non fallisce: viene
            // *traboccato* al 3 marzo. Una data inesistente diventava così un esercizio che
            // comincia due giorni dopo, senza un rilievo — e due giorni all'inizio di un esercizio
            // spostano la `data_inizio` di **ogni titolarità** scritta.
            //
            // Il controllo è quello che PHP non fa: riformattare e confrontare. Se il giorno è
            // traboccato, la stringa non torna uguale in nessuna delle due riletture.
            foreach ($riletture as $rilettura) {
                if ($data->format($rilettura) === $testo) {
                    return $data;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Un numero scritto a mano: `450,50` all'italiana o `450.50` come lo salva Excel.
     */
    private function numero(mixed $valore): ?float
    {
        if ($valore === null || trim((string) $valore) === '') {
            return null;
        }

        if (is_numeric($valore)) {
            return (float) $valore;
        }

        $pulito = str_replace(',', '.', str_replace('.', '', trim((string) $valore)));

        return is_numeric($pulito) ? (float) $pulito : null;
    }

    /**
     * Un **importo in euro** scritto a mano, riportato al numero che rappresenta.
     *
     * ## Perché non basta `numero()`
     *
     * ⚠️ `1.234` è ambiguo, e le due letture differiscono di **mille volte**. `is_numeric('1.234')`
     * è vero, quindi `numero()` esce subito e legge «uno virgola duecentotrentaquattro»: un saldo
     * di € 1.234,00 diventerebbe € 1,23. La pulizia italiana che c'è due righe più sotto — quella
     * che gestisce correttamente `1.234,50` — non viene mai raggiunta.
     *
     * Su un **importo** la risposta non è ambigua: in Italia un prezzo con tre decimali e senza
     * virgola non esiste, mentre il separatore delle migliaia è ovunque. Su un **millesimo**
     * invece `1.234` con tre decimali è normale, ed è per questo che questa normalizzazione vive
     * qui e non dentro `numero()`: la stessa scrittura vuol dire due cose diverse nelle due
     * colonne, e fingere il contrario romperebbe le tabelle per aggiustare i saldi.
     *
     * ## Cosa si accetta, e perché
     *
     * Il simbolo €, le parentesi contabili e il meno in coda si accettano: sono tre modi in cui
     * un amministratore scrive davvero, tutti e tre non ambigui, e tutti e tre oggi finivano a
     * zero in silenzio. Ciò che resta non leggibile — «n.d.», un trattino, una nota — torna
     * `null`, e chi chiama emette l'errore invece di scrivere zero.
     */
    private function denaro(mixed $valore): ?float
    {
        if (is_int($valore) || is_float($valore)) {
            return (float) $valore;
        }

        $testo = trim((string) $valore);

        if ($testo === '') {
            return null;
        }

        // Simbolo di valuta e spazi di ogni specie, compresi quelli unificatori che Excel infila
        // come separatore delle migliaia.
        $testo = trim((string) preg_replace('/[€\s\x{00A0}\x{202F}]/u', '', $testo));

        // Parentesi contabili: «(45,00)» è meno quarantacinque, in ogni bilancio del mondo.
        $negativo = false;

        if (preg_match('/^\((.*)\)$/', $testo, $m) === 1) {
            $negativo = true;
            $testo = $m[1];
        }

        // Meno in coda, come lo stampano i gestionali di una certa età.
        if (str_ends_with($testo, '-')) {
            $negativo = true;
            $testo = rtrim($testo, '-');
        }

        // ⚠️ Qui la differenza con `numero()`: punti a gruppi di tre e nessuna virgola sono
        // migliaia, non decimali. `450.50` non corrisponde al modello e resta quello che è.
        if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $testo) === 1) {
            $testo = str_replace('.', '', $testo);
        }

        $numero = $this->numero($testo);

        if ($numero === null) {
            return null;
        }

        return $negativo ? -abs($numero) : $numero;
    }

    /**
     * @param  array<string, int>  $mappa
     */
    private function cella(array $riga, array $mappa, string $colonna): mixed
    {
        $indice = $mappa[$colonna] ?? null;

        return $indice === null ? '' : ($riga[$indice] ?? '');
    }

    /**
     * Le righe che non sono dati: quelle vuote e le note di esempio che il modello lascia.
     *
     * ⚠️ La freccia `↑` apre la riga con cui il modello dice «cancella gli esempi». Chi cancella
     * gli esempi ma dimentica quella riga non deve trovarsi un'unità chiamata «↑ righe di
     * esempio»: sarebbe la nostra stessa nota trasformata in un condòmino.
     */
    private function daSaltare(array $riga): bool
    {
        $prima = trim((string) ($riga[0] ?? ''));

        if (str_starts_with($prima, '↑')) {
            return true;
        }

        foreach ($riga as $cella) {
            if (trim((string) ($cella ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /** La forma su cui due sigle si dicono uguali: minuscolo, senza spazi. */
    private function compatta(string $etichetta): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', trim($etichetta)));
    }

    /** La sigla come l'ha scritta l'amministratore, ricavata dalla chiave canonica. */
    private function etichettaDi(string $chiave): string
    {
        $pezzi = explode('-', $chiave, 3);

        return $pezzi[2] ?? $chiave;
    }

    /**
     * @param  array<string, array{chiave: string, etichetta: string}>  $indice
     */
    private function elencoSigle(array $indice): string
    {
        $sigle = array_map(fn (array $v) => '«'.$v['etichetta'].'»', array_values($indice));

        // Un elenco di duecento sigle in un messaggio d'errore non si legge: se ne mostrano
        // quante bastano a riconoscere il modo in cui sono scritte.
        if (count($sigle) > 8) {
            $sigle = [...array_slice($sigle, 0, 8), 'e altre '.(count($indice) - 8)];
        }

        return implode(', ', $sigle);
    }

    /** I millesimi come si leggono: senza decimali inutili in coda. */
    private function millesimi(float $valore): string
    {
        return rtrim(rtrim(number_format($valore, 3, ',', '.'), '0'), ',');
    }
}
