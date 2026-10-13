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
 * (art. 754 c.c.) e la strada, la stessa del conguaglio: il saldo manuale dal Wallet, se si può fare davvero (decisione 80).
 *
 * Una voce per defunto e unità, nella forma dei cambiamenti del motore (`motivo` = `defunto_nel_piano`), così il cancello, le tre rotte che
 * lo mostrano e il flash non cambiano. Le chiavi `passaggio_id`, `passaggi_ids`, `decesso`, `cifra`, `cifra_formattata` di testa, `wallet`,
 * `piano_applica_saldi`, `con_un_saldo`, `parti_da_decidere` e `defunto.nome` servono ai test e al laboratorio: la pagina legge `frase`, `eredi`,
 * `immobile_id` e `defunto.id`. Tre cose da tenere ferme:
 *
 * - **La condizione è la scelta, non le righe.** `capo()->registro['arretrato']['scelta']` è «agli eredi»: con l'ordinaria generata la
 *   prima volta dopo il decesso l'arretrato non scrive righe (non c'è niente di emesso), e `arretratoAgliEredi()` sarebbe falso. La
 *   scelta sta nel solo passaggio padre: la riga del box ha gli eredi ma non l'arretrato.
 * - **La cifra è la quota pura del motore** (`$totaliPerImmobile`), prima dei saldi del Wallet. Per questo la frase dice che il piano
 *   «calcola ancora per» il defunto, non che gli «intesta»: con i saldi manuali già assorbiti la sua quota netta è zero, e il cancello
 *   ritorna lo stesso a ogni ricalcolo, con la frase V4 che dice che i saldi ci sono già (decisione 86.4; farlo tacere è della 1.11.1).
 * - **Il Wallet entra in un piano solo se il piano applica i saldi** (`GeneratePianoRateAction::saldiSiApplicano`): una frase che
 *   promettesse «registra un saldo dal Wallet» a un piano che non li applica direbbe ciò che il piano non fa. Le varianti: V1 il saldo
 *   si può registrare e il piano lo assorbe; V2 si può registrare ma il piano non li applica (serve crearlo di nuovo; alla creazione, dove il
 *   piano non esiste ancora, basta registrarli e rimandare il modulo); V3 qualcuno ha già un saldo su quella gestione e quell'unità, e la
 *   parte si regola fra le parti; V4 il defunto e tutti gli eredi hanno un saldo che questa generazione assorbe nelle quote **e quei saldi
 *   spostano davvero la parte**: i saldi ci sono già e non serve registrare altro. Un riporto che non è la parte (il consuntivo, un credito
 *   di prima) non basta: con saldi di altra cifra resta V3.
 *
 * I saldi «che entrano» sono quelli che la generazione assorbe (`GenerateSaldiAction`): liberi nel Wallet (non applicati, anche quelli della
 * 1.7.x e della 1.8.x, senza piano) o già di questo piano, sulla stessa gestione, unità ed esercizio, e solo se il piano applica i saldi. Non
 * contano le righe dei passaggi (`subentro_id`) né un saldo che un altro piano ha già assorbito.
 *
 * Più successioni dello stesso defunto sulla stessa unità (la proprietà e la nuda proprietà, due ruoli): la cifra del motore è per persona e
 * unità, e si divide per ruolo leggendo il dettaglio del riparto che il motore ha appena calcolato — la riga di titolarità di ogni riga di
 * riparto dice a quale successione appartiene —, ognuna con i suoi eredi per quota. Se un pezzo non si attribuisce (un già versato, un
 * addebito diretto, un dettaglio che non c'è, una successione con l'arretrato a suo nome), la voce nomina tutte le successioni e dice che la
 * divisione la decide l'amministratore: senza cifre per erede, senza la strada del Wallet.
 *
 * Seconde fonti dichiarate: la strada del Wallet è `FermataDopoScrivi::conUnSaldo` (la stessa della fermata);
 * la coda di V3 è la gemella di `FermataDopoScrivi::stradaAMano`, che è privata; la divisione per quota è
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
        foreach ($gruppi as $successioni) {
            $cifra = $cifre[(int) $successioni[0]->anagrafica_uscente_id][(int) $successioni[0]->immobile_id];
            $voci[] = $this->voce($piano, $successioni, $cifra, $pianoApplicaSaldi, $allaCreazione, $righeDettaglio, $nomiPersone->all(), $nomiUnita->all());
        }
        usort($voci, fn (array $a, array $b) => [$a['immobile_id'], $a['defunto']['id']] <=> [$b['immobile_id'], $b['defunto']['id']]);

        return $voci;
    }

    /** La successione ha passato l'arretrato agli eredi, e ha degli eredi. */
    private static function agliEredi(Subentro $s): bool
    {
        return ($s->capo()->registro['arretrato']['scelta'] ?? null) === Subentro::ARRETRATO_AGLI_EREDI && $s->eredi() !== [];
    }

    /**
     * La voce di un defunto su un'unità, con le sue successioni (una, o più dello stesso defunto sulla stessa unità). `$cifra` ha il segno
     * (positivo = debito del defunto, negativo = un suo credito).
     *
     * @param list<Subentro> $successioni
     * @param list<array<string, mixed>> $righeDettaglio
     * @param array<int, string> $nomiPersone
     * @param array<int, string> $nomiUnita
     * @return array<string, mixed>
     */
    private function voce(PianoRate $piano, array $successioni, int $cifra, bool $pianoApplicaSaldi, bool $allaCreazione, array $righeDettaglio, array $nomiPersone, array $nomiUnita): array
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

        // La strada della decisione 80: il Wallet accetta un saldo per persona, gestione, unità ed esercizio; con un saldo già lì la riga
        // verrebbe rifiutata e la parte si regola fra le parti.
        $persone = array_merge([$defuntoId], array_column($eredi, 'id'));
        $esercizioId = $piano->esercizio_id !== null ? (int) $piano->esercizio_id : null;
        $conUnSaldo = FermataDopoScrivi::conUnSaldo($persone, (int) $piano->gestione_id, $immobileId, $esercizioId);
        $wallet = $conUnSaldo === [];
        $nomiConUnSaldo = array_values(array_map(fn (int $id) => $id === $defuntoId ? $defunto : ($nomiPersone[$id] ?? ''), array_values(array_filter($persone, fn (int $id) => in_array($id, $conUnSaldo, true)))));
        // Decisione 86.4: il defunto e tutti i suoi eredi hanno un saldo che questa generazione assorbe nelle quote, e quei saldi sono la parte
        // che passa agli eredi (la cifra, per ognuno, nel verso giusto, a meno di un centesimo di resto). Un riporto o un credito di prima
        // non lo sono: con saldi di altra cifra, o con qualcuno senza saldo, la parte non è passata e vale la strada di prima.
        $saldiCheEntrano = $pianoApplicaSaldi && $perErede !== null ? self::saldiCheEntrano($persone, $piano, $immobileId, $esercizioId) : [];
        $partePassata = $perErede !== null && $pianoApplicaSaldi && ! $wallet && self::sonoLaParte($saldiCheEntrano, $defuntoId, $cifra, $eredi);

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
            'frase'               => $perErede === null
                ? $this->fraseDaDecidere($defunto, $decessi, $unita, $cifra, $successioni, $nomiPersone)
                : $this->frase($defunto, $decessi, $unita, $cifra, $eredi, $wallet, $pianoApplicaSaldi, $allaCreazione, $nomiConUnSaldo, $partePassata, count($successioni) > 1 ? $this->divisionePerRuolo($successioni, $pezzi) : null),
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
     * I saldi che questa generazione assorbe nelle quote delle persone, sulla gestione, l'unità e l'esercizio del piano, per persona: la selezione
     * di `GenerateSaldiAction` (liberi nel Wallet, o già di questo piano), senza le righe dei passaggi e i debiti verso i fornitori. Un saldo della
     * 1.7.x o della 1.8.x, senza piano e con `is_applicato` falso, è libero come uno registrato adesso. Chi ha più saldi li somma.
     *
     * @param list<int> $persone
     * @return array<int, int> id della persona => centesimi (positivo = debito)
     */
    private static function saldiCheEntrano(array $persone, PianoRate $piano, int $immobileId, ?int $esercizioId): array
    {
        return Saldo::whereIn('anagrafica_id', $persone ?: [0])->where('gestione_id', $piano->gestione_id)->where('immobile_id', $immobileId)
            ->whereNull('subentro_id')->whereNull('fornitore_id')->where('saldo_iniziale', '!=', 0)
            ->when($esercizioId !== null, fn ($q) => $q->where('esercizio_id', $esercizioId))
            ->where(fn ($q) => $q->where('is_applicato', false)->orWhere('piano_rate_id', $piano->id))
            ->get()->groupBy('anagrafica_id')->map(fn ($g) => (int) $g->sum('saldo_iniziale'))->mapWithKeys(fn (int $cents, $id) => [(int) $id => $cents])->all();
    }

    /**
     * I saldi che entrano sono la parte che passa agli eredi: il defunto ha un credito pari alla cifra, ogni erede un debito pari alla sua parte
     * (con il segno della cifra, che un credito del defunto rovescia), a meno di un centesimo di resto.
     *
     * @param array<int, int> $saldi id della persona => centesimi
     * @param list<array<string, mixed>> $eredi
     */
    private static function sonoLaParte(array $saldi, int $defuntoId, int $cifra, array $eredi): bool
    {
        $attesi = [$defuntoId => -$cifra];
        foreach ($eredi as $e) {
            $attesi[(int) $e['id']] = (int) $e['cifra'];
        }
        foreach ($attesi as $id => $atteso) {
            if (! array_key_exists($id, $saldi) || abs($saldi[$id] - $atteso) > 1) {
                return false;
            }
        }

        return true;
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
     * La frase del cancello: la parte comune (chi, quanto, perché e a chi tocca) e la variante della strada, V1, V2, V3 o V4.
     *
     * @param list<array{nome: string, cifra: int}> $eredi
     * @param list<string> $decessi
     * @param list<string> $nomiConUnSaldo
     */
    private function frase(string $defunto, array $decessi, string $unita, int $cifra, array $eredi, bool $wallet, bool $pianoApplicaSaldi, bool $allaCreazione, array $nomiConUnSaldo, bool $partePassata, ?string $perRuolo): string
    {
        $credito = $cifra < 0;
        $parteDegliEredi = self::elenco(array_map(fn (array $e) => sprintf('%s %s', $e['nome'], MoneyHelper::format(abs($e['cifra']))), $eredi));
        $comune = self::apertura($defunto, $decessi, $unita, $cifra, $perRuolo !== null) . ' '
            . ($perRuolo !== null ? sprintf('La cifra è divisa per ruolo, come nel riparto del piano: %s. ', $perRuolo) : '')
            . ($credito
                ? sprintf('Il credito passa agli eredi per quota: %s.', $parteDegliEredi)
                : sprintf('Ne rispondono gli eredi, ciascuno per la sua quota (art. 754 c.c.): %s.', $parteDegliEredi));

        // Le righe del Wallet che passano la parte agli eredi, ognuna con il suo verso: chi ha il debito riceve una riga a credito e
        // viceversa (positivo = debito). Un credito del defunto le rovescia.
        $righe = self::elenco(array_merge(
            [sprintf('%s a %s di %s', MoneyHelper::format(abs($cifra)), $credito ? 'debito' : 'credito', $defunto)],
            array_map(fn (array $e) => sprintf('%s a %s di %s', MoneyHelper::format(abs($e['cifra'])), $credito ? 'credito' : 'debito', $e['nome']), $eredi),
        ));

        $strada = match (true) {
            // V4 (decisione 86.4): il defunto e gli eredi hanno già il loro saldo, quel saldo è la parte, e questo piano lo assorbe con questa
            // generazione: dire che «si regola fra le parti» inviterebbe a un secondo regolamento a mano di soldi che il piano sposta.
            $partePassata => sprintf('I saldi ci sono già: %s hanno un saldo su quella gestione e quell\'unità, e questo piano li assorbe nelle loro quote con questa generazione. Non serve registrare altro.',
                self::elenco($nomiConUnSaldo)),
            // V3: qualcuno ha già un saldo (decisione 80). Se il piano non applica i saldi, si dice anche perché quelli già registrati non
            // entrano nelle sue quote: è la promessa che il piano non manterrebbe.
            ! $wallet => sprintf('Se vanno agli eredi, la parte si regola fra le parti: %s già un saldo su quella gestione e quell\'unità, e il programma non ne registra un secondo.',
                self::elenco($nomiConUnSaldo) . (count($nomiConUnSaldo) > 1 ? ' hanno' : ' ha'))
                . ($pianoApplicaSaldi ? '' : ' Questo piano non applica i saldi del Wallet: quelli già registrati non entrano nelle sue quote.'),
            // V2: il Wallet è libero ma il piano non applica i saldi. Alla creazione il piano non esiste ancora (il cancello annulla la richiesta):
            // registrare i saldi e rimandare il modulo li fa entrare.
            ! $pianoApplicaSaldi && $allaCreazione => sprintf('Con il Wallet com\'è adesso il piano non applica i saldi, e non è ancora stato creato: se registri adesso i saldi (%s) e rimandi il modulo, il piano li assorbe; altrimenti la parte si regola fra le parti.', $righe),
            ! $pianoApplicaSaldi => sprintf('Questo piano non applica i saldi del Wallet: un saldo registrato adesso non entra qui. Per passare la parte agli eredi con il Wallet registra i saldi (%s) e crea di nuovo il piano; altrimenti la parte si regola fra le parti.', $righe),
            // V1: il Wallet è libero e il piano li applica.
            default => sprintf('Per passare la parte agli eredi registra un saldo manuale dal Wallet sulla stessa gestione, %s: il piano lo assorbe alla prossima generazione.', $righe),
        };

        return $comune . ' ' . $strada;
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
