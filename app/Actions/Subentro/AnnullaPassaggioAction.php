<?php

namespace App\Actions\Subentro;

use App\Enums\EventoTipo;
use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Evento;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\TitolaritaImmobile;
use App\Models\User;
use App\Services\Gestionale\EventiRataCondomino;
use App\Services\Gestionale\InboxService;
use App\Services\Subentro\FermataDopoScrivi;
use App\Services\Subentro\FrasiObbligati;
use App\Services\Subentro\GuardieTitolarita;
use App\Services\Subentro\QuoteNateDopoIlPassaggio;
use App\Services\Subentro\RegolaDelPiano;
use App\Services\Subentro\VociDaSpostare;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Annulla un passaggio registrato (1.11.0-beta.37, decisione 27 in `docs/subentro_e_competenza_temporale.md`).
 *
 * Fino alla beta.36 un passaggio non si annullava (decisione 24). Da qui si annulla **l'ultimo passaggio dell'unità** — l'ultimo
 * registrato, non l'ultimo per data, dalla 1.11.0-beta.50 (decisione 83.1) —
 * (e di ciascuna pertinenza passata con lei), **a rate intatte**, rileggendo al contrario il **registro** che il passaggio
 * ha scritto (`RegistraSubentroAction`, punto 8): le righe di titolarità che ha scritto tornano come prima, le bozze passate a chi è
 * entrato tornano a chi è uscito con le stesse regole congelate (e la quota del pregresso, divisa, torna una), il
 * conguaglio si toglie, i promemoria delle rate tornano a chi è uscito, il promemoria in agenda sparisce. Il passaggio
 * **resta** nella tabella, annullato, con la data, chi l'ha annullato e perché; il modello lo nasconde a ogni conto.
 *
 * Una regola sola per lo storico e per il server: `motivoBlocco()` dice perché no, e l'azione la richiama dentro la
 * transazione, **dopo** aver bloccato le righe che legge (vedi `execute()`). Si ferma, dicendo la via, quando:
 *
 * - il passaggio è di prima della beta.37 (nessun registro): ricostruire le righe dalle date sarebbe tirare a indovinare;
 * - dopo di lui, sull'unità o su una sua pertinenza, è registrato un altro passaggio, anche con una data precedente: si annulla
 *   prima quello;
 * - il conguaglio è già assorbito da un piano (la stessa frase di `AnnullaConguaglioAction`);
 * - una quota toccata dopo il passaggio — una bozza passata a chi è entrato, o una quota nata dopo che l'annullamento
 *   cambierebbe (un piano generato o ricalcolato dopo, con la titolarità di dopo: di chi è entrato, di chi è uscito, o di
 *   chi ha preso i giorni rimasti scoperti) — è stata emessa, pagata, o ha un pagamento segnalato dal portale non ancora
 *   verificato (lettura stretta di «rate intatte», dichiarata nel verbale della beta);
 * - una riga di titolarità scritta dal passaggio è stata corretta a mano dopo: rimetterla «come prima» perderebbe la
 *   correzione;
 * - lo stato che l'annullamento lascerebbe non passa le guardie di «Associa» (una riga associata o ritoccata a mano dopo
 *   il passaggio, fuori dal registro, si sovrappone alla riga che si riapre o porta le quote oltre 100).
 *
 * Un piano generato o ricalcolato dopo il passaggio e non ancora emesso non ferma l'annullamento, ma va ricalcolato di nuovo: lo
 * dicono gli `avvisi()`, come l'anteprima del passaggio dice i piani da ricalcolare. Dalla 1.11.0-beta.51 lo dice anche il piano:
 * annullato il passaggio, `PianoRate::passaggiDaSeguire` lo conta fra quelli da seguire (con la chiave `annullamento.quota_max_id`
 * che l'annullamento scrive nel registro) e l'emissione, l'incasso e il rimborso su quel piano si fermano finché non si ricalcola:
 * emesse così, le quote di chi era entrato andrebbero a giornale per un passaggio che non c'è più. Un piano ricalcolato o eliminato
 * che si è portato via le bozze del registro non ferma niente nemmeno lui: non c'è più niente da rimettere.
 */
final class AnnullaPassaggioAction
{
    public function __construct(private readonly EventiRataCondomino $eventi) {}

    /**
     * Perché questo passaggio non si può annullare, o `null`. Senza scrivere niente: la legge anche lo storico.
     */
    /**
     * Rilievo L10 della Fase 1-bis della .44: che cosa un piano ha già assorbito — il conguaglio, o anche l'arretrato del defunto di una
     * successione —, con le sue parole.
     */
    private static function aperturaAssorbite(Subentro $padre, \Illuminate\Support\Collection $assorbite): string
    {
        $dellArretrato = $assorbite->contains(fn (Saldo $x) => in_array((int) $x->id, $padre->saldiDellArretrato(), true));

        return $dellArretrato
            ? 'Le righe dell\'arretrato del defunto di questo passaggio sono già state assorbite da un piano rate, e il passaggio non si annulla così com\'è.'
            : 'Il conguaglio di questo passaggio è già stato assorbito da un piano rate, e il passaggio non si annulla così com\'è.';
    }

    /** Il rifiuto con un saldo del Wallet legato al passaggio già assorbito da un piano: la stessa frase della coppia, con le stesse strade. */
    private static function fraseLegateAssorbite(Collection $legate, array $presiDaSeguire): string
    {
        return AnnullaConguaglioAction::fraseAssorbite($legate, 'Un saldo del Wallet legato a questo passaggio è già stato assorbito da un piano rate, e il passaggio non si annulla così com\'è.',
            'torna qui', perIlPassaggio: true, presiDaSeguire: $presiDaSeguire, legate: true);
    }

    public function motivoBlocco(Subentro $passaggio): ?string
    {
        $padre = $passaggio->subentro_padre_id ? Subentro::conAnnullati()->find($passaggio->subentro_padre_id) : $passaggio;
        if ($padre === null || $padre->annullato()) {
            return 'Questo passaggio è già stato annullato.';
        }
        $famiglia = $this->famiglia($padre);

        if ($famiglia->contains(fn (Subentro $s) => ! is_array($s->registro))) {
            return 'Questo passaggio è stato registrato prima della 1.11.0-beta.37, che non teneva il registro di ciò che '
                . 'scriveva: non si annulla dal programma. Si corregge a mano, da «Modifica associazione» e «Associa soggetto», come prima.';
        }

        if ($motivo = $this->passaggioDopo($famiglia)) {
            return $motivo;
        }

        $assorbite = Saldo::whereIn('subentro_id', $famiglia->pluck('id'))->where('is_applicato', true)->with('pianoRate')->get();
        if ($assorbite->isNotEmpty()) {
            return AnnullaConguaglioAction::fraseAssorbite($assorbite, self::aperturaAssorbite($padre, $assorbite),
                'torna qui', perIlPassaggio: true, presiDaSeguire: $this->presiDaSeguire($famiglia), conArretrato: $padre->arretratoAgliEredi());
        }

        // 1.11.0-beta.53 (Coda 244): un saldo del Wallet legato a questo passaggio, già assorbito da un piano, ferma l'annullamento come la
        // coppia, con le stesse strade d'uscita.
        $legate = Saldo::where('passaggio_id', $padre->id)->where('is_applicato', true)->with('pianoRate')->get();
        if ($legate->isNotEmpty()) {
            return self::fraseLegateAssorbite($legate, $this->presiDaSeguire($famiglia));
        }

        return $this->motivoDelleQuoteEDelleRighe($padre, $famiglia);
    }

    /** Le ultime regole di `motivoBlocco`, dopo i passaggi registrati dopo e i piani: le quote toccate dopo il passaggio, le righe corrette a mano. */
    private function motivoDelleQuoteEDelleRighe(Subentro $padre, Collection $famiglia): ?string
    {
        if ($motivo = $this->quoteNonIntatte($padre, $famiglia)) {
            return $motivo;
        }

        return $this->righeCorrette($famiglia) ?? $this->righeInConflitto($famiglia);
    }

    /**
     * Ciò che l'annullamento lascia da fare: i piani generati o ricalcolati dopo il passaggio, non ancora emessi, con
     * quote che l'annullamento cambierebbe (`quoteNateDopo`).
     *
     * @return list<string>
     */
    public function avvisi(Subentro $padre): array
    {
        $famiglia = $this->famiglia($padre);

        $avvisi = $this->quoteNateDopo($padre, $famiglia)
            ->filter(fn ($q) => $q->scrittura_contabile_id === null)
            ->pluck('piano_nome')->unique()->values()
            ->map(fn (string $piano) => sprintf('Il piano «%s» è stato generato o ricalcolato dopo il passaggio: ricalcolalo di nuovo, così quote e riparto tornano sulla titolarità di prima.', $piano))
            ->all();

        // 1.11.0-beta.53: i saldi scritti a mano dal Wallet dopo la registrazione, senza legarli a un passaggio (la strada a mano di
        // prima del legame, o un saldo qualunque), per ogni passaggio: si nominano, non si toccano. I saldi legati a questo passaggio li dice
        // l'effetto (si tolgono); la fermata dopo «Scrivi» e quella del coinquilino non hanno più un avviso loro: senza saldi non c'è niente da dire.
        array_push($avvisi, ...FermataDopoScrivi::avvisiDeiSaldiAMano($padre));

        // Coda 254 (1.11.0-beta.51): la fine di un conduttore che ha fatto risalire la parte di una fermata di prima; annullata, la parte torna
        // com'era, e un saldo o un accordo adeguato nel frattempo si rivede. Se il conguaglio della fine di prima è già annullato non c'è più niente.
        foreach ($padre->registro['fermata_risale'] ?? [] as $f) {
            if (! FermataDopoScrivi::fineSenzaConguaglio($f)) {
                $avvisi[] = FermataDopoScrivi::avvisoRisaleAnnullando($f);
            }
        }

        // Coda 245 (decisione 95.6 b): il passaggio con la regola del piano che ha lasciato fuori dal conguaglio una parte (il resto): annullato,
        // quella parte non c'è più, e i saldi del Wallet legati al passaggio si tolgono con lui. Anche dopo «Annulla il conguaglio», della fine di
        // prima o di questo passaggio: il resto è la differenza del piano del passaggio (decisione 111.3), e fin lì valeva.
        foreach ($padre->registro['resto_del_piano'] ?? [] as $r) {
            if (! empty($r['wallet'])) {
                $avvisi[] = RegolaDelPiano::avvisoDelRestoAnnullando($r, Saldo::where('passaggio_id', (int) $padre->id)->where('immobile_id', (int) $r['immobile_id'])->exists());
            }
        }

        // Decisione 79 (2) e 86.1: l'inizio di una locazione che ha ridotto o azzerato una fermata di prima; annullato, la parte da regolare torna com'era.
        foreach ($padre->registro['fermata_ridotta'] ?? [] as $f) {
            if (! FermataDopoScrivi::fineSenzaConguaglio($f)) {
                $avvisi[] = FermataDopoScrivi::avvisoRidottaAnnullando($f);
            }
        }

        // Decisione 31.7 (1.11.0-beta.41): le voci che il passaggio ha spostato all'«Usufruttuario» restano dove sono. Valgono
        // per tutta la tabella e l'amministratore può averle toccate dopo: si nominano, non si disfano.
        $voci = $padre->registro['voci_spostate'] ?? [];
        if ($voci !== []) {
            $uno = count($voci) === 1;
            $avvisi[] = sprintf($uno
                ? 'La voce che questo passaggio ha spostato dal «Proprietario» all\'«Usufruttuario» resta com\'è: %s. Vale per tutta la tabella, anche per le altre unità in usufrutto; se va riportata com\'era, si cambia dalla pagina della voce.'
                : 'Le voci che questo passaggio ha spostato dal «Proprietario» all\'«Usufruttuario» restano come sono: %s. Valgono per tutta la tabella, anche per le altre unità in usufrutto; se vanno riportate com\'erano, si cambiano dalla pagina della voce.',
                // Rilievo A6 della Fase 1-bis: i coefficienti di prima, dal registro. «Al Proprietario» era falso per una voce divisa.
                $this->elenco(array_map(fn (array $v) => VociDaSpostare::etichetta($v, sprintf('; prima %s', VociDaSpostare::coefficientiAParole($v['prima'] ?? []))), $voci)));
        }

        // Decisione 46 (rilievo W2): con la rinuncia o con il conguaglio annullato le parti hanno regolato fra loro. Annullato e
        // registrato di nuovo, il passaggio non ha più niente da regolare, e il piano ricalcolato addebita a chi entra i suoi giorni.
        if ($padre->conguaglioRinunciato() || $padre->conguaglioAnnullato()) {
            $cifra = $padre->regolatoFuoriInParole();
            if ($cifra !== null || ! is_array($padre->registro['regolato_fuori'] ?? null)) {
                // Decisione 72 (1.11.0-beta.48): il conguaglio non scritto della successione non è un accordo fra le parti. Dalla .50 anche
                // quello della locazione (decisione 77), e la frase dice di quale passaggio è.
                $avvisi[] = $padre->conguaglioNonScritto() && ! $padre->conguaglioAnnullato()
                    ? sprintf('Il conguaglio %s non è stato scritto (%s): se il passaggio si registra di nuovo e il piano si ricalcola, il condominio addebita %s.',
                        $padre->conguaglioInParole(), $cifra ?? 'le cifre non sono nel registro',
                        // Coda 245 (1.11.0-beta.55): con la regola del piano, a ciascun conduttore la sua parte, per quota e per giorni.
                        ($padre->registro['conguaglio']['piano'] ?? []) !== [] ? $padre->aChiVannoIGiorni() : 'a ciascuno i suoi giorni')
                    : sprintf('Le parti hanno già regolato fra loro %s: se il passaggio si registra di nuovo e il piano si ricalcola, il condominio addebita %s, e quell\'accordo va rifatto fra le parti.',
                        $cifra ?? 'il conguaglio di questo passaggio', $padre->aChiVannoIGiorni());
            }
        }

        return $avvisi;
    }

    /**
     * Che cosa l'annullamento rimette, detto prima di confermare: solo ciò che questo passaggio ha davvero toccato (una
     * locazione non sposta rate, una rinuncia non scrive conguaglio, un piano ricalcolato si è portato via le bozze).
     *
     * @return list<string>
     */
    public function effetti(Subentro $padre): array
    {
        $famiglia = $this->famiglia($padre);
        $rate = $this->quoteDelRegistro($padre)['voci']->pluck('rata_id')->unique()->count();

        return self::frasiEffetti(
            ['rate' => $rate, 'saldi' => Saldo::whereIn('subentro_id', $famiglia->pluck('id'))->count(), 'arretrato' => count($padre->saldiDellArretrato()),
                'legate' => self::legateInParole(Saldo::where('passaggio_id', $padre->id)->orderBy('id')->get())],
            $this->nome($padre, 'uscente'), $this->nome($padre, 'entrante'), false, $padre->successione(),
        );
    }

    /**
     * Le frasi dell'esito, prima (`$fatto` falso, nel modulo) o dopo (nel messaggio di conferma): la stessa lettura. `$successione` non cambia la
     * preposizione, che è la stessa in ogni passaggio.
     *
     * @param array{rate: int, saldi: int, arretrato?: int, legate?: array{testo: string, plurale: bool}|null} $conti
     * @return list<string>
     */
    public static function frasiEffetti(array $conti, ?string $uscente, ?string $entrante, bool $fatto, bool $successione = false): array
    {
        // «ad» davanti ai nomi che cominciano per A, in ogni passaggio, come in `descrivi`.
        $a = fn (string $nome) => FrasiObbligati::a($nome);
        // «Scritte dal passaggio»: una riga associata a mano dopo, che non si scontra con niente, resta (giro di verifica, G-4).
        $frasi = [$fatto ? 'Le righe di titolarità scritte dal passaggio sono tornate come prima.' : 'Le righe di titolarità scritte dal passaggio tornano come prima.'];
        $n = (int) $conti['rate'];
        if ($n > 0) {
            $frasi[] = sprintf('%s %s %s, con le regole di prima.',
                ($n === 1 ? 'La quota di una rata passata ' : "Le quote di {$n} rate passate ") . $a($entrante ?? 'chi è entrato'),
                $fatto ? ($n === 1 ? 'è tornata' : 'sono tornate') : ($n === 1 ? 'torna' : 'tornano'),
                $a($uscente ?? 'chi è uscito'));
        }
        // 1.11.0-beta.44: le righe dell'arretrato di una successione se ne vanno con il conguaglio, e si dice.
        $arretrato = (int) ($conti['arretrato'] ?? 0) > 0;
        $conguaglio = (int) $conti['saldi'] - (int) ($conti['arretrato'] ?? 0) > 0;
        if ($conguaglio || $arretrato) {
            $cosa = match (true) {
                $conguaglio && $arretrato => 'Il conguaglio e l\'arretrato del defunto',
                $arretrato => 'L\'arretrato del defunto',
                default => 'Il conguaglio',
            };
            $frasi[] = $cosa . ($fatto ? ($conguaglio && $arretrato ? ' sono stati tolti' : ' è stato tolto') : ($conguaglio && $arretrato ? ' si tolgono' : ' si toglie')) . ' dai saldi della gestione.';
        }
        // 1.11.0-beta.53 (Coda 244): i saldi del Wallet legati al passaggio si tolgono con lui, e si dicono con il verso a parole.
        if (! empty($conti['legate']['testo'])) {
            $piu = (bool) $conti['legate']['plurale'];
            $frasi[] = sprintf('%s %s: %s.', $piu ? 'I saldi del Wallet legati a questo passaggio' : 'Il saldo del Wallet legato a questo passaggio',
                $fatto ? ($piu ? 'sono stati tolti' : 'è stato tolto') : ($piu ? 'si tolgono' : 'si toglie'), $conti['legate']['testo']);
        }

        return $frasi;
    }

    /**
     * Le righe legate al passaggio, nelle parole degli effetti: «€ 60,16 a credito di Ugo e € 60,16 a debito di Elsa (gestione Ordinaria 2026)»;
     * null se non ce ne sono (una riga a zero non si dice).
     *
     * @param Collection<int, Saldo> $legate
     * @return array{testo: string, plurale: bool}|null
     */
    private static function legateInParole(Collection $legate): ?array
    {
        $dette = $legate->filter(fn (Saldo $x) => (int) $x->saldo_iniziale !== 0)->values()->load(['anagrafica:id,nome', 'gestione:id,nome']);

        return $dette->isEmpty() ? null : ['testo' => FermataDopoScrivi::righeInParole($dette), 'plurale' => $dette->count() > 1];
    }

    /**
     * @return array{righe: int, rate: int, saldi: int, arretrato: int, avvisi: list<string>, uscente: ?string, entrante: ?string, successione: bool, legate: array{testo: string, plurale: bool}|null}
     */
    public function execute(Subentro $passaggio, string $nota, User $utente): array
    {
        $nota = trim($nota);
        if (mb_strlen($nota) < 10) {
            throw ValidationException::withMessages(['nota_annullamento' => 'Scrivi in almeno dieci caratteri perché annulli il passaggio: resta nello storico.']);
        }

        // Il passaggio e le sue pertinenze, letti prima della transazione: le righe figlie nascono solo con il padre, nella
        // transazione della registrazione, e le loro unità non cambiano più.
        $padreId = (int) ($passaggio->subentro_padre_id ?? $passaggio->id);
        $famigliaUnita = Subentro::conAnnullati()->where(fn ($q) => $q->whereKey($padreId)->orWhere('subentro_padre_id', $padreId))->pluck('immobile_id', 'id');
        $idsFamiglia = $famigliaUnita->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();
        $unita = $famigliaUnita->values()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        // Tre tentativi: un'emissione di più rate può prendere le quote dell'unità in un ordine diverso, e InnoDB scioglie
        // l'incrocio annullando una delle due transazioni. Il corpo scrive solo nel database, e rifarlo è sicuro.
        $esito = DB::transaction(function () use ($padreId, $idsFamiglia, $unita, $nota, $utente) {
            // 0. I lock, PRIMA di qualunque lettura senza lock. Su MySQL (REPEATABLE READ) l'istantanea delle letture nasce
            //    alla prima SELECT semplice: presi dopo, i lock non farebbero vedere ai controlli un passaggio, un'emissione
            //    o un «Associa» appena committati, e le scritture qui sotto li sovrascriverebbero (Fase 1-bis della beta.37,
            //    A2, riprodotto su MySQL). Prima l'unità, nello stesso ordine di `RegistraSubentroAction`: con i passaggi
            //    bloccati per primi (e l'intervallo dell'indice dei figli con loro) una registrazione in volo sulla stessa
            //    unità si incrociava con l'annullamento, e MySQL ne uccideva uno (1213, giro di verifica, C-R1, riprodotto).
            //    Poi i passaggi per chiave primaria, e le righe, le quote e i saldi dell'unità, che scrivono anche
            //    «Associa», l'emissione e l'incasso. Nessun `orderBy` sulle letture bloccanti: InnoDB blocca nell'ordine
            //    dell'indice che usa, e un ordinamento può fargli scegliere la chiave primaria e bloccare tutta la tabella.
            Immobile::bloccaPerScrivere($unita);
            $bloccati = Subentro::conAnnullati()->whereIn('id', $idsFamiglia)->lockForUpdate()->get()->keyBy('id');
            $padre = $bloccati->get($padreId) ?? throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(Subentro::class, [$padreId]);
            $voci = collect($padre->registro['quote'] ?? []);
            DB::table('anagrafica_immobile')->whereIn('immobile_id', $unita)->lockForUpdate()->pluck('id');
            DB::table('rate_quote')->whereIn('immobile_id', $unita)->lockForUpdate()->pluck('id');
            DB::table('rate_quote')->whereIn('id', $voci->pluck('id')->merge($voci->pluck('gemella_id'))->filter()->all())->lockForUpdate()->pluck('id');
            // I promemoria delle rate di chi esce e di chi entra, che `seguonoLeQuote` riscrive e che il controllo delle
            // segnalazioni legge: una segnalazione dal portale in volo aspetta, e poi rilegge (C-R5). Dalla tabella ponte
            // e per chiave, con letture bloccanti: una lettura semplice qui fisserebbe l'istantanea troppo presto.
            $persone = array_values(array_filter([(int) $padre->anagrafica_uscente_id, (int) $padre->anagrafica_entrante_id]));
            // Solo le chiavi dall'indice della persona, poi per chiave primaria: chiedere `evento_id` all'indice della
            // persona fa scegliere a MySQL la scansione dell'indice unico, che sotto lock bloccherebbe tutta la tabella
            // (verificato con EXPLAIN su MySQL 8.4, giro di verifica C-R6).
            $ponte = DB::table('anagrafica_evento')->whereIn('anagrafica_id', $persone)->lockForUpdate()->pluck('id')->all();
            $promemoria = DB::table('anagrafica_evento')->whereIn('id', $ponte)->lockForUpdate()->pluck('evento_id')->all();
            DB::table('eventi')->whereIn('id', $promemoria)->lockForUpdate()->pluck('id');
            $saldi = Saldo::whereIn('subentro_id', $idsFamiglia)->lockForUpdate()->with('pianoRate')->get();
            // 1.11.0-beta.53 (Coda 244): i saldi del Wallet legati al passaggio, dopo le righe che il passaggio ha scritto.
            $legate = Saldo::where('passaggio_id', $padreId)->lockForUpdate()->get();

            $famiglia = $this->famiglia($padre);
            if ($motivo = $this->motivoBlocco($padre)) {
                throw ValidationException::withMessages(['passaggio' => $motivo]);
            }
            $avvisi = $this->avvisi($padre);

            // 1. Il conguaglio: via tutte le righe del passaggio e delle pertinenze (la FK `saldi.subentro_id` è restrict).
            //    Riletto sulle righe bloccate, come `AnnullaConguaglioAction`.
            $assorbite = $saldi->filter(fn (Saldo $x) => (bool) $x->is_applicato);
            if ($assorbite->isNotEmpty()) {
                throw ValidationException::withMessages(['passaggio' => AnnullaConguaglioAction::fraseAssorbite($assorbite, self::aperturaAssorbite($padre, $assorbite),
                    'torna qui', perIlPassaggio: true, presiDaSeguire: $this->presiDaSeguire($famiglia), conArretrato: $padre->arretratoAgliEredi())]);
            }
            if ((int) $saldi->sum('saldo_iniziale') !== 0) {
                throw ValidationException::withMessages(['passaggio' => 'Le righe del conguaglio di questo passaggio non sommano zero: qualcosa è stato modificato a mano. Controlla i saldi della gestione prima di annullare.']);
            }
            Saldo::whereIn('id', $saldi->pluck('id'))->delete();

            //    Poi i saldi del Wallet legati al passaggio, bloccati qui sopra: un'assorbita l'ha già fermato `motivoBlocco`, letto dopo i blocchi
            //    nella stessa transazione; le libere si tolgono, anche una gamba sola (sono righe scritte a mano, non una coppia che deve sommare
            //    zero), e il registro le conserva.
            $legateInParole = self::legateInParole($legate);
            $legateTolte = $legate->map(fn (Saldo $x) => ['id' => (int) $x->id, 'anagrafica_id' => $x->anagrafica_id === null ? null : (int) $x->anagrafica_id, 'gestione_id' => (int) $x->gestione_id,
                'immobile_id' => (int) $x->immobile_id, 'esercizio_id' => (int) $x->esercizio_id, 'saldo_iniziale' => (int) $x->saldo_iniziale])->values()->all();
            Saldo::whereIn('id', $legate->pluck('id'))->delete();

            // 2. Le bozze passate a chi è entrato tornano com'erano; la quota del pregresso rimasta a chi esce si riunisce.
            //    Una bozza sparita col suo piano (ricalcolato o eliminato dopo) non ha niente da rimettere: si salta, e non
            //    si conta; la riconosce la traccia del passaggio dentro la quota, non l'id (`quoteDelRegistro`, MySQL 5.7).
            //    L'UPDATE è condizionato allo stato di dopo: se tocca zero righe qualcosa è cambiato, e si torna indietro
            //    per intero.
            ['voci' => $esistenti, 'gemelle' => $gemelle] = $this->quoteDelRegistro($padre);
            $rateToccate = [];
            foreach (array_reverse($voci->all()) as $voce) {
                if (! $esistenti->has((int) $voce['id'])) {
                    continue;
                }
                if (! empty($voce['gemella_id']) && $gemelle->has((int) $voce['gemella_id'])) {
                    // DC4, la terza sede (decisione 75.3, 1.11.0-beta.49): i legami della gemella del pregresso con le scritture
                    // (una compensazione o un rimborso stornati, la pivot a somma zero) passano alla quota con cui si riunisce,
                    // invece di andarsene a cascata con lei. La quota equivalente qui è certa: è quella da cui la gemella è nata.
                    DB::table('quota_scrittura')->where('rate_quota_id', $voce['gemella_id'])->update(['rate_quota_id' => (int) $voce['id']]);
                    DB::table('rate_quote')->where('id', $voce['gemella_id'])->delete();
                }
                $rimessa = DB::table('rate_quote')->where('id', $voce['id'])
                    ->where('anagrafica_id', $voce['dopo']['anagrafica_id'])->where('importo', $voce['dopo']['importo'])
                    ->whereNull('scrittura_contabile_id')->where('importo_pagato', 0)
                    ->update([
                        'anagrafica_id'  => $voce['prima']['anagrafica_id'],
                        'importo'        => $voce['prima']['importo'],
                        'stato'          => $voce['prima']['stato'],
                        'regole_calcolo' => $voce['prima']['regole_calcolo'],
                        'updated_at'     => now(),
                    ]);
                if ($rimessa !== 1) {
                    throw ValidationException::withMessages(['passaggio' => 'Mentre annullavi è cambiata una quota passata con questo passaggio: ricarica la pagina e riprova.']);
                }
                $rateToccate[(int) $voce['rata_id']] = true;
            }

            // 3. Le righe di titolarità, unità per unità, al contrario.
            $righe = 0;
            foreach ($famiglia as $s) {
                foreach (array_reverse($s->registro['righe'] ?? []) as $op) {
                    match ($op['operazione']) {
                        'aperta' => DB::table('anagrafica_immobile')->where('id', $op['id'])->delete(),
                        'chiusa' => DB::table('anagrafica_immobile')->where('id', $op['id'])->update(['data_fine' => $op['prima']['data_fine'], 'updated_at' => now()]),
                        'modificata' => DB::table('anagrafica_immobile')->where('id', $op['id'])->update($op['prima'] + ['updated_at' => now()]),
                    };
                    $righe++;
                }
            }

            // 4. I promemoria delle rate nel portale seguono di nuovo le quote; il promemoria in agenda del passaggio sparisce.
            if ($rateToccate !== []) {
                $this->eventi->seguonoLeQuote(array_keys($rateToccate), array_values(array_filter([(int) $padre->anagrafica_uscente_id, (int) $padre->anagrafica_entrante_id])), $utente);
            }
            Evento::where('eventable_type', Subentro::class)->whereIn('eventable_id', $idsFamiglia)->delete();

            // 5. Il passaggio resta, annullato. Il nome di chi annulla si copia nel registro, come quelli delle parti: la
            //    chiave esterna è `nullOnDelete`, e un utente che sparisce non deve cancellare la traccia.
            $uscente = $this->nome($padre, 'uscente');
            $entrante = $this->nome($padre, 'entrante');
            // Il fatto dell'annullamento (1.11.0-beta.51, §6.3; il dato si scrive, non si deriva): il più alto id di
            // quota di questo momento. Le quote più alte nascono da un ricalcolo fatto dopo, con la titolarità di prima, e non sono
            // stantie; quelle fino a qui, nate dopo il passaggio, hanno la titolarità del passaggio che non c'è più, e il piano non si
            // emette senza ricalcolo (`PianoRate::passaggiAnnullatiDaSeguire`). Letto qui, dopo i lock sulle quote dell'unità e con il
            // ricalcolo serializzato da `Immobile::bloccaDelCondominio`. Non c'è migrazione: il registro è JSON.
            $quotaMax = (int) DB::table('rate_quote')->max('id');
            foreach ($famiglia as $s) {
                $registro = $s->registro;
                $registro['nomi']['annullato_da'] = $utente->name;
                // I saldi legati tolti, sul passaggio padre: una cancellazione si lascia ispezionare.
                $registro['annullamento'] = ['quota_max_id' => $quotaMax] + ((int) $s->id === $padreId && $legateTolte !== [] ? ['saldi_legati_tolti' => $legateTolte] : []);
                $s->forceFill(['annullato_il' => now(), 'annullato_da' => $utente->id, 'nota_annullamento' => $nota, 'registro' => $registro])->save();
            }

            return ['righe' => $righe, 'rate' => count($rateToccate), 'saldi' => $saldi->count(), 'arretrato' => count($padre->saldiDellArretrato()), 'avvisi' => $avvisi, 'uscente' => $uscente, 'entrante' => $entrante,
                'successione' => $padre->successione(), 'legate' => $legateInParole];
        }, 3);

        // Il contatore dei promemoria dello staff è in cache per dieci minuti: il promemoria tolto non deve contare ancora.
        InboxService::clearAdminCache();

        return $esito;
    }

    // --- Le regole -----------------------------------------------------------------------------------

    /** Il passaggio e le sue pertinenze: la stessa operazione. @return Collection<int, Subentro> */
    private function famiglia(Subentro $padre): Collection
    {
        $figli = Subentro::conAnnullati()->with('immobile:id,nome')->where('subentro_padre_id', $padre->id)->orderBy('id')->get();

        return collect([$padre->loadMissing('immobile:id,nome')])->merge($figli);
    }

    /** Le unità della famiglia. @return list<int> */
    private function unita(Collection $famiglia): array
    {
        return QuoteNateDopoIlPassaggio::unita($famiglia);
    }

    /** Il nome di una parte, dal registro se la persona non c'è più. */
    private function nome(Subentro $s, string $parte): ?string
    {
        $persona = $parte === 'uscente' ? $s->uscente()->first(['id', 'nome']) : $s->entrante()->first(['id', 'nome']);

        return $persona?->nome ?? ($s->registro['nomi'][$parte] ?? null);
    }

    /**
     * Un altro passaggio registrato dopo questo, su una delle sue unità? Lo nomina come lo storico (tipo e parti) e dice da quale
     * storico si annulla: il passaggio di una pertinenza registrato con un'altra unità sta nello storico di quell'unità
     * (nella scheda della pertinenza compaiono solo i suoi passaggi padre); il passaggio suo di una pertinenza della
     * famiglia sta nello storico della pertinenza, non in quello dell'unità da cui si sta annullando. Gli incisi stanno
     * accanto a «un altro passaggio», che è maschile: «la vendita…, registrato» non concorda (giro di verifica, G-6).
     *
     * Decisione 83.1 (1.11.0-beta.50): «l'ultimo passaggio» della decisione 27 è l'ultimo registrato, non l'ultimo per data. Un passaggio
     * registrato dopo, con una data precedente, può aver letto ciò che questo ha scritto (la coppia di una fine «Scrivi» divisa sulla
     * titolarità di dopo la vendita, le fonti di una successione, una fermata): annullare prima questo lascerebbe soldi sbagliati. Un
     * passaggio legge solo ciò che è registrato prima di lui, e quello con l'id più alto si può sempre annullare: nessuno stallo.
     */
    private function passaggioDopo(Collection $famiglia): ?string
    {
        $qui = (int) $famiglia->first()->immobile_id;
        foreach ($famiglia as $s) {
            $ultimo = Subentro::with(['uscente:id,nome', 'entrante:id,nome', 'padre.immobile:id,nome'])->where('immobile_id', $s->immobile_id)
                ->orderByDesc('id')->first();
            if ($ultimo === null || (int) $ultimo->id === (int) $s->id) {
                continue;
            }
            $unita = $s->immobile?->nome ?? 'l\'unità';
            $stessoGiorno = $ultimo->decorrenza?->toDateString() === $s->decorrenza?->toDateString();
            $prima = ! $stessoGiorno && $ultimo->decorrenza !== null && $s->decorrenza !== null && $ultimo->decorrenza->lt($s->decorrenza);
            $insieme = $ultimo->subentro_padre_id !== null && $ultimo->padre !== null && (int) $ultimo->padre->immobile_id !== (int) $s->immobile_id
                ? ($ultimo->padre->immobile?->nome ?? 'un\'altra unità') : null;
            $inciso = match (true) {
                $stessoGiorno && $insieme !== null => ' con la stessa data, registrato dopo, insieme ' . FrasiObbligati::a($insieme),
                $stessoGiorno => ' con la stessa data, registrato dopo',
                $prima && $insieme !== null => ', registrato dopo, con una data precedente, insieme ' . FrasiObbligati::a($insieme),
                $prima => ', registrato dopo, con una data precedente',
                $insieme !== null => ', registrato insieme ' . FrasiObbligati::a($insieme),
                default => '',
            };
            $via = match (true) {
                $insieme !== null => "Si annulla dallo storico di {$insieme}, poi questo.",
                (int) $s->immobile_id !== $qui => "Si annulla prima quello, dallo storico di {$unita}, poi questo.",
                default => 'Si annulla prima quello, poi questo.',
            };

            return sprintf('Dopo questo, su %s, c\'è un altro passaggio%s: %s, dal %s. %s', $unita, $inciso, self::descrivi($ultimo), $this->data($ultimo->decorrenza), $via);
        }

        return null;
    }

    /**
     * Rilievo X3 del terzo giro: i piani che un membro della famiglia ha preso nel conguaglio e che devono ancora seguire un
     * passaggio — il passaggio della .41 preso solo in parte (V3), o un piano preso che segue un altro passaggio. Per loro la strada
     * breve della decisione 50 («il passaggio può restare, il piano si emette così com'è») è falsa: non si emettono senza ricalcolo.
     *
     * @return list<string> i nomi dei piani
     */
    private function presiDaSeguire(Collection $famiglia): array
    {
        $pianoIds = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->whereIn('rate_quote.immobile_id', $famiglia->pluck('immobile_id')->unique()->all())->distinct()->pluck('rate.piano_rate_id');

        return PianoRate::whereIn('id', $pianoIds)->get()
            ->filter(fn (PianoRate $p) => $famiglia->contains(fn (Subentro $s) => $p->presoNelConguaglioDa($s)) && $p->passaggiDaSeguire() !== [])
            ->pluck('nome')->values()->all();
    }

    /**
     * Il passaggio in parole, come lo legge lo storico: «la vendita da Ugo a Elsa», «la fine locazione di Luca». Anche per il
     * rifiuto dell'estinzione (rilievo V8).
     */
    public static function descrivi(Subentro $s): string
    {
        $uscente = $s->uscente?->nome ?? ($s->registro['nomi']['uscente'] ?? null);
        $entrante = $s->entrante?->nome ?? ($s->registro['nomi']['entrante'] ?? null);

        return match ($s->tipo_passaggio) {
            // Testi T8 della beta.38: «o donazione», come il tipo di base; la donazione della nuda proprietà è il caso più frequente.
            // Rilievo W4 del giro sulle correzioni della .42: la vendita della sola nuda proprietà si dice, perché due vendite dello
            // stesso giorno fra le stesse persone (la piena e la nuda) non si confondano. La piena resta «la vendita».
            'vendita' => ($s->riservaUsufrutto() ? 'la vendita o donazione con riserva d\'usufrutto' : ($s->tipologia === 'nuda_proprietario' ? 'la vendita della nuda proprietà' : 'la vendita')) . ($uscente !== null ? " da {$uscente}" : '') . ($entrante !== null ? ' ' . FrasiObbligati::a($entrante) : ''),
            'inizio_locazione' => 'l\'inizio locazione' . ($entrante !== null ? ' ' . FrasiObbligati::a($entrante) : ''),
            'fine_locazione' => 'la fine locazione' . ($uscente !== null ? " di {$uscente}" : '') . ($entrante !== null ? ", con {$entrante} al suo posto" : ''),
            'usufrutto' => $s->tipologia === 'proprietario'
                ? 'l\'estinzione dell\'usufrutto' . ($uscente !== null ? " di {$uscente}" : '') . ($s->conAccrescimento() ? ', con l\'accrescimento' : '')
                : 'la costituzione dell\'usufrutto' . ($entrante !== null ? " a favore di {$entrante}" : ''),
            // 1.11.0-beta.44: chi entra è uno solo degli eredi (decisione 65), e non si nomina.
            'successione' => 'la successione' . ($s->tipologia === 'nuda_proprietario' ? ' nella nuda proprietà' : '') . ($uscente !== null ? " di {$uscente}" : '') . ($s->legato() ? ', per legato' : ''),
            default => 'un passaggio',
        };
    }

    /** Chi il passaggio ha fatto entrare, su tutta la famiglia (vedi `QuoteNateDopoIlPassaggio::entrati`). @return list<int> */
    private function entrati(Collection $famiglia): array
    {
        return QuoteNateDopoIlPassaggio::entrati($famiglia);
    }

    /**
     * Le quote del registro che esistono ancora e sono ancora quelle del passaggio, riconosciute dalla traccia che il passaggio ha
     * scritto dentro ciascuna e non solo dall'id (vedi `QuoteNateDopoIlPassaggio::quoteDelRegistro`).
     *
     * @return array{voci: Collection<int, object>, gemelle: Collection<int, object>} per id
     */
    private function quoteDelRegistro(Subentro $padre): array
    {
        return QuoteNateDopoIlPassaggio::quoteDelRegistro($padre);
    }

    /** `regole_calcolo` di una quota letta con il query builder, come array. */
    private function regole(object $q): array
    {
        return QuoteNateDopoIlPassaggio::regole($q);
    }

    /**
     * Le quote nate dopo il passaggio sulle sue unità che l'annullamento cambierebbe: un piano generato o ricalcolato dopo, con la
     * titolarità di dopo. La regola, con i suoi casi, sta in `QuoteNateDopoIlPassaggio`: dalla beta.51 la legge anche
     * `PianoRate::passaggiAnnullatiDaSeguire`, e le due non possono divergere.
     */
    private function quoteNateDopo(Subentro $padre, Collection $famiglia): Collection
    {
        return QuoteNateDopoIlPassaggio::per($padre, $famiglia);
    }

    private function quoteNonIntatte(Subentro $padre, Collection $famiglia): ?string
    {
        $voci = collect($padre->registro['quote'] ?? []);
        ['voci' => $vociTrovate, 'gemelle' => $gemelleTrovate] = $this->quoteDelRegistro($padre);
        $gemelle = $gemelleTrovate->keys()->map(fn ($id) => (int) $id)->all();
        $delRegistro = $vociTrovate->values()->concat($gemelleTrovate->values());
        $toccate = $delRegistro->concat($this->quoteNateDopo($padre, $famiglia));
        $uscenteId = (int) $padre->anagrafica_uscente_id;
        $entranteId = (int) $padre->anagrafica_entrante_id;
        $entrati = $this->entrati($famiglia);
        $nomi = DB::table('anagrafiche')->whereIn('id', $toccate->pluck('anagrafica_id')->push($uscenteId, $entranteId)->unique())->pluck('nome', 'id');

        // Emesse: tutte, piano per piano, e la via intera. `EmissioneRateController::destroy` rifiuta l'annullamento
        // dell'emissione per un pagamento qualunque sulla rata, di chiunque sia, o un credito usato o rimborsato (si guarda
        // tutta la rata, come fa lei). Con pagamenti la via è quella intera, con i suoi costi detti (Vincenzo, 29/09/2026:
        // giro di verifica, G-5). Il consiglio «annulla prima il conguaglio», per la guardia della beta.31, non c'è più
        // (rilievo W8 del giro sulle correzioni della .42): una rata emessa dopo il passaggio non la blocca il passaggio stesso
        // (si guarda l'ora della scrittura), la guardia vale solo per i passaggi registrati prima della .42 (decisione 49), e
        // annullare il conguaglio non riapre niente (decisione 43).
        $emesse = $toccate->filter(fn ($q) => $q->scrittura_contabile_id !== null);
        if ($emesse->isNotEmpty()) {
            $chi = $emesse->first(fn ($q) => in_array((int) $q->anagrafica_id, $entrati, true) && ! in_array((int) $q->id, $gemelle, true))
                ?? $emesse->first(fn ($q) => ! in_array((int) $q->id, $gemelle, true)) ?? $emesse->first();
            $elenco = $emesse->groupBy('piano_nome')->map(function (Collection $q, string $piano) {
                $numeri = $q->pluck('numero_rata')->map(fn ($n) => (int) $n)->unique()->sort()->values()->all();

                return sprintf('%s %s del piano «%s»', count($numeri) === 1 ? 'la rata' : 'le rate', $this->elenco($numeri), $piano);
            })->values()->all();
            $una = $emesse->pluck('rata_id')->unique()->count() === 1;
            $conPagamenti = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
                ->whereIn('rate_quote.rata_id', $emesse->pluck('rata_id')->unique()->all())->where('rate_quote.importo_pagato', '!=', 0)
                ->pluck('rate.numero_rata')->map(fn ($n) => (int) $n)->unique()->sort()->values()->all();
            $chiNome = $nomi[$chi->anagrafica_id] ?? 'chi è entrato';
            // «ad» davanti ai nomi che cominciano per A, in ogni passaggio, come in `descrivi`.
            $aChi = FrasiObbligati::a($chiNome);

            if ($conPagamenti === []) {
                return sprintf('Dopo il passaggio %s %s %s. Annulla l\'emissione di %s, poi torna qui: le rate già in mano ai condòmini non si spostano da sole.',
                    $una ? 'è stata emessa' : 'sono state emesse', $aChi, $this->elenco($elenco), $una ? 'quella rata' : 'quelle rate');
            }

            $pagate = count($conPagamenti) === 1;

            return sprintf('Dopo il passaggio %s %s %s, e %s %s ci sono già dei pagamenti. L\'emissione di una rata non si annulla finché su una qualunque delle sue quote c\'è un pagamento, di chiunque sia, o un credito già usato o rimborsato. Per annullare il passaggio andrebbero stornati prima tutti gli incassi di %s, anche quelli degli altri condòmini, e poi annullata l\'emissione; dopo, %s e gli incassi si registrano di nuovo. Ogni storno resta nel giornale e toglie l\'incasso intero, anche sulle altre rate che pagava.',
                $una ? 'è stata emessa' : 'sono state emesse', $aChi, $this->elenco($elenco),
                $pagate ? 'sulla rata' : 'sulle rate', $this->elenco($conPagamenti),
                $pagate ? 'quella rata' : 'quelle rate', $pagate ? 'la rata si emette di nuovo' : 'le rate si emettono di nuovo');
        }
        if ($q = $toccate->first(fn ($q) => (int) $q->importo_pagato !== 0)) {
            return sprintf('Sulla rata %d del piano «%s» c\'è già un pagamento di %s. Storna prima l\'incasso, poi torna qui.', $q->numero_rata, $q->piano_nome, $nomi[$q->anagrafica_id] ?? 'chi è entrato');
        }

        // Un pagamento segnalato dal portale e non verificato, sulle persone di cui l'annullamento riscrive il
        // promemoria: chi esce e chi entra sulle rate del registro (`seguonoLeQuote` riscrive lo stato di entrambi, e
        // lo stato nuovo cancellerebbe il «segnalato»), e chi ha una quota nata dopo. R17 della beta.34 legge solo chi
        // esce, perché il passaggio riscrive solo quello; la segnalazione di un altro condòmino sulla stessa rata non
        // c'entra.
        $coppie = [];
        foreach ($delRegistro as $q) {
            foreach ([$uscenteId, $entranteId, (int) $q->anagrafica_id] as $persona) {
                $coppie[$q->rata_id . ':' . $persona] = $q;
            }
        }
        foreach ($toccate as $q) {
            $coppie[$q->rata_id . ':' . $q->anagrafica_id] = $q;
        }
        $persone = collect(array_keys($coppie))->map(fn ($k) => (int) explode(':', $k)[1])->filter()->unique()->values()->all();
        if ($persone !== []) {
            $segnalata = Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)->where('meta->status', 'reported')
                ->whereHas('anagrafiche', fn ($w) => $w->whereIn('anagrafica_id', $persone))
                ->with('anagrafiche:id,nome')->get()
                ->flatMap(fn (Evento $e) => $e->anagrafiche->map(fn ($a) => ['chiave' => ((int) ($e->meta['context']['rata_id'] ?? 0)) . ':' . $a->id, 'nome' => $a->nome]))
                ->first(fn (array $x) => isset($coppie[$x['chiave']]));
            if ($segnalata !== null) {
                $q = $coppie[$segnalata['chiave']];

                return sprintf('Sulla rata %d del piano «%s» c\'è un pagamento segnalato dal portale da %s, non ancora verificato. Verificalo o rifiutalo, poi torna qui.', $q->numero_rata, $q->piano_nome, $segnalata['nome']);
            }
        }

        // Una bozza cambiata dopo il passaggio (nome o importo) non si rimette «come prima» senza perdere la modifica. Una
        // bozza che non esiste più se n'è andata col suo piano (ricalcolato o eliminato): non c'è niente da perdere.
        foreach ($voci as $voce) {
            $q = $vociTrovate->get((int) $voce['id']);
            if ($q === null) {
                continue;
            }
            if ((int) $q->anagrafica_id !== (int) $voce['dopo']['anagrafica_id'] || (int) $q->importo !== (int) $voce['dopo']['importo']) {
                return sprintf('Una quota passata con questo passaggio (rata %d del piano «%s») è stata modificata dopo. L\'annullamento la rimetterebbe com\'era prima del passaggio, perdendo la modifica: controllala, poi correggi a mano.', $q->numero_rata, $q->piano_nome);
            }
        }

        return null;
    }

    private function righeCorrette(Collection $famiglia): ?string
    {
        foreach ($famiglia as $s) {
            foreach ($s->registro['righe'] ?? [] as $op) {
                if (! isset($op['dopo'])) {
                    continue;
                }
                $riga = DB::table('anagrafica_immobile')->where('id', $op['id'])->first();
                $diversa = $riga === null || collect($op['dopo'])->contains(fn ($valore, $campo) => $this->normalizza($campo, $riga->{$campo} ?? null) !== $this->normalizza($campo, $valore));
                if ($diversa) {
                    $nome = $riga !== null ? DB::table('anagrafiche')->where('id', $riga->anagrafica_id)->value('nome') : null;

                    return sprintf('La riga di %s su %s è stata modificata dopo il passaggio. L\'annullamento la rimetterebbe com\'era prima del passaggio, perdendo la correzione: riportala com\'era, oppure correggi a mano anche il resto da «Modifica associazione».',
                        $nome ?? 'una persona', $s->immobile?->nome ?? 'l\'unità');
                }
            }
        }

        return null;
    }

    /**
     * Lo stato che l'annullamento lascerebbe, passato per le stesse guardie di «Associa» e della registrazione (inv. 11 e
     * 12, `GuardieTitolarita`). Una riga associata o ritoccata a mano dopo il passaggio sta fuori dal registro: resta
     * dov'è, mentre la riga di chi era uscito si riapre (Fase 1-bis, A1: la vendita di una parte, con chi vende
     * riassociato per il resto; la fine locazione con il nuovo inquilino associato a mano). Togliere righe fa solo
     * scendere le somme: basta controllare le righe che l'annullamento rimette.
     */
    private function righeInConflitto(Collection $famiglia): ?string
    {
        foreach ($famiglia as $s) {
            $ops = collect($s->registro['righe'] ?? []);
            $aperte = $ops->where('operazione', 'aperta')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $righe = DB::table('anagrafica_immobile')->where('immobile_id', $s->immobile_id)->get()
                ->reject(fn ($r) => in_array((int) $r->id, $aperte, true))->keyBy('id');
            $rimesse = [];
            foreach ($ops as $op) {
                $riga = $righe->get($op['id']);
                if ($riga === null || $op['operazione'] === 'aperta') {
                    continue;
                }
                foreach ($op['prima'] as $campo => $valore) {
                    $riga->{$campo} = $valore;
                }
                $rimesse[] = (int) $op['id'];
            }

            foreach (array_unique($rimesse) as $id) {
                $r = $righe->get($id);
                $nuova = ['anagrafica_id' => (int) $r->anagrafica_id, 'tipologia' => (string) $r->tipologia, 'quota' => (float) $r->quota, 'data_inizio' => $r->data_inizio, 'data_fine' => $r->data_fine];
                if (isset($r->attivo) && ! (bool) $r->attivo) {
                    continue;
                }
                if ($altra = GuardieTitolarita::sovrapposizioneStessaPersona($righe, $nuova, $id)) {
                    return $this->fraseConflitto($s, $r, $altra, null);
                }
                if ($sforo = GuardieTitolarita::sforoQuotePerGiorno($righe, $nuova, $id)) {
                    $inCorso = fn ($x) => (int) $x->id !== $id && (string) $x->tipologia === (string) $r->tipologia && (! isset($x->attivo) || (bool) $x->attivo)
                        && substr((string) ($x->data_inizio ?? '1900-01-01'), 0, 10) <= $sforo['giorno']
                        && ($x->data_fine === null || substr((string) $x->data_fine, 0, 10) >= $sforo['giorno']);
                    $altra = $righe->first(fn ($x) => $inCorso($x) && ! in_array((int) $x->id, $rimesse, true)) ?? $righe->first($inCorso);

                    return $this->fraseConflitto($s, $r, $altra, $sforo);
                }
            }
        }

        return null;
    }

    private function fraseConflitto(Subentro $s, object $rimessa, ?object $altra, ?array $sforo): string
    {
        $nomi = DB::table('anagrafiche')->whereIn('id', array_filter([(int) $rimessa->anagrafica_id, (int) ($altra->anagrafica_id ?? 0)]))->pluck('nome', 'id');
        $ruolo = fn (object $r) => mb_strtolower(RuoloAnagraficaImmobile::tryFrom((string) $r->tipologia)?->label() ?? (string) $r->tipologia);
        $periodo = function (object $r): string {
            $dal = $r->data_inizio ? 'dal ' . $this->data(CarbonImmutable::parse(substr((string) $r->data_inizio, 0, 10))) : 'da sempre';

            return $r->data_fine ? $dal . ' al ' . $this->data(CarbonImmutable::parse(substr((string) $r->data_fine, 0, 10))) : $dal . ', in corso';
        };
        $frase = sprintf('Annullando, su %s, %s tornerebbe %s %s', $s->immobile?->nome ?? 'l\'unità', $nomi[$rimessa->anagrafica_id] ?? 'chi era uscito', $ruolo($rimessa), $periodo($rimessa));
        if ($altra !== null) {
            $frase .= sprintf('; ma c\'è anche la riga di %s come %s %s', $nomi[$altra->anagrafica_id] ?? 'un\'altra persona', $ruolo($altra), $periodo($altra));
        }
        $frase .= $sforo === null
            ? ': i due periodi si sovrapporrebbero.'
            : sprintf(': il %s le quote farebbero %s.', $this->data(CarbonImmutable::parse($sforo['giorno'])), rtrim(rtrim(number_format($sforo['somma'], 2, ',', '.'), '0'), ','));

        return $frase . ($altra !== null ? $this->viaDelConflitto($s, $altra) : ' Se quella riga l\'hai associata dopo il passaggio, toglila con «Dissocia»; se c\'era già e l\'hai cambiata, riportala com\'era da «Modifica associazione». Poi annulla.');
    }

    /**
     * Che cosa fare della riga in conflitto, detto per il suo caso (giro di verifica, G-3). «Chiudila» non sblocca mai
     * (la riga che si riapre è in corso), e «Dissocia» rifiuta una riga con una storia — chiusa, o seguito della riga
     * chiusa dal passaggio — mentre cancella senza chiedere una riga vecchia che l'amministratore ha solo ritoccato.
     * Associata dopo o c'era già: si decide per id (`registro.riga_max_id`), come per le quote, non per data. Il vicolo
     * della continuazione (chi vende una parte, riassociato a mano per il resto dal giorno del rogito) si esce a mano:
     * Vincenzo, 29/09/2026, solo testo.
     */
    private function viaDelConflitto(Subentro $s, object $altra): string
    {
        $rigaMax = $s->registro['riga_max_id'] ?? null;
        if ($rigaMax === null) {
            return ' Se quella riga l\'hai associata dopo il passaggio, toglila con «Dissocia»; se c\'era già e l\'hai cambiata, riportala com\'era da «Modifica associazione». Poi annulla.';
        }
        if ((int) $altra->id <= (int) $rigaMax) {
            return ' Quella riga c\'era già prima del passaggio: se l\'hai cambiata dopo, riportala com\'era da «Modifica associazione», poi annulla. Non toglierla con «Dissocia»: cancelleresti anche il periodo che aveva prima.';
        }
        $riga = TitolaritaImmobile::find($altra->id);
        if ($riga !== null && $riga->eContinuazione()) {
            $giorno = $this->data(CarbonImmutable::parse(substr((string) $altra->data_inizio, 0, 10))->addDay());

            return sprintf(' Quella riga è stata associata dopo il passaggio, dal giorno del passaggio: «Dissocia» la considera il seguito della riga chiusa dal passaggio e non la cancella. Da «Modifica associazione» spostane l\'inizio al %s%s, poi toglila con «Dissocia» e annulla.',
                $giorno, $altra->data_fine !== null ? ' e togli la data di fine' : '');
        }
        if ($altra->data_fine !== null) {
            return ' Quella riga è stata associata dopo il passaggio: da «Modifica associazione» togli la data di fine, perché «Dissocia» non cancella una riga chiusa, poi toglila con «Dissocia» e annulla.';
        }

        return ' Quella riga è stata associata dopo il passaggio: toglila con «Dissocia», poi annulla.';
    }

    private function normalizza(string $campo, mixed $valore): mixed
    {
        return match ($campo) {
            'quota' => $valore === null ? null : round((float) $valore, 2),
            'data_inizio', 'data_fine' => $valore === null ? null : substr((string) $valore, 0, 10),
            'anagrafica_id' => $valore === null ? null : (int) $valore,
            default => $valore,
        };
    }

    /** «5», «5 e 6», «5, 6 e 7». @param list<int|string> $voci */
    private function elenco(array $voci): string
    {
        if (count($voci) <= 1) {
            return (string) ($voci[0] ?? '');
        }
        $ultima = array_pop($voci);

        return implode(', ', $voci) . ' e ' . $ultima;
    }

    private function data(?CarbonInterface $data): string
    {
        return $data ? $data->locale('it')->translatedFormat('j F Y') : '';
    }
}
