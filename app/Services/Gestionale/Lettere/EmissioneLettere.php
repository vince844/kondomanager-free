<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Lettere;

use App\Exceptions\Gestionale\LetteraNonEmettibileException;
use App\Helpers\DateHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Notifications\Gestionale\LetteraNotification;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Debiti\ElencoRateScadute;
use App\Services\Gestionale\Invii\ArchivioAPezzi;
use App\Services\Gestionale\Invii\InviiPerPersona;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\Gestionale\Ricevute\ErroreDiPosta;
use App\Services\Gestionale\Ricevute\LimiteAltroIndirizzo;
use App\Services\PDF\CartaDiStampa;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use App\Services\Posta\CasellaPec;
use App\Services\Posta\CasellaPecNonImpostata;
use App\Services\Posta\DocumentoPerPec;
use App\Services\Posta\ErroreDellaPec;
use App\Services\Posta\InvioPerPec;
use App\Services\Posta\PecDelloStudio;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Le lettere ai condòmini dall'elenco delle rate scadute: promemoria, sollecito, diffida, messa in mora. Il tipo lo sceglie l'amministratore a
 * ogni emissione, e non si ricorda.
 *
 * - **Il contenuto** è la lettura dei debiti di oggi ({@see ElencoRateScadute}): per il promemoria le rate emesse che scadono nei prossimi N
 *   giorni, per gli altri tipi le rate scadute; per ognuna l'unità, la rata, la scadenza, il residuo e il ritardo (per un pregresso senza un
 *   numero di giorni, le sue parole). Niente credito nella lettera: se la persona ne ha uno, l'anteprima lo dice all'amministratore.
 * - **Il testo** è quello che l'amministratore ha nella finestra, con i campi riempiti per ogni persona ({@see TestiLettere}); il testo usato
 *   resta nella riga del registro, e anche il testo con i campi (`dati.modello`), per rifare la lettera con le cifre di un altro giorno. Se il
 *   testo è partito da un modello dello studio, la riga tiene anche il suo numero e il suo nome ({@see ModelliLettere}): il testo è copiato, e
 *   cambiare o cancellare il modello dopo non cambia la lettera.
 * - **Il canale** di ogni persona si propone dalla sua ultima lettera; senza lettere, per il promemoria e il sollecito l'email se ne ha una (primaria o
 *   secondaria), poi per sollecito, diffida e messa in mora la PEC dalla casella dello studio se la persona l'ha comunicata, altrimenti la stampa. Si cambia
 *   persona per persona ({@see CANALI_PER_TIPO}). Diffida e messa in mora non partono mai per email: per PEC, su carta, o per PEC e su carta.
 * - **La PEC** (decisioni 569 e 588) parte dalla casella PEC dello studio con lo stesso servizio della convocazione ({@see InvioPerPec}), una riga `pec` per
 *   persona con il PDF di oggi conservato e la sua impronta ({@see mandaPerPec()}); la PEC non è più un indirizzo dell'email. «PEC e carta» è la stessa
 *   lettera mandata per PEC e stampata per la posta: due righe con lo stesso `dati.lettera` e lo stesso PDF, qualunque delle due azioni venga prima, se le
 *   scelte sono le stesse (con un altro testo, un altro termine o altre cifre è una lettera nuova). Una PEC dello stesso tipo già partita oggi alla persona
 *   non parte due volte. Le PEC non partite, o non consegnate, si rimandano una per una, in blocco ({@see rimandaLePec()}) o si stampano ({@see stampaLePec()}).
 * - **Il PDF conservato**: per sollecito, diffida e messa in mora il PDF si fa una volta per lettera, all'emissione, e si salva come documento
 *   della riga; l'email lo allega, un nuovo tentativo lo riusa, il .zip lo contiene, e l'impronta della riga è la sua. La stampa in blocco resta
 *   di un passaggio solo, con lo stesso contenuto. Il promemoria non conserva il PDF: basta l'istantanea della riga.
 * - **Il confine**: le persone sono quelle della selezione, rilette adesso (la lista spuntata o «tutte le N dei filtri»), e fra quelle solo le
 *   persone viste nell'anteprima e rimaste (`viste`); chi è entrato nel filtro dopo non riceve niente, e la risposta lo dice. Chi
 *   l'amministratore ha tolto nell'anteprima non è «entrato dopo»: la finestra manda anche tutte le persone dell'anteprima (`in_anteprima`).
 * - **La gestione** scelta nell'elenco vale per la lista spuntata come per «tutte le N», si scrive nella lettera, e la lettera rifatta la tiene.
 * - **L'accordo di rientro**: la lettera lascia fuori la parte di ogni rata coperta da un accordo in corso fino al suo «fino al» (il minore fra
 *   il residuo di oggi della rata e il suo importo riconosciuto, {@see \App\Services\Gestionale\Debiti\AccordiRientro}); una rata coperta per
 *   intero non c'è, e chi ha solo rate in accordo non riceve la lettera. L'anteprima dice quanto è in accordo; l'amministratore le rimette nella
 *   lettera con `anche_in_accordo`, che si scrive nella lettera e la lettera rifatta tiene. La riga del registro porta il residuo della rata
 *   (la guardia alla partenza lo rilegge) e, a parte, la parte in accordo.
 * - **Un messaggio per persona e per indirizzo**, mai un elenco a più persone. Quando l'installazione manda le email subito, mentre la pagina
 *   aspetta (la coda `sync`), al massimo {@see EmissioneRicevute::TETTO_MESSAGGI_SENZA_CODA} messaggi per volta, a persone intere; per i tipi
 *   che conservano il PDF anche al massimo {@see TETTO_PDF} lettere per volta, con qualunque coda.
 */
final class EmissioneLettere
{
    public const TIPI = RegistroInvio::TIPI_LETTERE;

    /** Il nome di ogni tipo: è anche il titolo della lettera. */
    public const NOMI = ['promemoria' => 'Promemoria', 'sollecito' => 'Sollecito', 'diffida' => 'Diffida', 'messa_in_mora' => 'Messa in mora'];

    /** Il titolo dell'elenco degli indirizzi del .zip. */
    public const NOMI_PLURALI = ['promemoria' => 'Promemoria', 'sollecito' => 'Solleciti', 'diffida' => 'Diffide', 'messa_in_mora' => 'Messe in mora'];

    /** I tipi che il programma non manda per email (decisione 569): partono per PEC dalla casella dello studio, o su carta. */
    public const TIPI_SENZA_EMAIL = ['diffida', 'messa_in_mora'];

    /** I tipi di cui si conserva il PDF partito. */
    public const TIPI_CONSERVATI = ['sollecito', 'diffida', 'messa_in_mora'];

    /**
     * I canali di ogni tipo (decisioni 569 e 588): `pec` è la PEC dalla casella dello studio; `pec_e_carta` la stessa lettera per PEC e stampata per la
     * posta. Il promemoria non è una lettera a chi è in ritardo: resta email, stampa e PDF per persona.
     */
    public const CANALI_PER_TIPO = [
        'promemoria'    => ['email', 'stampa', 'zip'],
        'sollecito'     => ['email', 'pec', 'pec_e_carta', 'stampa', 'zip'],
        'diffida'       => ['pec', 'pec_e_carta', 'stampa', 'zip'],
        'messa_in_mora' => ['pec', 'pec_e_carta', 'stampa', 'zip'],
    ];

    /** I canali che mandano la PEC, e quelli che stampano la lettera. */
    public const CANALI_CON_LA_PEC = ['pec', 'pec_e_carta'];

    public const CANALI_CON_LA_STAMPA = ['stampa', 'pec_e_carta'];

    /** Gli indirizzi dell'email nelle lettere: la primaria e la secondaria. La PEC non è un indirizzo dell'email: parte dalla casella dello studio. */
    public const INDIRIZZI_EMAIL = ['email' => 'primaria', 'email_secondaria' => 'secondaria'];

    /**
     * Le PEC per volta: partono mentre la pagina aspetta, fino a 20 secondi per collegamento. Venti, finché il tempo di una PEC non è misurato su una casella
     * vera: cinquanta potevano superare il tempo che il server web aspetta, e la pagina avrebbe detto «non sono partite» mentre partivano.
     */
    public const TETTO_PEC = 20;

    /** Quanto dura al più il blocco di un invio per PEC, in secondi: se il lavoro si ferma senza toglierlo, dopo questo tempo si riprova. */
    public const DURATA_DEL_BLOCCO = 900;

    /** Le chiavi della lettera nella riga: «PEC e carta» le copia dalla riga gemella, così le due righe dicono la stessa lettera. */
    private const CHIAVI_DELLA_LETTERA = ['lettera', 'persona', 'alla', 'modello', 'modello_id', 'modello_nome', 'singola', 'rifatta_da', 'testo', 'righe', 'totale',
        'in_accordo', 'anche_in_accordo', 'termine', 'termine_forma', 'termine_data', 'giorni', 'gestione_id', 'cassa_id', 'iban', 'intestatario', 'segnaposti'];

    /**
     * Le chiavi che fanno il contenuto di una lettera: con «PEC e carta» la seconda azione prende la riga gemella solo se queste sono uguali (lo stesso testo,
     * lo stesso termine, lo stesso conto, la stessa gestione, le stesse rate e cifre, la stessa persona). Altrimenti è un'altra lettera.
     */
    private const CHIAVI_DEL_CONTENUTO = ['persona', 'testo', 'righe', 'totale', 'in_accordo', 'termine', 'termine_forma', 'termine_data', 'gestione_id', 'cassa_id',
        'iban', 'intestatario'];

    public const TETTO_PDF = 200;

    public const ETICHETTA_ANTEPRIMA = 'ANTEPRIMA';
    public const ETICHETTA_PROVA = 'PROVA';

    public const SOLO_CARTA = 'Diffida e messa in mora non partono per email: mandale per PEC dalla casella dello studio, o stampale o scarica un PDF per persona e '
        . 'registra la spedizione.';
    public const SOLO_PEC_CON_LA_CASELLA = 'Ha solo la PEC: scegli «PEC dello studio», o stampala.';
    public const SOLO_PEC_SENZA_CASELLA = 'Ha solo la PEC: imposta la casella PEC dello studio, o stampala.';
    public const SOLO_PEC_CHIEDI_LA_CASELLA = 'Ha solo la PEC: chiedi a chi gestisce le impostazioni di impostare la casella PEC dello studio, o stampala.';
    public const SENZA_PEC = 'Nella sua scheda non c\'è una PEC.';
    public const PROMEMORIA_NON_PER_PEC = 'Il promemoria non parte per PEC: mandalo per email, o stampalo.';
    public const NESSUNA_PEC = 'Nessuna lettera da mandare per PEC: scegli «PEC dello studio» per qualcuno.';
    public const PEC_GIA_IN_CORSO = 'Le PEC di questo condominio stanno già partendo: aspetta che finiscano, poi rifai l\'anteprima.';
    public const SOLO_PEC_NON_PARTITE = 'Si manda di nuovo per PEC solo una lettera per PEC che non è partita, che ha l\'avviso di mancata consegna o che si è '
        . 'fermata in partenza.';
    public const NESSUNA_PEC_DA_RIMANDARE = 'Nessuna lettera per PEC da rimandare: quelle non partite o con l\'avviso di mancata consegna sono già state rifatte.';
    public const NESSUNA_PEC_DA_STAMPARE = 'Nessuna lettera per PEC da stampare: quelle non partite o con l\'avviso di mancata consegna sono già state rifatte, o hanno '
        . 'già la copia su carta.';
    public const SOLO_PEC_DA_RIMANDARE = 'Si stampa per la posta una lettera per PEC che non è partita o che ha l\'avviso di mancata consegna, e che non è già stata '
        . 'rifatta.';
    public const ERA_PER_EMAIL_ALLA_PEC = 'Questa lettera era andata per email alla PEC: mandala per PEC dalla casella dello studio, o stampala.';
    public const SENZA_SERVER_POSTA = 'Nessun server di posta configurato: le lettere non partono per email. Configuralo nelle impostazioni della posta; intanto puoi stamparle.';
    public const NESSUNA_PERSONA = 'Per nessuna delle persone scelte c\'è qualcosa da mettere in questa lettera.';
    public const SCEGLI_IL_CONTO = 'Il condominio ha più di un conto con l\'IBAN: scegli quello da scrivere nella lettera.';
    public const SERVE_IL_TERMINE = 'Scrivi il termine per il versamento, in giorni: da 1 a 365.';
    public const SCEGLI_IL_TERMINE = 'Scegli il termine per il versamento: un numero di giorni dal ricevimento, una data, o nessun termine nella lettera.';
    public const DATA_DEL_TERMINE = 'Scegli come termine una data da domani a un anno da oggi.';
    public const TERMINE_NEL_TESTO = 'Il testo usa [termine], ma hai scelto «nessun termine nella lettera»: togli [termine] dal testo, o scegli il termine.';
    public const PROMEMORIA_SENZA_TERMINE = 'Il promemoria non ha un termine per il versamento: togli [termine] dal testo.';
    public const TERMINE_PASSATO = 'La data del termine di questa lettera è passata: preparane una nuova dall\'elenco delle rate scadute, con un termine nuovo.';
    public const SERVE_IL_TESTO = 'Scrivi il testo della lettera, o parti da quello del programma o da un modello: una diffida o una messa in mora non parte senza testo.';
    public const ALTRO_SOLO_PER_UNA = 'Un altro indirizzo si scrive solo nella lettera di una persona: solo un indirizzo della persona o di chi la rappresenta.';
    public const TIPO_CHE_NON_C_E = 'Scegli il tipo della lettera: promemoria, sollecito, diffida o messa in mora.';
    public const ANCORA_IN_CODA = 'Questa lettera è ancora in coda: parte da sola. Si rimanda una lettera partita o non partita.';
    /** Il conto chiesto non è più fra quelli con cui si scrive una lettera: chiuso, o senza l'IBAN. */
    public static function contoNonPiuUsabile(string $nome): string
    {
        return 'Il conto «' . $nome . '» non è più attivo o non ha più l\'IBAN: chiudi e riapri la finestra delle lettere, che propone i conti di oggi.';
    }

    /** In testa all'elenco degli indirizzi dello .zip delle lettere: come si spediscono, e che la lettera conta fra le scritte con la spedizione. */
    public const COME_SPEDIRE_I_PDF = 'Un PDF per persona, da stampare e spedire, o da mandare con la tua PEC o il tuo programma di posta. Poi registra la spedizione '
        . 'nel programma: finché non la registri, la lettera non conta fra quelle scritte.';

    public const OLTRE_IL_TETTO = 'Scegli le persone di una pagina dell\'elenco e prepara le loro, poi passa alla pagina dopo: stampare o scaricare le lettere non '
        . 'cambia l\'ordine dell\'elenco. Se registri la spedizione e l\'elenco ha il filtro «Mai scritto» o «Nessuna lettera negli ultimi N giorni», chi l\'ha '
        . 'avuta esce dall\'elenco: le altre persone ricominciano dalla pagina 1.';
    public const GIA_RIFATTA = 'Dopo questa lettera la persona ne ha già un\'altra dello stesso tipo, partita o in coda: se serve, rimanda quella.';

    /** I PDF conservati scritti sul disco nell'operazione in corso: se l'operazione non riesce, si tolgono ({@see conIFile()}). */
    private array $scritti = [];

    /** @var list<array{prima: string, ora: ?string}> le lettere rifatte senza il conto di prima, nell'operazione in corso */
    private array $contiCambiati = [];

    public function __construct(
        private readonly ElencoRateScadute $elenco,
        private readonly PdfService $pdf,
        private readonly ArchivioDocumenti $archivio,
        private readonly ModelliLettere $modelli,
    ) {}

    // ── Le opzioni della finestra ────────────────────────────────────────

    /**
     * Quello che la finestra sa prima di chiedere l'anteprima: i tipi, il testo del programma di ognuno, i modelli dello studio validi nel
     * condominio e l'ultimo modello di ogni tipo, i campi, i conti con l'IBAN, le scelte ricordate (il termine, il conto, gli indirizzi: mai il
     * tipo), i dati dello studio, il server di posta.
     *
     * @return array<string, mixed>
     */
    public function opzioni(Condominio $condominio, ?User $utente): array
    {
        return [
            'tipi'               => collect(self::NOMI)->map(fn ($nome, $tipo) => ['tipo' => $tipo, 'nome' => $nome, 'senza_email' => in_array($tipo, self::TIPI_SENZA_EMAIL, true),
                'conservato' => in_array($tipo, self::TIPI_CONSERVATI, true), 'canali' => self::CANALI_PER_TIPO[$tipo]])->values()->all(),
            // I testi del programma: non si cambiano. Per diffida e messa in mora la finestra dice di controllarli.
            'testi'              => TestiLettere::DI_SERIE,
            'modelli'            => $this->modelli->delCondominio($condominio),
            // L'ultimo modello di ogni tipo nel condominio, dalla riga dell'ultima lettera di quel tipo.
            'modelli_ricordati'  => $this->modelli->ricordati($condominio),
            'campi'              => TestiLettere::CAMPI,
            'conti'              => $this->conti($condominio),
            'scelta_ricordata'   => $this->sceltaRicordata($condominio, $utente),
            // Il termine di serie delle regole del recupero del condominio, se lo dicono: la finestra lo propone quando non ricorda un termine.
            'termine_di_serie'   => \App\Services\Gestionale\Accessori\RegoleDelRecupero::termineDelleLettere(
                (new \App\Services\Gestionale\Accessori\RegoleDelRecupero())->delCondominio((int) $condominio->id), DateHelper::oggiUtente()),
            'studio'             => self::studio(),
            'studio_compilato'   => self::studio()['nome'] !== null,
            // Le lettere escono su una carta intestata che si legge: lo studio lo porta la carta, e la frase della finestra lo dice.
            'carta_intestata'    => self::cartaIntestata(),
            'senza_server_posta' => EmissioneRicevute::senzaServerPosta(),
            'tetto'              => self::TETTO_PDF,
            // La casella PEC dello studio: senza, la PEC si vede spenta con il perché e «Imposta» per chi apre le impostazioni.
            'casella_pec'        => self::casellaPec(),
        ];
    }

    /**
     * La casella PEC dello studio vista dalla finestra delle lettere (decisione 572, proposta 11): se c'è, da quale indirizzo partono le PEC, senza il perché
     * e dove si imposta (solo a chi apre le impostazioni), e l'avviso dell'ultima prova non andata, per la conferma.
     *
     * @return array{impostata: bool, indirizzo: ?string, perche: ?string, dove: ?string, avviso: ?string}
     */
    public static function casellaPec(): array
    {
        $pec = app(PecDelloStudio::class);
        $casella = app(CasellaPec::class)->configurazione();

        return ['impostata' => $casella !== null, 'indirizzo' => $casella?->indirizzo, 'perche' => $casella === null ? PecDelloStudio::NON_IMPOSTATA : null,
            'dove' => $pec->dove(), 'avviso' => $casella === null ? null : $pec->avviso()];
    }

    /**
     * Le scelte ricordate di chi lavora in questo condominio, dall'ultima lettera che ha emesso: il termine del sollecito (dall'ultimo sollecito: la
     * forma e i giorni, non la data, che sarebbe passata; per diffida e messa in mora la forma si sceglie ogni volta, il promemoria non ha un
     * termine), il conto
     * dell'IBAN, gli indirizzi dell'ultimo invio per email. Il tipo no (un tipo sbagliato ricordato manderebbe una diffida al posto di un
     * sollecito), né il testo.
     *
     * @return array{termini: array<string, ?array{forma: string, giorni: ?int}>, cassa_id: ?int, indirizzi: ?array<int, string>}
     */
    public function sceltaRicordata(Condominio $condominio, ?User $utente): array
    {
        // Il termine si ricorda dove la finestra propone una forma: il sollecito. Per diffida e messa in mora la forma si sceglie ogni volta; il
        // promemoria non ha un termine.
        $conTermine = ['sollecito'];
        if ($utente === null) {
            return ['termini' => array_fill_keys($conTermine, null), 'cassa_id' => null, 'indirizzi' => null];
        }
        $sue = fn () => RegistroInvio::where('condominio_id', $condominio->id)->whereIn('tipo', self::TIPI)->where('emesso_da', $utente->id)->orderByDesc('id');
        $termini = [];
        foreach ($conTermine as $tipo) {
            $dati = RegistroInvio::where('condominio_id', $condominio->id)->where('tipo', $tipo)->where('emesso_da', $utente->id)->orderByDesc('id')->first()?->dati;
            $forma = $dati === null ? null : TestiLettere::formaDelTermine((array) $dati);
            $termini[$tipo] = $forma === null ? null : ['forma' => $forma, 'giorni' => $forma === 'giorni' && isset($dati['termine']) ? (int) $dati['termine'] : null];
        }
        $cassa = $sue()->whereNotNull('dati->cassa_id')->first()?->dati['cassa_id'] ?? null;
        // Il conto si ricorda solo se è ancora fra i conti di oggi (attivo, con l'IBAN): uno chiuso fermerebbe ogni lettera.
        if ($cassa !== null && ! collect($this->conti($condominio))->contains('id', (int) $cassa)) {
            $cassa = null;
        }

        // Gli indirizzi dell'email ricordati: la PEC spuntata in una lettera di prima non c'è più, perché non è un indirizzo dell'email.
        $indirizzi = $sue()->whereNotNull('dati->tipi_scelti')->first()?->dati['tipi_scelti'] ?? null;

        return [
            'termini'   => $termini,
            'cassa_id'  => $cassa === null ? null : (int) $cassa,
            'indirizzi' => $indirizzi === null ? null : array_values(array_intersect((array) $indirizzi, array_keys(self::INDIRIZZI_EMAIL))),
        ];
    }

    /** @return list<array{id: int, nome: string, iban: string, intestatario: ?string}> le casse di tipo banca del condominio con un IBAN */
    public function conti(Condominio $condominio): array
    {
        return Cassa::with('contoCorrente')->where('condominio_id', $condominio->id)->where('tipo', 'banca')->where('attiva', true)->orderBy('nome')->get()
            ->filter(fn (Cassa $c) => filled($c->contoCorrente?->iban))
            ->map(fn (Cassa $c) => ['id' => (int) $c->id, 'nome' => (string) $c->nome, 'iban' => (string) $c->contoCorrente->iban,
                'intestatario' => $c->contoCorrente->intestatario ?: $condominio->nome])
            ->values()->all();
    }

    /**
     * Lo studio delle impostazioni di stampa, pulito: lo stesso delle stampe ({@see CartaDiStampa::studio()}).
     *
     * @return array{nome: ?string, indirizzo: ?string, email: ?string, pec: ?string, telefono: ?string, comune: ?string, partita_iva: ?string, codice_fiscale: ?string, sito: ?string}
     */
    public static function studio(): array
    {
        return CartaDiStampa::studio();
    }

    /** La carta delle lettere è una carta intestata che si legge ({@see CartaDiStampa::perGruppo()}). */
    public static function cartaIntestata(): bool
    {
        return app(CartaDiStampa::class)->perGruppo(GruppoCarta::LETTERE)->cartaIntestata() !== null;
    }

    /**
     * Il luogo in testa alla lettera: il comune dello studio, se l'amministratore lo ha scritto nelle impostazioni di stampa; senza, quello del
     * condominio. Lo legge anche «Prova la carta» ({@see \App\Services\PDF\ProvaDellaCarta}).
     */
    public static function luogo(?Condominio $condominio): ?string
    {
        return self::studio()['comune'] ?? (filled($condominio?->comune) ? (string) $condominio->comune : null);
    }

    // ── La selezione e il contenuto ──────────────────────────────────────

    /**
     * Le scelte dell'emissione come arrivano dalla finestra: il tipo (obbligatorio, nessuno di serie), il testo, il modello da cui è partito
     * ({@see ModelliLettere::scelto()}), il termine ({@see termineScelto()}: giorni, una data o nessuno; il promemoria non ne ha), il conto
     * dell'IBAN, i tipi di indirizzo.
     *
     * @param array<string, mixed> $input
     * @return array{tipo: string, modello: string, modello_scelto: ?array{id: int, nome: string}, termine: ?int, termine_forma: ?string, termine_data: ?string, cassa: ?array<string, mixed>, tipi_scelti: array<int, string>, giorni: int}
     */
    public function scelte(Condominio $condominio, array $input): array
    {
        $tipo = is_string($input['tipo'] ?? null) ? $input['tipo'] : '';
        if (! in_array($tipo, self::TIPI, true)) {
            throw new LetteraNonEmettibileException(self::TIPO_CHE_NON_C_E);
        }
        // Il modello dello studio da cui è partito il testo: uno di un altro condominio è un 404 per tutta la richiesta.
        $modelloScelto = ModelliLettere::scelto($condominio, $tipo, $input['modello_id'] ?? null);
        $modello = $input['testo'] ?? '';
        if (! is_scalar($modello) && $modello !== null) {
            throw new LetteraNonEmettibileException('Il testo della lettera deve essere un testo.');
        }
        $modello = str_replace("\r\n", "\n", trim((string) $modello));
        if (mb_strlen($modello) > TestiLettere::LUNGHEZZA_MASSIMA) {
            throw new LetteraNonEmettibileException('Il testo è troppo lungo: al massimo ' . TestiLettere::LUNGHEZZA_MASSIMA . ' caratteri.');
        }
        [$forma, $termine, $dataDelTermine] = self::termineScelto($tipo, $modello, $input);

        $conti = collect($this->conti($condominio))->keyBy('id');
        $cassaId = is_numeric($input['cassa_id'] ?? null) ? (int) $input['cassa_id'] : null;
        if ($cassaId !== null && ! $conti->has($cassaId)) {
            // Una cassa di questo condominio chiusa, o senza l'IBAN: la strada, non un 404. Una cassa di un altro condominio resta un 404.
            $delCondominio = Cassa::where('condominio_id', $condominio->id)->whereKey($cassaId)->first(['id', 'nome']);
            abort_if($delCondominio === null, 404);

            throw new LetteraNonEmettibileException(self::contoNonPiuUsabile((string) $delCondominio->nome));
        }
        if ($cassaId === null && $conti->count() > 1) {
            throw new LetteraNonEmettibileException(self::SCEGLI_IL_CONTO);
        }
        $cassa = $cassaId !== null ? $conti[$cassaId] : $conti->first();

        $tipi = self::indirizziScelti($input);

        // I giorni della vista «In scadenza nei prossimi N giorni», da cui il promemoria prende le rate.
        $giorni = $input['filtri']['giorni'] ?? $input['giorni'] ?? null;
        $giorni = is_numeric($giorni) && (int) $giorni >= 1 && (int) $giorni <= 366 ? (int) $giorni : ElencoRateScadute::GIORNI_DI_SERIE;

        return ['tipo' => $tipo, 'modello' => $modello, 'modello_scelto' => $modelloScelto, 'termine' => $termine, 'termine_forma' => $forma,
            'termine_data' => $dataDelTermine, 'cassa' => $cassa, 'tipi_scelti' => $tipi, 'giorni' => $giorni,
            // La lettera di una persona (dall'estratto conto o dalla sua riga): il suo modello non diventa quello ricordato del condominio.
            'singola' => filter_var($input['singola'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'gestione' => $this->gestioneScelta($condominio, $input), 'anche_in_accordo' => self::ancheInAccordo($input)];
    }

    /**
     * Il termine per il versamento scelto nella finestra: la forma (`termine_forma`: giorni, data, nessuno; un numero di giorni senza la forma vale
     * «giorni») con i giorni (`termine`, da 1 a 365) o la data (`termine_data`, dopo oggi, entro un anno). Il promemoria non ha un termine. Per gli
     * altri tipi la scelta è dell'amministratore: senza, il server rifiuta. Un testo che usa `[termine]` vuole un termine. Diffida e messa in mora
     * non partono senza testo.
     *
     * @return array{0: ?string, 1: ?int, 2: ?string} la forma, i giorni, la data
     */
    private static function termineScelto(string $tipo, string $modello, array $input): array
    {
        if ($tipo === 'promemoria') {
            if (str_contains($modello, '[termine]')) {
                throw new LetteraNonEmettibileException(self::PROMEMORIA_SENZA_TERMINE);
            }

            return [null, null, null];
        }
        if (in_array($tipo, self::TIPI_SENZA_EMAIL, true) && $modello === '') {
            throw new LetteraNonEmettibileException(self::SERVE_IL_TESTO);
        }
        $giorni = $input['termine'] ?? null;
        $forma = $input['termine_forma'] ?? (filled($giorni) ? 'giorni' : null);
        if (! is_string($forma) || ! in_array($forma, TestiLettere::FORME_DEL_TERMINE, true)) {
            throw new LetteraNonEmettibileException(self::SCEGLI_IL_TERMINE);
        }
        if ($forma === 'giorni') {
            $giorni = is_numeric($giorni) && (int) $giorni >= 1 && (int) $giorni <= 365 && (string) (int) $giorni === trim((string) $giorni) ? (int) $giorni : null;
            if ($giorni === null) {
                throw new LetteraNonEmettibileException(self::SERVE_IL_TERMINE);
            }

            return ['giorni', $giorni, null];
        }
        if ($forma === 'data') {
            $data = $input['termine_data'] ?? null;
            $oggi = DateHelper::oggiUtente();
            $limite = \Carbon\CarbonImmutable::parse($oggi, 'UTC')->addYear()->format('Y-m-d');
            if (! is_string($data) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || ! checkdate((int) substr($data, 5, 2), (int) substr($data, 8, 2), (int) substr($data, 0, 4))
                || $data <= $oggi || $data > $limite) {
                throw new LetteraNonEmettibileException(self::DATA_DEL_TERMINE);
            }

            return ['data', null, $data];
        }
        if (str_contains($modello, '[termine]')) {
            throw new LetteraNonEmettibileException(self::TERMINE_NEL_TESTO);
        }

        return ['nessuno', null, null];
    }

    /**
     * La gestione scelta nell'elenco: `gestione_id`, o quella dei filtri con «tutte le N». Vale per la lista spuntata come per «tutte»: le
     * stesse persone non hanno lettere diverse secondo come le si è scelte. Una gestione di un altro condominio è un 404 per tutta la richiesta.
     *
     * @param array<string, mixed> $input
     * @return array{id: int, nome: string}|null
     */
    private function gestioneScelta(Condominio $condominio, array $input): ?array
    {
        $filtri = ! empty($input['tutte']) && is_array($input['filtri'] ?? null) ? $input['filtri'] : [];
        $id = $input['gestione_id'] ?? $filtri['gestione_id'] ?? null;
        if (! is_numeric($id) || (int) $id < 1) {
            return null;
        }
        $gestione = $condominio->gestioni()->whereKey((int) $id)->first(['gestioni.id', 'gestioni.nome']);
        abort_if($gestione === null, 404);

        return ['id' => (int) $gestione->id, 'nome' => (string) $gestione->nome];
    }

    /**
     * Le persone della selezione con il contenuto della loro lettera di oggi. La selezione è la lista (`persone`: tutte di questo condominio,
     * altrimenti 404 per tutta la richiesta) o «tutte le N dei filtri» (`tutte` con i `filtri`), rilette adesso; con `viste`, solo le persone
     * viste nell'anteprima, e `aggiunte` conta quelle entrate nel filtro dopo.
     *
     * @param array<string, mixed> $input
     * @return array{persone: array<int, array<string, mixed>>, aggiunte: int, uscite: int, senza_niente: array<int, string>}
     */
    public function contenuti(Condominio $condominio, string $tipo, array $input): array
    {
        return $this->contenutiPer($condominio, $tipo, $input, $this->gestioneScelta($condominio, $input)['id'] ?? null);
    }

    /**
     * Il contenuto con la gestione già decisa: quella scelta nella richiesta ({@see contenuti()}) o quella scritta nella lettera di prima (una
     * lettera rifatta). La gestione di una lettera di prima non si controlla di nuovo: se nel frattempo non c'è più, la lettura non trova niente.
     *
     * @param array<string, mixed> $input
     * @return array{persone: array<int, array<string, mixed>>, aggiunte: int, uscite: int, senza_niente: array<int, string>, solo_in_accordo: array<int, string>}
     */
    private function contenutiPer(Condominio $condominio, string $tipo, array $input, ?int $gestioneId): array
    {
        $grezzi = ! empty($input['tutte']) ? (array) ($input['filtri'] ?? []) : [];
        $grezzi['giorni'] = $grezzi['giorni'] ?? ($input['giorni'] ?? null);
        $filtri = $this->elenco->filtriNormalizzati($grezzi);
        $filtri['gestione_id'] = $gestioneId;
        // Una lettera dice la situazione di oggi, non quella di un altro giorno.
        $filtri['alla'] = DateHelper::oggiUtente();
        $filtri['vista'] = $tipo === 'promemoria' ? ElencoRateScadute::VISTA_IN_SCADENZA : ElencoRateScadute::VISTA_SCADUTE;
        $solo = null;
        if (empty($input['tutte'])) {
            $solo = self::identificativi($input['persone'] ?? []);
            if ($solo === []) {
                throw new LetteraNonEmettibileException('Scegli a chi scrivere.');
            }
            $delCondominio = Anagrafica::whereIn('id', $solo)->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominio->id))->count();
            abort_unless($delCondominio === count($solo), 404);
        }

        $righe = $this->elenco->righe($condominio, $filtri, $solo, false)->keyBy('anagrafica_id');
        $senzaNiente = $solo === null ? [] : Anagrafica::whereIn('id', array_diff($solo, $righe->keys()->all()) ?: [0])->orderBy('nome')->pluck('nome')->all();
        $aggiunte = 0;
        $uscite = 0;
        if (array_key_exists('viste', $input) && $input['viste'] !== null) {
            $viste = self::identificativi($input['viste']);
            // Entrate dopo sono le persone che l'anteprima non aveva: chi l'amministratore ha tolto («togli») c'era, e non è «entrato dopo».
            $inAnteprima = array_key_exists('in_anteprima', $input) && $input['in_anteprima'] !== null ? self::identificativi($input['in_anteprima']) : $viste;
            $aggiunte = $righe->keys()->diff($inAnteprima)->count();
            $uscite = count(array_diff($viste, $righe->keys()->all()));
            $righe = $righe->only($viste);
        }

        $persone = Anagrafica::whereIn('id', $righe->keys()->all() ?: [0])->get()->keyBy('id');
        $anche = self::ancheInAccordo($input);
        $contenuti = [];
        $soloInAccordo = [];
        foreach ($righe as $id => $r) {
            $p = $persone->get($id);
            if ($p === null) {
                continue;
            }
            // La parte in accordo di ogni rata resta fuori dalla lettera, finché l'accordo vale per le lettere; una rata tutta in accordo non c'è.
            $rate = [];
            $inAccordo = 0;
            // Le rate di un accordo in corso con «fino al» passato tornano nella lettera: l'anteprima lo dice per la persona.
            $passato = ['fino_al' => null, 'importo' => 0];
            foreach ($r['rate'] as $d) {
                $fuori = ! $anche && ($d['accordo']['fuori_dalle_lettere'] ?? false) ? min((int) $d['accordo']['in_accordo'], (int) $d['residuo']) : 0;
                $inAccordo += $fuori;
                if (isset($d['accordo']) && ! $d['accordo']['fuori_dalle_lettere'] && (int) $d['accordo']['in_accordo'] > 0) {
                    $passato['importo'] += min((int) $d['accordo']['in_accordo'], (int) $d['residuo']);
                    $passato['fino_al'] = max((string) $passato['fino_al'], (string) $d['accordo']['fino_al']);
                }
                if ($fuori < (int) $d['residuo']) {
                    $rate[] = $d + ['parte_in_accordo' => $fuori];
                }
            }
            if ($rate === []) {
                $soloInAccordo[] = (string) $p->nome;

                continue;
            }
            $contenuti[(int) $id] = [
                'anagrafica_id' => (int) $id,
                'nome'          => (string) $p->nome,
                'indirizzo'     => filled($p->indirizzo) ? (string) $p->indirizzo : null,
                'persona'       => $p,
                'totale'        => (int) array_sum(array_map(fn ($d) => (int) $d['residuo'] - $d['parte_in_accordo'], $rate)),
                'in_accordo'    => $inAccordo,
                'accordo_passato' => $passato['importo'] > 0 ? $passato : null,
                'credito'       => (int) ($r['credito'] ?? 0),
                'righe'         => array_map(fn (array $d) => array_filter([
                    'rata_id'      => (int) $d['rata_id'],
                    'immobile_id'  => $d['immobile_id'] === null ? null : (int) $d['immobile_id'],
                    'unita'        => $d['unita'],
                    'piano'        => $d['piano'],
                    'numero_rata'  => (int) $d['numero_rata'],
                    // La causale di un addebito a una persona sola: la lettera la scrive al posto di «Rata N».
                    'addebito'     => $d['addebito'] ?? null,
                    // Decisione 493 (12): il debito ereditato, con da chi e la successione; la rata è quella del defunto. Decisione 516: anche dopo
                    // l'emissione della rata nuova che lo porta, finché i suoi interessi corrono, con la rata nuova e la sua scadenza (la frase di «Rate scadute»).
                    'ereditato'    => isset($d['ereditato']) ? 'Ereditato ' . ($d['ereditato']['frase'] ?? sprintf('da %s, successione del %s', $d['ereditato']['da'],
                        \Carbon\CarbonImmutable::parse($d['ereditato']['successione_del'])->format('d/m/Y'))) : null,
                    'scadenza'     => $d['scadenza'],
                    // Il residuo della rata, come la lettura: la guardia alla partenza lo rilegge. Nella lettera c'è quello che resta fuori
                    // dall'accordo (`da_versare`), con la parte in accordo a parte.
                    'residuo'      => (int) $d['residuo'],
                    'in_accordo'   => $d['parte_in_accordo'] > 0 ? $d['parte_in_accordo'] : null,
                    'da_versare'   => $d['parte_in_accordo'] > 0 ? (int) $d['residuo'] - $d['parte_in_accordo'] : null,
                    'giorni_ritardo' => $d['giorni_ritardo'],
                    'ritardo'      => $tipo === 'promemoria' ? null : self::ritardo($d),
                ], fn ($v, $k) => $v !== null || in_array($k, ['immobile_id', 'unita', 'giorni_ritardo', 'ritardo'], true), ARRAY_FILTER_USE_BOTH), $rate),
            ];
        }
        uasort($contenuti, fn ($a, $b) => [mb_strtolower($a['nome']), $a['anagrafica_id']] <=> [mb_strtolower($b['nome']), $b['anagrafica_id']]);
        sort($soloInAccordo);
        // Gli interessi e le spese addebitati fra le righe della lettera, con la cifra che la lettera chiede, e il tasso delle regole del recupero di
        // oggi: i segnaposto della lettera. Stanno nel suo totale.
        $accessori = \App\Services\Gestionale\Accessori\AccessoriDellePersone::nelleRighe((int) $condominio->id, array_map(fn (array $p) => array_map(fn (array $r) => [
            'rata_id' => $r['rata_id'], 'immobile_id' => $r['immobile_id'] ?? null, 'importo' => (int) ($r['da_versare'] ?? $r['residuo']),
        ], $p['righe']), $contenuti));
        $tasso = self::tassoDellaLettera($condominio);
        foreach ($contenuti as $id => $p) {
            $contenuti[$id]['accessori'] = ($accessori[$id] ?? ['interessi' => 0, 'spese' => 0]) + ['tasso' => $tasso];
        }

        return ['persone' => $contenuti, 'aggiunte' => $aggiunte, 'uscite' => $uscite, 'senza_niente' => $senzaNiente, 'solo_in_accordo' => $soloInAccordo];
    }

    /**
     * Il segnaposto `[tasso]` di oggi: «1,60 % annuo, tasso legale», «3,00 % annuo, art. 12 del regolamento»; senza il tasso legale dell'anno nella
     * tabella, «il tasso legale dell'anno».
     */
    private static function tassoDellaLettera(Condominio $condominio): string
    {
        $regole = (new \App\Services\Gestionale\Accessori\RegoleDelRecupero())->delCondominio((int) $condominio->id);

        return \App\Services\Gestionale\Accessori\RegoleDelRecupero::tassoPerLaLettera($regole, DateHelper::oggiUtente()) ?? 'il tasso legale dell\'anno';
    }

    /** Il ritardo di una rata nella lettera, sotto «Ritardo»: «N giorni», o per un pregresso senza un numero di quale esercizio è. */
    public static function ritardo(array $rata): string
    {
        if ($rata['giorni_ritardo'] === null) {
            // Al condòmino si dice di quando è il debito, mai lo stato interno dell'origine («non dichiarata», «prima del primo esercizio»).
            $pregresso = (array) ($rata['pregresso'] ?? []);

            return ($pregresso['origine'] ?? null) === \App\Services\Gestionale\Debiti\OriginePregresso::ESERCIZIO
                ? \App\Services\Gestionale\Debiti\OriginePregresso::etichetta($pregresso)
                : 'pregresso di esercizi precedenti';
        }

        // Sotto l'intestazione «Ritardo»: i giorni, e basta.
        return $rata['giorni_ritardo'] . ' ' . ((int) $rata['giorni_ritardo'] === 1 ? 'giorno' : 'giorni');
    }

    /**
     * Il canale proposto per ogni persona: quello della sua ultima lettera (promemoria o sollecito; per diffida e messa in mora l'ultima diffida o messa in
     * mora), se oggi c'è ancora; senza lettere, l'email per il promemoria e il sollecito se la persona ha la primaria o la secondaria fra i tipi scelti; poi,
     * per sollecito, diffida e messa in mora, la PEC dalla casella dello studio a chi l'ha comunicata (decisione 588, L1); altrimenti la stampa. Mai la PEC
     * senza la PEC della persona né senza la casella dello studio ({@see canaliSpenti()}).
     *
     * @param array<int, array<string, mixed>> $contenuti
     * @param array<int, string> $tipiScelti
     * @return array<int, string>
     */
    public function canaliProposti(Condominio $condominio, string $tipo, array $contenuti, array $tipiScelti): array
    {
        $simili = in_array($tipo, self::TIPI_SENZA_EMAIL, true) ? self::TIPI_SENZA_EMAIL : ['promemoria', 'sollecito'];
        $ultime = RegistroInvio::where('condominio_id', $condominio->id)->whereIn('anagrafica_id', array_keys($contenuti) ?: [0])->whereIn('tipo', $simili)
            ->whereIn('stato', [...RegistroInvio::STATI_LETTERA_USCITA, RegistroInvio::STATO_IN_CODA])
            ->orderBy('id')->get(['id', 'anagrafica_id', 'canale', 'dati->formato as formato', 'dati->pec_e_carta as pec_e_carta'])
            ->groupBy('anagrafica_id')->map(fn ($g) => $g->last());
        $casella = app(CasellaPec::class)->impostata();

        $canali = [];
        foreach ($contenuti as $id => $c) {
            $spenti = self::canaliSpenti($tipo, $c['persona'], $casella);
            $ammessi = array_values(array_diff(self::CANALI_PER_TIPO[$tipo], array_keys($spenti)));
            // Gli indirizzi dell'email dei tipi spuntati: la primaria e la secondaria.
            $conEmail = array_intersect_key(EmissioneRicevute::indirizzi($c['persona']), array_flip($tipiScelti)) !== [];
            $ultima = $ultime->get($id);
            $daUltima = $ultima === null ? null : self::canaleDellaRiga($ultima->canale, $ultima->formato, $ultima->pec_e_carta);
            if ($daUltima !== null && in_array($daUltima, $ammessi, true) && ($daUltima !== 'email' || $conEmail)) {
                $canali[$id] = $daUltima;

                continue;
            }
            $pecComunicata = in_array('pec', $ammessi, true) && filled($c['persona']->pec_comunicata_il ?? null);
            $canali[$id] = match (true) {
                $conEmail && in_array('email', $ammessi, true) => 'email',
                $pecComunicata => 'pec',
                default => 'stampa',
            };
        }

        return $canali;
    }

    /**
     * I canali che la persona non può avere per questo tipo, con il perché: la PEC senza la casella dello studio, o senza la PEC nella sua scheda.
     *
     * @return array<string, string>
     */
    public static function canaliSpenti(string $tipo, Anagrafica $persona, bool $casella): array
    {
        if (! in_array('pec', self::CANALI_PER_TIPO[$tipo] ?? [], true)) {
            return [];
        }
        $perche = ! $casella ? PecDelloStudio::NON_IMPOSTATA : (! filled($persona->pec) ? self::SENZA_PEC : null);

        return $perche === null ? [] : array_fill_keys(self::CANALI_CON_LA_PEC, $perche);
    }

    /** Il canale della finestra di una riga del registro: l'email, la PEC, la stampa, il PDF per persona, «PEC e carta». */
    private static function canaleDellaRiga(string $canale, mixed $formato, mixed $pecECarta): string
    {
        if (in_array(self::testoJson($pecECarta), ['1', 'true'], true)) {
            return 'pec_e_carta';
        }

        return match ($canale) {
            RegistroInvio::CANALE_EMAIL => 'email',
            RegistroInvio::CANALE_PEC => 'pec',
            default => self::testoJson($formato) === 'zip' ? 'zip' : 'stampa',
        };
    }

    /** Un valore letto dal JSON con `->`: su MySQL arriva con le virgolette, su SQLite senza. */
    private static function testoJson(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        $v = (string) $v;

        return str_starts_with($v, '"') ? (string) json_decode($v) : $v;
    }

    /**
     * L'anteprima di chi riceve che cosa: per persona il totale, quante rate, il credito accanto, il canale proposto (o quello scelto nella
     * finestra) con i canali del tipo e quelli spenti con il perché, gli indirizzi dell'email dei tipi scelti, la PEC e se la persona l'ha comunicata, se manca
     * l'indirizzo di casa; i conteggi per canale; chi della lista non ha niente da scrivere; i dati dello studio; il server di posta; la casella PEC; i tetti.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function anteprima(Condominio $condominio, array $input): array
    {
        $tipo = is_string($input['tipo'] ?? null) && in_array($input['tipo'], self::TIPI, true) ? $input['tipo'] : throw new LetteraNonEmettibileException(self::TIPO_CHE_NON_C_E);
        $modello = ModelliLettere::scelto($condominio, $tipo, $input['modello_id'] ?? null);
        $tipi = self::indirizziScelti($input);
        $gestione = $this->gestioneScelta($condominio, $input);
        $c = $this->contenutiPer($condominio, $tipo, array_diff_key($input, ['viste' => true, 'in_anteprima' => true]), $gestione['id'] ?? null);
        $canali = $this->canaliScelti($condominio, $tipo, $c['persone'], $tipi, $input);
        $casella = self::casellaPec();
        // La PEC dello stesso tipo già partita oggi: non parte due volte, e l'anteprima lo dice accanto alla persona.
        $partiteOggi = in_array('pec', self::CANALI_PER_TIPO[$tipo], true) ? $this->pecDiOggi($condominio, $tipo, array_keys($c['persone'])) : [];

        $persone = [];
        foreach ($c['persone'] as $id => $p) {
            $tutti = EmissioneRicevute::indirizzi($p['persona']);
            $scelti = array_intersect_key($tutti, array_flip($tipi));
            $pec = filled($p['persona']->pec) ? (string) $p['persona']->pec : null;
            $soloPec = $scelti === [] && $pec !== null;
            $persone[] = [
                'id'          => $id,
                'nome'        => $p['nome'],
                'totale'      => $p['totale'],
                'in_accordo'  => $p['in_accordo'],
                // Un accordo di rientro con «fino al» passato: le sue rate sono nella lettera, e l'amministratore la toglie se non deve partire.
                'accordo_passato' => $p['accordo_passato'],
                'righe'       => count($p['righe']),
                'credito'     => $p['credito'],
                // I segnaposto della persona: gli interessi e le spese addebitati fra le righe della lettera.
                'interessi'   => (int) ($p['accessori']['interessi'] ?? 0),
                'spese'       => (int) ($p['accessori']['spese'] ?? 0),
                'canale'      => $canali[$id],
                'canali'      => self::CANALI_PER_TIPO[$tipo],
                // La PEC senza la casella dello studio, o senza la PEC nella scheda: la voce del menu c'è, spenta, con il perché.
                'canali_spenti' => self::canaliSpenti($tipo, $p['persona'], $casella['impostata']),
                'indirizzi'   => collect($scelti)->map(fn ($indirizzo, $t) => ['tipo' => $t, 'indirizzo' => $indirizzo])->values()->all(),
                'senza_email' => $scelti === [],
                'solo_pec'    => $soloPec,
                // Senza la casella, a chi non apre le impostazioni la frase dice di chiederla.
                'frase_solo_pec' => $soloPec && $tipo !== 'promemoria' ? ($casella['impostata'] ? self::SOLO_PEC_CON_LA_CASELLA
                    : ($casella['dove'] !== null ? self::SOLO_PEC_SENZA_CASELLA : self::SOLO_PEC_CHIEDI_LA_CASELLA)) : null,
                // La PEC della persona, e il giorno in cui l'ha comunicata: senza, la riga lo dice se la PEC è scelta (decisione 588, L1). Avvisa, non blocca.
                'pec'         => $pec,
                'pec_comunicata_il' => $pec !== null && filled($p['persona']->pec_comunicata_il) ? \Carbon\CarbonImmutable::parse($p['persona']->pec_comunicata_il)->format('Y-m-d') : null,
                'pec_non_comunicata' => $pec !== null && ! filled($p['persona']->pec_comunicata_il),
                // L'ora della PEC dello stesso tipo già partita oggi: «Manda N PEC» la salta.
                'pec_partita_oggi' => isset($partiteOggi[$id]) ? $partiteOggi[$id]->emesso_il->copy()->setTimezone(self::fuso())->format('H:i') : null,
                // La scheda della persona, dove si scrive il giorno in cui ha comunicato la sua PEC: a chi la può modificare.
                'scheda'      => auth()->user()?->can('update', $p['persona']) ? route('admin.anagrafiche.edit', $id) : null,
                'senza_indirizzo_postale' => $p['indirizzo'] === null,
            ];
        }
        $conteggi = array_fill_keys(['email', 'pec', 'pec_e_carta', 'stampa', 'zip'], 0);
        foreach ($canali as $canale) {
            $conteggi[$canale]++;
        }

        return [
            'tipo'               => $tipo,
            // Il modello dello studio da cui è partito il testo, se c'è.
            'modello'            => $modello,
            'alla'               => DateHelper::oggiUtente(),
            'gestione'           => $gestione,
            'persone'            => $persone,
            'conteggi'           => $conteggi,
            'messaggi'           => array_sum(array_map(fn ($p) => $p['canale'] === 'email' ? count($p['indirizzi']) : 0, $persone)),
            'credito'            => array_values(array_map(fn ($p) => ['nome' => $p['nome'], 'credito' => $p['credito']], array_filter($persone, fn ($p) => $p['credito'] > 0))),
            'senza_niente'       => $c['senza_niente'],
            // Chi ha solo rate in un accordo di rientro: non è nella lettera, se l'amministratore non le rimette.
            'solo_in_accordo'    => $c['solo_in_accordo'],
            'anche_in_accordo'   => self::ancheInAccordo($input),
            // Per `[interessi]` e `[spese]`: chi non ne ha fra le righe della lettera riceve «€ 0,00», e la finestra lo dice.
            'interessi_a_zero'   => count(array_filter($persone, fn ($p) => $p['interessi'] === 0)),
            'spese_a_zero'       => count(array_filter($persone, fn ($p) => $p['spese'] === 0)),
            'tasso'              => self::tassoDellaLettera($condominio),
            'studio_compilato'   => self::studio()['nome'] !== null,
            // Le lettere escono su una carta intestata che si legge: lo studio lo porta la carta, e la frase della finestra lo dice.
            'carta_intestata'    => self::cartaIntestata(),
            'senza_server_posta' => EmissioneRicevute::senzaServerPosta(),
            'casella_pec'        => $casella,
            'tetto_messaggi'     => EmissioneRicevute::tettoMessaggi(),
            'tetto_pec'          => self::TETTO_PEC,
            'tetto'              => self::TETTO_PDF,
            // Il tetto è del PDF: conta chi si stampa (anche «PEC e carta») o si scarica in un PDF per persona, non chi va per email o per PEC.
            'oltre_tetto'        => max($conteggi['stampa'] + $conteggi['pec_e_carta'], $conteggi['zip']) > self::TETTO_PDF,
        ];
    }

    /**
     * Il canale di ogni persona: quello scelto nella finestra (`canali`), se è uno dei canali di quel tipo e non è spento per lei, altrimenti quello proposto.
     *
     * @param array<int, array<string, mixed>> $contenuti
     * @return array<int, string>
     */
    private function canaliScelti(Condominio $condominio, string $tipo, array $contenuti, array $tipiScelti, array $input): array
    {
        $proposti = $this->canaliProposti($condominio, $tipo, $contenuti, $tipiScelti);
        $scelti = is_array($input['canali'] ?? null) ? $input['canali'] : [];
        $casella = $scelti === [] ? false : app(CasellaPec::class)->impostata();
        foreach ($proposti as $id => $canale) {
            $chiesto = $scelti[$id] ?? $scelti[(string) $id] ?? null;
            if (is_string($chiesto) && in_array($chiesto, self::CANALI_PER_TIPO[$tipo], true)
                && ! array_key_exists($chiesto, self::canaliSpenti($tipo, $contenuti[$id]['persona'], $casella))) {
                $proposti[$id] = $chiesto;
            }
        }

        return $proposti;
    }

    /**
     * Le persone su cui lavora un'azione: senza `canali` tutte quelle della selezione; con `canali`, quelle con uno dei canali dell'azione.
     *
     * @param array<int, array<string, mixed>> $contenuti
     * @param string|list<string> $canale
     * @return array<int, array<string, mixed>>
     */
    private function dellAzione(Condominio $condominio, string $tipo, array $contenuti, array $tipiScelti, array $input, string|array $canale): array
    {
        if (! is_array($input['canali'] ?? null)) {
            return $contenuti;
        }
        $canali = $this->canaliScelti($condominio, $tipo, $contenuti, $tipiScelti, $input);

        return array_filter($contenuti, fn ($c, $id) => in_array($canali[$id] ?? null, (array) $canale, true), ARRAY_FILTER_USE_BOTH);
    }

    /** Gli indirizzi dell'email scelti nella finestra: la primaria e la secondaria (la PEC non è un indirizzo dell'email). */
    private static function indirizziScelti(array $input): array
    {
        return array_values(array_intersect(array_keys(self::INDIRIZZI_EMAIL), array_map('strval', array_filter((array) ($input['indirizzi'] ?? EmissioneRicevute::TIPI_PROPOSTI), 'is_scalar'))));
    }

    // ── La stampa e il PDF per persona ───────────────────────────────────

    /**
     * La stampa: un PDF con una lettera per pagina nuova, nell'ordine dei nomi, con l'indirizzo per la busta a finestra; una riga «stampata»
     * per lettera, con il PDF conservato per sollecito, diffida e messa in mora. Con `$anteprima` lo stesso PDF con «ANTEPRIMA» in testa, e
     * niente nel registro.
     *
     * @param array<string, mixed> $input
     * @return array{pdf: string, nome: string, aggiunte: int, invii: array<int, int>} `invii`: riga del registro => persona
     */
    public function stampa(Condominio $condominio, array $input, ?User $utente, bool $anteprima = false): array
    {
        $scelte = $this->scelte($condominio, $input);
        $c = $this->contenutiPer($condominio, $scelte['tipo'], $input, $scelte['gestione']['id'] ?? null);
        // «Vedi il PDF» mostra tutte le lettere dell'anteprima, qualunque sia il canale; la stampa solo quelle da stampare, anche la copia di «PEC e carta».
        $persone = $anteprima ? $c['persone'] : $this->dellAzione($condominio, $scelte['tipo'], $c['persone'], $scelte['tipi_scelti'], $input, self::CANALI_CON_LA_STAMPA);
        $conLaPec = $anteprima || ! is_array($input['canali'] ?? null) ? [] : array_keys(array_filter($this->canaliScelti($condominio, $scelte['tipo'], $c['persone'],
            $scelte['tipi_scelti'], $input), fn ($canale) => $canale === 'pec_e_carta'));
        $this->dentroIlTettoDelPdf($persone, $anteprima ? 'anteprima' : 'stampa');
        $adesso = now();

        $voci = [];
        if ($anteprima) {
            foreach ($persone as $p) {
                $voci[] = ['etichetta' => self::ETICHETTA_ANTEPRIMA] + self::voce($condominio, $this->datiDellaLettera($condominio, $p, $scelte, $adesso), $scelte['tipo'], $adesso);
            }

            return ['pdf' => $this->rendi($condominio, $voci), 'nome' => self::nomeFile($condominio, $scelte['tipo'], 'anteprima'), 'aggiunte' => $c['aggiunte'], 'invii' => []];
        }

        $invii = [];
        // Una closure con `use (&…)`, non una funzione freccia: la freccia copia le variabili, e le righe scritte (`invii`, per «Registra la spedizione» subito
        // dalla finestra) non tornerebbero fuori.
        $pdf = $this->conIFile(function () use ($condominio, $persone, $scelte, $utente, $adesso, $conLaPec, &$voci, &$invii) {
            return DB::transaction(function () use ($condominio, $persone, $scelte, $utente, $adesso, $conLaPec, &$voci, &$invii) {
                $ids = [];
                foreach ($persone as $id => $p) {
                    // «PEC e carta»: la copia su carta della stessa lettera, con la riga gemella della PEC se è già partita oggi.
                    [$righe] = in_array($id, $conLaPec, true)
                        ? $this->scriviLaCopiaSuCarta($condominio, $p, $scelte, $utente, $adesso)
                        : $this->scriviLettera($condominio, $p, $scelte, $utente, $adesso, 'stampa');
                    $voci[] = $this->voceDallaRiga($righe[0]);
                    $ids = [...$ids, ...array_map(fn ($r) => $r->id, $righe)];
                    $invii[(int) $righe[0]->id] = (int) $p['anagrafica_id'];
                }
                $pdf = $this->rendi($condominio, $voci);
                if (! in_array($scelte['tipo'], self::TIPI_CONSERVATI, true)) {
                    RegistroInvio::whereIn('id', $ids ?: [0])->update(['impronta' => hash('sha256', $pdf)]);
                }

                return $pdf;
            });
        });

        return ['pdf' => $pdf, 'nome' => self::nomeFile($condominio, $scelte['tipo']), 'aggiunte' => $c['aggiunte'], 'invii' => $invii];
    }

    /**
     * «Scarica un PDF per persona»: un .zip con la lettera di ogni persona (il PDF conservato, per i tipi che lo conservano) e l'elenco degli
     * indirizzi, la PEC compresa; una riga «stampata» per lettera. È il canale della diffida e della messa in mora mandate per PEC fuori dal
     * programma. Lo .zip si scrive a pezzi ({@see ArchivioAPezzi}): ogni PDF va sul disco appena è fatto, e la memoria tiene una lettera alla volta
     * anche con la carta intestata, che ogni PDF porta.
     *
     * @param array<string, mixed> $input
     * @return array{zip: string, nome: string, aggiunte: int, invii: array<int, int>} `zip`: il percorso del file temporaneo dello .zip, che chi lo
     *     manda cancella
     */
    public function archivioPerPersona(Condominio $condominio, array $input, ?User $utente): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new LetteraNonEmettibileException('Su questo server PHP non ha l\'estensione zip: l\'archivio non si può fare. Stampa le lettere, '
                . 'o chiedi a chi gestisce l\'installazione di attivare l\'estensione.');
        }
        $scelte = $this->scelte($condominio, $input);
        $c = $this->contenutiPer($condominio, $scelte['tipo'], $input, $scelte['gestione']['id'] ?? null);
        $persone = $this->dellAzione($condominio, $scelte['tipo'], $c['persone'], $scelte['tipi_scelti'], $input, 'zip');
        $this->dentroIlTettoDelPdf($persone, 'zip');
        $adesso = now();
        $archivio = new ArchivioAPezzi(LetteraNonEmettibileException::class);

        try {
            return $this->conIFile(fn () => DB::transaction(function () use ($condominio, $persone, $scelte, $utente, $adesso, $c, $archivio) {
                $usati = [mb_strtolower(InviiPerPersona::FILE_DEGLI_INDIRIZZI)];
                $elenco = [];
                $invii = [];
                foreach ($persone as $p) {
                    [$righe, $pdf] = $this->scriviLettera($condominio, $p, $scelte, $utente, $adesso, 'zip');
                    $invii[(int) $righe[0]->id] = (int) $p['anagrafica_id'];
                    if ($pdf === null) {
                        $pdf = $this->rendi($condominio, [$this->voceDallaRiga($righe[0])]);
                        $righe[0]->update(['impronta' => hash('sha256', $pdf)]);
                    }
                    $nome = InviiPerPersona::nomeLibero(EmissioneRicevute::nomeFileDellaPersona($p['nome']), $usati) . '.pdf';
                    $archivio->aggiungi($nome, $pdf);
                    unset($pdf);
                    $elenco[] = ['nome' => $p['nome'], 'indirizzi' => EmissioneRicevute::indirizzi($p['persona']), 'documenti' => 1, 'file' => $nome];
                }
                $archivio->aggiungi(InviiPerPersona::FILE_DEGLI_INDIRIZZI, InviiPerPersona::elencoDegliIndirizzi(self::NOMI_PLURALI[$scelte['tipo']], (string) $condominio->nome,
                    $elenco, $adesso, fn (int $n) => $n === 1 ? '1 lettera' : $n . ' lettere', EmissioneRicevute::TIPI_INDIRIZZO, self::COME_SPEDIRE_I_PDF));

                return [
                    'zip'      => $archivio->scrivi(),
                    'nome'     => Str::replaceLast('.pdf', '.zip', self::nomeFile($condominio, $scelte['tipo'], 'per-persona')),
                    'aggiunte' => $c['aggiunte'],
                    'invii'    => $invii,
                ];
            }));
        } catch (\Throwable $e) {
            $archivio->togli();

            throw $e;
        }
    }

    // ── L'invio per PEC ──────────────────────────────────────────────────

    /**
     * «Manda le PEC» (decisioni 569 e 588): per ogni persona dell'anteprima con il canale «PEC dello studio» o «PEC e carta», la lettera di oggi parte dalla
     * casella PEC dello studio con lo stesso servizio della convocazione ({@see InvioPerPec}): il PDF della lettera (la vista di sempre, con la carta delle
     * lettere), l'oggetto e il testo dell'email, una riga `pec` con il PDF conservato e la sua impronta. La PEC parte solo verso la PEC della persona: per la
     * PEC non c'è un altro indirizzo. Una PEC che il gestore non accetta resta «non partita» con l'errore; un collegamento che non parte ferma le altre. Al più
     * {@see TETTO_PEC} per volta, a persone intere (le altre tornano in `restanti`, per «Manda le altre»); un invio per volta per condominio ({@see conIlBlocco()}).
     * Con «PEC e carta» la lettera è la stessa della copia su carta già stampata oggi, se c'è ed è la stessa lettera: lo stesso PDF, lo stesso `dati.lettera`.
     *
     * **Nessuna PEC due volte**: chi ha già oggi una PEC dello stesso tipo partita (o in partenza) non la riceve di nuovo, anche dopo un collegamento caduto e
     * l'anteprima rifatta; la frase lo dice (`gia_partite`). Dopo un collegamento caduto **chi non è stato provato** torna in `restanti` con chi è oltre il
     * tetto, per «Manda le altre». Un errore che non è del trasporto (il PDF, un indirizzo scritto male) è una riga «non partita» con la frase.
     *
     * @param array<string, mixed> $input
     * @return array{partite: int, non_partite: int, non_provate: int, frase: string, errore: ?string, restanti: ?array{persone: list<int>}, gia_partite: list<string>, aggiunte: int, uscite: int}
     */
    public function mandaPerPec(Condominio $condominio, array $input, User $utente): array
    {
        $scelte = $this->scelte($condominio, $input);
        if (! in_array('pec', self::CANALI_PER_TIPO[$scelte['tipo']], true)) {
            throw new LetteraNonEmettibileException(self::PROMEMORIA_NON_PER_PEC);
        }
        if (! app(CasellaPec::class)->impostata()) {
            throw new LetteraNonEmettibileException(PecDelloStudio::NON_IMPOSTATA);
        }
        $c = $this->contenutiPer($condominio, $scelte['tipo'], $input, $scelte['gestione']['id'] ?? null);
        $canali = $this->canaliScelti($condominio, $scelte['tipo'], $c['persone'], $scelte['tipi_scelti'], $input);
        $persone = array_filter($c['persone'], fn ($p, $id) => in_array($canali[$id] ?? null, self::CANALI_CON_LA_PEC, true) && filled($p['persona']->pec),
            ARRAY_FILTER_USE_BOTH);
        if ($persone === []) {
            throw new LetteraNonEmettibileException(self::NESSUNA_PEC);
        }

        return self::conIlBlocco($condominio, function () use ($condominio, $persone, $scelte, $canali, $utente, $c) {
            // Dentro il blocco: chi ha già oggi una PEC dello stesso tipo partita o in partenza non la riceve di nuovo.
            $giaOggi = $this->pecDiOggi($condominio, $scelte['tipo'], array_keys($persone));
            $gia = array_intersect_key($persone, $giaOggi);
            [$dentro, $fuori] = self::dentroIlTettoDellaPec(array_diff_key($persone, $giaOggi));
            $adesso = now();
            [$partite, $nonPartite, $provate, $errore] = [0, 0, [], null];
            foreach ($dentro as $id => $p) {
                try {
                    $riga = $this->mandaUnaPec($condominio, $p, $scelte, $utente, $adesso, $canali[$id] === 'pec_e_carta');
                } catch (CasellaPecNonImpostata) {
                    $errore = PecDelloStudio::NON_IMPOSTATA;

                    break;
                }
                $provate[] = (int) $id;
                if ($riga->stato === RegistroInvio::STATO_INVIATA) {
                    $partite++;

                    continue;
                }
                $nonPartite++;
                $errore = ErroreDellaPec::frase($riga->errore, app(CasellaPec::class)->configurazione());
                // Il collegamento non parte: gli altri non si provano (la porta chiusa li farebbe aspettare tutti).
                if (ErroreDellaPec::diConnessione($riga->errore)) {
                    break;
                }
            }
            // Chi non è stato provato (dopo un collegamento caduto) torna con chi è oltre il tetto: «Manda le altre» manda solo loro.
            $nonProvate = array_values(array_diff(array_map('intval', array_keys($dentro)), $provate));
            $restanti = [...$nonProvate, ...$fuori];
            $frase = $dentro === [] ? '' : self::fraseDellePec($partite, count($dentro) - $partite, $errore);

            return ['partite' => $partite, 'non_partite' => $nonPartite, 'non_provate' => count($nonProvate),
                'frase' => trim($frase . ' ' . self::fraseGiaPartite($gia, $giaOggi)), 'errore' => $errore,
                'restanti' => $restanti === [] ? null : ['persone' => $restanti], 'gia_partite' => array_values(array_map(fn ($p) => (string) $p['nome'], $gia)),
                'aggiunte' => $c['aggiunte'], 'uscite' => $c['uscite']];
        });
    }

    /**
     * Le PEC dello stesso tipo partite oggi (o in partenza) per le persone date, nel calendario dell'amministratore: una per persona, l'ultima. Non quelle che
     * il gestore non ha consegnato.
     *
     * @param list<int> $persone
     * @return array<int, RegistroInvio>
     */
    private function pecDiOggi(Condominio $condominio, string $tipo, array $persone): array
    {
        $inizio = \Illuminate\Support\Carbon::parse(DateHelper::oggiUtente(), self::fuso())->startOfDay()->utc();

        return RegistroInvio::where('condominio_id', $condominio->id)->whereIn('anagrafica_id', $persone ?: [0])->where('tipo', $tipo)
            ->where('canale', RegistroInvio::CANALE_PEC)->whereIn('stato', [RegistroInvio::STATO_INVIATA, RegistroInvio::STATO_IN_CODA])
            ->where(fn ($q) => $q->whereNull('esito')->orWhere('esito', '!=', RegistroInvio::ESITO_NON_CONSEGNATA))
            ->where('emesso_il', '>=', $inizio)->where('emesso_il', '<', $inizio->copy()->addDay())->orderBy('id')->get()
            ->keyBy(fn (RegistroInvio $r) => (int) $r->anagrafica_id)->all();
    }

    /**
     * «A Ugo è già partita oggi alle 11:00: non parte due volte nello stesso giorno, la trovi nella sua corrispondenza.»; con più persone i nomi, senza l'ora.
     *
     * @param array<int, array<string, mixed>> $gia
     * @param array<int, RegistroInvio> $righe
     */
    private static function fraseGiaPartite(array $gia, array $righe): string
    {
        if ($gia === []) {
            return '';
        }
        $nomi = array_values(array_map(fn ($p) => (string) $p['nome'], $gia));
        if (count($nomi) === 1) {
            $id = (int) array_key_first($gia);

            return 'A ' . $nomi[0] . ' è già partita oggi alle ' . $righe[$id]->emesso_il->copy()->setTimezone(self::fuso())->format('H:i')
                . ': non parte due volte nello stesso giorno, la trovi nella sua corrispondenza.';
        }

        return 'A ' . implode(', ', array_slice($nomi, 0, -1)) . ' e ' . end($nomi) . ' è già partita oggi: non parte due volte nello stesso giorno, la trovi '
            . 'nella loro corrispondenza.';
    }

    /**
     * «Manda di nuovo per PEC, con le cifre di oggi»: una lettera per PEC che non è partita, che il gestore non ha consegnato (l'avviso di mancata consegna) o
     * che si è fermata in partenza ({@see ConsegnaDellaPec::ferma()}) si rifà come «Invia di nuovo» dell'email, in una riga nuova, alla PEC che la persona ha
     * oggi nella sua scheda. Non per una PEC partita, e non se dopo la persona ha già un'altra lettera dello stesso tipo uscita o in coda.
     *
     * @return array{partite: int, non_partite: int, frase: string, errore: ?string, conti_cambiati: list<array{prima: string, ora: ?string}>}
     */
    public function mandaDiNuovoPerPec(Condominio $condominio, RegistroInvio $riga, User $utente): array
    {
        if (! self::daRimandarePerPec($riga)) {
            throw new LetteraNonEmettibileException(self::SOLO_PEC_NON_PARTITE);
        }
        if (! app(CasellaPec::class)->impostata()) {
            throw new LetteraNonEmettibileException(PecDelloStudio::NON_IMPOSTATA);
        }
        $this->contiCambiati = [];
        $nuova = self::conIlBlocco($condominio, fn () => $this->rifaiPerPec($condominio, $riga, $utente));
        $partita = $nuova->stato === RegistroInvio::STATO_INVIATA;
        $errore = $partita ? null : ErroreDellaPec::frase($nuova->errore, app(CasellaPec::class)->configurazione());

        return ['partite' => $partita ? 1 : 0, 'non_partite' => $partita ? 0 : 1, 'frase' => self::fraseDellePec($partita ? 1 : 0, $partita ? 0 : 1, $errore),
            'errore' => $errore, 'conti_cambiati' => $this->contiCambiati];
    }

    /** Una riga per PEC che si manda di nuovo: non partita, non consegnata, o ferma in partenza. */
    public static function daRimandarePerPec(RegistroInvio $riga): bool
    {
        return $riga->canale === RegistroInvio::CANALE_PEC && in_array($riga->tipo, self::TIPI, true)
            && ($riga->stato === RegistroInvio::STATO_NON_PARTITA || \App\Services\Posta\ConsegnaDellaPec::nonConsegnata($riga)
                || \App\Services\Posta\ConsegnaDellaPec::ferma($riga));
    }

    /**
     * La lettera di una riga per PEC rifatta con le cifre di oggi e mandata, in una riga nuova; senza il blocco, che tiene chi chiama.
     *
     * @throws LetteraNonEmettibileException|CasellaPecNonImpostata
     */
    private function rifaiPerPec(Condominio $condominio, RegistroInvio $riga, User $utente): RegistroInvio
    {
        $sue = RegistroInvio::where('condominio_id', $condominio->id)->where('anagrafica_id', $riga->anagrafica_id)->where('tipo', $riga->tipo)->get();
        if (RegistroLettere::superata($riga, $sue)) {
            throw new LetteraNonEmettibileException(self::GIA_RIFATTA);
        }
        [$scelte, $p, $persona] = $this->letteraDaRifare($condominio, $riga, null);
        if (! filled($persona->pec)) {
            throw new LetteraNonEmettibileException($persona->nome . ' non ha più la PEC nella sua scheda: stampala per la posta.');
        }

        return $this->mandaUnaPec($condominio, $p, $scelte, $utente, now(), false);
    }

    /**
     * Le lettere per PEC da rimandare: le righe `pec` non partite o non consegnate, dopo le quali la persona non ha un'altra lettera dello stesso tipo uscita o
     * in coda. Le contano le rate scadute, accanto alle email da rifare.
     *
     * @return Collection<int, RegistroInvio>
     */
    public function pecDaRimandare(Condominio $condominio, ?int $personaId = null): Collection
    {
        $tutte = RegistroInvio::where('condominio_id', $condominio->id)->whereIn('tipo', self::TIPI)->whereNotNull('anagrafica_id')
            ->when($personaId !== null, fn ($q) => $q->where('anagrafica_id', $personaId))->orderBy('id')->get();

        return $tutte->filter(fn (RegistroInvio $r) => $r->canale === RegistroInvio::CANALE_PEC
            && ($r->stato === RegistroInvio::STATO_NON_PARTITA || \App\Services\Posta\ConsegnaDellaPec::nonConsegnata($r)))
            ->reject(fn (RegistroInvio $r) => RegistroLettere::superata($r, $tutte))->values();
    }

    /**
     * «Rimanda le PEC non partite»: ogni lettera da rimandare ({@see pecDaRimandare()}) rifatta con le cifre di oggi e mandata dalla casella dello studio, al
     * più {@see TETTO_PEC} per volta; un collegamento che non parte ferma le altre. Quelle che non si rifanno (la persona non ha più niente da versare, la data
     * del termine è passata, la PEC tolta dalla scheda) restano, e la frase le conta.
     *
     * @return array{partite: int, non_partite: int, non_provate: int, saltate: int, restanti: int, frase: string, errore: ?string}
     */
    public function rimandaLePec(Condominio $condominio, User $utente): array
    {
        $righe = $this->pecDaRimandare($condominio);
        if ($righe->isEmpty()) {
            throw new LetteraNonEmettibileException(self::NESSUNA_PEC_DA_RIMANDARE);
        }
        if (! app(CasellaPec::class)->impostata()) {
            throw new LetteraNonEmettibileException(PecDelloStudio::NON_IMPOSTATA);
        }
        $this->contiCambiati = [];

        return self::conIlBlocco($condominio, function () use ($condominio, $righe, $utente) {
            $dentro = $righe->take(self::TETTO_PEC);
            [$partite, $nonPartite, $provate, $saltate, $errore] = [0, 0, 0, 0, null];
            foreach ($dentro as $riga) {
                try {
                    $nuova = $this->rifaiPerPec($condominio, $riga, $utente);
                } catch (LetteraNonEmettibileException) {
                    $provate++;
                    $saltate++;

                    continue;
                } catch (CasellaPecNonImpostata) {
                    $errore = PecDelloStudio::NON_IMPOSTATA;

                    break;
                }
                $provate++;
                if ($nuova->stato === RegistroInvio::STATO_INVIATA) {
                    $partite++;

                    continue;
                }
                $nonPartite++;
                $errore = ErroreDellaPec::frase($nuova->errore, app(CasellaPec::class)->configurazione());
                if (ErroreDellaPec::diConnessione($nuova->errore)) {
                    break;
                }
            }
            $restanti = $righe->count() - $provate;
            $frase = self::fraseDellePec($partite, $nonPartite + $saltate, $errore);
            if ($saltate > 0) {
                $frase .= ' ' . ($saltate === 1 ? '1 lettera non si rifà' : $saltate . ' lettere non si rifanno') . ': la persona non ha più niente da versare, la '
                    . 'data del termine è passata o la PEC non è più nella sua scheda. Le trovi nella sua corrispondenza.';
            }
            if ($restanti > 0) {
                $frase .= ' ' . ($restanti === 1 ? 'Ne resta 1: premi di nuovo.' : 'Ne restano ' . $restanti . ': premi di nuovo.');
            }

            return ['partite' => $partite, 'non_partite' => $nonPartite, 'non_provate' => $dentro->count() - $provate, 'saltate' => $saltate,
                'restanti' => $restanti, 'frase' => $frase, 'errore' => $errore, 'conti_cambiati' => $this->contiCambiati];
        });
    }

    /**
     * «Stampale per la posta»: le lettere per PEC da rimandare ({@see pecDaRimandare()}), o quelle date dalla corrispondenza, rifatte con le cifre di oggi e
     * stampate in un PDF, una riga «stampata» per lettera (poi «Registra la spedizione»). Una lettera «PEC e carta» che ha già la copia su carta non si stampa
     * di nuovo; quelle che non si rifanno restano, e l'intestazione le conta.
     *
     * @param ?list<int> $invii
     * @return array{pdf: string, nome: string, invii: array<int, int>, saltate: int}
     */
    public function stampaLePec(Condominio $condominio, ?array $invii, ?User $utente): array
    {
        $righe = $this->pecDaRimandare($condominio);
        if ($invii !== null) {
            $chieste = app(RegistroLettere::class)->righeDelCondominio($condominio, $invii);
            if ($chieste->contains(fn (RegistroInvio $r) => ! $righe->contains('id', $r->id))) {
                throw new LetteraNonEmettibileException(self::SOLO_PEC_DA_RIMANDARE);
            }
            $righe = $righe->whereIn('id', $chieste->pluck('id')->all())->values();
        }
        $conLaCopia = RegistroInvio::where('condominio_id', $condominio->id)->where('canale', RegistroInvio::CANALE_STAMPA)
            ->whereIn('anagrafica_id', $righe->pluck('anagrafica_id')->unique()->values()->all() ?: [0])->get()
            ->map(fn (RegistroInvio $r) => (string) ($r->dati['lettera'] ?? ''))->filter()->flip();
        $righe = $righe->reject(fn (RegistroInvio $r) => $conLaCopia->has((string) ($r->dati['lettera'] ?? '')))->values();
        if ($righe->isEmpty()) {
            throw new LetteraNonEmettibileException(self::NESSUNA_PEC_DA_STAMPARE);
        }
        if ($righe->count() > self::TETTO_PDF) {
            $righe = $righe->take(self::TETTO_PDF);
        }
        $this->contiCambiati = [];
        $voci = [];
        $scritte = [];
        $saltate = 0;
        $pdf = $this->conIFile(function () use ($condominio, $righe, $utente, &$voci, &$scritte, &$saltate) {
            return DB::transaction(function () use ($condominio, $righe, $utente, &$voci, &$scritte, &$saltate) {
                $adesso = now();
                foreach ($righe as $riga) {
                    try {
                        [$scelte, $p] = $this->letteraDaRifare($condominio, $riga, null);
                    } catch (LetteraNonEmettibileException) {
                        $saltate++;

                        continue;
                    }
                    [$nuove] = $this->scriviLettera($condominio, $p, $scelte, $utente, $adesso, 'stampa');
                    $voci[] = $this->voceDallaRiga($nuove[0]);
                    $scritte[(int) $nuove[0]->id] = (int) $p['anagrafica_id'];
                }
                if ($voci === []) {
                    throw new LetteraNonEmettibileException(self::NESSUNA_PEC_DA_STAMPARE);
                }

                return $this->rendi($condominio, $voci);
            });
        });

        return ['pdf' => $pdf, 'nome' => self::nomeFile($condominio, (string) $righe->first()->tipo, 'per-la-posta'), 'invii' => $scritte, 'saltate' => $saltate];
    }

    /**
     * Le persone di un invio per PEC dentro il tetto ({@see TETTO_PEC}), e gli identificativi di quelle che restano.
     *
     * @param array<int, array<string, mixed>> $persone
     * @return array{0: array<int, array<string, mixed>>, 1: list<int>}
     */
    public static function dentroIlTettoDellaPec(array $persone): array
    {
        return [array_slice($persone, 0, self::TETTO_PEC, true), array_map('intval', array_keys(array_slice($persone, self::TETTO_PEC, null, true)))];
    }

    /**
     * Un invio per PEC per volta per condominio: un secondo clic, o una seconda scheda, trova il blocco e lo dice, invece di mandare le stesse lettere due volte
     * mentre il primo invio lavora. Lo stesso blocco della convocazione, con la chiave sua.
     *
     * @template T
     * @param \Closure(): T $lavoro
     * @return T
     */
    public static function conIlBlocco(Condominio $condominio, \Closure $lavoro): mixed
    {
        $blocco = Cache::lock(self::chiaveDelBlocco($condominio), self::DURATA_DEL_BLOCCO);
        if (! $blocco->get()) {
            throw new LetteraNonEmettibileException(self::PEC_GIA_IN_CORSO);
        }
        try {
            return $lavoro();
        } finally {
            $blocco->release();
        }
    }

    public static function chiaveDelBlocco(Condominio $condominio): string
    {
        return 'lettere:pec:' . $condominio->id;
    }

    /** «2 PEC sono partite: il gestore le ha accettate, la consegna la dice la ricevuta.», con le altre e il perché: le parole della convocazione. */
    public static function fraseDellePec(int $partite, int $altre, ?string $errore): string
    {
        $frase = match (true) {
            $partite === 0 => 'Nessuna PEC è partita.',
            $partite === 1 => '1 PEC è partita: il gestore l\'ha accettata, la consegna la dice la ricevuta.',
            default => $partite . ' PEC sono partite: il gestore le ha accettate, la consegna la dice la ricevuta.',
        };
        if ($errore === null) {
            return $frase;
        }

        return $partite === 0 ? $errore . ' ' . $frase
            : $frase . ' ' . ($altre === 1 ? 'L\'altra non è partita: ' : 'Le altre ' . $altre . ' non sono partite: ') . lcfirst($errore);
    }

    /**
     * Una lettera per PEC a una persona: la sua riga `pec`, partita o no. Con «PEC e carta» e una copia su carta già stampata oggi della stessa lettera, la
     * lettera è quella: gli stessi dati e lo stesso PDF (letto dal file conservato), quindi la stessa impronta.
     *
     * @param array<string, mixed> $p il contenuto della lettera della persona
     * @throws CasellaPecNonImpostata
     */
    private function mandaUnaPec(Condominio $condominio, array $p, array $scelte, User $utente, CarbonInterface $adesso, bool $conLaCarta): RegistroInvio
    {
        $dati = null;
        try {
            $dati = $this->datiDellaLettera($condominio, $p, $scelte, $adesso);
            // La gemella solo se è la stessa lettera: con un altro testo, un altro termine o altre cifre la PEC parte con le scelte di adesso.
            $gemella = $conLaCarta ? $this->gemellaDiOggi($condominio, (int) $p['anagrafica_id'], $scelte['tipo'], RegistroInvio::CANALE_PEC, $dati) : null;
            if ($gemella !== null) {
                $dati = array_intersect_key((array) $gemella->dati, array_flip(self::CHIAVI_DELLA_LETTERA));
                $voce = $this->voceDallaRiga($gemella);
                $pdf = $this->pdfConservatoDi($gemella) ?? $this->rendi($condominio, [$voce]);
            } else {
                $voce = self::voce($condominio, $dati, $scelte['tipo'], $adesso);
                $pdf = $this->rendi($condominio, [$voce]);
            }
            // L'oggetto e il testo dell'email di oggi: la lettera è nel PDF.
            $messaggio = LetteraNotification::testo((string) $condominio->nome, $voce);
            $testo = implode("\n\n", [$messaggio['saluto'], ...$messaggio['righe'], $messaggio['chiusura']]);
        } catch (\Throwable $e) {
            // Il programma non è riuscito a preparare la PEC (il PDF, il testo): una riga «non partita» con l'errore, come quelle che il gestore rifiuta.
            Log::warning('PEC non preparata: ' . $e->getMessage(), ['persona' => $p['anagrafica_id']]);

            return $this->pecNonPreparata($condominio, $p, $scelte, $utente, $adesso, $dati, $conLaCarta, $e);
        }
        $documento = new DocumentoPerPec((int) $condominio->id, (int) $p['anagrafica_id'], (string) $p['persona']->pec, $scelte['tipo'], $p['persona'], $pdf,
            self::nomeFile($condominio, $scelte['tipo'], Str::slug($p['nome'])), $messaggio['oggetto'], $testo,
            $dati + ['tipo_indirizzo' => 'pec'] + ($conLaCarta ? ['pec_e_carta' => true] : []));

        return app(InvioPerPec::class)->invia($documento, $utente->id);
    }

    /** La riga di una PEC che il programma non è riuscito a preparare: «non partita», con l'errore, per mandarla di nuovo dalla corrispondenza. */
    private function pecNonPreparata(Condominio $condominio, array $p, array $scelte, User $utente, CarbonInterface $adesso, ?array $dati, bool $conLaCarta,
        \Throwable $e): RegistroInvio
    {
        return RegistroInvio::create(['condominio_id' => $condominio->id, 'anagrafica_id' => $p['anagrafica_id'], 'tipo' => $scelte['tipo'],
            'canale' => RegistroInvio::CANALE_PEC, 'documentabile_type' => Anagrafica::class, 'documentabile_id' => $p['anagrafica_id'],
            'destinatario' => (string) $p['persona']->pec, 'stato' => RegistroInvio::STATO_NON_PARTITA, 'emesso_da' => $utente->id, 'emesso_il' => $adesso,
            'errore' => mb_substr(ErroreDellaPec::PREPARAZIONE . ($e->getMessage() !== '' ? $e->getMessage() : $e::class), 0, 1000),
            'dati' => ($dati ?? ['lettera' => (string) Str::uuid(), 'persona' => ['id' => $p['anagrafica_id'], 'nome' => $p['nome']], 'modello' => $scelte['modello'],
                'termine' => $scelte['termine'], 'termine_forma' => $scelte['termine_forma'] ?? null, 'termine_data' => $scelte['termine_data'] ?? null])
                + ['tipo_indirizzo' => 'pec'] + ($conLaCarta ? ['pec_e_carta' => true] : [])]);
    }

    /**
     * «PEC e carta», la copia su carta: la riga `stampata` della stessa lettera della PEC partita oggi (gli stessi dati, lo stesso PDF conservato e la stessa
     * impronta), o una lettera nuova segnata «PEC e carta» se la PEC non è ancora partita: allora sarà la PEC a prendere questa.
     *
     * @return array{0: array<int, RegistroInvio>, 1: ?string}
     */
    private function scriviLaCopiaSuCarta(Condominio $condominio, array $p, array $scelte, ?User $utente, CarbonInterface $adesso): array
    {
        // La gemella solo se è la stessa lettera: con scelte nuove la copia su carta è una lettera nuova, e la PEC di prima resta la sua.
        $gemella = $this->gemellaDiOggi($condominio, (int) $p['anagrafica_id'], $scelte['tipo'], RegistroInvio::CANALE_STAMPA,
            $this->datiDellaLettera($condominio, $p, $scelte, $adesso));
        if ($gemella === null) {
            return $this->scriviLettera($condominio, $p, $scelte, $utente, $adesso, 'stampa', [], ['pec_e_carta' => true]);
        }
        $dati = array_intersect_key((array) $gemella->dati, array_flip(self::CHIAVI_DELLA_LETTERA)) + ['formato' => 'stampa', 'pec_e_carta' => true]
            + (isset($gemella->dati['documento_id']) ? ['documento_id' => $gemella->dati['documento_id']] : []);
        $riga = RegistroInvio::create(['condominio_id' => $condominio->id, 'anagrafica_id' => $p['anagrafica_id'], 'tipo' => $scelte['tipo'],
            'documentabile_type' => Anagrafica::class, 'documentabile_id' => $p['anagrafica_id'], 'emesso_da' => $utente?->id, 'emesso_il' => $adesso,
            'canale' => RegistroInvio::CANALE_STAMPA, 'stato' => RegistroInvio::STATO_STAMPATA, 'dati' => $dati, 'impronta' => $gemella->impronta]);

        return [[$riga], null];
    }

    /**
     * La riga gemella di «PEC e carta» emessa oggi per la persona e il tipo: della lettera segnata «PEC e carta» che non ha ancora la riga del canale che manca
     * (la stampa dopo la PEC, o la PEC dopo la stampa), **ed è la stessa lettera** di quella di adesso ({@see CHIAVI_DEL_CONTENUTO}). Null se non c'è.
     *
     * @param array<string, mixed> $adesso i dati della lettera con le scelte di adesso
     */
    private function gemellaDiOggi(Condominio $condominio, int $persona, string $tipo, string $manca, array $adesso): ?RegistroInvio
    {
        $inizio = \Illuminate\Support\Carbon::parse(DateHelper::oggiUtente(), self::fuso())->startOfDay()->utc();
        $righe = RegistroInvio::where('condominio_id', $condominio->id)->where('anagrafica_id', $persona)->where('tipo', $tipo)
            ->where('emesso_il', '>=', $inizio)->where('emesso_il', '<', $inizio->copy()->addDay())->orderByDesc('id')->get();
        foreach ($righe->groupBy(fn (RegistroInvio $r) => (string) ($r->dati['lettera'] ?? 'riga-' . $r->id)) as $sue) {
            if (empty($sue->first()->dati['pec_e_carta']) || $sue->contains(fn (RegistroInvio $r) => $r->canale === $manca)
                || ! self::stessaLettera((array) $sue->first()->dati, $adesso)) {
                continue;
            }

            return $sue->first();
        }

        return null;
    }

    /** Due lettere con lo stesso contenuto: le chiavi di {@see CHIAVI_DEL_CONTENUTO}, nell'ordine che sia. */
    private static function stessaLettera(array $a, array $b): bool
    {
        $chiavi = array_flip(self::CHIAVI_DEL_CONTENUTO);

        return self::inOrdine(array_intersect_key($a, $chiavi)) === self::inOrdine(array_intersect_key($b, $chiavi));
    }

    /** Un valore con le chiavi in ordine, in JSON: per confrontare due istantanee lette da strade diverse (il registro, la lettura di adesso). */
    private static function inOrdine(mixed $v): string
    {
        $ordina = function (mixed $x) use (&$ordina): mixed {
            if (! is_array($x)) {
                return $x;
            }
            ksort($x);

            return array_map($ordina, $x);
        };

        return (string) json_encode($ordina($v), JSON_UNESCAPED_UNICODE);
    }

    /** Il PDF conservato di una riga, letto dal disco: null se non c'è. */
    private function pdfConservatoDi(RegistroInvio $riga): ?string
    {
        $id = (int) ($riga->dati['documento_id'] ?? 0);
        $documento = $id === 0 ? null : \App\Models\Documento::find($id);

        return $documento === null ? null : $this->archivio->leggi($documento->path);
    }

    // ── L'invio per email ────────────────────────────────────────────────

    /**
     * L'invio per email (promemoria e sollecito): per ogni persona un messaggio per indirizzo dei tipi scelti, e, nella lettera di una persona
     * sola, un altro indirizzo scritto a mano (nel limite di dieci al minuto, che conta il controller). Chi non ha un indirizzo fra quelli
     * scelti resta da stampare per la posta. Con la coda `sync` (le email partono mentre la pagina aspetta) al massimo cinquanta messaggi per
     * volta, a persone intere; per sollecito, che conserva il PDF, al massimo {@see TETTO_PDF} lettere per volta con qualunque coda. Le altre
     * tornano in `restanti`, e la finestra le manda con «Invia le altre».
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> il riepilogo
     */
    public function invia(Condominio $condominio, array $input, ?User $utente): array
    {
        $scelte = $this->scelte($condominio, $input);
        if (in_array($scelte['tipo'], self::TIPI_SENZA_EMAIL, true)) {
            throw new LetteraNonEmettibileException(self::SOLO_CARTA);
        }
        self::conServerPosta();
        if ($scelte['tipi_scelti'] === [] && ! filled($input['altro_indirizzo'] ?? null)) {
            throw new LetteraNonEmettibileException('Scegli a quali indirizzi mandare le lettere: la primaria, la secondaria o tutte e due.');
        }
        $c = $this->contenutiPer($condominio, $scelte['tipo'], $input, $scelte['gestione']['id'] ?? null);
        $persone = $this->dellAzione($condominio, $scelte['tipo'], $c['persone'], $scelte['tipi_scelti'], $input, 'email');
        $altro = $this->altroIndirizzo($input, $persone);

        $gruppi = [];
        $senza = [];
        foreach ($persone as $id => $p) {
            $destinazioni = collect(EmissioneRicevute::indirizzi($p['persona']))->only($scelte['tipi_scelti'])->map(fn ($i, $t) => [$i, $t])->values()->all();
            if ($altro !== null) {
                $destinazioni[] = [$altro, 'altro'];
            }
            if ($destinazioni === []) {
                $senza[] = $p['nome'];

                continue;
            }
            $gruppi[] = ['persona' => $p, 'destinazioni' => $destinazioni];
        }
        // Il tetto: con la coda `sync` i messaggi per volta; per i tipi che conservano il PDF anche le lettere per volta (un PDF per lettera, fatto
        // nella richiesta), con qualunque coda: una richiesta troppo lunga morirebbe a metà, e un secondo clic rifarebbe le lettere già scritte.
        [$dentro, $fuori] = self::dentroIlTettoDellInvio($gruppi, $scelte['tipo']);
        $adesso = now();
        $messaggi = [];
        foreach ($dentro as $g) {
            [$righe] = $this->conIFile(fn () => DB::transaction(fn () => $this->scriviLettera($condominio, $g['persona'], $scelte, $utente, $adesso, 'email', $g['destinazioni'])));
            $messaggi = [...$messaggi, ...$this->spedisci($righe)];
        }

        return $this->riepilogo($messaggi, count($dentro)) + [
            'senza_indirizzo'  => $senza,
            'restanti'         => $fuori === null ? null : ['persone' => array_map(fn ($g) => (int) $g['persona']['anagrafica_id'], $fuori),
                'messaggi' => array_sum(array_map(fn ($g) => count($g['destinazioni']), $fuori))],
            'aggiunte'         => $c['aggiunte'],
            'uscite'           => $c['uscite'],
        ];
    }

    /**
     * Il tetto di un invio per email: i messaggi per volta quando le email partono mentre la pagina aspetta (la coda `sync`), e le lettere per
     * volta per i tipi che conservano il PDF, con qualunque coda ({@see TETTO_PDF}). Null dove non c'è.
     *
     * @return array{lettere: ?int, messaggi: ?int}
     */
    public static function tettoDellInvio(string $tipo): array
    {
        return ['lettere' => in_array($tipo, self::TIPI_CONSERVATI, true) ? self::TETTO_PDF : null, 'messaggi' => EmissioneRicevute::tettoMessaggi()];
    }

    /**
     * Le persone di un invio per email dentro il tetto ({@see tettoDellInvio()}), a persone intere, e quelle che restano (null se non ne resta
     * nessuna): prima i messaggi per volta, poi le lettere per volta.
     *
     * @param list<array{persona: array<string, mixed>, destinazioni: list<array{0: string, 1: string}>}> $gruppi
     * @return array{0: list<array<string, mixed>>, 1: ?list<array<string, mixed>>}
     */
    public static function dentroIlTettoDellInvio(array $gruppi, string $tipo): array
    {
        $tetto = self::tettoDellInvio($tipo);

        return InviiPerPersona::dentroIDueTetti($gruppi, $tetto['messaggi'], $tetto['lettere'], fn (array $g) => count($g['destinazioni']));
    }

    /**
     * «Mandane una a me»: la lettera della prima persona dell'invio, con «PROVA» in testa, all'indirizzo di chi lavora. Nessuna riga nel
     * registro degli invii (nel registro delle email sì, come ogni messaggio).
     *
     * @param array<string, mixed> $input
     * @return array{indirizzo: string, persona: string}
     */
    public function provaAMe(Condominio $condominio, array $input, User $utente): array
    {
        $scelte = $this->scelte($condominio, $input);
        self::conServerPosta();
        if (! filled($utente->email)) {
            throw new LetteraNonEmettibileException('Il tuo utente non ha un indirizzo email.');
        }
        $c = $this->contenutiPer($condominio, $scelte['tipo'], $input, $scelte['gestione']['id'] ?? null);
        $persone = in_array($scelte['tipo'], self::TIPI_SENZA_EMAIL, true) ? $c['persone'] : $this->dellAzione($condominio, $scelte['tipo'], $c['persone'], $scelte['tipi_scelti'], $input, 'email');
        $prima = reset($persone);
        if ($prima === false) {
            throw new LetteraNonEmettibileException(self::NESSUNA_PERSONA);
        }
        $adesso = now();
        $voce = ['etichetta' => self::ETICHETTA_PROVA] + self::voce($condominio, $this->datiDellaLettera($condominio, $prima, $scelte, $adesso), $scelte['tipo'], $adesso);
        try {
            Notification::route('mail', $utente->email)->notifyNow(LetteraNotification::prova((int) $condominio->id, $voce, $this->rendi($condominio, [$voce])));
        } catch (\Throwable $e) {
            $errore = ErroreDiPosta::daEccezione($e);
            throw new LetteraNonEmettibileException('La prova non è partita: ' . lcfirst((string) ErroreDiPosta::frase($errore)));
        }

        return ['indirizzo' => (string) $utente->email, 'persona' => $prima['nome']];
    }

    /**
     * Le lettere per email da rifare: per ogni persona, tipo e indirizzo l'ultima riga per email, se non è partita, e se dopo non c'è un'altra
     * lettera dello stesso tipo alla stessa persona uscita o in coda ({@see RegistroLettere::superata()}: le righe della stessa lettera, gli altri
     * suoi indirizzi, non contano). Quelle la cui persona oggi non ha più niente da scrivere in quella lettera sono a parte (`saldate`); fra
     * queste, i promemoria di chi intanto ha rate scadute (`promemoria_scaduti`): per loro c'è il sollecito. Il contenuto di oggi si legge una
     * volta per tipo, gestione e giorni (`contenuti`), e «riprova» lo riusa. Le lettere con la data del termine già passata non si rifanno: sono
     * contate a parte (`termine_passato`), fuori da quelle da rifare.
     *
     * @return array{da_rifare: Collection<int, RegistroInvio>, saldate: Collection<int, RegistroInvio>, promemoria_scaduti: int, contenuti: array<string, array<int, array<string, mixed>>>, termine_passato: int}
     */
    public function daRiprovare(Condominio $condominio, ?int $personaId = null): array
    {
        $tutte = RegistroInvio::where('condominio_id', $condominio->id)->whereIn('tipo', self::TIPI)->whereNotNull('anagrafica_id')
            ->when($personaId !== null, fn ($q) => $q->where('anagrafica_id', $personaId))
            ->orderBy('id')->get();
        $ultime = $tutte->where('canale', RegistroInvio::CANALE_EMAIL)
            ->groupBy(fn (RegistroInvio $r) => $r->anagrafica_id . '|' . $r->tipo . '|' . ($r->dati['tipo_indirizzo'] ?? 'altro') . '|'
                . (($r->dati['tipo_indirizzo'] ?? 'altro') === 'altro' ? $r->destinatario : ''))
            ->map(fn (Collection $g) => $g->last())
            ->filter(fn (RegistroInvio $r) => $r->stato === RegistroInvio::STATO_NON_PARTITA)
            ->reject(fn (RegistroInvio $r) => RegistroLettere::superata($r, $tutte))
            // Un'email non partita verso la PEC della persona non si rifà per email (decisione 588, L2): si manda per PEC dalla casella dello studio, o si stampa.
            ->reject(fn (RegistroInvio $r) => RegistroLettere::perEmailAllaPec($r))
            ->values();
        // Una lettera con la data del termine già passata non si rifà: la si prepara di nuovo dall'elenco, con un termine nuovo. Sta a parte, e
        // non conta fra quelle da riprovare.
        $oggi = DateHelper::oggiUtente();
        [$passate, $ultime] = $ultime->partition(fn (RegistroInvio $r) => TestiLettere::formaDelTermine((array) $r->dati) === 'data'
            && (string) ($r->dati['termine_data'] ?? '') <= $oggi);
        $ultime = $ultime->values();
        $terminePassato = $passate->map(fn (RegistroInvio $r) => self::chiaveDellaLettera($r))->unique()->count();
        if ($ultime->isEmpty()) {
            return ['da_rifare' => collect(), 'saldate' => collect(), 'promemoria_scaduti' => 0, 'contenuti' => [], 'termine_passato' => $terminePassato];
        }

        $contenuti = [];
        foreach ($ultime->groupBy(fn (RegistroInvio $r) => self::chiaveDelContenuto($r)) as $chiave => $righe) {
            $prima = $righe->first();
            $contenuti[$chiave] = $this->contenutiPer($condominio, $prima->tipo, ['persone' => $righe->pluck('anagrafica_id')->unique()->values()->all(),
                'giorni' => $prima->dati['giorni'] ?? null, 'anche_in_accordo' => ! empty($prima->dati['anche_in_accordo'])],
                isset($prima->dati['gestione_id']) ? (int) $prima->dati['gestione_id'] : null)['persone'];
        }
        [$da, $saldate] = $ultime->partition(fn (RegistroInvio $r) => isset($contenuti[self::chiaveDelContenuto($r)][$r->anagrafica_id]));

        // Un promemoria che non serve più perché le sue rate sono scadute: la persona deve ancora, e per lei c'è il sollecito.
        $promemoria = $saldate->where('tipo', 'promemoria');
        $scaduti = $promemoria->isEmpty() ? [] : array_keys($this->contenutiPer($condominio, 'sollecito',
            ['persone' => $promemoria->pluck('anagrafica_id')->unique()->values()->all()], null)['persone']);

        return ['da_rifare' => $da->values(), 'saldate' => $saldate->values(),
            'promemoria_scaduti' => $promemoria->filter(fn (RegistroInvio $r) => in_array((int) $r->anagrafica_id, $scaduti, true))->count(), 'contenuti' => $contenuti,
            'termine_passato' => $terminePassato];
    }

    /** Le lettere da rifare, contate come lettere (una lettera a due indirizzi è una). */
    public function lettereDaRiprovare(Condominio $condominio): int
    {
        return $this->daRiprovare($condominio)['da_rifare']->map(fn (RegistroInvio $r) => self::chiaveDellaLettera($r))->unique()->count();
    }

    /**
     * «Riprova le non partite»: rifà ogni lettera da rifare con le cifre di oggi, in righe nuove, agli stessi indirizzi (una lettera a due
     * indirizzi resta una lettera, con un PDF conservato); quelle di prima restano come sono. Quando le email partono subito (la coda `sync`), al
     * massimo {@see EmissioneRicevute::TETTO_MESSAGGI_SENZA_CODA} messaggi per volta, a lettere intere, e la risposta dice quante restano.
     *
     * @return array<string, mixed> il riepilogo, con `lettere` (le lettere rifatte), `saldate`, `promemoria_scaduti`, `restanti`
     */
    public function riprova(Condominio $condominio, ?int $personaId, ?User $utente): array
    {
        self::conServerPosta();
        $this->contiCambiati = [];
        $da = $this->daRiprovare($condominio, $personaId);
        $lettere = $da['da_rifare']->groupBy(fn (RegistroInvio $r) => self::chiaveDellaLettera($r))->values()->all();
        [$dentro, $fuori] = InviiPerPersona::dentroIlTetto($lettere, EmissioneRicevute::tettoMessaggi(), fn (Collection $righe) => $righe->count());
        // Rifare un sollecito rifà il suo PDF conservato: anche qui al massimo le lettere del tetto del PDF per volta.
        [$dentro, $oltre] = InviiPerPersona::dentroIlTetto($dentro, self::TETTO_PDF, fn (Collection $righe) => in_array($righe->first()->tipo, self::TIPI_CONSERVATI, true) ? 1 : 0);
        $fuori = $oltre === null ? $fuori : [...$oltre, ...($fuori ?? [])];
        $messaggi = [];
        $persone = [];
        $fatte = 0;
        $ferme = 0;
        foreach ($dentro as $righe) {
            // Un indirizzo scritto a mano conta nel limite di dieci al minuto: oltre, quell'indirizzo resta da riprovare.
            $righe = $righe->reject(function (RegistroInvio $r) use ($utente, &$ferme) {
                $fermo = LimiteAltroIndirizzo::aMano($r) && ! LimiteAltroIndirizzo::consuma($utente);
                $ferme += $fermo ? 1 : 0;

                return $fermo;
            });
            if ($righe->isEmpty()) {
                continue;
            }
            try {
                $messaggi = [...$messaggi, ...$this->rifai($condominio, $righe, $utente, $da['contenuti'])];
                $persone[$righe->first()->anagrafica_id] = true;
                $fatte++;
            } catch (LetteraNonEmettibileException) {
                continue;
            }
        }

        return $this->riepilogo($messaggi, count($persone)) + [
            'lettere'                => $fatte,
            'saldate'                => $da['saldate']->count(),
            'promemoria_scaduti'     => $da['promemoria_scaduti'],
            'limite_altro_indirizzo' => $ferme,
            'restanti'               => $fuori === null ? null : ['lettere' => count($fuori), 'messaggi' => array_sum(array_map(fn (Collection $g) => $g->count(), $fuori))],
            'conti_cambiati'         => $this->contiCambiati,
            // Le lettere non rifatte perché la data del termine è passata.
            'termine_passato'        => $da['termine_passato'],
        ];
    }

    /**
     * «Invia di nuovo» una lettera per email (promemoria e sollecito): rifatta con le cifre di oggi, allo stesso indirizzo, in una riga nuova.
     * Non per una riga ancora in coda (parte da sola), e non per una riga dopo la quale la persona ha già un'altra lettera dello stesso tipo
     * uscita o in coda: due clic, o due schede, non fanno due lettere.
     */
    public function inviaDiNuovo(Condominio $condominio, RegistroInvio $riga, ?User $utente): array
    {
        self::conServerPosta();
        if ($riga->canale !== RegistroInvio::CANALE_EMAIL || ! in_array($riga->tipo, self::TIPI, true) || in_array($riga->tipo, self::TIPI_SENZA_EMAIL, true)) {
            throw new LetteraNonEmettibileException('Si rimanda solo una lettera già mandata per email.');
        }
        if (RegistroLettere::perEmailAllaPec($riga)) {
            throw new LetteraNonEmettibileException(self::ERA_PER_EMAIL_ALLA_PEC);
        }
        if ($riga->stato === RegistroInvio::STATO_IN_CODA) {
            throw new LetteraNonEmettibileException(self::ANCORA_IN_CODA);
        }
        $sue = RegistroInvio::where('condominio_id', $condominio->id)->where('anagrafica_id', $riga->anagrafica_id)->where('tipo', $riga->tipo)->get();
        if (RegistroLettere::superata($riga, $sue)) {
            throw new LetteraNonEmettibileException(self::GIA_RIFATTA);
        }
        $this->contiCambiati = [];

        return $this->riepilogo($this->rifai($condominio, collect([$riga]), $utente), 1) + ['conti_cambiati' => $this->contiCambiati];
    }

    /**
     * Una lettera rifatta dalle sue righe (gli indirizzi da rifare della stessa lettera): lo stesso tipo, lo stesso modello del testo, lo stesso
     * termine, lo stesso conto, la stessa gestione, le cifre di oggi, gli stessi indirizzi (lo stesso tipo com'è oggi nell'anagrafica, o lo
     * stesso indirizzo scritto a mano). Con il contenuto di oggi già letto da {@see daRiprovare()}, non lo rilegge.
     *
     * @param Collection<int, RegistroInvio> $righe
     * @param array<string, array<int, array<string, mixed>>>|null $contenuti
     * @return array<int, array<int, int>> le righe di ogni messaggio
     */
    private function rifai(Condominio $condominio, Collection $righe, ?User $utente, ?array $contenuti = null): array
    {
        [$scelte, $p, $persona] = $this->letteraDaRifare($condominio, $righe->first(), $contenuti);
        $destinazioni = [];
        $senza = null;
        foreach ($righe as $r) {
            $tipo = (string) (($r->dati['tipo_indirizzo'] ?? null) ?: 'altro');
            // La PEC non è più un indirizzo dell'email: una lettera di prima andata anche alla PEC si rifà agli altri indirizzi.
            if ($tipo === 'pec') {
                continue;
            }
            $indirizzo = $tipo === 'altro' ? $r->destinatario : (EmissioneRicevute::indirizzi($persona)[$tipo] ?? null);
            if (filled($indirizzo)) {
                $destinazioni[] = [$indirizzo, $tipo];
            } else {
                $senza ??= $tipo;
            }
        }
        if ($destinazioni === []) {
            throw new LetteraNonEmettibileException($senza === null ? self::ERA_PER_EMAIL_ALLA_PEC
                : $persona->nome . ' non ha più l\'indirizzo ' . (EmissioneRicevute::TIPI_INDIRIZZO[$senza] ?? '') . ': stampala per la posta.');
        }
        [$nuove] = $this->conIFile(fn () => DB::transaction(fn () => $this->scriviLettera($condominio, $p, $scelte, $utente, now(), 'email', $destinazioni)));

        return $this->spedisci($nuove);
    }

    /**
     * Le scelte e il contenuto di oggi di una lettera da rifare dalla sua riga: lo stesso tipo, lo stesso modello del testo, lo stesso termine, lo stesso conto
     * (o quello solo di oggi, detto in `conti_cambiati`), la stessa gestione, le cifre di oggi. Per «Invia di nuovo», «Riprova le non partite» e «Manda di
     * nuovo per PEC».
     *
     * @param array<string, array<int, array<string, mixed>>>|null $contenuti
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: Anagrafica}
     */
    private function letteraDaRifare(Condominio $condominio, RegistroInvio $riga, ?array $contenuti): array
    {
        $dati = (array) $riga->dati;
        $persona = $riga->anagrafica;
        if ($persona === null) {
            throw new LetteraNonEmettibileException('La persona di questa lettera non c\'è più.');
        }
        $gestioneId = isset($dati['gestione_id']) ? (int) $dati['gestione_id'] : null;
        $conti = collect($this->conti($condominio))->keyBy('id');
        // Il conto della lettera di prima; se oggi non è più fra i conti (chiuso, o senza l'IBAN), il conto solo di oggi, o nessun IBAN con più
        // conti o senza: il programma non sceglie fra più conti. La risposta lo dice (`conti_cambiati`).
        $cassa = isset($dati['cassa_id']) ? $conti->get((int) $dati['cassa_id']) : null;
        if (isset($dati['cassa_id']) && $cassa === null) {
            $cassa = $conti->count() === 1 ? $conti->first() : null;
            $this->contiCambiati[] = ['prima' => (string) (Cassa::whereKey((int) $dati['cassa_id'])->value('nome') ?? ''), 'ora' => $cassa['nome'] ?? null];
        }
        // Il termine della lettera di prima; una data già passata non si rimanda.
        if (TestiLettere::formaDelTermine($dati) === 'data' && (string) ($dati['termine_data'] ?? '') <= DateHelper::oggiUtente()) {
            throw new LetteraNonEmettibileException(self::TERMINE_PASSATO);
        }
        // Il testo e il modello di allora, copiati: un modello cambiato o cancellato dopo non cambia la lettera rifatta. La riga nuova dice da quale
        // viene: non è una scelta, e non cambia il modello ricordato.
        $scelte = ['tipo' => $riga->tipo, 'modello' => (string) ($dati['modello'] ?? $dati['testo'] ?? ''),
            'modello_scelto' => isset($dati['modello_id']) ? ['id' => (int) $dati['modello_id'], 'nome' => (string) ($dati['modello_nome'] ?? '')] : null,
            'rifatta_da' => (int) $riga->id, 'singola' => ! empty($dati['singola']),
            'termine' => isset($dati['termine']) ? (int) $dati['termine'] : null,
            'termine_forma' => TestiLettere::formaDelTermine($dati), 'termine_data' => $dati['termine_data'] ?? null,
            'cassa' => $cassa, 'tipi_scelti' => (array) ($dati['tipi_scelti'] ?? []),
            'giorni' => (int) ($dati['giorni'] ?? ElencoRateScadute::GIORNI_DI_SERIE), 'gestione' => $gestioneId === null ? null : ['id' => $gestioneId, 'nome' => null],
            'anche_in_accordo' => ! empty($dati['anche_in_accordo'])];
        $p = $contenuti !== null && array_key_exists(self::chiaveDelContenuto($riga), $contenuti)
            ? ($contenuti[self::chiaveDelContenuto($riga)][$persona->id] ?? null)
            : ($this->contenutiPer($condominio, $riga->tipo, ['persone' => [$persona->id], 'giorni' => $dati['giorni'] ?? null,
                'anche_in_accordo' => ! empty($dati['anche_in_accordo'])], $gestioneId)['persone'][$persona->id] ?? null);
        if ($p === null) {
            throw new LetteraNonEmettibileException(self::NESSUNA_PERSONA);
        }

        return [$scelte, $p, $persona];
    }

    /** Una lettera è l'insieme delle sue righe (una per indirizzo): `dati.lettera`. */
    private static function chiaveDellaLettera(RegistroInvio $r): string
    {
        return (string) ($r->dati['lettera'] ?? 'riga-' . $r->id);
    }

    /** Il contenuto di oggi di una lettera dipende dal tipo, dalla gestione, per il promemoria dai giorni, e dalle rate in accordo rimesse. */
    private static function chiaveDelContenuto(RegistroInvio $r): string
    {
        return $r->tipo . '|' . ($r->dati['gestione_id'] ?? '') . '|' . ($r->dati['giorni'] ?? '') . '|' . (empty($r->dati['anche_in_accordo']) ? '' : '1');
    }

    /** Le rate in accordo rimesse nella lettera dall'amministratore: una scelta di questa emissione, mai ricordata. */
    private static function ancheInAccordo(array $input): bool
    {
        return filter_var($input['anche_in_accordo'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Un'operazione che scrive PDF conservati sul disco dentro una transazione: se non riesce, la transazione torna indietro e i file scritti
     * si tolgono (nessuno li vedrebbe, nessuno li toglierebbe, e lo spazio dei documenti non li conterebbe).
     */
    private function conIFile(callable $operazione): mixed
    {
        $prima = count($this->scritti);
        try {
            return $operazione();
        } catch (\Throwable $e) {
            foreach (array_slice($this->scritti, $prima) as $percorso) {
                $this->archivio->elimina($percorso);
            }
            array_splice($this->scritti, $prima);

            throw $e;
        }
    }

    // ── Interni ──────────────────────────────────────────────────────────

    /**
     * Le righe di una lettera: una per la stampa o il .zip, una per indirizzo per l'email; con il PDF conservato, fatto una volta, per i tipi
     * che lo conservano. Si chiama dentro una transazione: se il PDF non si salva, non resta niente.
     *
     * @param array<string, mixed> $persona il contenuto della lettera di una persona
     * @param array<int, array{0: string, 1: string}> $destinazioni [indirizzo, tipo]
     * @param array<string, mixed> $piu i dati in più della riga («PEC e carta»)
     * @return array{0: array<int, RegistroInvio>, 1: ?string} le righe e il PDF conservato
     */
    private function scriviLettera(Condominio $condominio, array $persona, array $scelte, ?User $utente, CarbonInterface $adesso, string $canale, array $destinazioni = [],
        array $piu = []): array
    {
        $dati = $this->datiDellaLettera($condominio, $persona, $scelte, $adesso) + $piu;
        $base = ['condominio_id' => $condominio->id, 'anagrafica_id' => $persona['anagrafica_id'], 'tipo' => $scelte['tipo'], 'documentabile_type' => Anagrafica::class,
            'documentabile_id' => $persona['anagrafica_id'], 'emesso_da' => $utente?->id, 'emesso_il' => $adesso];
        $righe = [];
        if ($canale === 'email') {
            foreach ($destinazioni as [$indirizzo, $tipo]) {
                $righe[] = RegistroInvio::create($base + ['canale' => RegistroInvio::CANALE_EMAIL, 'destinatario' => $indirizzo, 'stato' => RegistroInvio::STATO_IN_CODA,
                    'dati' => $dati + ['tipo_indirizzo' => $tipo, 'gettone' => (string) Str::uuid(), 'tipi_scelti' => $scelte['tipi_scelti']]]);
            }
        } else {
            $righe[] = RegistroInvio::create($base + ['canale' => RegistroInvio::CANALE_STAMPA, 'stato' => RegistroInvio::STATO_STAMPATA, 'dati' => $dati + ['formato' => $canale]]);
        }

        $pdf = null;
        if (in_array($scelte['tipo'], self::TIPI_CONSERVATI, true)) {
            $pdf = $this->rendi($condominio, [$this->voceDallaRiga($righe[0])]);
            $percorso = $this->archivio->salvaContenuto($pdf, 'pdf', ArchivioDocumenti::CARTELLA . '/' . $condominio->id . '/lettere');
            $this->scritti[] = $percorso;
            try {
                $documento = $righe[0]->documenti()->create([
                    'name' => self::nomeFile($condominio, $scelte['tipo'], Str::slug($persona['nome'])),
                    'description' => self::NOMI[$scelte['tipo']] . ' a ' . $persona['nome'] . ' del ' . $adesso->copy()->setTimezone(self::fuso())->format('d/m/Y'),
                    'path' => $percorso,
                    'mime_type' => 'application/pdf',
                    'file_size' => strlen($pdf),
                    'created_by' => $utente?->id,
                    'is_published' => false,
                    'is_approved' => true,
                ]);
            } catch (\Throwable $e) {
                $this->archivio->elimina($percorso);

                throw $e;
            }
            foreach ($righe as $r) {
                $r->update(['impronta' => hash('sha256', $pdf), 'dati' => (array) $r->dati + ['documento_id' => $documento->id]]);
            }
        }

        return [$righe, $pdf];
    }

    /**
     * L'istantanea della lettera di una persona, come la scrive il registro: la persona com'era, la data della situazione, il testo con i campi
     * (`modello`) e quello riempito, il numero e il nome del modello dello studio se c'era, le righe con il residuo di allora, il totale, il
     * termine, il conto.
     *
     * @return array<string, mixed>
     */
    private function datiDellaLettera(Condominio $condominio, array $persona, array $scelte, CarbonInterface $adesso): array
    {
        $dati = [
            'lettera' => (string) Str::uuid(),
            'persona' => ['id' => $persona['anagrafica_id'], 'nome' => $persona['nome'], 'indirizzo' => $persona['indirizzo']],
            'alla'    => DateHelper::oggiUtente(),
            'modello' => $scelte['modello'],
            // Il modello dello studio da cui è partito il testo: il numero e il nome di allora.
            'modello_id'   => $scelte['modello_scelto']['id'] ?? null,
            'modello_nome' => $scelte['modello_scelto']['nome'] ?? null,
            // La lettera di una persona, e la riga da cui una lettera rifatta viene: tutte e due non cambiano il modello ricordato.
            'singola'      => ! empty($scelte['singola']) ? true : null,
            'rifatta_da'   => $scelte['rifatta_da'] ?? null,
            'testo'   => TestiLettere::riempi($scelte['modello'], $persona['nome'], $persona['totale'], TestiLettere::termineNelTesto(['termine_forma' => $scelte['termine_forma'] ?? null,
                'termine' => $scelte['termine'], 'termine_data' => $scelte['termine_data'] ?? null]), (string) $condominio->nome, $persona['accessori'] ?? []),
            'righe'   => $persona['righe'],
            'totale'  => $persona['totale'],
            // La parte delle rate in accordo di rientro lasciata fuori dalla lettera, e la scelta di rimetterla.
            'in_accordo' => ($persona['in_accordo'] ?? 0) > 0 ? (int) $persona['in_accordo'] : null,
            'anche_in_accordo' => ! empty($scelte['anche_in_accordo']) ? true : null,
            'termine' => $scelte['termine'],
            'termine_forma' => $scelte['termine_forma'] ?? null,
            'termine_data'  => $scelte['termine_data'] ?? null,
            'giorni'  => $scelte['tipo'] === 'promemoria' ? $scelte['giorni'] : null,
            // La gestione scelta nell'elenco: la lettera rifatta la tiene.
            'gestione_id' => $scelte['gestione']['id'] ?? null,
        ];
        if ($scelte['cassa'] !== null) {
            $dati += ['cassa_id' => $scelte['cassa']['id'], 'iban' => $scelte['cassa']['iban'], 'intestatario' => $scelte['cassa']['intestatario']];
        }
        // I segnaposto degli interessi, delle spese e del tasso riempiti, come erano il giorno della lettera, se il testo li usa.
        if (preg_match('/\[(interessi|spese|tasso)\]/', (string) $scelte['modello']) === 1 && isset($persona['accessori'])) {
            $dati['segnaposti'] = $persona['accessori'];
        }

        return array_filter($dati, fn ($v) => $v !== null);
    }

    /** I messaggi di una lettera per email: uno per riga (un indirizzo), con il gettone che lega la riga al registro delle email. */
    private function spedisci(array $righe): array
    {
        $messaggi = [];
        foreach ($righe as $r) {
            try {
                $notifica = new LetteraNotification([$r->id]);
                $notifica->id = (string) $r->dati['gettone'];
                Notification::route('mail', $r->destinatario)->notify($notifica);
            } catch (\Throwable $e) {
                Log::warning('Lettera non partita: ' . $e->getMessage(), ['invio' => $r->id]);
                RegistroInvio::whereKey($r->id)->where('stato', RegistroInvio::STATO_IN_CODA)->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => ErroreDiPosta::daEccezione($e)]);
            }
            $messaggi[] = [$r->id];
        }

        return $messaggi;
    }

    /** @param array<int, array<int, int>> $messaggi */
    private function riepilogo(array $messaggi, int $persone): array
    {
        $ids = array_merge([], ...$messaggi);
        $scritte = RegistroInvio::whereIn('id', $ids ?: [0])->get(['id', 'stato', 'errore'])->keyBy('id');
        $stato = fn (array $m) => $scritte[$m[0]]->stato ?? null;

        return [
            'persone'     => $persone,
            'messaggi'    => count($messaggi),
            'inviate'     => count(array_filter($messaggi, fn ($m) => $stato($m) === RegistroInvio::STATO_INVIATA)),
            'in_coda'     => count(array_filter($messaggi, fn ($m) => $stato($m) === RegistroInvio::STATO_IN_CODA)),
            'non_partite' => count(array_filter($messaggi, fn ($m) => $stato($m) === RegistroInvio::STATO_NON_PARTITA)),
            'errori'      => $scritte->pluck('errore')->filter()->unique()->take(3)->map(fn (string $e) => ErroreDiPosta::frase($e))->unique()->values()->all(),
            'tetto_messaggi' => EmissioneRicevute::tettoMessaggi(),
        ];
    }

    /** L'altro indirizzo scritto a mano: solo nella lettera di una persona, e un indirizzo vero. */
    private function altroIndirizzo(array $input, array $persone): ?string
    {
        $altro = $input['altro_indirizzo'] ?? null;
        if ($altro === null || (is_string($altro) && trim($altro) === '')) {
            return null;
        }
        if (! is_string($altro)) {
            throw new LetteraNonEmettibileException('L\'indirizzo email deve essere un testo.');
        }
        if (count($persone) !== 1 || ! empty($input['tutte'])) {
            throw new LetteraNonEmettibileException(self::ALTRO_SOLO_PER_UNA);
        }
        $altro = trim($altro);
        if (filter_var($altro, FILTER_VALIDATE_EMAIL) === false) {
            throw new LetteraNonEmettibileException('«' . $altro . '» non è un indirizzo email.');
        }

        return $altro;
    }

    /**
     * Il tetto di un PDF: conta le lettere dell'azione (quelle da stampare, o da scaricare in un PDF per persona), non quelle per email. Oltre,
     * la frase dice come fare il resto: le persone di una pagina dell'elenco, poi la pagina dopo. Non il filtro «nessuna lettera negli ultimi N
     * giorni»: una lettera stampata o scaricata conta per quel filtro solo con la spedizione registrata, e riproporrebbe le stesse persone. E dice
     * che, registrata la spedizione con un filtro sulle lettere, chi l'ha avuta esce e le altre ricominciano dalla pagina 1: la pagina dopo
     * salterebbe delle persone.
     */
    private function dentroIlTettoDelPdf(array $persone, string $azione = 'stampa'): void
    {
        if ($persone === []) {
            throw new LetteraNonEmettibileException(self::NESSUNA_PERSONA);
        }
        if (count($persone) > self::TETTO_PDF) {
            $quali = ['stampa' => 'da stampare', 'zip' => 'da scaricare', 'anteprima' => 'dell\'anteprima'][$azione] ?? 'da stampare';
            // Per lo .zip ogni PDF ha una lettera: il tetto è dell'archivio, non del PDF.
            $dove = $azione === 'zip' ? 'in un .zip' : 'in un PDF';
            throw new LetteraNonEmettibileException('Le lettere ' . $quali . ' sono ' . count($persone) . ': ' . $dove . ' ne stanno al massimo ' . self::TETTO_PDF
                . '. ' . self::OLTRE_IL_TETTO);
        }
    }

    /**
     * La voce della vista di una lettera dalla sua riga del registro: quello che la lettera diceva quando è stata emessa.
     *
     * @return array<string, mixed>
     */
    public function voceDallaRiga(RegistroInvio $riga): array
    {
        return self::voce($riga->condominio ?? Condominio::find($riga->condominio_id), (array) $riga->dati, $riga->tipo, $riga->emesso_il);
    }

    /** @return array<string, mixed> */
    private static function voce(?Condominio $condominio, array $dati, string $tipo, CarbonInterface $emessa): array
    {
        return [
            'tipo'         => $tipo,
            'titolo'       => self::NOMI[$tipo] ?? $tipo,
            'persona'      => (array) ($dati['persona'] ?? []),
            'testo'        => (string) ($dati['testo'] ?? ''),
            'righe'        => (array) ($dati['righe'] ?? []),
            'totale'       => (int) ($dati['totale'] ?? 0),
            'termine'      => isset($dati['termine']) ? (int) $dati['termine'] : null,
            // La riga a parte del termine: solo se il testo non usa [termine].
            'riga_del_termine' => TestiLettere::rigaDelTermine($dati),
            'iban'         => $dati['iban'] ?? null,
            'intestatario' => $dati['intestatario'] ?? null,
            'alla'         => (string) ($dati['alla'] ?? DateHelper::oggiUtente()),
            'emessa_il'    => $emessa->copy()->setTimezone(self::fuso())->format('d/m/Y'),
            'luogo'        => self::luogo($condominio),
            'etichetta'    => null,
        ];
    }

    /**
     * I dati della vista della lettera: il condominio, il suo nome sotto il titolo (con «Condominio» davanti una volta sola), le lettere, il giorno
     * per il piede, il gruppo della carta. Lo studio lo dà la carta delle lettere ({@see CartaDiStampa}).
     *
     * @param array<int, array<string, mixed>> $voci
     * @return array<string, mixed>
     */
    public function datiDellaVista(Condominio $condominio, array $voci): array
    {
        return ['condominio' => $condominio, 'condominio_nel_titolo' => TestiLettere::condominioConNome((string) $condominio->nome), 'lettere' => $voci,
            'data_emissione_stampe' => now()->setTimezone(self::fuso()), 'gruppo_carta' => GruppoCarta::LETTERE];
    }

    /** @param array<int, array<string, mixed>> $voci */
    private function rendi(Condominio $condominio, array $voci): string
    {
        $mpdf = $this->pdf->generate('pdf.gestionale.lettera', $this->datiDellaVista($condominio, $voci), ['margin_top' => 14]);
        $pdf = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        unset($mpdf);
        gc_collect_cycles();

        return $pdf;
    }

    /** Il nome del file di una lettera: il tipo, il condominio, il giorno (della lettera, se si dà; altrimenti oggi), il suffisso. */
    public static function nomeFile(Condominio $condominio, string $tipo, ?string $suffisso = null, ?string $giorno = null): string
    {
        return PdfService::nomeFile(Str::slug(self::NOMI[$tipo] ?? $tipo), (string) $condominio->nome, $giorno ?? DateHelper::oggiUtente(), $suffisso);
    }

    /** Il giorno di una lettera, nel calendario dell'amministratore. */
    public static function giornoDellaLettera(RegistroInvio $riga): string
    {
        return $riga->emesso_il->copy()->setTimezone(self::fuso())->format('Y-m-d');
    }

    /** @return list<int> */
    private static function identificativi(mixed $valori): array
    {
        return array_values(array_unique(array_map('intval', array_filter((array) $valori, 'is_numeric'))));
    }

    private static function conServerPosta(): void
    {
        if (EmissioneRicevute::senzaServerPosta()) {
            throw new LetteraNonEmettibileException(self::SENZA_SERVER_POSTA);
        }
    }

    private static function fuso(): string
    {
        return (string) config('app.user_timezone', 'Europe/Rome');
    }
}
