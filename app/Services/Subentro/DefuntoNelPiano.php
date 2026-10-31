<?php

namespace App\Services\Subentro;

use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use Carbon\CarbonImmutable;

/**
 * Il defunto che un piano calcola ancora, detto al cancello della generazione (1.11.0-beta.51, §6.2, Coda 226).
 *
 * Dopo una successione con l'arretrato **agli eredi** (decisione 65), la posizione del defunto passa agli eredi con le righe di saldo
 * dell'arretrato: le rate emesse e non pagate, le bozze, i saldi. Ma un piano generato o ricalcolato **dopo** il decesso, per una
 * competenza anteriore, calcola ancora per il defunto i suoi giorni fino al decesso: le rate nascono ora, fuori dall'arretrato, e
 * nessuna riga le passa agli eredi. Con la sola frase «la posizione del defunto si chiude» il pannello lo lasciava intendere
 * chiuso; qui il cancello (2) di `GeneratePianoRateAction` lo dice al momento giusto, con la cifra, la parte di ogni erede per quota
 * (art. 754 c.c.) e la strada, la stessa del conguaglio: dalla 1.11.0-beta.53 i saldi dal Wallet legati alla successione, che non occupano il
 * posto di un riporto.
 *
 * Una voce per defunto e unità, nella forma dei cambiamenti del motore (`motivo` = `defunto_nel_piano`), così il cancello, le tre rotte che
 * lo mostrano e il flash non cambiano. Le chiavi `passaggio_id`, `passaggi_ids`, `decesso`, `cifra`, `cifra_formattata` di testa, `wallet`,
 * `piano_applica_saldi`, `con_un_saldo`, `parti_da_decidere` e `defunto.nome` servono ai test e al laboratorio: la pagina legge `frase`, `eredi`,
 * `due_serie`, `immobile_id` e `defunto.id`. Tre cose da tenere ferme:
 *
 * - **La condizione è la scelta, non le righe.** `capo()->registro['arretrato']['scelta']` è «agli eredi»: con l'ordinaria generata la
 *   prima volta dopo il decesso l'arretrato non scrive righe (non c'è niente di emesso), e `arretratoAgliEredi()` sarebbe falso. La
 *   scelta sta nel solo passaggio padre: la riga del box ha gli eredi ma non l'arretrato.
 * - **La cifra è la quota pura del motore** (`$totaliPerImmobile`), prima dei saldi del Wallet. Per questo la frase dice che il piano
 *   «calcola ancora per» il defunto, non che gli «intesta»: con i saldi manuali già assorbiti la sua quota netta è zero, e il cancello
 *   ritorna lo stesso a ogni ricalcolo, con la frase V4 che dice che i saldi ci sono già (decisione 86.4; farlo tacere è della 1.11.1).
 * - **Il Wallet entra in un piano solo se il piano applica i saldi** (`GeneratePianoRateAction::saldiSiApplicano`): una frase che
 *   promettesse «registra un saldo dal Wallet» a un piano che non li applica direbbe ciò che il piano non fa. Le varianti: V1 i saldi
 *   legati alla successione si registrano e il piano li assorbe; V2 si registrano ma il piano non li applica (serve crearlo di nuovo; alla
 *   creazione, dove il piano non esiste ancora, basta registrarli e rimandare il modulo); V4 il defunto e tutti gli eredi hanno saldi che
 *   questa generazione assorbe nelle quote **e quei saldi spostano davvero la parte**: i saldi ci sono già e non serve registrare altro. Il
 *   confronto è per persona: chi ha righe legate alla successione si confronta con quelle (un riporto accanto non conta); chi non ne ha, con i
 *   saldi non legati, come prima della 1.11.0-beta.53 (tutti quelli senza righe legate devono avere la loro parte). V5: fra chi non ha righe
 *   legate, qualcuno ha su quella gestione e quell'unità saldi non legati e la parte non torna (un riporto, il consuntivo, un credito di
 *   prima): il programma non sa se la comprendono, e lo dice. La frase chiede solo ciò che manca: le righe di chi non ha niente, al
 *   condizionale quelle di chi ha saldi non legati che il programma non sa leggere, la correzione di una riga legata che non è la parte, e
 *   dice di chi la parte c'è già. Con il piano che non applica i saldi, se i saldi non legati comprendono la parte basta creare di nuovo il
 *   piano, che li assorbe; le righe legate alla successione si leggono anche lì, e chi le ha già non se le vede chiedere di nuovo (la porta
 *   del Wallet le rifiuterebbe come doppione): il piano creato di nuovo le assorbe. Se la parte si regola fra le parti, le righe legate ancora
 *   libere nel Wallet si tolgono: il prossimo piano della gestione che applica i saldi le assorbirebbe. Le righe legate già assorbite da un
 *   altro piano della stessa gestione che si riscrive ancora (`saldiLegatiAltrove`) non si chiedono di nuovo nemmeno loro: la frase dice in
 *   quale piano sono e le due strade, secondo di chi sono (eliminare quel piano, e le righe tornano libere; o registrare quelle di questo
 *   piano dopo aver emesso quell'altro, quando le sue sono ferme). La regola della porta resta quella. Seguendola, nessuna parte entra due volte.
 *
 * I saldi «che entrano» sono quelli che la generazione assorbe (`GenerateSaldiAction`): liberi nel Wallet (non applicati, anche quelli della
 * 1.7.x e della 1.8.x, senza piano) o già di questo piano, sulla stessa gestione e unità, di ogni esercizio (la generazione li prende per gestione:
 * con la gestione riusata su più esercizi un piano assorbe anche i saldi di un altro anno, Coda 332), e solo se il piano applica i saldi. Non
 * contano le righe dei passaggi (`subentro_id`), le righe legate a un passaggio (`passaggio_id`, contate a parte) né un saldo che un altro
 * piano ha già assorbito. Le righe legate alla successione si leggono per gestione e unità, senza l'esercizio, come le assorbe la generazione;
 * quelle già in un altro piano, nell'esercizio aperto, dove la porta del Wallet guarda il doppione. Chi occupa il posto del Wallet
 * (`FermataDopoScrivi::conUnSaldo`) resta per esercizio; chi resta senza la sua parte e ha saldi non legati che la generazione assorbe da un altro
 * esercizio è incerto (V5) come chi occupa quel posto (decisione 164.3). Quando i saldi che il piano assorbe di chi ha già la sua parte, o degli
 * incerti, stanno in un esercizio che non è quello aperto, la frase lo dice: il Wallet mostra un solo esercizio aperto.
 *
 * La parte due volte (decisione 164.1): chi ha le righe legate alla successione e, accanto, saldi non legati della stessa cifra della sua parte (la
 * strada a mano di prima della 1.11.0-beta.53, o un saldo importato) la vede entrare due volte, perché la generazione le assorbe tutte e due. Il
 * cancello lo dice al posto di «i saldi ci sono già», in una frase e in una tabella (decisione 170): la frase dice i nomi e quale serie si toglie
 * dal Wallet, la tabella (`due_serie`) per persona le due serie, con la cifra, l'esercizio e se il Wallet le mostra; finché ci sono tutte e due, la
 * voce torna a ogni generazione. Un riporto accanto alle righe legate che non è la parte continua a non contare. Con più
 * defunti sulla stessa unità i saldi non legati di una persona sono gli stessi in ogni voce: sono la seconda serie in una voce sola, e in nessuna
 * se in un'altra voce sono la sua parte (`secondaSerieAltrove`).
 *
 * Più successioni dello stesso defunto sulla stessa unità (la proprietà e la nuda proprietà, due ruoli): la cifra del motore è per persona e
 * unità, e si divide per ruolo leggendo il dettaglio del riparto che il motore ha appena calcolato — la riga di titolarità di ogni riga di
 * riparto dice a quale successione appartiene —, ognuna con i suoi eredi per quota. Se un pezzo non si attribuisce (un già versato, un
 * addebito diretto, un dettaglio che non c'è, una successione con l'arretrato a suo nome), la voce nomina tutte le successioni e dice che la
 * divisione la decide l'amministratore: senza cifre per erede, senza la strada del Wallet.
 *
 * Seconde fonti dichiarate: chi ha già un saldo non legato è `FermataDopoScrivi::conUnSaldo` (la stessa regola del posto del Wallet);
 * le righe di V1 sono le gemelle di quelle di `FermataDopoScrivi::stradaAMano`, che è privata; la divisione per quota è
 * `MoneyHelper::ripartisciPerQuote` con i pesi dell'arretrato (`AnteprimaPassaggio::pesiDegliEredi`); i saldi che entrano sono la
 * selezione di `GenerateSaldiAction::execute`.
 */
final class DefuntoNelPiano
{
    public const MOTIVO = 'defunto_nel_piano';

    /**
     * Le voci del cancello per il piano che sta per essere generato.
     *
     * @param array<int|string, array<int|string, int|float>> $totaliPerImmobile le quote del motore: [anagrafica_id][immobile_id] => centesimi, prima dei saldi
     * @param bool $pianoApplicaSaldi se i saldi del Wallet entreranno in questo piano (`GeneratePianoRateAction::saldiSiApplicano`)
     * @param list<array<string, mixed>> $righeDettaglio il dettaglio del riparto che il motore ha appena calcolato (`CalcoloQuoteService::getRigheDettaglio`):
     *        serve a dividere per ruolo la cifra di un defunto con più successioni sulla stessa unità
     * @param bool $allaCreazione il piano nasce con questa richiesta e il cancello la annulla: non esiste ancora, e la strada dei saldi è un'altra
     * @return list<array<string, mixed>>
     */
    public function per(PianoRate $piano, array $totaliPerImmobile, bool $pianoApplicaSaldi, array $righeDettaglio = [], bool $allaCreazione = false): array
    {
        $cifre = [];
        foreach ($totaliPerImmobile as $anagraficaId => $perUnita) {
            foreach ((array) $perUnita as $immobileId => $cents) {
                if ((int) round((float) $cents) !== 0) {
                    $cifre[(int) $anagraficaId][(int) $immobileId] = (int) round((float) $cents);
                }
            }
        }
        if ($cifre === []) {
            return [];
        }

        // Le successioni non annullate (lo scope `non_annullati` nasconde le altre) di chi ha una cifra, sulle unità della cifra, per defunto e
        // unità, nell'ordine delle righe di titolarità che chiudono: quello non cambia con l'ordine in cui sono state registrate.
        $gruppi = [];
        $unita = collect($cifre)->flatMap(fn (array $perUnita) => array_keys($perUnita))->unique()->values()->all();
        foreach (Subentro::with('padre')->where('condominio_id', $piano->condominio_id)->where('tipo_passaggio', 'successione')
            ->whereIn('immobile_id', $unita)->whereIn('anagrafica_uscente_id', array_keys($cifre))->orderBy('riga_uscente_id')->orderBy('id')->get() as $s) {
            if (($cifre[(int) $s->anagrafica_uscente_id][(int) $s->immobile_id] ?? 0) !== 0) {
                $gruppi[(int) $s->anagrafica_uscente_id . ':' . (int) $s->immobile_id][] = $s;
            }
        }
        // La scelta sta nel padre: la riga del box ha gli eredi, non l'arretrato.
        $gruppi = array_filter($gruppi, fn (array $successioni) => collect($successioni)->contains(fn (Subentro $s) => self::agliEredi($s)));
        if ($gruppi === []) {
            return [];
        }

        $persone = [];
        foreach ($gruppi as $successioni) {
            foreach ($successioni as $s) {
                $persone[] = (int) $s->anagrafica_uscente_id;
                array_push($persone, ...array_column($s->eredi(), 'anagrafica_id'));
            }
        }
        $nomiPersone = Anagrafica::whereIn('id', array_values(array_unique($persone)))->pluck('nome', 'id');
        $nomiUnita = Immobile::whereIn('id', array_map(fn (array $successioni) => (int) $successioni[0]->immobile_id, $gruppi))->pluck('nome', 'id');

        $voci = [];
        $serie = [];
        foreach ($gruppi as $chiave => $successioni) {
            $cifra = $cifre[(int) $successioni[0]->anagrafica_uscente_id][(int) $successioni[0]->immobile_id];
            $voci[$chiave] = $this->voce($piano, $successioni, $cifra, $pianoApplicaSaldi, $allaCreazione, $righeDettaglio, $nomiPersone->all(), $nomiUnita->all(), [], $serie[$chiave]);
        }
        // Decisione 164.1 con più defunti sulla stessa unità: i saldi non legati di una persona si leggono per unità, non per successione, e sono
        // gli stessi in ogni voce. Sono la seconda serie in una voce sola, e in nessuna se in un'altra voce sono la sua parte.
        foreach (self::secondaSerieAltrove($gruppi, $serie) as $chiave => $escludi) {
            $successioni = $gruppi[$chiave];
            $cifra = $cifre[(int) $successioni[0]->anagrafica_uscente_id][(int) $successioni[0]->immobile_id];
            $voci[$chiave] = $this->voce($piano, $successioni, $cifra, $pianoApplicaSaldi, $allaCreazione, $righeDettaglio, $nomiPersone->all(), $nomiUnita->all(), $escludi);
        }
        $voci = array_values($voci);
        usort($voci, fn (array $a, array $b) => [$a['immobile_id'], $a['defunto']['id']] <=> [$b['immobile_id'], $b['defunto']['id']]);

        return $voci;
    }

    /** La successione ha passato l'arretrato agli eredi, e ha degli eredi. */
    private static function agliEredi(Subentro $s): bool
    {
        return ($s->capo()->registro['arretrato']['scelta'] ?? null) === Subentro::ARRETRATO_AGLI_EREDI && $s->eredi() !== [];
    }

    /**
     * Decisione 164.1, più defunti sulla stessa unità (due voci): i saldi non legati di una persona (`saldiCheEntrano`) sono gli stessi in ogni voce
     * dell'unità. Se in una voce sono la sua parte (chi non ha righe legate e ha la parte nei saldi non legati), in nessun'altra sono la seconda
     * serie; altrimenti lo sono nella prima voce, nell'ordine delle successioni, e non nelle altre. Per ogni voce da rileggere, chi non ha la parte
     * due volte.
     *
     * @param array<string, list<Subentro>> $gruppi
     * @param array<string, array{immobile_id: int, doppie: list<int>, parte_non_legata: list<int>}|null> $serie
     * @return array<string, list<int>>
     */
    private static function secondaSerieAltrove(array $gruppi, array $serie): array
    {
        $escludi = [];
        foreach (collect(array_keys($gruppi))->groupBy(fn (string $k) => (int) $gruppi[$k][0]->immobile_id) as $chiavi) {
            if ($chiavi->count() < 2) {
                continue;
            }
            $persone = $chiavi->flatMap(fn (string $k) => $serie[$k]['doppie'] ?? [])->unique();
            foreach ($persone as $id) {
                $doppie = $chiavi->filter(fn (string $k) => in_array($id, $serie[$k]['doppie'] ?? [], true))->values();
                $parteAltrove = $chiavi->contains(fn (string $k) => in_array($id, $serie[$k]['parte_non_legata'] ?? [], true));
                foreach ($parteAltrove ? $doppie : $doppie->slice(1) as $k) {
                    $escludi[$k][] = (int) $id;
                }
            }
        }

        return $escludi;
    }

    /**
     * La voce di un defunto su un'unità, con le sue successioni (una, o più dello stesso defunto sulla stessa unità). `$cifra` ha il segno
     * (positivo = debito del defunto, negativo = un suo credito).
     *
     * @param list<Subentro> $successioni
     * @param list<array<string, mixed>> $righeDettaglio
     * @param array<int, string> $nomiPersone
     * @param array<int, string> $nomiUnita
     * @param list<int> $senzaSecondaSerie chi non ha la parte due volte in questa voce (`secondaSerieAltrove`)
     * @param array{immobile_id: int, doppie: list<int>, parte_non_legata: list<int>}|null $serie chi ha la parte due volte e chi ce l'ha nei saldi non legati
     * @return array<string, mixed>
     */
    private function voce(PianoRate $piano, array $successioni, int $cifra, bool $pianoApplicaSaldi, bool $allaCreazione, array $righeDettaglio, array $nomiPersone, array $nomiUnita, array $senzaSecondaSerie = [], ?array &$serie = null): array
    {
        $s = $successioni[count($successioni) - 1];
        $defuntoId = (int) $s->anagrafica_uscente_id;
        $immobileId = (int) $s->immobile_id;
        $defunto = $nomiPersone[$defuntoId] ?? ($s->registro['nomi']['uscente'] ?? 'il defunto');
        $unita = $nomiUnita[$immobileId] ?? 'un\'unità';
        $decessi = array_values(array_unique(array_map(fn (Subentro $x) => $x->decorrenza->toDateString(), $successioni)));

        // La parte di ogni erede per la sua quota ereditata (i pesi dell'arretrato, nell'ordine del modulo: il centesimo di resto al primo
        // dei resti maggiori), con il segno della cifra, che la primitiva riapplica sul valore assoluto. Con più successioni, per ruolo:
        // `null` se la divisione per ruolo non si può fare.
        $pezzi = count($successioni) === 1 ? [(int) $s->id => $cifra] : $this->pezziPerSuccessione($successioni, $righeDettaglio, $defuntoId, $immobileId, $cifra);
        $perErede = $pezzi === null ? null : self::perErede($successioni, $pezzi);
        $eredi = [];
        foreach ($perErede ?? self::soloLeQuote($successioni) as $id => $e) {
            $eredi[] = ['id' => $id, 'nome' => $nomiPersone[$id] ?? ('Anagrafica #' . $id), 'quota' => $e['quota'], 'cifra' => $e['cifra'],
                'cifra_formattata' => $e['cifra'] === null ? null : self::cifra($e['cifra'])];
        }

        // Chi ha già un saldo su quella gestione, quell'unità e quell'esercizio, senza contare quelli scritti da un passaggio né quelli legati a un
        // passaggio (il posto del Wallet): la strada è sempre il Wallet con i saldi legati alla successione, e questi saldi dicono chi, senza la
        // sua parte, ha un saldo che il programma non sa leggere (V5).
        $persone = array_merge([$defuntoId], array_column($eredi, 'id'));
        $esercizioId = $piano->esercizio_id !== null ? (int) $piano->esercizio_id : null;
        $conUnSaldo = FermataDopoScrivi::conUnSaldo($persone, (int) $piano->gestione_id, $immobileId, $esercizioId);
        $wallet = $conUnSaldo === [];
        $nomiConUnSaldo = array_values(array_map(fn (int $id) => $id === $defuntoId ? $defunto : ($nomiPersone[$id] ?? ''), array_values(array_filter($persone, fn (int $id) => in_array($id, $conUnSaldo, true)))));
        // Decisione 86.4: il defunto e tutti i suoi eredi hanno saldi che questa generazione assorbe nelle quote, e quei saldi sono la parte
        // che passa agli eredi (la cifra, per ognuno, nel verso giusto, a meno di un centesimo di resto). Il confronto è per persona (`chiHaLaParte`):
        // le righe legate alla successione per chi ne ha, i saldi non legati per gli altri, come prima della .53.
        $passaggiIds = array_values(array_unique(array_map(fn (Subentro $x) => (int) $x->capo()->id, $successioni)));
        // Le righe legate alla successione si leggono anche con il piano che non applica i saldi: chi le ha già non se le vede chiedere di nuovo
        // (la porta del Wallet le rifiuterebbe come doppione), e il piano creato di nuovo le assorbe.
        $legati = $perErede !== null ? self::saldiLegati($persone, $piano, $immobileId, $passaggiIds) : [];
        // Le righe legate già assorbite da un altro piano della stessa gestione che si riscrive ancora: questo piano non le assorbe, e la porta del
        // Wallet rifiuta una seconda riga per le stesse persone finché sono lì.
        $altrove = $perErede !== null ? self::saldiLegatiAltrove($persone, $piano, $immobileId, $passaggiIds) : [];
        // Coda 332: i saldi che la generazione assorbe, di ogni esercizio della gestione, come li prende `GenerateSaldiAction`.
        $saldiCheEntrano = $pianoApplicaSaldi && $perErede !== null ? self::saldiCheEntrano($persone, $piano, $immobileId) : [];
        $strada = self::chiHaLaParte($persone, $defuntoId, $cifra, $eredi, $legati, $saldiCheEntrano, $conUnSaldo, $pianoApplicaSaldi && $perErede !== null, $altrove, $senzaSecondaSerie)
            + ['successione' => self::allaSuccessione($decessi, count($successioni) > 1)];
        $partePassata = $strada['parte_passata'];
        $serie = ['immobile_id' => $immobileId, 'doppie' => $strada['doppie'], 'parte_non_legata' => $strada['parte_non_legata']];
        // Gli esercizi dei saldi che questa generazione assorbe di chi ha già la sua parte, e quelli non legati di chi è incerto (decisione 164.3),
        // quando non sono quello aperto: il Wallet non li mostra, e la frase lo dice. Solo con il piano che applica i saldi: senza, questo piano non
        // li assorbe. Senza nessuno con la sua parte né incerti (anche quando la cifra non si divide) non c'è nessuna riga da cercare. Le due serie di
        // chi ha la parte due volte le dice `dueSerie`: la frase con i nomi e la strada, la tabella con ogni serie e il suo esercizio.
        $fuoriDalWallet = $pianoApplicaSaldi
            ? self::fuoriDalWallet($strada['ok_legati'], array_values(array_unique(array_merge(array_diff($strada['ok'], $strada['ok_legati']), $strada['incerti']))), $piano, $immobileId, $passaggiIds)
            : ['righe' => 0, 'esercizi' => []];
        $dueSerie = self::dueSerie($strada['doppie'], [$defuntoId => $defunto] + array_column($eredi, 'nome', 'id'), $piano, $immobileId, $passaggiIds);

        return [
            'immobile_id'         => $immobileId,
            'immobile_nome'       => $unita,
            'tipologia'           => (string) $s->tipologia,
            'conto_id'            => null,
            'conto_nome'          => null,
            'motivo'              => self::MOTIVO,
            'gradino'             => null,
            'periodo'             => [],
            // Il passaggio che il pannello nomina è quello dell'unità principale, anche per il box.
            'passaggio_id'        => (int) $s->capo()->id,
            'passaggi_ids'        => array_map(fn (Subentro $x) => (int) $x->capo()->id, $successioni),
            'defunto'             => ['id' => $defuntoId, 'nome' => $defunto],
            'decesso'             => $decessi[count($decessi) - 1],
            'cifra'               => $cifra,
            'cifra_formattata'    => self::cifra($cifra),
            'eredi'               => $eredi,
            'wallet'              => $wallet,
            'piano_applica_saldi' => $pianoApplicaSaldi,
            'con_un_saldo'        => $nomiConUnSaldo,
            'parti_da_decidere'   => $perErede === null,
            // Decisione 170: chi ha la parte due volte, una riga per persona (vuota se nessuno).
            'due_serie'           => $dueSerie['righe'],
            'frase'               => $perErede === null
                ? $this->fraseDaDecidere($defunto, $decessi, $unita, $cifra, $successioni, $nomiPersone)
                : $this->frase($defunto, $decessi, $unita, $cifra, $eredi, $pianoApplicaSaldi, $allaCreazione, $strada, $partePassata, count($successioni) > 1 ? $this->divisionePerRuolo($successioni, $pezzi) : null, $fuoriDalWallet, $dueSerie['frase']),
        ];
    }

    /**
     * La cifra del motore di un defunto con più successioni sulla stessa unità, divisa per successione: la somma delle righe del dettaglio del
     * riparto della sua riga di titolarità (`anagrafica_immobile_id`, o per le righe che non la portano il ruolo, se lo ha una successione sola).
     * Se una riga non appartiene a nessuna successione (un già versato, un addebito diretto), se il dettaglio non c'è, se una successione non ha
     * passato l'arretrato agli eredi, o se i pezzi non sommano alla cifra, la divisione non si può fare: null.
     *
     * @param list<Subentro> $successioni
     * @param list<array<string, mixed>> $righe
     * @return array<int, int>|null id della successione => centesimi
     */
    private function pezziPerSuccessione(array $successioni, array $righe, int $defuntoId, int $immobileId, int $cifra): ?array
    {
        if (! collect($successioni)->every(fn (Subentro $s) => self::agliEredi($s))) {
            return null;
        }
        $pezzi = array_fill_keys(array_map(fn (Subentro $s) => (int) $s->id, $successioni), 0);
        foreach ($righe as $r) {
            if ((int) ($r['anagrafica_id'] ?? 0) !== $defuntoId || (int) ($r['immobile_id'] ?? 0) !== $immobileId || (int) ($r['importo'] ?? 0) === 0) {
                continue;
            }
            $di = isset($r['anagrafica_immobile_id'])
                ? array_values(array_filter($successioni, fn (Subentro $s) => (int) $s->riga_uscente_id === (int) $r['anagrafica_immobile_id']))
                : array_values(array_filter($successioni, fn (Subentro $s) => isset($r['ruolo_risolto']) && (string) $s->tipologia === (string) $r['ruolo_risolto']));
            if (count($di) !== 1) {
                return null;
            }
            $pezzi[(int) $di[0]->id] += (int) $r['importo'];
        }

        return array_sum($pezzi) === $cifra ? $pezzi : null;
    }

    /**
     * La parte di ogni erede, sommata fra le successioni: ognuna divide il suo pezzo con i pesi dei propri eredi.
     *
     * @param list<Subentro> $successioni
     * @param array<int, int> $pezzi id della successione => centesimi
     * @return array<int, array{quota: float, cifra: int}> id dell'erede => quota e parte
     */
    private static function perErede(array $successioni, array $pezzi): array
    {
        $perErede = [];
        foreach ($successioni as $s) {
            $pesi = [];
            foreach ($s->eredi() as $e) {
                $pesi[$e['anagrafica_id']] = $e['quota'];
            }
            $parti = MoneyHelper::ripartisciPerQuote($pezzi[(int) $s->id] ?? 0, $pesi);
            foreach ($pesi as $id => $quota) {
                $perErede[$id] = ['quota' => ($perErede[$id]['quota'] ?? 0) + $quota, 'cifra' => ($perErede[$id]['cifra'] ?? 0) + (int) ($parti[$id] ?? 0)];
            }
        }

        return $perErede;
    }

    /**
     * Gli eredi di tutte le successioni con la sola quota, senza la parte: la divisione la decide l'amministratore.
     *
     * @param list<Subentro> $successioni
     * @return array<int, array{quota: float, cifra: null}>
     */
    private static function soloLeQuote(array $successioni): array
    {
        $eredi = [];
        foreach ($successioni as $s) {
            foreach ($s->eredi() as $e) {
                $eredi[$e['anagrafica_id']] = ['quota' => ($eredi[$e['anagrafica_id']]['quota'] ?? 0) + $e['quota'], 'cifra' => null];
            }
        }

        return $eredi;
    }

    /**
     * I saldi che questa generazione assorbe nelle quote delle persone, sulla gestione e l'unità del piano, di ogni esercizio, per persona: la
     * selezione di `GenerateSaldiAction` (liberi nel Wallet, o già di questo piano, per gestione: Coda 332), senza le righe dei passaggi, le righe
     * legate a un passaggio (le legge `saldiLegati`) e i debiti verso i fornitori. Un saldo della
     * 1.7.x o della 1.8.x, senza piano e con `is_applicato` falso, è libero come uno registrato adesso. Chi ha più saldi li somma.
     *
     * @param list<int> $persone
     * @return array<int, int> id della persona => centesimi (positivo = debito)
     */
    private static function saldiCheEntrano(array $persone, PianoRate $piano, int $immobileId): array
    {
        return self::righeCheEntrano($persone, $piano, $immobileId)
            ->groupBy('anagrafica_id')->map(fn ($g) => (int) $g->sum('saldo_iniziale'))->mapWithKeys(fn (int $cents, $id) => [(int) $id => $cents])->all();
    }

    /**
     * Le righe dei saldi non legati che questa generazione assorbe (vedi `saldiCheEntrano`).
     *
     * @param list<int> $persone
     * @return \Illuminate\Support\Collection<int, Saldo>
     */
    private static function righeCheEntrano(array $persone, PianoRate $piano, int $immobileId): \Illuminate\Support\Collection
    {
        return Saldo::whereIn('anagrafica_id', $persone ?: [0])->where('gestione_id', $piano->gestione_id)->where('immobile_id', $immobileId)
            ->whereNull('subentro_id')->whereNull('passaggio_id')->whereNull('fornitore_id')->where('saldo_iniziale', '!=', 0)
            ->where(fn ($q) => $q->where('is_applicato', false)->orWhere('piano_rate_id', $piano->id))
            ->get();
    }

    /**
     * Le righe del Wallet legate alle successioni della voce che questa generazione assorbe, per persona: sulla gestione e l'unità del piano,
     * libere o già di questo piano, senza filtro d'esercizio (la generazione le prende per gestione). Chi ne ha più d'una le somma.
     *
     * @param list<int> $persone
     * @param list<int> $passaggiIds le successioni padre
     * @return array<int, array{cents: int, righe: int}> id della persona => la somma in centesimi (positivo = debito) e quante righe
     */
    private static function saldiLegati(array $persone, PianoRate $piano, int $immobileId, array $passaggiIds): array
    {
        return self::righeLegate($persone, $piano, $immobileId, $passaggiIds)
            ->groupBy('anagrafica_id')->mapWithKeys(fn ($g, $id) => [(int) $id => ['cents' => (int) $g->sum('saldo_iniziale'), 'righe' => $g->count()]])->all();
    }

    /**
     * Le righe legate alla successione che questa generazione assorbe (vedi `saldiLegati`).
     *
     * @param list<int> $persone
     * @param list<int> $passaggiIds le successioni padre
     * @return \Illuminate\Support\Collection<int, Saldo>
     */
    private static function righeLegate(array $persone, PianoRate $piano, int $immobileId, array $passaggiIds): \Illuminate\Support\Collection
    {
        return Saldo::whereIn('anagrafica_id', $persone ?: [0])->whereIn('passaggio_id', $passaggiIds ?: [0])->where('gestione_id', $piano->gestione_id)
            ->where('immobile_id', $immobileId)->where('saldo_iniziale', '!=', 0)
            ->where(fn ($q) => $q->where('is_applicato', false)->orWhere('piano_rate_id', $piano->id))
            ->get();
    }

    /**
     * Coda 332: i saldi di chi ha già la sua parte — le righe legate di chi le ha, i saldi non legati che questa generazione assorbe degli altri — che
     * stanno in un esercizio diverso da quello aperto. La pagina del Wallet mostra un solo esercizio aperto, il primo del condominio (`FermataDopoScrivi::fuoriDalWallet`),
     * mentre la generazione li assorbe per gestione: la frase lo dice, con il nome degli esercizi in ordine di data.
     *
     * @param list<int> $conLegate chi ha la parte nelle righe legate alla successione
     * @param list<int> $conNonLegati chi ha la parte nei saldi non legati
     * @param list<int> $passaggiIds le successioni padre
     * @return array{righe: int, esercizi: list<string>}
     */
    private static function fuoriDalWallet(array $conLegate, array $conNonLegati, PianoRate $piano, int $immobileId, array $passaggiIds): array
    {
        $righe = self::righeLegate($conLegate, $piano, $immobileId, $passaggiIds)->merge(self::righeCheEntrano($conNonLegati, $piano, $immobileId));
        $fuori = FermataDopoScrivi::fuoriDalWallet($righe)->load('esercizio:id,nome,data_inizio');

        return [
            'righe' => $fuori->count(),
            'esercizi' => $fuori->pluck('esercizio')->filter()->unique('id')->sortBy(fn ($e) => (string) $e->data_inizio)->pluck('nome')->map(fn ($n) => (string) $n)->values()->all(),
        ];
    }

    /**
     * La frase che chiude la voce quando i saldi di chi ha già la sua parte stanno in un esercizio che non è quello aperto: «I saldi dell'esercizio «Esercizio 2026»
     * non compaiono nel Wallet, che mostra un solo esercizio aperto: il piano li assorbe lo stesso, perché prende i saldi della gestione di ogni
     * esercizio.» Vuota se sono tutti nell'esercizio che il Wallet mostra.
     *
     * @param array{righe: int, esercizi: list<string>} $fuori
     */
    private static function nonNelWallet(array $fuori): string
    {
        if ($fuori['esercizi'] === []) {
            return '';
        }
        $quali = sprintf('%s %s', count($fuori['esercizi']) > 1 ? 'degli esercizi' : 'dell\'esercizio', self::elenco(array_map(fn (string $n) => '«' . $n . '»', $fuori['esercizi'])));

        return $fuori['righe'] === 1
            ? sprintf('Il saldo %s non compare nel Wallet, che mostra un solo esercizio aperto: il piano lo assorbe lo stesso, perché prende i saldi della gestione di ogni esercizio.', $quali)
            : sprintf('I saldi %s non compaiono nel Wallet, che mostra un solo esercizio aperto: il piano li assorbe lo stesso, perché prende i saldi della gestione di ogni esercizio.', $quali);
    }

    /**
     * Coda 332, decisioni 164.1 e 170: le due serie di chi ha la parte due volte (`chiHaLaParte`, `doppie`), le righe legate alla successione e i saldi
     * non legati della stessa cifra, che questa generazione assorbe tutte e due. La voce le dice in una frase e in una tabella.
     *
     * La tabella (`righe`, la chiave `due_serie` della voce), una riga per persona: la serie legata e quella non legata, ognuna con la cifra già detta
     * con il verso (la somma delle sue righe, con il segno del Wallet: positivo = debito; la pagina legge il testo), gli esercizi in ordine di data
     * (ogni saldo ha il suo) e se il Wallet la mostra; `esercizio` quando le due stanno in un solo esercizio, lo stesso, altrimenti null. Una serie è nel
     * Wallet, e si toglie da lì, solo se tutte le sue righe stanno nell'esercizio che la pagina mostra, un solo esercizio aperto
     * (`FermataDopoScrivi::fuoriDalWallet`), e la porta accetta l'eliminazione di una riga libera o di un piano che si riscrive ancora.
     *
     * La frase dice i nomi e la strada. A chi ne ha almeno una nel Wallet: «togline una», e il piano assorbe quella che resta; quale togliere lo decide
     * l'amministratore, e la frase non sceglie fra due serie che stanno tutte e due nel Wallet. Se il Wallet non le mostra tutte, la frase lo dice: per
     * chi ne mostra una sola, che è quella da togliere; per chi nessuna, e a lui non indica il Wallet né «quella che resta» (le sue serie restano due).
     * Vuote se nessuno ha la parte due volte.
     *
     * @param list<int> $doppie
     * @param array<int, string> $nomeDi id della persona => nome, come nella frase
     * @param list<int> $passaggiIds le successioni padre
     * @return array{frase: string, righe: list<array{id: int, nome: string, legata: array{cifra_formattata: string, esercizi: list<string>, nel_wallet: bool}, non_legata: array{cifra_formattata: string, esercizi: list<string>, nel_wallet: bool}, esercizio: string|null}>}
     */
    private static function dueSerie(array $doppie, array $nomeDi, PianoRate $piano, int $immobileId, array $passaggiIds): array
    {
        if ($doppie === []) {
            return ['frase' => '', 'righe' => []];
        }
        $legate = self::righeLegate($doppie, $piano, $immobileId, $passaggiIds)->load('esercizio:id,nome,data_inizio')->groupBy('anagrafica_id');
        $nonLegate = self::righeCheEntrano($doppie, $piano, $immobileId)->load('esercizio:id,nome,data_inizio')->groupBy('anagrafica_id');
        // Gli esercizi delle righe di una serie, in ordine di data.
        $esercizi = fn ($righe) => collect($righe)->pluck('esercizio')->filter()->unique('id')->sortBy(fn ($e) => (string) $e->data_inizio)->values();
        // Una serie nella tabella: la somma delle sue righe detta con il verso, i nomi dei suoi esercizi, e se il Wallet la mostra.
        $serie = fn ($righe): array => [
            'cifra_formattata' => self::inVerso((int) collect($righe)->sum('saldo_iniziale')),
            'esercizi'         => $esercizi($righe)->map(fn ($e) => (string) $e->nome)->all(),
            'nel_wallet'       => FermataDopoScrivi::fuoriDalWallet(collect($righe))->isEmpty(),
        ];

        $righe = [];
        $soloUna = [];
        $nessuna = [];
        foreach ($doppie as $id) {
            $l = $legate->get($id, collect());
            $n = $nonLegate->get($id, collect());
            [$legata, $nonLegata] = [$serie($l), $serie($n)];
            $unoSolo = $esercizi($l)->count() === 1 && $esercizi($l)->pluck('id')->all() === $esercizi($n)->pluck('id')->all();
            $righe[] = ['id' => $id, 'nome' => $nomeDi[$id] ?? '', 'legata' => $legata, 'non_legata' => $nonLegata,
                'esercizio' => $unoSolo ? (string) $esercizi($l)->first()->nome : null];
            $quante = (int) $legata['nel_wallet'] + (int) $nonLegata['nel_wallet'];
            if ($quante === 1) {
                $soloUna[] = $id;
            } elseif ($quante === 0) {
                $nessuna[] = $id;
            }
        }

        $nomi = fn (array $chi): string => self::elenco(array_map(fn (int $id) => $nomeDi[$id] ?? '', $chi));
        $frasi = [count($doppie) > 1 ? sprintf('Le parti di %s entrano due volte.', $nomi($doppie)) : sprintf('La parte di %s entra due volte.', $nomi($doppie))];
        // La strada, per chi ne ha almeno una nel Wallet: tutti, se il Wallet ne mostra almeno una di ognuno.
        $daTogliere = array_values(array_diff($doppie, $nessuna));
        if ($daTogliere !== []) {
            $frasi[] = sprintf('%s una%s dal Wallet: il piano assorbe quella che resta alla prossima generazione.',
                $nessuna === [] ? 'Togline' : 'Per ' . $nomi($daTogliere) . ' togline', count($daTogliere) > 1 ? ' per ognuno' : '');
        }
        $nelWallet = array_filter([
            $soloUna === [] ? '' : sprintf('per %s ce n\'è solo una%s, ed è quella da togliere', $nomi($soloUna), count($soloUna) > 1 ? ' per ognuno' : ''),
            $nessuna === [] ? '' : sprintf('per %s non ce n\'è nessuna', $nomi($nessuna)),
        ], fn (string $x) => $x !== '');
        if ($nelWallet !== []) {
            $frasi[] = 'Il Wallet mostra un solo esercizio aperto: ' . implode('; ', $nelWallet) . '.';
        }

        return ['frase' => implode(' ', $frasi), 'righe' => $righe];
    }

    /**
     * Le righe del Wallet legate alle successioni della voce già assorbite da un altro piano della stessa gestione che si riscrive ancora (in bozza,
     * o approvato senza emissioni né incassi), per persona: questo piano non le assorbe, e la porta del Wallet (`SaldoInizialeController::scriviLegata`)
     * rifiuta una seconda riga per la stessa persona finché sono lì, perché il doppione conta le righe che si possono ancora modificare. Solo le
     * righe dell'esercizio aperto, dove la porta scrive (`FermataDopoScrivi::fuoriDalWallet`): per una riga di un altro esercizio la porta ne
     * accetta una nuova. Una riga in un piano che non si riscrive più è di quel piano, e qui non conta.
     *
     * @param list<int> $persone
     * @param list<int> $passaggiIds le successioni padre
     * @return array<int, array{cents: int, righe: int, piani: list<PianoRate>}> id della persona => la somma, quante righe e i piani che le hanno
     */
    private static function saldiLegatiAltrove(array $persone, PianoRate $piano, int $immobileId, array $passaggiIds): array
    {
        $righe = Saldo::with('pianoRate')->whereIn('anagrafica_id', $persone ?: [0])->whereIn('passaggio_id', $passaggiIds ?: [0])->where('gestione_id', $piano->gestione_id)
            ->where('immobile_id', $immobileId)->where('saldo_iniziale', '!=', 0)->where('is_applicato', true)->whereNotNull('piano_rate_id')->where('piano_rate_id', '!=', $piano->id)
            ->orderBy('id')->get()->filter(fn (Saldo $x) => $x->pianoRate !== null && ! $x->pianoRate->eImmutabile())->values();
        $fuori = FermataDopoScrivi::fuoriDalWallet($righe)->pluck('id')->all();

        return $righe->reject(fn (Saldo $x) => in_array($x->id, $fuori, true))->groupBy('anagrafica_id')
            ->mapWithKeys(fn ($g, $id) => [(int) $id => ['cents' => (int) $g->sum('saldo_iniziale'), 'righe' => $g->count(), 'piani' => $g->pluck('pianoRate')->unique('id')->values()->all()]])->all();
    }

    /** «alla successione del 1 maggio 2026», o con più successioni «alle successioni del 1 maggio 2026»; con `$conData` falso senza la data. @param list<string> $decessi */
    private static function allaSuccessione(array $decessi, bool $piu, bool $conData = true): string
    {
        $giorni = array_map(fn (string $d) => CarbonImmutable::parse($d)->locale('it')->translatedFormat('j F Y'), $decessi);

        return ($piu ? 'alle successioni' : 'alla successione') . ($conData ? ' del ' . implode(' e del ', $giorni) : '');
    }

    /**
     * La parte di ognuno, nel verso del Wallet: il defunto ha un credito pari alla cifra, ogni erede un debito pari alla sua parte (con il segno
     * della cifra, che un credito del defunto rovescia).
     *
     * @param list<array<string, mixed>> $eredi
     * @return array<int, int> id della persona => centesimi (positivo = debito)
     */
    private static function attesi(int $defuntoId, int $cifra, array $eredi): array
    {
        $attesi = [$defuntoId => -$cifra];
        foreach ($eredi as $e) {
            $attesi[(int) $e['id']] = (int) $e['cifra'];
        }

        return $attesi;
    }

    /**
     * Chi ha già la sua parte, persona per persona, a meno di un centesimo di resto e nel verso giusto. Chi ha righe legate alla successione si
     * confronta con quelle (un riporto accanto non conta): se non tornano, sono «da correggere». Chi non ne ha si confronta con i saldi non
     * legati che questa generazione assorbe, come prima della 1.11.0-beta.53: la parte di queste persone è passata se ognuna ha la sua. Se non
     * lo è, chi di loro ha un saldo non legato su quella gestione e quell'unità (`conUnSaldo`) è «incerto» — il programma non sa se lo
     * comprende —, chi non ha niente «manca». Con `$siLegge` falso (il piano non applica i saldi) i saldi non legati non si leggono: chi ne ha
     * uno è incerto, gli altri senza righe legate mancano, e la parte non è passata (questo piano non assorbe niente); le righe legate si
     * confrontano lo stesso, perché il piano creato di nuovo le assorbe. Con la cifra che non si divide `$legati` è vuoto. Chi non ha righe legate
     * libere ma ne ha in un altro piano che si riscrive ancora (`$altrove`) non si confronta: le sue sono di quel piano, e la porta del Wallet non ne
     * accetta altre finché sono lì; la parte non è passata.
     *
     * Coda 332, decisione 164: chi resta senza la sua parte è «incerto» anche se i saldi non legati che questa generazione assorbe stanno in un altro
     * esercizio (la generazione li prende per gestione), non solo se occupa il posto del Wallet (`conUnSaldo`, per esercizio). E chi ha le righe legate
     * che tornano e, accanto, saldi non legati che questa generazione assorbe e che tornano anch'essi ha la parte due volte (`doppie`): la generazione
     * le assorbe tutte e due, e la parte non è passata finché una delle due serie non si toglie. Un riporto accanto che non è la parte non conta.
     * Chi ha la parte due volte non è fra chi ce l'ha (`ok`, `ok_legati`). `parte_non_legata` dice, persona per persona, chi senza righe legate ha la
     * sua parte nei saldi non legati, anche se a un altro manca ancora la sua: con più defunti sulla stessa unità quei saldi sono la sua parte in
     * questa voce, e in un'altra non sono una seconda serie (`secondaSerieAltrove`). Con `$siLegge` falso `$saldiCheEntrano` arriva vuoto, e nessuno ce l'ha.
     *
     * @param list<int> $persone il defunto e gli eredi, nell'ordine della frase
     * @param list<array<string, mixed>> $eredi
     * @param array<int, array{cents: int, righe: int}> $legati
     * @param array<int, int> $saldiCheEntrano
     * @param list<int> $conUnSaldo
     * @param array<int, array{cents: int, righe: int, piani: list<PianoRate>}> $altrove
     * @param list<int> $senzaSecondaSerie chi non ha la parte due volte qui, perché i suoi saldi non legati sono di un'altra voce della stessa unità
     * @return array{parte_passata: bool, defunto_id: int, ok: list<int>, ok_legati: list<int>, doppie: list<int>, parte_non_legata: list<int>, incerti: list<int>, mancanti: list<int>, da_correggere: array<int, array{cents: int, righe: int, atteso: int}>, altrove: list<int>, righe_altrove: int, piani_altrove: list<PianoRate>}
     */
    private static function chiHaLaParte(array $persone, int $defuntoId, int $cifra, array $eredi, array $legati, array $saldiCheEntrano, array $conUnSaldo, bool $siLegge, array $altrove = [], array $senzaSecondaSerie = []): array
    {
        $attesi = self::attesi($defuntoId, $cifra, $eredi);
        $torna = fn (int $cents, int $id): bool => abs($cents - ($attesi[$id] ?? 0)) <= 1;
        $okLegati = [];
        $daCorreggere = [];
        $senzaLegate = [];
        $inUnAltroPiano = [];
        foreach ($persone as $id) {
            if (! isset($legati[$id]) && isset($altrove[$id])) {
                $inUnAltroPiano[] = $id;
            } elseif (! isset($legati[$id])) {
                $senzaLegate[] = $id;
            } elseif ($torna($legati[$id]['cents'], $id)) {
                $okLegati[] = $id;
            } else {
                $daCorreggere[$id] = $legati[$id] + ['atteso' => (int) ($attesi[$id] ?? 0)];
            }
        }
        $senzaLegateOk = $siLegge && $senzaLegate !== []
            && collect($senzaLegate)->every(fn (int $id) => array_key_exists($id, $saldiCheEntrano) && $torna($saldiCheEntrano[$id], $id));
        $restano = $senzaLegateOk ? [] : $senzaLegate;
        // La parte due volte: le righe legate tornano, e tornano anche i saldi non legati che questa generazione assorbe accanto a loro.
        // Non chi ha quei saldi come seconda serie, o come parte, in un'altra voce della stessa unità (`secondaSerieAltrove`).
        $doppie = array_values(array_filter($okLegati, fn (int $id) => ! in_array($id, $senzaSecondaSerie, true) && array_key_exists($id, $saldiCheEntrano) && $torna($saldiCheEntrano[$id], $id)));
        $okLegati = array_values(array_diff($okLegati, $doppie));
        // Chi ha saldi non legati: nel posto del Wallet (l'esercizio del piano) o fra quelli che questa generazione assorbe, di ogni esercizio.
        $conSaldiNonLegati = array_merge($conUnSaldo, array_keys($saldiCheEntrano));

        return [
            'parte_passata' => $siLegge && $daCorreggere === [] && $inUnAltroPiano === [] && $doppie === [] && ($senzaLegate === [] || $senzaLegateOk),
            'defunto_id' => $defuntoId,
            'altrove' => $inUnAltroPiano,
            'righe_altrove' => (int) array_sum(array_map(fn (int $id) => $altrove[$id]['righe'], $inUnAltroPiano)),
            'piani_altrove' => collect($inUnAltroPiano)->flatMap(fn (int $id) => $altrove[$id]['piani'])->unique('id')->values()->all(),
            'ok' => array_values(array_filter($persone, fn (int $id) => in_array($id, $okLegati, true) || ($senzaLegateOk && in_array($id, $senzaLegate, true)))),
            'ok_legati' => $okLegati,
            'doppie' => $doppie,
            'parte_non_legata' => array_values(array_filter($senzaLegate, fn (int $id) => array_key_exists($id, $saldiCheEntrano) && $torna($saldiCheEntrano[$id], $id))),
            'incerti' => array_values(array_filter($restano, fn (int $id) => in_array($id, $conSaldiNonLegati, true))),
            'mancanti' => array_values(array_filter($restano, fn (int $id) => ! in_array($id, $conSaldiNonLegati, true))),
            'da_correggere' => $daCorreggere,
        ];
    }

    /**
     * La parte comune della frase: chi, quanto, perché e a chi tocca. Con più successioni il plurale e i decessi; i pezzi, se la cifra è divisa
     * per ruolo, sono detti dove la frase li usa.
     *
     * @param list<string> $decessi
     */
    private static function apertura(string $defunto, array $decessi, string $unita, int $cifra, bool $piu): string
    {
        $giorni = array_map(fn (string $d) => CarbonImmutable::parse($d)->locale('it')->translatedFormat('j F Y'), $decessi);
        $quando = count($giorni) === 1 ? 'decesso del ' . $giorni[0] : 'decessi del ' . implode(' e del ', $giorni);

        return sprintf('%s di %s (%s) %s passato l\'arretrato agli eredi, ma questo piano calcola ancora per %s %s su %s: la competenza è anteriore al decesso e le rate nascono ora, fuori dall\'arretrato.',
            $piu ? 'Le successioni' : 'La successione', $defunto, $quando, $piu ? 'hanno' : 'ha', $defunto,
            $cifra < 0 ? 'un credito di ' . MoneyHelper::format(abs($cifra)) : MoneyHelper::format($cifra), $unita);
    }

    /**
     * La voce di un defunto con più successioni sulla stessa unità quando la cifra non si divide per ruolo: nomina le successioni, ognuna con i
     * suoi eredi, e dice che la divisione la decide l'amministratore. Senza cifre per erede e senza la strada del Wallet.
     *
     * @param list<string> $decessi
     * @param list<Subentro> $successioni
     * @param array<int, string> $nomiPersone
     */
    private function fraseDaDecidere(string $defunto, array $decessi, string $unita, int $cifra, array $successioni, array $nomiPersone): string
    {
        $quali = implode('; ', array_map(function (Subentro $s) use ($nomiPersone) {
            $eredi = array_map(fn (array $e) => $nomiPersone[$e['anagrafica_id']] ?? ('Anagrafica #' . $e['anagrafica_id']), $s->eredi());

            return sprintf('%s, %s %s%s', self::ruolo((string) $s->tipologia), count($eredi) === 1 ? 'con l\'erede' : 'con gli eredi', self::elenco($eredi),
                self::agliEredi($s) ? '' : ' (l\'arretrato resta a suo nome)');
        }, $successioni));

        return self::apertura($defunto, $decessi, $unita, $cifra, count($successioni) > 1) . sprintf(' %s ha su quest\'unità più di una successione (%s) e il programma non sa quale parte della cifra spetti a ciascuna: la divisione fra gli eredi la decide l\'amministratore, e il programma non ne propone le parti né le righe del Wallet.',
            $defunto, $quali);
    }

    /**
     * La divisione per ruolo, detta: «€ 197,26 per la proprietà e € 197,26 per la nuda proprietà».
     *
     * @param list<Subentro> $successioni
     * @param array<int, int> $pezzi
     */
    private function divisionePerRuolo(array $successioni, array $pezzi): string
    {
        return self::elenco(array_map(fn (Subentro $s) => sprintf('%s per %s', self::cifra($pezzi[(int) $s->id] ?? 0), self::ruolo((string) $s->tipologia)), $successioni));
    }

    /** Il ruolo che il defunto aveva, come lo si dice di una successione: «la proprietà», «la nuda proprietà». */
    private static function ruolo(string $tipologia): string
    {
        return match ($tipologia) {
            'proprietario' => 'la proprietà',
            'nuda_proprietario' => 'la nuda proprietà',
            default => mb_strtolower((string) (\App\Enums\RuoloAnagraficaImmobile::tryFrom($tipologia)?->label() ?? $tipologia)),
        };
    }

    /**
     * La frase del cancello: la parte comune (chi, quanto, perché e a chi tocca) e la variante della strada, V1, V2, V4 o V5. Le righe da
     * registrare sono solo quelle che mancano (`chiHaLaParte`). Con il piano che non applica i saldi i saldi non legati non si leggono: senza
     * condizione si chiedono le righe di chi non ha niente, al condizionale quelle di chi ha saldi non legati, e la strada è creare di nuovo il piano;
     * le righe legate alla successione sì: di chi le ha la parte c'è già (o la riga si corregge), e il piano creato di nuovo le assorbe. Con righe
     * legate di qualcuno già in un altro piano che si riscrive ancora, la variante è `conRigheAltrove`.
     *
     * @param list<array{id: int, nome: string, cifra: int}> $eredi
     * @param list<string> $decessi
     * @param array{parte_passata: bool, defunto_id: int, ok: list<int>, ok_legati: list<int>, incerti: list<int>, mancanti: list<int>, da_correggere: array<int, array{cents: int, righe: int, atteso: int}>, successione: string} $strada
     * @param array{righe: int, esercizi: list<string>} $fuoriDalWallet i saldi di chi ha già la sua parte, e degli incerti, che il Wallet non mostra (`fuoriDalWallet`)
     * @param string $dueSerie la frase delle due serie di chi ha la parte due volte (`dueSerie`: i nomi e la strada; le serie sono nella tabella), vuota se nessuno
     */
    private function frase(string $defunto, array $decessi, string $unita, int $cifra, array $eredi, bool $pianoApplicaSaldi, bool $allaCreazione, array $strada, bool $partePassata, ?string $perRuolo, array $fuoriDalWallet = ['righe' => 0, 'esercizi' => []], string $dueSerie = ''): string
    {
        $credito = $cifra < 0;
        $parteDegliEredi = self::elenco(array_map(fn (array $e) => sprintf('%s %s', $e['nome'], MoneyHelper::format(abs($e['cifra']))), $eredi));
        $comune = self::apertura($defunto, $decessi, $unita, $cifra, $perRuolo !== null) . ' '
            . ($perRuolo !== null ? sprintf('La cifra è divisa per ruolo, come nel riparto del piano: %s. ', $perRuolo) : '')
            . ($credito
                ? sprintf('Il credito passa agli eredi per quota: %s.', $parteDegliEredi)
                : sprintf('Ne rispondono gli eredi, ciascuno per la sua quota (art. 754 c.c.): %s.', $parteDegliEredi));

        // Le righe del Wallet che passano la parte agli eredi, ognuna con il suo verso: chi ha il debito riceve una riga a credito e
        // viceversa (positivo = debito). Un credito del defunto le rovescia. Per persona: il defunto, poi gli eredi.
        $nomeDi = [$strada['defunto_id'] => $defunto];
        $rigaDi = [$strada['defunto_id'] => sprintf('%s a %s di %s', MoneyHelper::format(abs($cifra)), $credito ? 'debito' : 'credito', $defunto)];
        foreach ($eredi as $e) {
            $nomeDi[(int) $e['id']] = $e['nome'];
            $rigaDi[(int) $e['id']] = sprintf('%s a %s di %s', MoneyHelper::format(abs($e['cifra'])), $credito ? 'credito' : 'debito', $e['nome']);
        }
        $righe = self::elenco(array_values($rigaDi));
        $nomiDi = fn (array $ids): array => array_values(array_map(fn (int $id) => $nomeDi[$id] ?? '', $ids));
        $righeDi = fn (array $ids): string => self::elenco(array_values(array_map(fn (int $id) => $rigaDi[$id] ?? '', $ids)));

        $allaSuccessione = $strada['successione'];
        $senzaData = str_starts_with($allaSuccessione, 'alle ') ? 'alle successioni' : 'alla successione';
        $tutti = $nomiDi(array_keys($nomeDi));
        $incerti = $strada['incerti'];
        $mancanti = $strada['mancanti'];
        $piuMancanti = count($mancanti) > 1;
        // «il saldo legato alla successione del … che manca», «i saldi legati alla successione del … che mancano».
        $cheMancano = sprintf('%s %s che %s', $piuMancanti ? 'i saldi legati' : 'il saldo legato', $allaSuccessione, $piuMancanti ? 'mancano' : 'manca');
        // Una riga legata che non è la parte si corregge, non si registra di nuovo: la porta la rifiuterebbe come doppione.
        $daCorreggere = implode(' ', array_map(fn (int $id, array $x) => $x['righe'] > 1
            ? sprintf('Per %s i saldi legati %s sommano %s invece di %s: correggili dal Wallet.', $nomeDi[$id] ?? '', $senzaData, self::inVerso($x['cents']), self::inVerso($x['atteso']))
            : sprintf('Per %s il saldo legato %s è di %s invece di %s: correggilo dal Wallet.', $nomeDi[$id] ?? '', $senzaData, self::inVerso($x['cents']), self::inVerso($x['atteso'])),
            array_keys($strada['da_correggere']), array_values($strada['da_correggere'])));
        $nonApplica = 'Questo piano non applica i saldi del Wallet: quelli già registrati non entrano nelle sue quote.';
        // Con il piano che non applica i saldi le righe legate alla successione già registrate non entrano qui, ma il piano creato di nuovo le
        // assorbe: di chi le ha, la parte c'è già, e non si chiede di nuovo (la porta rifiuterebbe il doppione); una che non è la parte si corregge.
        $conLegate = $strada['ok_legati'] !== [] || $strada['da_correggere'] !== [];
        $okLegati = $nomiDi($strada['ok_legati']);
        $primaDelPiano = implode(' ', array_filter([
            $okLegati === [] ? '' : sprintf('%s di %s %s già, nei saldi legati %s.', count($okLegati) > 1 ? 'Le parti' : 'La parte', self::elenco($okLegati), count($okLegati) > 1 ? 'ci sono' : 'c\'è', $senzaData),
            $daCorreggere,
        ], fn (string $x) => $x !== ''));
        $unite = fn (string ...$pezzi): string => implode(' ', array_filter($pezzi, fn (string $x) => $x !== ''));
        // «La parte si regola fra le parti» con righe legate già registrate e libere nel Wallet: restano lì, e il prossimo piano della gestione che
        // applica i saldi le assorbirebbe, regolando la parte una seconda volta. Si tolgono.
        $coda = $conLegate ? ', e allora i saldi legati alla successione ancora liberi nel Wallet si tolgono: altrimenti li assorbe il prossimo piano della stessa gestione che applica i saldi' : '';
        $senzaPianoNuovo = 'Senza un piano nuovo la parte si regola fra le parti' . $coda . '.';
        $oppure = ', oppure la parte si regola fra le parti' . $coda . '.';
        // V2: il piano non applica i saldi. Alla creazione il piano non esiste ancora (il cancello annulla la richiesta): registrare i saldi legati e
        // rimandare il modulo li fa entrare; altrimenti si crea di nuovo il piano. Con righe legate già registrate, solo quelle che mancano.
        $v2 = match (true) {
            ! $conLegate && $allaCreazione => sprintf('Con il Wallet com\'è adesso il piano non applica i saldi, e non è ancora stato creato: se registri adesso dal Wallet i saldi legati %s (%s) e rimandi il modulo, il piano li assorbe; altrimenti la parte si regola fra le parti.', $senzaData, $righe),
            ! $conLegate => sprintf('Questo piano non applica i saldi del Wallet: un saldo registrato adesso non entra qui. Per passare la parte agli eredi registra dal Wallet i saldi legati %s (%s) e crea di nuovo il piano; altrimenti la parte si regola fra le parti.', $senzaData, $righe),
            $allaCreazione && $mancanti !== [] => $unite($nonApplica, $primaDelPiano, sprintf('Il piano non è ancora stato creato: registra adesso dal Wallet %s (%s) e rimanda il modulo (il piano li assorbe con quelli già registrati)%s',
                $cheMancano, $righeDi($mancanti), $oppure)),
            $allaCreazione => $unite($nonApplica, $primaDelPiano, sprintf('Il piano non è ancora stato creato: %srimanda il modulo (il piano assorbe i saldi legati %s) e non registrare altro%s',
                $daCorreggere !== '' ? 'poi ' : '', $senzaData, $oppure)),
            $mancanti !== [] => $unite($nonApplica, $primaDelPiano, sprintf('Per passare la parte agli eredi registra dal Wallet %s (%s) e crea di nuovo il piano, che assorbe anche i saldi legati già registrati. %s',
                $cheMancano, $righeDi($mancanti), $senzaPianoNuovo)),
            default => $unite($nonApplica, $primaDelPiano, sprintf('%s il piano, che assorbe i saldi legati %s, e non registrare altro. %s',
                $daCorreggere !== '' ? 'Poi crea di nuovo' : 'Crea di nuovo', $senzaData, $senzaPianoNuovo)),
        };

        // La strada di V2 dopo la frase di V5, che dice già che il piano non applica i saldi. Qui il piano non legge i saldi non legati: le
        // righe di chi non ha niente si chiedono senza condizione, quelle degli incerti solo se i loro saldi non legati non comprendono la parte.
        // Se la comprendono il piano creato di nuovo li assorbe (alla creazione, con il Wallet che non ha saldi liberi, un altro piano li ha già
        // assorbiti): chiedere anche le righe legate farebbe entrare la parte due volte.
        $incertiAlSingolare = count($incerti) === 1 ? 'il saldo legato' : 'i saldi legati';
        $v2DopoV5 = match (true) {
            $allaCreazione && $mancanti === [] => sprintf('Il piano non è ancora stato creato: se quelli non legati comprendono la parte non serve registrare altro; se non la comprendono, registra adesso dal Wallet %s %s (%s) e rimanda il modulo (il piano li assorbe)%s',
                $incertiAlSingolare, $senzaData, $righeDi($incerti), $oppure),
            $allaCreazione => sprintf('Il piano non è ancora stato creato: registra adesso dal Wallet %s (%s) e, se quelli non legati non la comprendono, anche %s; poi rimanda il modulo (il piano li assorbe)%s',
                $cheMancano, $righeDi($mancanti), $righeDi($incerti), $oppure),
            $mancanti === [] => sprintf('Se quelli non legati comprendono la parte, crea di nuovo il piano, che assorbe quelli ancora liberi nel Wallet, e non registrare altro; se non la comprendono, registra dal Wallet %s %s (%s) e crea di nuovo il piano. %s',
                $incertiAlSingolare, $senzaData, $righeDi($incerti), $senzaPianoNuovo),
            default => sprintf('Per passare la parte agli eredi registra dal Wallet %s (%s) e, se quelli non legati non la comprendono, anche %s; poi crea di nuovo il piano. %s',
                $cheMancano, $righeDi($mancanti), $righeDi($incerti), $senzaPianoNuovo),
        };
        $ok = $nomiDi($strada['ok']);
        $ciSonoGia = $ok === [] ? '' : sprintf('%s di %s %s già.', count($ok) > 1 ? 'Quelli' : 'Quello', self::elenco($ok), count($ok) > 1 ? 'ci sono' : 'c\'è');
        $poi = fn (string $prima): string => implode(' ', array_filter([$prima, $daCorreggere, $ciSonoGia], fn (string $x) => $x !== ''));
        // Coda 332, decisione 164.1: qualcuno ha la parte due volte e a nessuno manca niente (né righe da registrare né saldi da leggere): le due serie
        // aprono la strada, e di chi ha la sua parte una volta sola la frase lo dice dopo. Altrimenti le due serie seguono la variante. Dopo la strada
        // delle due serie («quella che resta») la frase nomina la parte: «quella di …» si leggerebbe riferito alla serie (decisione 170).
        $soloDueSerie = $dueSerie !== '' && $strada['altrove'] === [] && $incerti === [] && $mancanti === [];
        $unaVoltaSola = $ok === [] ? '' : sprintf('%s di %s %s una volta sola.', count($ok) > 1 ? 'Le parti' : 'La parte', self::elenco($ok), count($ok) > 1 ? 'entrano' : 'entra');

        $variante = match (true) {
            // Le righe legate di qualcuno sono già in un altro piano che si riscrive ancora (prima delle V4: con loro la parte non è passata).
            $strada['altrove'] !== [] => $this->conRigheAltrove($strada, $nomiDi, $righeDi, $cheMancano, $senzaData, $pianoApplicaSaldi, $allaCreazione,
                $unite(! $pianoApplicaSaldi ? $nonApplica : '', $primaDelPiano), $coda),
            // V4 (decisione 86.4), con le righe legate alla successione: sono la parte, e questo piano le assorbe con questa generazione.
            $partePassata && count($strada['ok_legati']) === count($tutti) => sprintf('I saldi ci sono già: %s hanno saldi legati %s su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.',
                self::elenco($tutti), $allaSuccessione),
            // V4 con le righe miste: i saldi non legati di chi non ha righe legate sono la sua parte, e le righe legate quella degli altri.
            $partePassata && $strada['ok_legati'] !== [] => sprintf('I saldi ci sono già: %s hanno un saldo su quella gestione e quell\'unità (%s di %s %s %s), e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.',
                self::elenco($tutti), count($strada['ok_legati']) > 1 ? 'quelli' : 'quello', self::elenco($nomiDi($strada['ok_legati'])), count($strada['ok_legati']) > 1 ? 'legati' : 'legato', $allaSuccessione),
            // V4 con i saldi non legati di prima, che sono la parte: dire che «si regola fra le parti» inviterebbe a un secondo regolamento a mano
            // di soldi che il piano sposta.
            $partePassata => sprintf('I saldi ci sono già: %s hanno un saldo su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.',
                self::elenco($tutti)),
            // V5: fra chi non ha righe legate qualcuno ha un saldo non legato e la parte non torna (un riporto, un credito di prima). Il programma non
            // sa se lo comprende: lo dice, chiede le righe di chi non ha niente e, al condizionale, le loro. Se il piano non applica i saldi, si dice
            // anche perché quelli già registrati non entrano.
            $incerti !== [] => sprintf('%s %s su quella gestione e quell\'unità saldi non legati %s, e il programma non sa se comprendono la parte',
                self::elenco($nomiDi($incerti)), count($incerti) > 1 ? 'hanno' : 'ha', $senzaData)
                . (! $pianoApplicaSaldi
                    ? '. ' . $unite($nonApplica, $primaDelPiano, $v2DopoV5)
                    : $poi($mancanti === []
                        ? sprintf(': se la comprendono non serve registrare altro; altrimenti registra dal Wallet %s %s (%s), che il piano assorbe alla prossima generazione.',
                            count($incerti) > 1 ? 'i saldi legati' : 'il saldo legato', $allaSuccessione, $righeDi($incerti))
                        : sprintf(': registra dal Wallet %s (%s) e, se quelli non legati non la comprendono, anche %s; il piano li assorbe alla prossima generazione.',
                            $cheMancano, $righeDi($mancanti), $righeDi($incerti)))),
            ! $pianoApplicaSaldi => $v2,
            // V1: il piano applica i saldi, e nessuno ha la sua parte: tutte le righe.
            $mancanti !== [] && count($mancanti) === count($tutti) => sprintf('Per passare la parte agli eredi registra dal Wallet i saldi legati %s, sulla stessa gestione: %s; il piano li assorbe alla prossima generazione.', $allaSuccessione, $righe),
            // V1 con qualcuno che ha già la sua parte: solo le righe che mancano.
            $mancanti !== [] => $poi(sprintf('Per passare la parte agli eredi registra dal Wallet %s, sulla stessa gestione: %s; il piano %s assorbe alla prossima generazione.',
                $cheMancano, $righeDi($mancanti), $piuMancanti ? 'li' : 'lo')),
            // Non manca niente, e qualcuno ha la parte due volte.
            $soloDueSerie => $unite($dueSerie, $unaVoltaSola, $daCorreggere),
            // Non manca niente, ma una riga legata non è la parte.
            default => $poi(''),
        };

        // Le due serie di chi ha la parte due volte, dopo la variante che chiede ciò che manca. Poi, Coda 332: i saldi di chi ha già la sua parte stanno
        // in un esercizio che non è quello aperto. Il Wallet non li mostra e la frase lo dice, in ogni variante: chi li cerca nel Wallet e non li trova li
        // registrerebbe di nuovo. `voce()` li cerca solo con il piano che applica i saldi.
        return $comune . ' ' . $unite($variante, $soloDueSerie ? '' : $dueSerie, self::nonNelWallet($fuoriDalWallet));
    }

    /**
     * La strada quando le righe legate alla successione di qualcuno sono già in un altro piano della stessa gestione che si riscrive ancora
     * (`saldiLegatiAltrove`): questo piano non le assorbe, e la porta del Wallet non ne accetta altre per le stesse persone finché sono lì. La frase
     * non le chiede di nuovo: dice dove sono e le due strade che dalla pagina passano, secondo di chi sono. Se sono di questo piano, si elimina
     * quell'altro (riportato prima in bozza, se è approvato), le righe tornano libere nel Wallet e questo piano le assorbe (creato di nuovo, se non
     * applica i saldi; alla creazione, rimandando il modulo). Se sono di quell'altro, quelle di questo piano si registrano dopo che quello è emesso
     * (approvato prima, se è in bozza): da allora le sue righe sono ferme, e la porta accetta le nuove. Chi non ha niente, o ha saldi non legati che
     * il programma non sa leggere, ha le sue righe come nelle altre varianti; chi ha già la sua parte, o una riga da correggere, è detto in `$prima`.
     *
     * @param array<string, mixed> $strada il risultato di `chiHaLaParte`
     * @param callable(list<int>): list<string> $nomiDi
     * @param callable(list<int>): string $righeDi
     */
    private function conRigheAltrove(array $strada, callable $nomiDi, callable $righeDi, string $cheMancano, string $senzaData, bool $pianoApplicaSaldi, bool $allaCreazione, string $prima, string $coda): string
    {
        $piani = collect($strada['piani_altrove']);
        $uno = $piani->count() === 1;
        $nomi = $piani->map(fn (PianoRate $p) => '«' . $p->nome . '»')->all();
        $approvato = fn (PianoRate $p): bool => $p->stato === \App\Enums\StatoPianoRate::APPROVATO;
        $piu = $strada['righe_altrove'] > 1;
        $chi = $strada['altrove'];
        $dove = sprintf('%s %s di %s %s già %s %s, che non %s ancora %s: finché %s lì, il Wallet non ne accetta %s per %s.',
            $piu ? 'I saldi legati' : 'Il saldo legato', $senzaData, self::elenco($nomiDi($chi)), $piu ? 'sono' : 'è', $uno ? 'nel piano' : 'nei piani', self::elenco($nomi),
            $uno ? 'è' : 'sono', $uno ? 'emesso' : 'emessi', $piu ? 'sono' : 'è', $piu ? 'altri' : 'un altro', count($chi) > 1 ? 'le stesse persone' : 'la stessa persona');
        $elimina = match (true) {
            $uno && $approvato($piani->first()) => sprintf('riporta %s in bozza ed eliminalo dalla sua pagina', $nomi[0]),
            $uno => sprintf('elimina %s dalla sua pagina', $nomi[0]),
            default => sprintf('elimina %s dalle loro pagine%s', self::elenco($nomi), $piani->contains($approvato) ? ' (riportando prima in bozza quelli approvati)' : ''),
        };
        // Il piano da emettere si nomina: con un pronome la frase si legge riferita a questo piano. Dopo il suo nome, questo piano si dice «questo piano».
        $dopoAver = match (true) {
            $uno && $approvato($piani->first()) => 'dopo aver emesso ' . $nomi[0],
            $uno => 'dopo aver approvato ed emesso ' . $nomi[0],
            default => 'dopo aver emesso quei piani' . ($piani->contains(fn (PianoRate $p) => ! $approvato($p)) ? ' (approvando prima quelli in bozza)' : ''),
        };
        [$sono, $tornano, $li, $quelli] = $piu ? ['sono', 'tornano liberi', 'li', 'quelli'] : ['è', 'torna libero', 'lo', 'quello'];
        $diQuello = $uno ? $nomi[0] : 'quei piani';
        $nuove = $righeDi($chi);
        $strade = match (true) {
            $allaCreazione => sprintf('Il piano non è ancora stato creato. Se %s di questo piano, %s, così %s nel Wallet, e rimanda il modulo (il piano %s assorbe). Se %s di %s, registra dal Wallet %s di questo piano (%s) %s, e rimanda il modulo.',
                $sono, $elimina, $tornano, $li, $sono, $diQuello, $quelli, $nuove, $dopoAver),
            $pianoApplicaSaldi => sprintf('Se %s di questo piano, %s: %s nel Wallet, e questo piano %s assorbe alla prossima generazione. Se %s di %s, registra dal Wallet %s di questo piano (%s) %s: questo piano %s assorbe alla prossima generazione.',
                $sono, $elimina, $tornano, $li, $sono, $diQuello, $quelli, $nuove, $dopoAver, $li),
            default => sprintf('Se %s di questo piano, %s, così %s nel Wallet, e crea di nuovo questo piano, che %s assorbe. Se %s di %s, registra dal Wallet %s di questo piano (%s) %s, e crea di nuovo questo piano.',
                $sono, $elimina, $tornano, $li, $sono, $diQuello, $quelli, $nuove, $dopoAver),
        };
        $incerti = $strada['incerti'];
        // Chi ha la sua parte in saldi non legati che questa generazione assorbe (le righe legate di chi le ha sono già dette in `$prima`).
        $conINonLegati = array_values(array_diff($strada['ok'], $strada['ok_legati']));
        $pezzi = [
            $prima,
            $conINonLegati === [] ? '' : sprintf('%s di %s %s già.', count($conINonLegati) > 1 ? 'Quelli' : 'Quello', self::elenco($nomiDi($conINonLegati)), count($conINonLegati) > 1 ? 'ci sono' : 'c\'è'),
            $dove,
            $strada['mancanti'] !== [] ? sprintf('Registra dal Wallet %s (%s).', $cheMancano, $righeDi($strada['mancanti'])) : '',
            $incerti !== [] ? sprintf('%s %s su quella gestione e quell\'unità saldi non legati %s, e il programma non sa se comprendono la parte: se non la comprendono, registra dal Wallet anche %s.',
                self::elenco($nomiDi($incerti)), count($incerti) > 1 ? 'hanno' : 'ha', $senzaData, $righeDi($incerti)) : '',
            $strade,
            match (true) {
                $allaCreazione && ! $pianoApplicaSaldi => 'Senza il piano la parte si regola fra le parti' . $coda . '.',
                ! $pianoApplicaSaldi => 'Senza un piano nuovo la parte si regola fra le parti' . $coda . '.',
                default => '',
            },
        ];

        return implode(' ', array_filter($pezzi, fn (string $x) => $x !== ''));
    }

    /** «€ 236,71 a debito», «€ 394,52 a credito», «€ 0,00» (positivo = debito). */
    private static function inVerso(int $cents): string
    {
        return MoneyHelper::format(abs($cents)) . ($cents > 0 ? ' a debito' : ($cents < 0 ? ' a credito' : ''));
    }

    /** «€ 394,52», e per un credito «€ 394,52 a credito» (mai il segno meno). */
    private static function cifra(int $cents): string
    {
        return MoneyHelper::format(abs($cents)) . ($cents < 0 ? ' a credito' : '');
    }

    /** «Anna», «Anna e Bruno», «Anna, Bruno e Carla». @param list<string> $voci */
    private static function elenco(array $voci): string
    {
        if (count($voci) <= 1) {
            return $voci[0] ?? '';
        }
        $ultima = array_pop($voci);

        return implode(', ', $voci) . ' e ' . $ultima;
    }
}
