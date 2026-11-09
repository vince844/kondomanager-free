<?php

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
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
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
 * - **Il canale** di ogni persona si propone dalla sua ultima lettera; senza lettere, l'email se ne ha una (primaria o secondaria), altrimenti la
 *   stampa. Si cambia persona per persona. Diffida e messa in mora non partono per email dal programma: stampa o un PDF per persona (.zip), poi
 *   la spedizione registrata a mano.
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

    /** I tipi che il programma non manda per email: si stampano o si scaricano, poi si spediscono. */
    public const TIPI_SOLO_CARTA = ['diffida', 'messa_in_mora'];

    /** I tipi di cui si conserva il PDF partito. */
    public const TIPI_CONSERVATI = ['sollecito', 'diffida', 'messa_in_mora'];

    public const CANALI = ['email', 'stampa', 'zip'];

    public const TETTO_PDF = 200;

    public const ETICHETTA_ANTEPRIMA = 'ANTEPRIMA';
    public const ETICHETTA_PROVA = 'PROVA';

    public const SOLO_CARTA = 'Diffida e messa in mora non partono per email dal programma: stampale o scarica un PDF per persona, spediscile, poi registra la spedizione.';
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
            'tipi'               => collect(self::NOMI)->map(fn ($nome, $tipo) => ['tipo' => $tipo, 'nome' => $nome, 'solo_carta' => in_array($tipo, self::TIPI_SOLO_CARTA, true),
                'conservato' => in_array($tipo, self::TIPI_CONSERVATI, true)])->values()->all(),
            // I testi del programma: non si cambiano. Per diffida e messa in mora la finestra dice di controllarli.
            'testi'              => TestiLettere::DI_SERIE,
            'modelli'            => $this->modelli->delCondominio($condominio),
            // L'ultimo modello di ogni tipo nel condominio, dalla riga dell'ultima lettera di quel tipo.
            'modelli_ricordati'  => $this->modelli->ricordati($condominio),
            'campi'              => TestiLettere::CAMPI,
            'conti'              => $this->conti($condominio),
            'scelta_ricordata'   => $this->sceltaRicordata($condominio, $utente),
            'studio'             => self::studio(),
            'studio_compilato'   => self::studio()['nome'] !== null,
            // Le lettere escono su una carta intestata che si legge: lo studio lo porta la carta, e la frase della finestra lo dice.
            'carta_intestata'    => self::cartaIntestata(),
            'senza_server_posta' => EmissioneRicevute::senzaServerPosta(),
            'tetto'              => self::TETTO_PDF,
        ];
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

        return [
            'termini'   => $termini,
            'cassa_id'  => $cassa === null ? null : (int) $cassa,
            'indirizzi' => $sue()->whereNotNull('dati->tipi_scelti')->first()?->dati['tipi_scelti'] ?? null,
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

        $tipi = array_values(array_intersect(array_keys(EmissioneRicevute::TIPI_INDIRIZZO), array_map('strval', array_filter((array) ($input['indirizzi'] ?? EmissioneRicevute::TIPI_PROPOSTI), 'is_scalar'))));

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
        if (in_array($tipo, self::TIPI_SOLO_CARTA, true) && $modello === '') {
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

        return ['persone' => $contenuti, 'aggiunte' => $aggiunte, 'uscite' => $uscite, 'senza_niente' => $senzaNiente, 'solo_in_accordo' => $soloInAccordo];
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
     * Il canale proposto per ogni persona: quello della sua ultima lettera (promemoria o sollecito; per diffida e messa in mora l'ultima diffida
     * o messa in mora, fra stampa e .zip); senza lettere, l'email se la persona ha la primaria o la secondaria fra i tipi scelti, altrimenti la
     * stampa.
     *
     * @param array<int, array<string, mixed>> $contenuti
     * @param array<int, string> $tipiScelti
     * @return array<int, string>
     */
    public function canaliProposti(Condominio $condominio, string $tipo, array $contenuti, array $tipiScelti): array
    {
        $soloCarta = in_array($tipo, self::TIPI_SOLO_CARTA, true);
        $simili = $soloCarta ? self::TIPI_SOLO_CARTA : ['promemoria', 'sollecito'];
        $ultime = RegistroInvio::where('condominio_id', $condominio->id)->whereIn('anagrafica_id', array_keys($contenuti) ?: [0])->whereIn('tipo', $simili)
            ->whereIn('stato', [...RegistroInvio::STATI_LETTERA_USCITA, RegistroInvio::STATO_IN_CODA])
            ->orderBy('id')->get(['id', 'anagrafica_id', 'canale', 'dati->formato as formato'])
            ->groupBy('anagrafica_id')->map(fn ($g) => $g->last());

        $canali = [];
        foreach ($contenuti as $id => $c) {
            // Gli indirizzi dei tipi spuntati: la primaria e la secondaria, come la ricevuta, e la PEC solo se l'amministratore l'ha spuntata (chi
            // ha solo la PEC, senza la PEC spuntata, è proposto per la stampa, con la frase).
            $conEmail = array_intersect_key(EmissioneRicevute::indirizzi($c['persona']), array_flip($tipiScelti)) !== [];
            $ultima = $ultime->get($id);
            $daUltima = $ultima === null ? null : ($ultima->canale === RegistroInvio::CANALE_EMAIL ? 'email' : ($ultima->formato === 'zip' ? 'zip' : 'stampa'));
            $canale = $daUltima ?? ($conEmail ? 'email' : 'stampa');
            if ($canale === 'email' && ($soloCarta || ! $conEmail)) {
                $canale = 'stampa';
            }
            $canali[$id] = $canale;
        }

        return $canali;
    }

    /**
     * L'anteprima di chi riceve che cosa: per persona il totale, quante rate, il credito accanto, il canale proposto (o quello scelto nella
     * finestra), gli indirizzi dei tipi scelti, se manca l'indirizzo di casa; i conteggi per canale; chi della lista non ha niente da scrivere;
     * i dati dello studio; il server di posta; il tetto.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function anteprima(Condominio $condominio, array $input): array
    {
        $tipo = is_string($input['tipo'] ?? null) && in_array($input['tipo'], self::TIPI, true) ? $input['tipo'] : throw new LetteraNonEmettibileException(self::TIPO_CHE_NON_C_E);
        $modello = ModelliLettere::scelto($condominio, $tipo, $input['modello_id'] ?? null);
        $tipi = array_values(array_intersect(array_keys(EmissioneRicevute::TIPI_INDIRIZZO), array_map('strval', array_filter((array) ($input['indirizzi'] ?? EmissioneRicevute::TIPI_PROPOSTI), 'is_scalar'))));
        $gestione = $this->gestioneScelta($condominio, $input);
        $c = $this->contenutiPer($condominio, $tipo, array_diff_key($input, ['viste' => true, 'in_anteprima' => true]), $gestione['id'] ?? null);
        $canali = $this->canaliScelti($condominio, $tipo, $c['persone'], $tipi, $input);

        $persone = [];
        foreach ($c['persone'] as $id => $p) {
            $tutti = EmissioneRicevute::indirizzi($p['persona']);
            $scelti = array_intersect_key($tutti, array_flip($tipi));
            $persone[] = [
                'id'          => $id,
                'nome'        => $p['nome'],
                'totale'      => $p['totale'],
                'in_accordo'  => $p['in_accordo'],
                // Un accordo di rientro con «fino al» passato: le sue rate sono nella lettera, e l'amministratore la toglie se non deve partire.
                'accordo_passato' => $p['accordo_passato'],
                'righe'       => count($p['righe']),
                'credito'     => $p['credito'],
                'canale'      => $canali[$id],
                'canali'      => in_array($tipo, self::TIPI_SOLO_CARTA, true) ? ['stampa', 'zip'] : self::CANALI,
                'indirizzi'   => collect($scelti)->map(fn ($indirizzo, $t) => ['tipo' => $t, 'indirizzo' => $indirizzo])->values()->all(),
                'senza_email' => $scelti === [],
                'solo_pec'    => $scelti === [] && isset($tutti['pec']),
                'senza_indirizzo_postale' => $p['indirizzo'] === null,
            ];
        }
        $conteggi = ['email' => 0, 'stampa' => 0, 'zip' => 0];
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
            'studio_compilato'   => self::studio()['nome'] !== null,
            // Le lettere escono su una carta intestata che si legge: lo studio lo porta la carta, e la frase della finestra lo dice.
            'carta_intestata'    => self::cartaIntestata(),
            'senza_server_posta' => EmissioneRicevute::senzaServerPosta(),
            'tetto_messaggi'     => EmissioneRicevute::tettoMessaggi(),
            'tetto'              => self::TETTO_PDF,
            // Il tetto è del PDF: conta chi si stampa (o si scarica in un PDF per persona), non chi va per email.
            'oltre_tetto'        => max($conteggi['stampa'], $conteggi['zip']) > self::TETTO_PDF,
        ];
    }

    /**
     * Il canale di ogni persona: quello scelto nella finestra (`canali`), se è uno dei canali di quel tipo, altrimenti quello proposto.
     *
     * @param array<int, array<string, mixed>> $contenuti
     * @return array<int, string>
     */
    private function canaliScelti(Condominio $condominio, string $tipo, array $contenuti, array $tipiScelti, array $input): array
    {
        $proposti = $this->canaliProposti($condominio, $tipo, $contenuti, $tipiScelti);
        $ammessi = in_array($tipo, self::TIPI_SOLO_CARTA, true) ? ['stampa', 'zip'] : self::CANALI;
        $scelti = is_array($input['canali'] ?? null) ? $input['canali'] : [];
        foreach ($proposti as $id => $canale) {
            $chiesto = $scelti[$id] ?? $scelti[(string) $id] ?? null;
            if (is_string($chiesto) && in_array($chiesto, $ammessi, true)) {
                $proposti[$id] = $chiesto;
            }
        }

        return $proposti;
    }

    /**
     * Le persone su cui lavora un'azione: senza `canali` tutte quelle della selezione; con `canali`, quelle con il canale dell'azione.
     *
     * @param array<int, array<string, mixed>> $contenuti
     * @return array<int, array<string, mixed>>
     */
    private function dellAzione(Condominio $condominio, string $tipo, array $contenuti, array $tipiScelti, array $input, string $canale): array
    {
        if (! is_array($input['canali'] ?? null)) {
            return $contenuti;
        }
        $canali = $this->canaliScelti($condominio, $tipo, $contenuti, $tipiScelti, $input);

        return array_filter($contenuti, fn ($c, $id) => ($canali[$id] ?? null) === $canale, ARRAY_FILTER_USE_BOTH);
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
        // «Vedi il PDF» mostra tutte le lettere dell'anteprima, qualunque sia il canale; la stampa solo quelle da stampare.
        $persone = $anteprima ? $c['persone'] : $this->dellAzione($condominio, $scelte['tipo'], $c['persone'], $scelte['tipi_scelti'], $input, 'stampa');
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
        $pdf = $this->conIFile(fn () => DB::transaction(function () use ($condominio, $persone, $scelte, $utente, $adesso, &$voci, &$invii) {
            $ids = [];
            foreach ($persone as $p) {
                [$righe] = $this->scriviLettera($condominio, $p, $scelte, $utente, $adesso, 'stampa');
                $voci[] = $this->voceDallaRiga($righe[0]);
                $ids = [...$ids, ...array_map(fn ($r) => $r->id, $righe)];
                $invii[(int) $righe[0]->id] = (int) $p['anagrafica_id'];
            }
            $pdf = $this->rendi($condominio, $voci);
            if (! in_array($scelte['tipo'], self::TIPI_CONSERVATI, true)) {
                RegistroInvio::whereIn('id', $ids ?: [0])->update(['impronta' => hash('sha256', $pdf)]);
            }

            return $pdf;
        }));

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
        if (in_array($scelte['tipo'], self::TIPI_SOLO_CARTA, true)) {
            throw new LetteraNonEmettibileException(self::SOLO_CARTA);
        }
        self::conServerPosta();
        if ($scelte['tipi_scelti'] === [] && ! filled($input['altro_indirizzo'] ?? null)) {
            throw new LetteraNonEmettibileException('Scegli a quali indirizzi mandare le lettere: primaria, secondaria, PEC.');
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
        [$dentro, $fuori] = InviiPerPersona::dentroIlTetto($gruppi, $tetto['messaggi'], fn (array $g) => count($g['destinazioni']));
        if ($tetto['lettere'] !== null) {
            [$dentro, $oltre] = InviiPerPersona::dentroIlTetto($dentro, $tetto['lettere'], fn (array $g) => 1);
            $fuori = $oltre === null ? $fuori : [...$oltre, ...($fuori ?? [])];
        }

        return [$dentro, $fuori];
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
        $persone = in_array($scelte['tipo'], self::TIPI_SOLO_CARTA, true) ? $c['persone'] : $this->dellAzione($condominio, $scelte['tipo'], $c['persone'], $scelte['tipi_scelti'], $input, 'email');
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
        if ($riga->canale !== RegistroInvio::CANALE_EMAIL || ! in_array($riga->tipo, self::TIPI, true) || in_array($riga->tipo, self::TIPI_SOLO_CARTA, true)) {
            throw new LetteraNonEmettibileException('Si rimanda solo una lettera già mandata per email.');
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
        $riga = $righe->first();
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
        $destinazioni = [];
        $senza = null;
        foreach ($righe as $r) {
            $tipo = (string) (($r->dati['tipo_indirizzo'] ?? null) ?: 'altro');
            $indirizzo = $tipo === 'altro' ? $r->destinatario : (EmissioneRicevute::indirizzi($persona)[$tipo] ?? null);
            if (filled($indirizzo)) {
                $destinazioni[] = [$indirizzo, $tipo];
            } else {
                $senza ??= $tipo;
            }
        }
        if ($destinazioni === []) {
            throw new LetteraNonEmettibileException($persona->nome . ' non ha più l\'indirizzo ' . (EmissioneRicevute::TIPI_INDIRIZZO[$senza] ?? '') . ': stampala per la posta.');
        }
        [$nuove] = $this->conIFile(fn () => DB::transaction(fn () => $this->scriviLettera($condominio, $p, $scelte, $utente, now(), 'email', $destinazioni)));

        return $this->spedisci($nuove);
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
     * @return array{0: array<int, RegistroInvio>, 1: ?string} le righe e il PDF conservato
     */
    private function scriviLettera(Condominio $condominio, array $persona, array $scelte, ?User $utente, CarbonInterface $adesso, string $canale, array $destinazioni = []): array
    {
        $dati = $this->datiDellaLettera($condominio, $persona, $scelte, $adesso);
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
                    'created_by' => $utente?->id ?? 1,
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
                'termine' => $scelte['termine'], 'termine_data' => $scelte['termine_data'] ?? null]), (string) $condominio->nome),
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
