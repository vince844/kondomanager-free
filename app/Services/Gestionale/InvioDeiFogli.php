<?php

namespace App\Services\Gestionale;

use App\Exceptions\Gestionale\FogliNonInviatiException;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Notifications\Gestionale\FoglioConguaglioNotification;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Invii\InviiPerPersona;
use App\Services\Gestionale\Lettere\ModelliLettere;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\Gestionale\Ricevute\ErroreDiPosta;
use App\Services\PDF\PdfService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * L'invio dei fogli del conguaglio per email: il foglio di ogni persona ({@see FogliDelConguaglio}, lo stesso di «il suo foglio», con la carta
 * dello studio) allegato a un messaggio per ognuno dei suoi indirizzi scelti, mai a un'altra persona. È un altro documento sulla strada delle
 * ricevute e delle lettere, e ne chiama le regole:
 *
 * - **Le persone** sono quelle delle scelte dell'archivio ({@see FogliDelConguaglio::scelte()}: tutte, con il conguaglio a debito, con un
 *   indirizzo; di tutto l'elenco o di una pagina), rilette adesso; con `viste`, solo quelle viste nell'anteprima e rimaste (chi entra dopo non
 *   riceve niente, e la risposta lo dice). Fra le viste, una persona che non è nella lettura dell'esercizio né legata al condominio è un 404 per
 *   tutta la richiesta; chi è nella lettura passa anche se l'amministratore l'ha staccata dal condominio (chi ha venduto nell'anno).
 * - **Gli indirizzi** sono quelli della persona dei tipi scelti ({@see EmissioneRicevute::indirizzi()}): la primaria e la secondaria proposte,
 *   la PEC se l'amministratore la spunta (il programma la manda come email semplice). Chi non ne ha uno fra quelli scelti resta fuori, e la
 *   finestra dice di scaricarne il foglio.
 * - **Il testo**: l'oggetto e il testo della finestra, con i campi [nome], [condominio] ed [esercizio] riempiti per ogni persona (la regola delle
 *   lettere, {@see TestiLettere::riempi()}). Il testo parte dal testo del programma o da un modello dello studio ({@see ModelliLettere}, tipo
 *   {@see TIPO}), come le lettere: la finestra riparte dall'ultimo modello scelto nel condominio, mai da un testo scritto a mano; l'oggetto
 *   dall'ultimo usato nel condominio. Il messaggio aggiunge sempre, fuori dal testo che si cambia, la frase della proposta ({@see FRASE_PROPOSTA}:
 *   da approvare dall'assemblea, niente da pagare per il conguaglio finché non è approvata, le rate già chieste da versare come sempre), la riga di chi
 *   ha un foglio con una parte non ancora calcolata ({@see FRASE_NON_PRONTO}) e la riga con la data e l'ora del calcolo delle cifre ({@see rigaDelCalcolo()}).
 * - **Il registro**: una riga di {@see RegistroInvio} per persona e indirizzo, tipo {@see TIPO}, con l'esercizio come «documentabile», il testo
 *   usato (riempito e con i campi), le cifre principali del foglio e la data e l'ora del calcolo (il foglio si calcola al momento, e cambia con le
 *   registrazioni). Il PDF partito si conserva come documento della riga, con la sua impronta: è la prova di che cosa è partito.
 * - **La posta**: un messaggio per indirizzo, in coda; senza un server di posta non si accoda niente. Quando le email partono mentre la pagina
 *   aspetta (la coda `sync`) al massimo {@see EmissioneRicevute::TETTO_MESSAGGI_SENZA_CODA} messaggi per volta, a persone intere, e con
 *   qualunque coda al massimo {@see ConguaglioDelRendiconto::FOGLI_PER_RICHIESTA} fogli per volta (ogni foglio è un PDF fatto nella richiesta):
 *   le altre persone tornano in `restanti`, per «Invia le altre». L'errore del server si dice in chiaro ({@see ErroreDiPosta}).
 * - **Il rinvio** ({@see riprova()}): un invio non partito a un indirizzo, se dopo la persona non ha avuto un altro foglio dello stesso esercizio
 *   (partito o in coda), si rifà con le cifre di oggi, allo stesso tipo di indirizzo e con lo stesso testo, in una riga nuova; quella di prima resta.
 * - **La scheda** ({@see perLaScheda()}): l'ultimo invio di ogni persona, quante persone hanno un invio da rimandare, quanti fogli sono in coda da
 *   più di un'ora (la regola delle lettere e delle ricevute: il servizio della coda non gira).
 */
final class InvioDeiFogli
{
    public const TIPO = RegistroInvio::TIPO_FOGLIO_CONGUAGLIO;

    /** I campi dell'oggetto e del testo, riempiti per ogni persona. */
    public const CAMPI = [
        '[nome]'       => 'il nome della persona',
        '[condominio]' => 'il nome del condominio',
        '[esercizio]'  => 'il nome dell\'esercizio',
    ];

    public const OGGETTO_DI_SERIE = '[condominio]: la sua parte delle spese e la proposta di conguaglio, [esercizio]';

    /** Il testo di partenza: chi scrive, che cosa c'è nel PDF, che cosa fare. Si cambia nella finestra, o con un modello dello studio. */
    public const TESTO_DI_SERIE = "Le mando in PDF il riepilogo delle spese del condominio [condominio] ([esercizio]), con la sua parte e la proposta di conguaglio.\n"
        . 'Se ha dubbi, risponda a questa email.';

    /**
     * La frase che ogni messaggio dice, fuori dal testo che si cambia: il conguaglio è una proposta, che non si paga finché l'assemblea non la
     * approva, e l'email non è una richiesta di pagamento; ma le rate già chieste restano da versare come sempre (chi legge deve capire da solo che
     * cosa deve già e che cosa è solo proposto).
     */
    public const FRASE_PROPOSTA = 'È una proposta di conguaglio, da approvare dall\'assemblea: finché non è approvata non c\'è niente da pagare per il conguaglio, '
        . 'e questa email non è una richiesta di pagamento. Le rate già chieste restano da versare come sempre.';

    /** La riga fissa del messaggio di chi ha un foglio con una parte non ancora calcolata (il conguaglio di una sua unità non si calcola). Le stesse parole del
     * foglio («non ancora calcolato», «l'amministratore»: lo studio può non esserci nelle impostazioni); mai «pronto», come nelle stampe della proposta. */
    public const FRASE_NON_PRONTO = 'Una parte del suo foglio non è ancora calcolata: l\'amministratore le scriverà appena possibile.';

    public const LUNGHEZZA_OGGETTO = 200;

    public const SENZA_SERVER_POSTA = 'Nessun server di posta configurato: i fogli non partono per email. Configuralo nelle impostazioni della posta; intanto puoi scaricarli.';
    public const SCEGLI_GLI_INDIRIZZI = 'Scegli a quali indirizzi mandare i fogli: primaria, secondaria, PEC.';
    public const SERVE_L_OGGETTO = 'Scrivi l\'oggetto dell\'email.';
    public const SERVE_IL_TESTO = 'Scrivi il testo dell\'email.';
    public const CAMPI_CHE_NON_CI_SONO = 'Nell\'email del foglio non ci sono [totale] e [termine]: usa [nome], [condominio] ed [esercizio].';
    public const NESSUNA_PERSONA = 'Nessuna persona fra quelle scelte ha un indirizzo fra quelli spuntati: non parte niente. Scarica i loro fogli, o spunta un altro indirizzo.';

    /** I PDF scritti sul disco nell'operazione in corso: se l'operazione non riesce, si tolgono ({@see conIFile()}). */
    private array $scritti = [];

    public function __construct(
        private readonly ConguaglioDelRendiconto $conguaglio,
        private readonly FogliDelConguaglio $fogli,
        private readonly ArchivioDocumenti $archivio,
        private readonly ModelliLettere $modelli,
    ) {}

    // ── La finestra ──────────────────────────────────────────────────────

    /**
     * Quello che la finestra sa prima dell'anteprima: l'oggetto (l'ultimo usato nel condominio, non da un rinvio, o quello del programma); il testo
     * del programma e i modelli dello studio validi nel condominio, con l'ultimo scelto (`modello_ricordato`, come le lettere: `mancante` se non c'è
     * più); se chi lavora può salvare un modello; gli indirizzi del suo ultimo invio; i campi; il server di posta; i tetti.
     *
     * @return array<string, mixed>
     */
    public function opzioni(Condominio $condominio, ?User $utente): array
    {
        $ultimo = RegistroInvio::where('condominio_id', $condominio->id)->where('tipo', self::TIPO)->whereNull('dati->rifatto_da')->orderByDesc('id')->first()?->dati;
        $indirizzi = $utente === null ? null : RegistroInvio::where('condominio_id', $condominio->id)->where('tipo', self::TIPO)->where('emesso_da', $utente->id)
            ->whereNull('dati->rifatto_da')->orderByDesc('id')->first()?->dati['tipi_scelti'] ?? null;

        return [
            'oggetto'              => (string) ($ultimo['oggetto_modello'] ?? self::OGGETTO_DI_SERIE),
            'oggetto_di_serie'     => self::OGGETTO_DI_SERIE,
            'testo_di_serie'       => self::TESTO_DI_SERIE,
            'modelli'              => $this->modelli->delCondominio($condominio, [self::TIPO]),
            'modello_ricordato'    => $this->modelli->ricordati($condominio, [self::TIPO])[self::TIPO],
            'puo_salvare_il_testo' => $utente?->can(\App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value) === true,
            'frase_proposta'       => self::FRASE_PROPOSTA,
            'campi'                => self::CAMPI,
            'indirizzi'            => is_array($indirizzi) ? array_values($indirizzi) : EmissioneRicevute::TIPI_PROPOSTI,
            'senza_server_posta'   => EmissioneRicevute::senzaServerPosta(),
            'tetto_messaggi'       => EmissioneRicevute::tettoMessaggi(),
            'tetto_fogli'          => ConguaglioDelRendiconto::FOGLI_PER_RICHIESTA,
        ];
    }

    /**
     * L'anteprima di chi riceve il foglio: per persona gli indirizzi dei tipi scelti (o che non ne ha uno, e se ha solo la PEC) e il suo ultimo
     * invio; quanti messaggi, chi resta senza, quanti partono con il tetto; la data e l'ora del calcolo; il messaggio della prima persona con i campi
     * riempiti; il server di posta.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function anteprima(Condominio $condominio, Esercizio $esercizio, array $input): array
    {
        $scelte = $this->scelte($condominio, $input + ['oggetto' => self::OGGETTO_DI_SERIE, 'testo' => self::TESTO_DI_SERIE]);
        $letto = $this->conguaglio->leggi($esercizio, [], true);
        $persone = $this->persone($condominio, $esercizio, $letto, $scelte['chi'], $scelte['pagina']);
        $ultimi = $this->ultimi($condominio, $esercizio, array_keys($persone));
        $nonDisponibili = self::conRigheNonDisponibili($letto);
        [$righe, $gruppi, $senza] = $this->gruppi($persone, $scelte['tipi']);
        foreach ($righe as $i => $r) {
            $righe[$i]['ultimo'] = $ultimi[$r['id']] ?? null;
            // Il suo foglio ha una parte «non disponibile»: parte lo stesso, e l'anteprima lo dice.
            $righe[$i]['non_disponibile'] = isset($nonDisponibili[$r['id']]);
        }
        [$dentro] = self::dentroIlTetto($gruppi);
        $data = ConguaglioDelRendiconto::data($esercizio, $letto);
        // Il messaggio di esempio è quello della prima persona che lo riceve; se nessuna ha un indirizzo, della prima.
        $primo = $gruppi[0]['persona'] ?? (reset($persone) ?: null);

        return [
            'persone'            => $righe,
            'messaggi'           => array_sum(array_map(fn ($g) => count($g['destinazioni']), $gruppi)),
            'senza_indirizzo'    => $senza,
            'non_disponibili'    => count(array_filter($righe, fn ($r) => $r['non_disponibile'])),
            'partono'            => ['persone' => count($dentro), 'messaggi' => array_sum(array_map(fn ($g) => count($g['destinazioni']), $dentro))],
            'tetto_messaggi'     => EmissioneRicevute::tettoMessaggi(),
            'tetto_fogli'        => ConguaglioDelRendiconto::FOGLI_PER_RICHIESTA,
            'data'               => $data,
            'esempio'            => $primo === null ? null : ['persona' => (string) $primo->nome]
                + FoglioConguaglioNotification::testo((string) $condominio->nome, (string) $primo->nome, $this->testoDellaPersona($condominio, $esercizio, $primo, $scelte, $data)
                    + ['non_disponibile' => isset($nonDisponibili[(int) $primo->id])]),
            'senza_server_posta' => EmissioneRicevute::senzaServerPosta(),
        ];
    }

    // ── L'invio ──────────────────────────────────────────────────────────

    /**
     * L'invio: per ogni persona (nell'ordine dei nomi) il foglio di oggi, conservato, e un messaggio per indirizzo; dentro il tetto, a persone
     * intere. La risposta dice quanti messaggi sono partiti, in coda o non partiti con l'errore, chi è rimasto senza indirizzo, chi resta per
     * «Invia le altre», chi è entrato o uscito dalla scelta dopo l'anteprima, e l'ultimo invio di ogni persona fatta.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function invia(Condominio $condominio, Esercizio $esercizio, array $input, ?User $utente): array
    {
        $scelte = $this->scelte($condominio, $input);
        self::conServerPosta();
        if ($scelte['tipi'] === []) {
            throw new FogliNonInviatiException(self::SCEGLI_GLI_INDIRIZZI);
        }
        $viste = array_key_exists('viste', $input) && $input['viste'] !== null ? self::identificativi($input['viste']) : null;

        $letto = $this->conguaglio->leggi($esercizio, [], true);
        if ($viste !== null) {
            // Fra le viste una persona che non è nella lettura dell'esercizio né legata al condominio: 404 per tutta la richiesta, prima di scrivere.
            // Chi è nella lettura passa anche staccato dal condominio: ha venduto nell'anno, e il suo foglio è suo.
            $nellaLettura = FogliDelConguaglio::persone($letto);
            $estranee = array_values(array_filter($viste, fn (int $id) => ! array_key_exists($id, $nellaLettura)));
            $delCondominio = $estranee === [] ? 0
                : Anagrafica::whereIn('id', $estranee)->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominio->id))->count();
            abort_unless($delCondominio === count($estranee), 404);
        }
        $persone = $this->persone($condominio, $esercizio, $letto, $scelte['chi'], $scelte['pagina']);
        $aggiunte = 0;
        $uscite = 0;
        if ($viste !== null) {
            $inAnteprima = array_key_exists('in_anteprima', $input) && $input['in_anteprima'] !== null ? self::identificativi($input['in_anteprima']) : $viste;
            $aggiunte = count(array_diff(array_keys($persone), $inAnteprima));
            $uscite = count(array_diff($viste, array_keys($persone)));
            $persone = array_intersect_key($persone, array_flip($viste));
        }
        [, $gruppi, $senza] = $this->gruppi($persone, $scelte['tipi']);
        if ($gruppi === []) {
            throw new FogliNonInviatiException(self::NESSUNA_PERSONA);
        }
        [$dentro, $fuori] = self::dentroIlTetto($gruppi);

        $data = ConguaglioDelRendiconto::data($esercizio, $letto);
        $contesto = $this->fogli->contesto($esercizio, $letto, array_map(fn ($g) => (int) $g['persona']->id, $dentro));
        $adesso = now();
        $messaggi = [];
        $fatte = [];
        foreach ($dentro as $g) {
            $righe = $this->scriviFoglio($condominio, $esercizio, $letto, $contesto, $g['persona'], $scelte, $data, $g['destinazioni'], $utente, $adesso);
            if ($righe === null) {
                continue;
            }
            $messaggi = [...$messaggi, ...$this->spedisci($righe)];
            $fatte[] = (int) $g['persona']->id;
        }

        return $this->riepilogo($messaggi, count($fatte)) + [
            'senza_indirizzo' => $senza,
            'restanti'        => $fuori === null ? null : ['persone' => array_map(fn ($g) => (int) $g['persona']->id, $fuori),
                'messaggi' => array_sum(array_map(fn ($g) => count($g['destinazioni']), $fuori))],
            'aggiunte'        => $aggiunte,
            'uscite'          => $uscite,
            'ultimi'          => $this->ultimi($condominio, $esercizio, $fatte),
            'da_rimandare'    => $this->daRimandare($condominio, $esercizio),
        ];
    }

    /**
     * Il rinvio: per ogni persona (una, o tutte quelle dell'esercizio) gli indirizzi il cui ultimo invio non è partito e dopo il quale la persona
     * non ha avuto un altro foglio dello stesso esercizio, partito o in coda ({@see daRimandare()}); il foglio si rifà con le cifre di oggi, allo
     * stesso tipo di indirizzo com'è oggi nell'anagrafica e con il testo di allora, in righe nuove. Dentro il tetto, a persone intere.
     *
     * @return array<string, mixed> il riepilogo, con `fogli` (i fogli rifatti), `senza_indirizzo` (le frasi di chi non ha più l'indirizzo) e `restanti`
     */
    public function riprova(Condominio $condominio, Esercizio $esercizio, ?int $personaId, ?User $utente): array
    {
        $stato = $this->statoDegliInvii($condominio, $esercizio, $personaId === null ? null : [$personaId]);
        // Una persona senza fogli di questo esercizio e non legata al condominio: 404. Chi ha un foglio qui passa anche staccato dal condominio.
        abort_if($personaId !== null && $stato === []
            && ! Anagrafica::whereKey($personaId)->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominio->id))->exists(), 404);
        self::conServerPosta();
        $daRifare = [];
        foreach ($stato as $aid => $s) {
            $ids = array_column(array_filter($s['indirizzi'], fn ($r) => $r['da_rimandare']), 'id');
            if ($ids !== []) {
                $daRifare[$aid] = RegistroInvio::whereIn('id', $ids)->orderBy('id')->get();
            }
        }
        $persone = Anagrafica::whereIn('id', array_keys($daRifare) ?: [0])->get()->keyBy('id');
        $gruppi = [];
        $senza = [];
        foreach ($daRifare as $aid => $righe) {
            $persona = $persone->get($aid);
            if ($persona === null) {
                continue;
            }
            $indirizzi = EmissioneRicevute::indirizzi($persona);
            $destinazioni = [];
            foreach ($righe as $r) {
                $tipo = (string) ($r->dati['tipo_indirizzo'] ?? '');
                if (isset($indirizzi[$tipo])) {
                    $destinazioni[] = [$indirizzi[$tipo], $tipo];
                } else {
                    $senza[] = $persona->nome . ' non ha più l\'indirizzo ' . (EmissioneRicevute::TIPI_INDIRIZZO[$tipo] ?? $tipo) . ': scarica il suo foglio.';
                }
            }
            if ($destinazioni !== []) {
                $gruppi[] = ['persona' => $persona, 'destinazioni' => $destinazioni, 'prima' => $righe->first()];
            }
        }
        [$dentro, $fuori] = self::dentroIlTetto($gruppi);
        $messaggi = [];
        $fatte = [];
        if ($dentro !== []) {
            $letto = $this->conguaglio->leggi($esercizio, [], true);
            $data = ConguaglioDelRendiconto::data($esercizio, $letto);
            $contesto = $this->fogli->contesto($esercizio, $letto, array_map(fn ($g) => (int) $g['persona']->id, $dentro));
            $adesso = now();
            foreach ($dentro as $g) {
                $dati = (array) $g['prima']->dati;
                // Il testo e il modello di allora: il modello cambiato o cancellato dopo non cambia il foglio rifatto.
                $scelte = ['oggetto' => (string) ($dati['oggetto_modello'] ?? self::OGGETTO_DI_SERIE), 'testo' => (string) ($dati['testo_modello'] ?? self::TESTO_DI_SERIE),
                    'chi' => (string) ($dati['chi'] ?? 'tutte'), 'pagina' => $dati['pagina'] ?? null, 'tipi' => (array) ($dati['tipi_scelti'] ?? []),
                    'modello_scelto' => isset($dati['modello_id']) ? ['id' => (int) $dati['modello_id'], 'nome' => (string) ($dati['modello_nome'] ?? '')] : null];
                $righe = $this->scriviFoglio($condominio, $esercizio, $letto, $contesto, $g['persona'], $scelte, $data, $g['destinazioni'], $utente, $adesso, (int) $g['prima']->id);
                if ($righe === null) {
                    continue;
                }
                $messaggi = [...$messaggi, ...$this->spedisci($righe)];
                $fatte[] = (int) $g['persona']->id;
            }
        }

        return $this->riepilogo($messaggi, count($fatte)) + [
            'fogli'           => count($fatte),
            'senza_indirizzo' => $senza,
            'restanti'        => $fuori === null ? null : ['persone' => count($fuori), 'messaggi' => array_sum(array_map(fn ($g) => count($g['destinazioni']), $fuori))],
            'ultimi'          => $this->ultimi($condominio, $esercizio, $fatte),
            'da_rimandare'    => $this->daRimandare($condominio, $esercizio),
        ];
    }

    // ── L'ultimo invio di ogni persona ───────────────────────────────────

    /**
     * Quello che la scheda dice dei fogli inviati, da una lettura sola: l'ultimo invio di ogni persona data ({@see ultimi()}), quante persone hanno
     * un invio da rimandare, quanti fogli sono in coda da più di un'ora (contati come fogli: un foglio a due indirizzi è uno; la regola delle
     * lettere, `RateScaduteController`).
     *
     * @param  list<int>  $aids
     * @return array{ultimi: array<int, array<string, mixed>>, da_rimandare: int, in_coda_da_tempo: int}
     */
    public function perLaScheda(Condominio $condominio, Esercizio $esercizio, array $aids): array
    {
        $stato = $this->statoDegliInvii($condominio, $esercizio, null);

        return [
            'ultimi'           => self::ultimiDa(array_intersect_key($stato, array_flip($aids))),
            'da_rimandare'     => count(array_filter($stato, fn ($s) => $s['da_rimandare'])),
            'in_coda_da_tempo' => $this->inCodaDaTempo($condominio, $esercizio),
        ];
    }

    /**
     * L'ultimo invio del foglio di questo esercizio di ogni persona (di quelle date), per la scheda: lo stato dell'ultimo foglio partito per lei
     * (inviato, in coda, non partito, in parte) con le sue righe, il giorno e l'ora; in più le righe di un foglio di prima ancora da rimandare; se
     * c'è da rimandare; la riga del PDF più recente.
     *
     * @param  list<int>|null  $aids
     * @return array<int, array<string, mixed>>
     */
    public function ultimi(Condominio $condominio, Esercizio $esercizio, ?array $aids = null): array
    {
        return self::ultimiDa($this->statoDegliInvii($condominio, $esercizio, $aids));
    }

    /** Quante persone hanno un invio del foglio di questo esercizio da rimandare. */
    public function daRimandare(Condominio $condominio, Esercizio $esercizio): int
    {
        return count(array_filter($this->statoDegliInvii($condominio, $esercizio, null), fn ($s) => $s['da_rimandare']));
    }

    /** I fogli di questo esercizio in coda da più di un'ora: il servizio della coda, sul server, non li manda. */
    public function inCodaDaTempo(Condominio $condominio, Esercizio $esercizio): int
    {
        return RegistroInvio::where('condominio_id', $condominio->id)->where('tipo', self::TIPO)
            ->where('documentabile_type', Esercizio::class)->where('documentabile_id', $esercizio->id)
            ->where('stato', RegistroInvio::STATO_IN_CODA)->where('emesso_il', '<', now()->subHour())
            ->get(['id', 'dati->foglio as foglio'])->map(fn ($r) => $r->foglio ?? 'riga-' . $r->id)->unique()->count();
    }

    /**
     * @param  array<int, array<string, mixed>>  $stato
     * @return array<int, array<string, mixed>>
     */
    private static function ultimiDa(array $stato): array
    {
        $ultimi = [];
        foreach ($stato as $aid => $s) {
            $stati = array_values(array_unique(array_column($s['ultimo_foglio'], 'stato')));
            $righe = $s['ultimo_foglio'];
            foreach ($s['indirizzi'] as $r) {
                if ($r['da_rimandare'] && ! in_array($r['id'], array_column($righe, 'id'), true)) {
                    $righe[] = $r;
                }
            }
            usort($righe, fn ($a, $b) => $a['id'] <=> $b['id']);
            $ultimi[$aid] = [
                'quando'       => $s['quando'],
                'stato'        => count($stati) > 1 ? 'in_parte' : match ($stati[0]) {
                    RegistroInvio::STATO_INVIATA => 'inviato', RegistroInvio::STATO_IN_CODA => 'in_coda', default => 'non_partito',
                },
                'righe'        => array_map(fn ($r) => array_diff_key($r, ['da_rimandare' => true]), $righe),
                'da_rimandare' => $s['da_rimandare'],
                'pdf'          => $s['pdf'],
            ];
        }

        return $ultimi;
    }

    /**
     * Le righe del registro dei fogli di questo esercizio, per persona, da una lettura sola con le colonne che servono: le righe dell'ultimo foglio
     * (lo stato che la scheda dice), l'ultima riga di ogni indirizzo (il tipo) e se è da rimandare: non partita, e dopo di lei nessuna riga di un
     * altro foglio alla stessa persona partita o in coda (le righe dello stesso foglio, gli altri suoi indirizzi, non contano).
     *
     * @param  list<int>|null  $aids
     * @return array<int, array{quando: string, pdf: int, da_rimandare: bool, ultimo_foglio: list<array<string, mixed>>, indirizzi: list<array<string, mixed>>}>
     */
    private function statoDegliInvii(Condominio $condominio, Esercizio $esercizio, ?array $aids): array
    {
        if ($aids === []) {
            return [];
        }
        $righe = RegistroInvio::where('condominio_id', $condominio->id)->where('tipo', self::TIPO)
            ->where('documentabile_type', Esercizio::class)->where('documentabile_id', $esercizio->id)->whereNotNull('anagrafica_id')
            ->when($aids !== null, fn ($q) => $q->whereIn('anagrafica_id', $aids))
            ->orderBy('id')
            ->get(['id', 'anagrafica_id', 'destinatario', 'stato', 'errore', 'emesso_il', 'dati->foglio as foglio', 'dati->tipo_indirizzo as tipo_indirizzo']);
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $stato = [];
        foreach ($righe->groupBy('anagrafica_id') as $aid => $sue) {
            $riga = fn (RegistroInvio $r) => [
                'id'           => (int) $r->id,
                'destinatario' => $r->destinatario,
                'tipo'         => $r->tipo_indirizzo,
                'stato'        => $r->stato,
                'quando'       => $r->emesso_il->copy()->setTimezone($fuso)->format('Y-m-d H:i'),
                'errore'       => ErroreDiPosta::frase($r->errore),
                'da_rimandare' => $r->stato === RegistroInvio::STATO_NON_PARTITA && ! $sue->contains(fn (RegistroInvio $altra) => $altra->id > $r->id
                    && $altra->foglio !== $r->foglio && in_array($altra->stato, [RegistroInvio::STATO_INVIATA, RegistroInvio::STATO_IN_CODA], true)),
            ];
            $indirizzi = $sue->groupBy(fn (RegistroInvio $r) => (string) $r->tipo_indirizzo)->map(fn (Collection $g) => $g->last())->sortBy('id')->values()->map($riga)->all();
            $ultima = $sue->last();
            $stato[(int) $aid] = [
                'quando'        => $ultima->emesso_il->copy()->setTimezone($fuso)->format('Y-m-d H:i'),
                'pdf'           => (int) $ultima->id,
                'ultimo_foglio' => $sue->where('foglio', $ultima->foglio)->values()->map($riga)->all(),
                'indirizzi'     => $indirizzi,
                'da_rimandare'  => in_array(true, array_column($indirizzi, 'da_rimandare'), true),
            ];
        }

        return $stato;
    }

    // ── Interni ──────────────────────────────────────────────────────────

    /**
     * Le scelte della finestra: le persone (`chi`, `pagina`), i tipi di indirizzo, l'oggetto e il testo (non vuoti, nella loro lunghezza, senza
     * i campi delle lettere che l'email del foglio non ha).
     *
     * Il modello dello studio da cui è partito il testo (`modello_id`, {@see ModelliLettere::scelto()}): uno di un altro condominio è un 404 per
     * tutta la richiesta; uno che non c'è più non è il modello di questo invio.
     *
     * @param  array<string, mixed>  $input
     * @return array{chi: string, pagina: ?int, tipi: list<string>, oggetto: string, testo: string, modello_scelto: ?array{id: int, nome: string}}
     */
    private function scelte(Condominio $condominio, array $input): array
    {
        $chi = in_array($input['chi'] ?? null, FogliDelConguaglio::SCELTE, true) ? (string) $input['chi'] : 'tutte';
        $pagina = is_numeric($input['pagina'] ?? null) && (int) $input['pagina'] >= 1 ? (int) $input['pagina'] : null;
        $tipi = array_values(array_intersect(array_keys(EmissioneRicevute::TIPI_INDIRIZZO),
            array_map('strval', array_filter((array) ($input['indirizzi'] ?? EmissioneRicevute::TIPI_PROPOSTI), 'is_scalar'))));
        $oggetto = trim((string) preg_replace('/\s+/u', ' ', self::comeTesto($input['oggetto'] ?? null, 'L\'oggetto')));
        $testo = str_replace("\r\n", "\n", trim(self::comeTesto($input['testo'] ?? null, 'Il testo')));
        if ($oggetto === '') {
            throw new FogliNonInviatiException(self::SERVE_L_OGGETTO);
        }
        if ($testo === '') {
            throw new FogliNonInviatiException(self::SERVE_IL_TESTO);
        }
        if (mb_strlen($oggetto) > self::LUNGHEZZA_OGGETTO) {
            throw new FogliNonInviatiException('L\'oggetto è troppo lungo: al massimo ' . self::LUNGHEZZA_OGGETTO . ' caratteri.');
        }
        if (mb_strlen($testo) > TestiLettere::LUNGHEZZA_MASSIMA) {
            throw new FogliNonInviatiException('Il testo è troppo lungo: al massimo ' . TestiLettere::LUNGHEZZA_MASSIMA . ' caratteri.');
        }
        if (self::conCampiDelleLettere($oggetto . ' ' . $testo)) {
            throw new FogliNonInviatiException(self::CAMPI_CHE_NON_CI_SONO);
        }

        return ['chi' => $chi, 'pagina' => $pagina, 'tipi' => $tipi, 'oggetto' => $oggetto, 'testo' => $testo,
            'modello_scelto' => ModelliLettere::scelto($condominio, self::TIPO, $input['modello_id'] ?? null)];
    }

    /** Un testo con i campi delle lettere che l'email del foglio non ha: [totale] e [termine]. Lo rifiutano l'invio e i modelli. */
    public static function conCampiDelleLettere(string $testo): bool
    {
        return preg_match('/\[(totale|termine)\]/iu', $testo) === 1;
    }

    /**
     * Le persone che hanno almeno una riga «non disponibile» nella lettura: il loro foglio lo dice, e l'anteprima anche.
     *
     * @return array<int, true>
     */
    private static function conRigheNonDisponibili(array $letto): array
    {
        $persone = [];
        foreach ($letto['persone'] as $perPersona) {
            foreach ($perPersona as $aid => $perUnita) {
                foreach ($perUnita as $r) {
                    if (($r['non_disponibile'] ?? null) !== null) {
                        $persone[(int) $aid] = true;
                    }
                }
            }
        }

        return $persone;
    }

    /**
     * Le persone scelte, nell'ordine dei nomi (come l'archivio).
     *
     * @return array<int, Anagrafica>
     */
    private function persone(Condominio $condominio, Esercizio $esercizio, array $letto, string $chi, ?int $pagina): array
    {
        $persone = [];
        foreach (array_chunk($this->fogli->scelte($condominio, $esercizio, $letto, $chi, $pagina), 1000) as $pezzo) {
            foreach (Anagrafica::whereIn('id', $pezzo)->get() as $a) {
                $persone[(int) $a->id] = $a;
            }
        }
        uasort($persone, fn (Anagrafica $a, Anagrafica $b) => [mb_strtolower((string) $a->nome), $a->id] <=> [mb_strtolower((string) $b->nome), $b->id]);

        return $persone;
    }

    /**
     * Per ogni persona gli indirizzi dei tipi scelti: le righe dell'anteprima, i gruppi dell'invio (la persona e le sue destinazioni) e i nomi di
     * chi non ne ha uno.
     *
     * @param  array<int, Anagrafica>  $persone
     * @param  list<string>  $tipi
     * @return array{0: list<array<string, mixed>>, 1: list<array{persona: Anagrafica, destinazioni: list<array{0: string, 1: string}>}>, 2: list<string>}
     */
    private function gruppi(array $persone, array $tipi): array
    {
        $righe = [];
        $gruppi = [];
        $senza = [];
        foreach ($persone as $id => $p) {
            $tutti = EmissioneRicevute::indirizzi($p);
            $scelti = array_intersect_key($tutti, array_flip($tipi));
            $righe[] = ['id' => (int) $id, 'nome' => (string) $p->nome, 'indirizzi' => array_map(fn ($t, $i) => ['tipo' => $t, 'indirizzo' => $i], array_keys($scelti), $scelti),
                'senza_email' => $scelti === [], 'solo_pec' => $scelti === [] && isset($tutti['pec'])];
            if ($scelti === []) {
                $senza[] = (string) $p->nome;

                continue;
            }
            $gruppi[] = ['persona' => $p, 'destinazioni' => array_map(fn ($t, $i) => [$i, $t], array_keys($scelti), $scelti)];
        }

        return [$righe, $gruppi, $senza];
    }

    /**
     * Le persone dentro il tetto, a persone intere, e quelle che restano (null se nessuna): prima i messaggi per volta senza il servizio della coda,
     * poi i fogli per volta con qualunque coda (la regola delle lettere che conservano il PDF).
     *
     * @return array{0: list<array<string, mixed>>, 1: ?list<array<string, mixed>>}
     */
    private static function dentroIlTetto(array $gruppi): array
    {
        return InviiPerPersona::dentroIDueTetti($gruppi, EmissioneRicevute::tettoMessaggi(), ConguaglioDelRendiconto::FOGLI_PER_RICHIESTA,
            fn (array $g) => count($g['destinazioni']));
    }

    /**
     * Il foglio di una persona e le sue righe: il PDF fatto una volta e conservato come documento della prima riga, una riga per indirizzo con
     * l'impronta, l'oggetto e il testo riempiti, le cifre e l'ora del calcolo. Dentro una transazione: se il PDF non si salva non resta niente, e
     * il file scritto si toglie. Null se la persona non ha un foglio (nessuna riga nella lettura: la stessa regola dell'archivio).
     *
     * @param  list<array{0: string, 1: string}>  $destinazioni  [indirizzo, tipo]
     * @return list<RegistroInvio>|null
     */
    private function scriviFoglio(Condominio $condominio, Esercizio $esercizio, array $letto, array $contesto, Anagrafica $persona, array $scelte, array $data,
        array $destinazioni, ?User $utente, CarbonInterface $adesso, ?int $rifattoDa = null): ?array
    {
        $foglio = $this->fogli->datiDelFoglio($condominio, $esercizio, $letto, (int) $persona->id, $contesto);
        if ($foglio === null) {
            return null;
        }
        $pdf = $this->fogli->pdf($condominio, $esercizio, $foglio);
        $allegato = PdfService::nomeFile('conguaglio', (string) $condominio->nome, $esercizio->data_inizio->format('Y'), Str::slug((string) $persona->nome));
        $dati = $this->testoDellaPersona($condominio, $esercizio, $persona, $scelte, $data) + [
            'foglio'          => (string) Str::uuid(),
            'esercizio_id'    => (int) $esercizio->id,
            'esercizio'       => (string) $esercizio->nome,
            'persona'         => ['id' => (int) $persona->id, 'nome' => (string) $persona->nome],
            'oggetto_modello' => $scelte['oggetto'],
            'testo_modello'   => $scelte['testo'],
            // Il modello dello studio da cui è partito il testo: il numero e il nome di allora (il testo è copiato).
            'modello_id'      => $scelte['modello_scelto']['id'] ?? null,
            'modello_nome'    => $scelte['modello_scelto']['nome'] ?? null,
            'cifre'           => $foglio['totale'],
            'non_disponibile' => $foglio['non_disponibile'] ? true : null,
            'allegato'        => $allegato,
            'chi'             => $scelte['chi'],
            'pagina'          => $scelte['pagina'],
            'tipi_scelti'     => $scelte['tipi'],
            'rifatto_da'      => $rifattoDa,
        ];
        $dati = array_filter($dati, fn ($v) => $v !== null);

        $prima = count($this->scritti);
        try {
            return DB::transaction(function () use ($condominio, $persona, $destinazioni, $utente, $adesso, $pdf, $dati, $allegato, $esercizio) {
                $percorso = $this->archivio->salvaContenuto($pdf, 'pdf', ArchivioDocumenti::CARTELLA . '/' . $condominio->id . '/conguaglio');
                $this->scritti[] = $percorso;
                $righe = [];
                foreach ($destinazioni as [$indirizzo, $tipo]) {
                    $righe[] = RegistroInvio::create(['condominio_id' => $condominio->id, 'anagrafica_id' => $persona->id, 'tipo' => self::TIPO,
                        'canale' => RegistroInvio::CANALE_EMAIL, 'documentabile_type' => Esercizio::class, 'documentabile_id' => $esercizio->id, 'destinatario' => $indirizzo,
                        'stato' => RegistroInvio::STATO_IN_CODA, 'impronta' => hash('sha256', $pdf), 'emesso_da' => $utente?->id, 'emesso_il' => $adesso,
                        'dati' => $dati + ['tipo_indirizzo' => $tipo, 'gettone' => (string) Str::uuid()]]);
                }
                $documento = $righe[0]->documenti()->create([
                    'name'         => $allegato,
                    'description'  => 'Consuntivo e proposta di conguaglio, ' . $esercizio->nome . ', a ' . $persona->nome . ', del '
                        . $adesso->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y'),
                    'path'         => $percorso,
                    'mime_type'    => 'application/pdf',
                    'file_size'    => strlen($pdf),
                    'created_by'   => $utente?->id ?? 1,
                    'is_published' => false,
                    'is_approved'  => true,
                ]);
                foreach ($righe as $r) {
                    $r->update(['dati' => (array) $r->dati + ['documento_id' => $documento->id]]);
                }

                return $righe;
            });
        } catch (\Throwable $e) {
            foreach (array_slice($this->scritti, $prima) as $percorso) {
                $this->archivio->elimina($percorso);
            }
            array_splice($this->scritti, $prima);

            throw $e;
        } finally {
            unset($pdf);
            gc_collect_cycles();
        }
    }

    /**
     * L'oggetto e il testo di una persona, con i campi riempiti, e la data e l'ora del calcolo che il messaggio dice.
     *
     * @return array{oggetto: string, testo: string, alla: string, finito: bool, calcolato_il: ?string}
     */
    private function testoDellaPersona(Condominio $condominio, Esercizio $esercizio, Anagrafica $persona, array $scelte, array $data): array
    {
        return [
            'oggetto'      => self::riempi($scelte['oggetto'], (string) $persona->nome, (string) $esercizio->nome, (string) $condominio->nome),
            'testo'        => self::riempi($scelte['testo'], (string) $persona->nome, (string) $esercizio->nome, (string) $condominio->nome),
            'alla'         => (string) $data['alla'],
            'finito'       => (bool) $data['finito'],
            'calcolato_il' => $data['calcolato_il'],
        ];
    }

    /**
     * I campi riempiti: [esercizio], poi [nome] e [condominio] con la regola delle lettere (il nome del condominio una volta sola: «del condominio
     * [condominio]» con «Condominio Le Rose» è «del Condominio Le Rose»). [totale] e [termine] non arrivano qui: {@see scelte()} li rifiuta.
     */
    public static function riempi(string $modello, string $nome, string $esercizio, string $condominio): string
    {
        return TestiLettere::riempi(strtr($modello, ['[esercizio]' => $esercizio]), $nome, 0, null, $condominio);
    }

    /**
     * La riga del messaggio con la data e l'ora del calcolo: alla fine dell'esercizio, o a oggi finché è aperto (e allora le cifre possono cambiare).
     *
     * @param  array{alla?: ?string, finito?: ?bool, calcolato_il?: ?string}  $dati
     */
    public static function rigaDelCalcolo(array $dati): string
    {
        $giorno = isset($dati['alla']) ? CarbonImmutable::parse((string) $dati['alla'])->format('d/m/Y') : '';
        $calcolato = (string) ($dati['calcolato_il'] ?? $giorno);

        return ! empty($dati['finito'])
            ? 'Le cifre sono quelle al ' . $giorno . ', alla fine dell\'esercizio, calcolate il ' . $calcolato . '.'
            : 'L\'esercizio è ancora aperto: le cifre sono quelle a oggi, calcolate il ' . $calcolato . ', e possono cambiare con le registrazioni che seguono.';
    }

    /**
     * I messaggi di un foglio: uno per riga (un indirizzo), con il gettone che lega la riga al registro delle email. Un errore della posta mentre
     * il messaggio parte (con la coda `sync`) fa la riga «non partita», con l'errore.
     *
     * @param  list<RegistroInvio>  $righe
     * @return list<int>
     */
    private function spedisci(array $righe): array
    {
        $ids = [];
        foreach ($righe as $r) {
            try {
                $notifica = new FoglioConguaglioNotification([$r->id]);
                $notifica->id = (string) $r->dati['gettone'];
                Notification::route('mail', $r->destinatario)->notify($notifica);
            } catch (\Throwable $e) {
                Log::warning('Foglio del conguaglio non partito: ' . $e->getMessage(), ['invio' => $r->id]);
                RegistroInvio::whereKey($r->id)->where('stato', RegistroInvio::STATO_IN_CODA)->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => ErroreDiPosta::daEccezione($e)]);
            }
            $ids[] = (int) $r->id;
        }

        return $ids;
    }

    /** @param  list<int>  $ids */
    private function riepilogo(array $ids, int $persone): array
    {
        $scritte = RegistroInvio::whereIn('id', $ids ?: [0])->get(['id', 'stato', 'errore']);
        $conta = fn (string $stato) => $scritte->where('stato', $stato)->count();

        return [
            'persone'        => $persone,
            'messaggi'       => count($ids),
            'inviate'        => $conta(RegistroInvio::STATO_INVIATA),
            'in_coda'        => $conta(RegistroInvio::STATO_IN_CODA),
            'non_partite'    => $conta(RegistroInvio::STATO_NON_PARTITA),
            'errori'         => $scritte->pluck('errore')->filter()->unique()->take(3)->map(fn (string $e) => ErroreDiPosta::frase($e))->unique()->values()->all(),
            'tetto_messaggi' => EmissioneRicevute::tettoMessaggi(),
        ];
    }

    private static function conServerPosta(): void
    {
        if (EmissioneRicevute::senzaServerPosta()) {
            throw new FogliNonInviatiException(self::SENZA_SERVER_POSTA);
        }
    }

    /** Un campo che deve essere un testo: un elenco o un oggetto si rifiuta, non si converte. */
    private static function comeTesto(mixed $valore, string $campo): string
    {
        if ($valore === null) {
            return '';
        }
        if (! is_scalar($valore)) {
            throw new FogliNonInviatiException($campo . ' deve essere un testo.');
        }

        return (string) $valore;
    }

    /** @return list<int> */
    private static function identificativi(mixed $valori): array
    {
        return array_values(array_unique(array_map('intval', array_filter((array) $valori, 'is_numeric'))));
    }
}
