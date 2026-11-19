<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Accessori;

use App\Helpers\MoneyHelper;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decisione 489: gli interessi sul debito del defunto passato agli eredi con la successione, dal decesso, ciascuno per la sua parte, finché quel debito non
 * entra in una loro rata e ne segue gli interessi. Sola lettura: niente si scrive al passaggio, e vale anche per le successioni registrate prima (decisione
 * 493 (5)). Le otto scelte dell'amministratore sono {@see RegoleDegliEredi}.
 *
 * - **Quali successioni**: i passaggi padre non annullati di tipo successione con l'arretrato agli eredi (le righe di `saldi` del registro,
 *   `arretrato.saldi`). Con «si fermano al decesso» gli eredi contano dal decesso; con «continuano a suo nome» solo se l'amministratore ha scelto che
 *   coprano il buco fra l'emissione e la scadenza della rata nuova (scelta 7).
 * - **La base**: per ogni rata del defunto sulle unità del passaggio (l'unità e le pertinenze) il capitale alla fine del giorno prima del decesso, con la
 *   copertura dei suoi crediti ({@see InteressiDiMora::capitaliAl()}); con la scelta 6 anche la parte che un accordo di rientro in regola teneva fuori, con
 *   la scelta 1 anche gli addebiti a lui solo passati con le righe di saldo. La parte dell'erede per quota (un arrotondamento per rata), con un **tetto** per
 *   gestione e unità: la somma non supera le sue righe dell'arretrato, tolto dalle rate più recenti.
 * - **Il primo giorno**: il più tardo fra il decesso (o il giorno dopo, scelta 2), il primo giorno della rata del defunto e il giorno dopo l'ultimo già
 *   registrato al defunto su quella rata.
 * - **L'ultimo giorno**: il piano che assorbe le righe dell'erede le mette nelle sue rate (`saldo_usato`), prima nella più vecchia; una parte esce dalla
 *   riga ereditata solo quando la rata che la porta è emessa, il giorno prima che quella rata cominci a contare i suoi interessi (con la scelta 3, il giorno
 *   della sua scadenza; il giorno prima di un accordo dell'erede che la copre). Con la scelta 4 spenta esce tutto alla prima rata. Le parti delle rate sono
 *   nella proporzione in cui il piano divide l'arretrato (E4 della seconda prova della beta.12).
 * - **Senza cambiare il motore**: la riga ereditata è una chiave in più dell'erede (`persona|rata|unità|s<passaggio>`), con i suoi movimenti; la rata nuova
 *   ha, il giorno dell'emissione, la parte ereditata in meno, e la riprende il giorno in cui esce dalla riga ereditata: nessun giorno contato due volte,
 *   nessuno perso. Con la scelta 5 la chiave ereditata ha l'ordine della rata del defunto, e un versamento dell'erede la copre prima; spenta, per ultima.
 * - **Il versamento dell'erede sul debito ereditato** (decisione 529): si registra sulla rata del defunto, con il legame a chi ha versato (`riferimento`),
 *   e una coppia nel Wallet legata al passaggio (l'erede − y, il defunto + y) che il piano dopo compensa ({@see versamentiDegliEredi()}). La proposta lo
 *   conta il giorno del versamento sulla chiave dell'erede; «Rate scadute» dà a ciascuno la sua parte meno quello che ha versato; il piano lo legge dalla
 *   coppia, e le parti delle rate nuove sono al netto dei versamenti.
 * - **Le successioni a catena** (decisione 530): l'erede che muore a sua volta smette di contare il debito che aveva ereditato al suo decesso, come le sue
 *   rate ({@see PropostaInteressi::fermiAlDecesso()}), e da lì lo conta il suo erede, con le quote e le scelte della seconda successione: la sua base sono
 *   le chiavi ereditate del secondo defunto alla vigilia del decesso ({@see baseEreditataDelDefunto()}); in «Rate scadute» le righe sono dei suoi eredi.
 * - **Le pagine dei debiti** (decisione 536): ogni persona dalle sue righe ({@see perLaLettura()}). Gli eredi dalle loro chiavi ereditate, la stessa fonte
 *   della proposta ({@see chiaviAlGiorno()}); il defunto dal suo credito della successione non ancora usato ({@see sulDefunto()}). Niente si sposta dal
 *   residuo di oggi del defunto agli eredi.
 */
final class DebitoEreditato
{
    /** @var array<int, Collection<int, Subentro>> */
    private array $successioni = [];

    /**
     * Le successioni del condominio con l'arretrato agli eredi, non annullate (i passaggi padre).
     *
     * @return Collection<int, Subentro>
     */
    public function successioni(int $condominioId): Collection
    {
        return $this->successioni[$condominioId] ??= Subentro::query()->where('condominio_id', $condominioId)->where('tipo_passaggio', 'successione')
            ->whereNull('subentro_padre_id')->orderBy('id')->get()->filter(fn (Subentro $s) => $s->arretratoAgliEredi())->values();
    }

    /**
     * Le persone date con chi è legato a loro da una successione con l'arretrato agli eredi: il defunto porta i suoi eredi (decisione 489: gli eredi entrano
     * nella proposta quando entra il defunto). L'erede porta il defunto, che dopo la successione non è più in «Rate scadute» per il debito passato, per i suoi
     * interessi fino al decesso non registrati, e solo per quelli: le sue righe sulle unità del passaggio (`solo_unita`). Non porta i coeredi, che con la sua
     * proposta non c'entrano (osservazione della seconda prova della beta.12): per loro si chiede la loro, o quella del defunto.
     *
     * @param list<int> $persone
     * @return array{persone: list<int>, solo_unita: array<int, list<int>>} le persone, e per il defunto portato da un erede le unità delle sue righe
     */
    public function perLaProposta(int $condominioId, array $persone): array
    {
        $chi = array_flip(array_map('intval', $persone));
        $aggiunti = [];
        $soloUnita = [];
        // Decisione 530: con le successioni a catena il defunto porta anche gli eredi dei suoi eredi (Ettore porta Marco, Marco morto porta Luca): si
        // ripete finché nessuno si aggiunge.
        $portano = $chi;
        for ($giro = 0; $giro < 5; $giro++) {
            $nuovi = [];
            foreach ($this->successioni($condominioId) as $s) {
                $defunto = (int) $s->anagrafica_uscente_id;
                if (isset($portano[$defunto])) {
                    foreach ($s->registro['eredi'] ?? [] as $e) {
                        $id = (int) ($e['anagrafica_id'] ?? 0);
                        if ($id > 0 && ! isset($chi[$id]) && ! isset($aggiunti[$id])) {
                            $aggiunti[$id] = true;
                            $nuovi[$id] = true;
                        }
                    }
                }
            }
            if ($nuovi === []) {
                break;
            }
            $portano = $nuovi;
        }
        foreach ($this->successioni($condominioId) as $s) {
            $defunto = (int) $s->anagrafica_uscente_id;
            $eredi = array_map(fn ($e) => (int) ($e['anagrafica_id'] ?? 0), (array) ($s->registro['eredi'] ?? []));
            if (! isset($chi[$defunto]) && ! isset($aggiunti[$defunto]) && $defunto > 0 && array_intersect_key($chi, array_flip($eredi)) !== []) {
                $aggiunti[$defunto] = true;
                $soloUnita[$defunto] = array_values(array_unique([...($soloUnita[$defunto] ?? []), ...$this->unitaDelPassaggio($s)]));
            }
        }
        // Un defunto chiesto per sé o portato da una sua successione come defunto ha tutte le sue righe.
        foreach (array_keys($soloUnita) as $defunto) {
            if (isset($chi[$defunto])) {
                unset($soloUnita[$defunto]);
            }
        }

        return ['persone' => array_values(array_unique([...array_map('intval', $persone), ...array_keys($aggiunti)])), 'solo_unita' => $soloUnita];
    }

    /** @var array<int, list<int>> */
    private array $unita = [];

    /** @var array<int, list<array{rata_id: int, immobile_id: int, erede: int, giorno: string, importo: int, di: int}>> */
    private array $versati = [];

    /** Le unità del passaggio: l'unità e le pertinenze passate con lei (un passaggio figlio ciascuna). Letta una volta per successione. */
    private function unitaDelPassaggio(Subentro $s): array
    {
        return $this->unita[(int) $s->id] ??= array_values(array_unique([(int) $s->immobile_id,
            ...Subentro::query()->where('subentro_padre_id', $s->id)->pluck('immobile_id')->map(fn ($id) => (int) $id)->all()]));
    }

    /**
     * I defunti sulle cui rate il giornale tiene il debito che la successione passa: il suo defunto e, con la catena (decisione 530), i defunti delle
     * successioni di prima di cui lui era erede, sulle stesse unità. Q5 della quarta prova della beta.12: l'erede dell'erede versa sulla rata del primo
     * defunto, dove il debito sta. Un controllo in memoria sulla lista delle successioni.
     *
     * @return list<int>
     */
    public function defuntiDelleRate(Subentro $s): array
    {
        $esito = [(int) $s->anagrafica_uscente_id];
        $giro = [$s];
        for ($i = 0; $i < 5 && $giro !== []; $i++) {
            $prossimi = [];
            foreach ($giro as $x) {
                foreach ($this->successioni((int) $x->condominio_id) as $y) {
                    if ((int) $y->id === (int) $x->id || $y->decorrenza === null || $y->decorrenza->format('Y-m-d') >= $x->decorrenza->format('Y-m-d')
                        || in_array((int) $y->anagrafica_uscente_id, $esito, true)
                        || ! collect($y->registro['eredi'] ?? [])->contains(fn ($e) => (int) ($e['anagrafica_id'] ?? 0) === (int) $x->anagrafica_uscente_id)
                        || array_intersect($this->unitaDelPassaggio($y), $this->unitaDelPassaggio($x)) === []) {
                        continue;
                    }
                    $esito[] = (int) $y->anagrafica_uscente_id;
                    $prossimi[] = $y;
                }
            }
            $giro = $prossimi;
        }

        return $esito;
    }

    /**
     * Decisione 529: i versamenti degli eredi sul debito ereditato, dal giornale. Sono le righe sulla rata del defunto, sulle unità del passaggio, con il
     * legame a un erede (`riferimento`, la riga di «Versato da» della Coda 167), dal giorno del decesso: AVERE meno DARE su «Crediti verso condomini», per
     * rata, unità, erede e giorno. Fuori le scritture annullate: lo storno dell'incasso le annulla, e la sua rettifica non porta il legame. Un versamento con
     * la data di prima del decesso non c'è: il capitale del defunto al decesso lo conta già. Con la catena (decisione 530) anche le righe sulle rate del primo
     * defunto ({@see defuntiDelleRate()}): `di` dice di chi è la rata.
     *
     * Solo gli incassi che hanno scritto la coppia nel Wallet (il registro del passaggio li tiene, `versamenti_degli_eredi`, non stornati): la strada «Debito
     * ereditato da …» dell'erede pagante e «Per il debito degli eredi» sulla rata del defunto. «Resta a …» con «Versato da» un erede ha il legame (la riga di
     * «Versato da» della Coda 167) ma non la coppia: il versamento resta al defunto, come nel giornale e nel piano dopo, e l'erede deve ancora la sua parte
     * (lo scettico stretto della riscrittura, decisione 536).
     *
     * @return list<array{rata_id: int, immobile_id: int, erede: int, giorno: string, importo: int, di: int}>
     */
    public function versamentiDegliEredi(Subentro $s): array
    {
        if (! isset($this->versati[(int) $s->id])) {
            $this->caricaVersamenti(collect([$s]));
        }

        return $this->versati[(int) $s->id];
    }

    /**
     * {@see versamentiDegliEredi()} per le successioni date, con una lettura sola: le righe con il legame a un erede sulle rate dei defunti, poi divise per
     * successione (i defunti delle rate, unità del passaggio, eredi, dal decesso).
     *
     * @param Collection<int, Subentro> $successioni
     */
    private function caricaVersamenti(Collection $successioni): void
    {
        $mancano = $successioni->filter(fn (Subentro $s) => ! isset($this->versati[(int) $s->id]))->values();
        if ($mancano->isEmpty()) {
            return;
        }
        $eredi = $mancano->flatMap(fn (Subentro $s) => array_map(fn ($e) => (int) ($e['anagrafica_id'] ?? 0), (array) ($s->registro['eredi'] ?? [])))->filter()->unique()->values()->all();
        $defuntiDi = $mancano->mapWithKeys(fn (Subentro $s) => [(int) $s->id => $this->defuntiDelleRate($s)])->all();
        // Gli incassi con la coppia, dal registro di ogni passaggio.
        $conLaCoppia = $mancano->mapWithKeys(fn (Subentro $s) => [(int) $s->id => array_flip(array_map(fn ($v) => (int) ($v['scrittura_id'] ?? 0),
            array_filter((array) ($s->registro['versamenti_degli_eredi'] ?? []), fn ($v) => ($v['stornato_il'] ?? null) === null && ($v['coppie'] ?? []) !== [])))])->all();
        $scritture = array_values(array_unique(array_merge([], ...array_map('array_keys', array_values($conLaCoppia)))));
        $righe = DB::table('righe_scritture as rs')->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->whereNull('sc.deleted_at')->where('sc.stato', '!=', 'annullata')->whereIn('sc.condominio_id', $mancano->pluck('condominio_id')->unique()->all())->where('cc.ruolo', 'crediti_condomini')
            ->whereIn('rs.anagrafica_id', array_values(array_unique(array_merge([], ...array_values($defuntiDi)))) ?: [0])->whereNotNull('rs.rata_id')
            ->where('rs.riferimento_type', \App\Models\Anagrafica::class)->whereIn('rs.riferimento_id', $eredi ?: [0])->whereIn('sc.id', $scritture ?: [0])
            ->groupBy('sc.id', 'rs.anagrafica_id', 'rs.rata_id', 'rs.immobile_id', 'rs.riferimento_id', 'sc.data_competenza')
            ->select(['sc.id as scrittura_id', 'rs.anagrafica_id', 'rs.rata_id', 'rs.immobile_id', 'rs.riferimento_id', 'sc.data_competenza'])
            ->selectRaw("SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE -rs.importo END) as y")->get();
        foreach ($mancano as $s) {
            $suoi = array_flip(array_map(fn ($e) => (int) ($e['anagrafica_id'] ?? 0), (array) ($s->registro['eredi'] ?? [])));
            $di = array_flip($defuntiDi[(int) $s->id]);
            $unita = $this->unitaDelPassaggio($s);
            $decesso = $s->decorrenza->format('Y-m-d');
            $registrati = $conLaCoppia[(int) $s->id];
            $this->versati[(int) $s->id] = $righe->filter(fn ($v) => isset($registrati[(int) $v->scrittura_id]) && isset($di[(int) $v->anagrafica_id]) && isset($suoi[(int) $v->riferimento_id])
                    && in_array((int) $v->immobile_id, $unita, true) && substr((string) $v->data_competenza, 0, 10) >= $decesso && (int) $v->y !== 0)
                ->map(fn ($v) => ['rata_id' => (int) $v->rata_id, 'immobile_id' => (int) $v->immobile_id, 'erede' => (int) $v->riferimento_id,
                    'giorno' => substr((string) $v->data_competenza, 0, 10), 'importo' => (int) $v->y, 'di' => (int) $v->anagrafica_id])
                ->sortBy(fn ($v) => [$v['giorno'], $v['rata_id']])->values()->all();
        }
    }

    /**
     * Le persone di {@see perLaProposta()}, senza le unità.
     *
     * @param list<int> $persone
     * @return list<int>
     */
    public function conIlDefuntoEGliEredi(int $condominioId, array $persone): array
    {
        return $this->perLaProposta($condominioId, $persone)['persone'];
    }

    /**
     * Per gli eredi fra le persone date: le chiavi ereditate, i loro movimenti, i movimenti che portano una parte nella rata nuova, e (scelta 1) gli
     * interessi e le spese del defunto passati a loro che entrano nella base.
     *
     * @param list<int> $persone
     * @param array<int, array<string, array<string, mixed>>> $coperteDegliEredi gli accordi di rientro delle persone ({@see \App\Services\Gestionale\Debiti\AccordiRientro::senzaInteressi()})
     * @param \Closure(list<int>): array<int, array<string, mixed>> $giornale il giornale del defunto ({@see PropostaInteressi::giornaleDelDefunto()})
     * @return array<int, array{chiavi: array<string, array<string, mixed>>, movimenti: list<array<string, mixed>>, aggiusta: list<array<string, mixed>>, accessori: array<string, array<string, mixed>>}>
     */
    public function perEredi(int $condominioId, string $alla, array $persone, Collection $regole, array $coperteDegliEredi, \Closure $giornale): array
    {
        $successioni = $this->successioni($condominioId)->filter(fn (Subentro $s) => $s->decorrenza !== null && $s->decorrenza->format('Y-m-d') <= $alla)->values();
        if ($successioni->isEmpty() || $persone === []) {
            return [];
        }
        // Il giornale di un defunto si legge una volta sola anche quando serve più volte (più successioni, la catena): P1 della lente P.
        $letti = [];
        $giornale = function (array $defunti) use ($giornale, &$letti): array {
            $mancano = array_values(array_diff(array_map('intval', $defunti), array_keys($letti)));
            if ($mancano !== []) {
                foreach ($mancano as $id) {
                    $letti[$id] = null;
                }
                foreach ($giornale($mancano) as $id => $g) {
                    $letti[(int) $id] = $g;
                }
            }

            return array_filter(array_intersect_key($letti, array_flip(array_map('intval', $defunti))), fn ($g) => $g !== null);
        };
        $righe = Saldo::whereIn('id', $successioni->flatMap(fn (Subentro $s) => $s->saldiDellArretrato())->all() ?: [0])->whereIn('anagrafica_id', $persone)->get();
        if ($righe->isEmpty()) {
            return [];
        }
        $perSuccessione = $righe->groupBy('subentro_id');
        // Decisione 529: le righe del Wallet legate al passaggio (la coppia del versamento dell'erede): il piano le assorbe con l'arretrato, e le parti delle
        // rate nuove le contano. Il tetto della base resta sulle righe dell'arretrato: i giorni prima del versamento restano.
        $legate = Saldo::whereIn('passaggio_id', $successioni->pluck('id')->all() ?: [0])->whereIn('anagrafica_id', $persone)->get()->groupBy('passaggio_id');
        $delCondominio = RegoleDegliEredi::delCondominio($condominioId);
        $nomi = DB::table('anagrafiche')->whereIn('id', $successioni->pluck('anagrafica_uscente_id')->all())->pluck('nome', 'id');
        $esito = [];
        foreach ($successioni as $s) {
            $sue = $perSuccessione[(int) $s->id] ?? null;
            if ($sue === null) {
                continue;
            }
            $scelte = RegoleDegliEredi::perSuccessione($s, $delCondominio);
            $modo = $s->interessi_del_defunto ?? Subentro::INTERESSI_AL_DECESSO;
            if ($modo === Subentro::INTERESSI_CONTINUANO && ! $scelte['buco_continuano']) {
                continue;
            }
            $defunto = (int) $s->anagrafica_uscente_id;
            $decesso = $s->decorrenza->format('Y-m-d');
            $base = $this->baseDelDefunto($s, $scelte, $alla, $giornale);
            // Decisione 530: il defunto era a sua volta erede di una successione di prima, sulle stesse unità: anche il debito che aveva ereditato passa.
            $catena = [];
            foreach ($this->baseEreditataDelDefunto($s, $condominioId, $regole, $giornale, $alla) as $k => $b) {
                $base[$k] = $b;
                $catena[$k] = $b['da_prima'];
            }
            $pesi = [];
            foreach ((array) ($s->registro['eredi'] ?? []) as $e) {
                $pesi[(int) $e['anagrafica_id']] = (float) $e['quota'];
            }
            $sommaPesi = array_sum($pesi);
            $inizioBase = $scelte['dal_decesso'] ? $decesso : self::dopo($decesso);
            $versati = collect($this->versamentiDegliEredi($s))->groupBy('erede');
            foreach ($sue->groupBy('anagrafica_id') as $erede => $righeErede) {
                $erede = (int) $erede;
                if (! isset($pesi[$erede])) {
                    continue;
                }
                $meta = ['subentro_id' => (int) $s->id, 'da_anagrafica_id' => $defunto, 'da' => (string) ($nomi[$defunto] ?? ($s->registro['nomi']['uscente'] ?? '')),
                    'percentuale' => self::percentuale($sommaPesi > 0 ? $pesi[$erede] / $sommaPesi * 100 : 0), 'decesso' => $decesso, 'modo' => $modo,
                    'inizio_base' => $inizioBase];
                $conLeCoppie = $righeErede->concat(($legate[(int) $s->id] ?? collect())->where('anagrafica_id', $erede))->values();
                $this->perErede($esito, $erede, $s, $scelte, $base, $pesi, $righeErede, $regole, $coperteDegliEredi[$erede] ?? [], $alla, $meta,
                    $conLeCoppie, ($versati[$erede] ?? collect())->all(), $catena);
            }
            // Scelta 1: gli interessi e le spese del defunto passati agli eredi con la successione, nella base dell'erede dal decesso.
            if ($scelte['interessi_sugli_interessi'] && $modo === Subentro::INTERESSI_AL_DECESSO) {
                foreach ((array) ($s->registro['arretrato']['accessori'] ?? []) as $a) {
                    foreach (\App\Models\Gestionale\RataQuote::whereIn('id', array_map('intval', (array) ($a['nuove'] ?? [])) ?: [0])->whereIn('anagrafica_id', $persone)->get() as $q) {
                        $k = (int) $q->anagrafica_id . '|' . (int) $q->rata_id . '|' . (int) $q->immobile_id;
                        $esito[(int) $q->anagrafica_id] ??= ['chiavi' => [], 'movimenti' => [], 'aggiusta' => [], 'accessori' => []];
                        $esito[(int) $q->anagrafica_id]['accessori'][$k] = ['inizio' => $inizioBase, 'ereditata' => ['subentro_id' => (int) $s->id, 'da_anagrafica_id' => $defunto,
                            'da' => (string) ($nomi[$defunto] ?? ''), 'percentuale' => self::percentuale($sommaPesi > 0 ? ($pesi[(int) $q->anagrafica_id] ?? 0) / $sommaPesi * 100 : 0),
                            'decesso' => $decesso, 'modo' => $modo, 'inizio_base' => $inizioBase, 'accessorio' => true, 'parte' => (int) $q->importo]];
                    }
                }
            }
        }

        return $esito;
    }

    /**
     * La base del defunto: per ogni sua rata sulle unità del passaggio, il capitale alla fine del giorno prima del decesso, con la copertura dei suoi crediti;
     * con la scelta 6 anche la parte che un accordo di rientro in regola teneva fuori; con la scelta 1 gli addebiti a lui solo passati con le righe di saldo.
     *
     * Per le pagine dei debiti (`$perLePagine`, decisione 536) anche le rate i cui interessi alla data non corrono ancora (una rata emessa prima del decesso
     * che scade dopo) e gli addebiti a lui solo passati con le righe di saldo anche senza la scelta 1: sono debito ereditato, e le pagine lo mostrano agli
     * eredi; la proposta conta gli interessi solo su quelle che li hanno. Le parti delle altre rate non cambiano: il tetto toglie dalle più recenti.
     *
     * @return array<string, array<string, mixed>> chiave del defunto => capitale, gestione, unità, rata, ordine, primo giorno, già registrato, di chi è la rata
     */
    private function baseDelDefunto(Subentro $s, array $scelte, string $alla, \Closure $giornale, bool $perLePagine = false): array
    {
        $defunto = (int) $s->anagrafica_uscente_id;
        $decesso = $s->decorrenza->format('Y-m-d');
        $unita = $this->unitaDelPassaggio($s);
        $g = $giornale([$defunto])[$defunto] ?? null;
        if ($g === null || $g['chiavi'] === []) {
            return [];
        }
        $chiavi = $g['chiavi'];
        foreach ($g['accordi'] as $chiaveAccordo => $fuori) {
            [$rata, $unitaCoperta] = explode('|', $chiaveAccordo);
            $k = $defunto . '|' . (int) $rata . '|' . (int) $unitaCoperta;
            if (! isset($chiavi[$k]) || ! $chiavi[$k]['base']) {
                continue;
            }
            if (($fuori['protetto'] ?? null) !== null) {
                $chiavi[$k]['protetto'] = $fuori['protetto'];
            } else {
                $chiavi[$k]['fine'] = CarbonImmutable::parse($fuori['dal'], 'UTC')->subDay()->format('Y-m-d');
            }
        }
        $letto = InteressiDiMora::capitaliAl(array_map(fn ($c) => array_intersect_key($c, array_flip(['inizio', 'base', 'ordine', 'fine', 'protetto', 'inizio_pieno'])), $chiavi), $g['movimenti'], $decesso,
            $perLePagine ? null : $alla);
        $degliAddebiti = array_flip(array_map('intval', (array) ($s->registro['arretrato']['addebiti']['rate'] ?? [])));
        $base = [];
        foreach ($chiavi as $k => $c) {
            if (! in_array((int) ($c['immobile_id'] ?? 0), $unita, true) || ! isset($c['rata'])) {
                continue;
            }
            $capitale = 0;
            if ($c['base']) {
                $capitale = (int) ($letto['capitali'][$k] ?? 0);
                if ($scelte['anche_accordo']) {
                    // Scelta 6: la parte che l'accordo di rientro del defunto, in regola al decesso, teneva fuori.
                    if (($c['fine'] ?? null) !== null && $c['fine'] < $decesso && ! isset($letto['capitali'][$k])) {
                        $capitale += max(0, (int) $letto['saldi'][$k]);
                    } elseif (isset($c['protetto'])) {
                        $capitale += (int) ($letto['protetti'][$k] ?? 0);
                    }
                }
            } elseif ($c['tipo'] === 'accessorio' && ($scelte['interessi_sugli_interessi'] || $perLePagine) && isset($degliAddebiti[(int) $c['rata']->id])) {
                // Scelta 1: un addebito a lui solo (una spesa con la fattura dello studio) passato agli eredi con le righe di saldo.
                $capitale = max(0, (int) $letto['saldi'][$k]);
            }
            if ($capitale <= 0) {
                continue;
            }
            $base[$k] = ['capitale' => $capitale, 'gestione_id' => (int) $c['rata']->gestione_id, 'immobile_id' => (int) $c['immobile_id'], 'rata' => $c['rata'],
                'scadenza' => $c['scadenza'], 'ordine' => $c['ordine'], 'inizio_pieno' => $c['inizio_pieno'] ?? null, 'gia' => $g['gia'][$k] ?? null, 'di' => $defunto];
        }

        return $base;
    }

    /**
     * Decisione 530, le successioni a catena: il defunto di questa successione era erede di una successione di prima (Marco, erede di Ettore, muore e lascia a
     * Luca). Le sue chiavi ereditate sulle unità del passaggio, alla vigilia del suo decesso ({@see perEredi()} al giorno prima: la successione di adesso
     * resta fuori da sé, e la ricorsione finisce), poi il capitale al decesso ({@see InteressiDiMora::capitaliAl()}): quello che gli resta dopo i suoi
     * versamenti e dopo quello già uscito nelle sue rate nuove. Ogni chiave porta la rata del primo defunto, la gestione e l'unità; il resto lo fa
     * {@see perErede()} come in una successione normale, con le quote, il tetto e le scelte della seconda. Gli interessi già registrati a Marco su quella
     * chiave (`gia`) fanno partire la chiave di Luca dal giorno dopo: gli stessi giorni non si chiedono due volte. Vuota se il defunto non è erede di niente
     * (un controllo in memoria sulla lista delle successioni).
     *
     * @return array<string, array<string, mixed>>
     */
    private function baseEreditataDelDefunto(Subentro $s, int $condominioId, Collection $regole, \Closure $giornale, string $alla): array
    {
        $defunto = (int) $s->anagrafica_uscente_id;
        $decesso = $s->decorrenza->format('Y-m-d');
        $unita = $this->unitaDelPassaggio($s);
        $prima = $this->successioni($condominioId)->contains(fn (Subentro $x) => (int) $x->id !== (int) $s->id && $x->decorrenza !== null && $x->decorrenza->format('Y-m-d') < $decesso
            && collect($x->registro['eredi'] ?? [])->contains(fn ($e) => (int) ($e['anagrafica_id'] ?? 0) === $defunto)
            && array_intersect($this->unitaDelPassaggio($x), $unita) !== []);
        if (! $prima) {
            return [];
        }
        $vigilia = self::prima($decesso);
        $coperte = app(\App\Services\Gestionale\Debiti\AccordiRientro::class)->senzaInteressi($condominioId, [$defunto], $vigilia);
        $sue = $this->perEredi($condominioId, $vigilia, [$defunto], $regole, $coperte, $giornale)[$defunto] ?? null;
        if ($sue === null || $sue['chiavi'] === []) {
            return [];
        }
        $chiavi = array_filter($sue['chiavi'], fn ($c) => ($c['tipo'] ?? null) === 'ereditata' && in_array((int) $c['immobile_id'], $unita, true));
        if ($chiavi === []) {
            return [];
        }
        $movimenti = array_values(array_filter($sue['movimenti'], fn ($m) => isset($chiavi[$m['chiave']])));
        $letto = InteressiDiMora::capitaliAl(array_map(fn ($c) => array_intersect_key($c, array_flip(['inizio', 'base', 'ordine', 'fine', 'protetto', 'inizio_pieno'])), $chiavi), $movimenti, $decesso, $alla);
        $gia = $giornale([$defunto])[$defunto]['gia'] ?? [];
        $base = [];
        foreach ($chiavi as $k => $c) {
            $capitale = (int) ($letto['capitali'][$k] ?? 0);
            if ($capitale <= 0) {
                continue;
            }
            $base[$k] = ['capitale' => $capitale, 'gestione_id' => (int) $c['rata']->gestione_id, 'immobile_id' => (int) $c['immobile_id'], 'rata' => $c['rata'],
                'scadenza' => $c['scadenza'], 'ordine' => \App\Services\Gestionale\Incassi\OrdineImputazione::chiave($c['scadenza'], (int) $c['rata']->numero_rata, true, (int) $c['rata']->id),
                'inizio_pieno' => null, 'gia' => $gia[$k] ?? null, 'da_prima' => (string) ($c['ereditata']['da'] ?? ''),
                'da_prima_del' => (string) ($c['ereditata']['decesso'] ?? ''), 'di' => (int) ($c['ereditata']['di'] ?? ($c['ereditata']['da_anagrafica_id'] ?? 0))];
        }

        return $base;
    }

    /**
     * Decisione 536, «le chiavi ereditate al giorno»: le parti di un erede in una successione, gruppo per gruppo (gestione e unità), come le conta la
     * proposta. Per ogni rata del defunto della base la parte dell'erede per quota (un arrotondamento per rata), con il **tetto** sulle sue righe
     * dell'arretrato del gruppo (tolto dalle rate più recenti); i suoi versamenti su quella rata ({@see versamentiDegliEredi()}, al più la parte); il netto; e
     * le parti delle rate nuove che prendono il debito ereditato ({@see tranche()}). Una fonte sola per la proposta ({@see perErede()}) e per le pagine dei
     * debiti ({@see perLaLettura()}): le righe «ereditato» sono queste chiavi, non il residuo di oggi del defunto spostato sugli eredi.
     *
     * Q4 della quarta prova della beta.12: lo storno dopo il piano scrive la coppia rovescia (l'erede + y, libera, legata al passaggio). Quella parte è debito
     * ereditato non ancora in una rata: le rate nuove prendono il netto meno quella, e quella resta sulla chiave, con i suoi interessi, finché un piano dopo
     * non la prende.
     *
     * @param list<array{rata_id: int, immobile_id: int, erede: int, giorno: string, importo: int, di: int}> $versati
     * @return list<array{gruppo: string, gestione_id: int, immobile_id: int, chiavi: list<string>, parti: array<string, int>, versamenti: array<string, list<array<string, mixed>>>, netti: array<string, int>, totale: int, tranche: list<array<string, mixed>>}>
     */
    private function chiaviAlGiorno(int $erede, array $base, array $pesi, Collection $righeErede, ?Collection $conLeCoppie, array $versati, int $passaggioId,
        Collection $regole, array $scelte, array $coperte, string $alla): array
    {
        $parti = [];
        foreach ($base as $k => $b) {
            $parti[$k] = (int) (MoneyHelper::ripartisciPerQuote($b['capitale'], $pesi)[$erede] ?? 0);
        }
        $gruppi = [];
        foreach ($base as $k => $b) {
            $gruppi[$b['gestione_id'] . '|' . $b['immobile_id']][] = $k;
        }
        $righePerGruppo = $righeErede->groupBy(fn (Saldo $r) => (int) $r->gestione_id . '|' . (int) $r->immobile_id);
        $conLeCoppiePerGruppo = ($conLeCoppie ?? $righeErede)->groupBy(fn (Saldo $r) => (int) $r->gestione_id . '|' . (int) $r->immobile_id);
        // I versamenti dell'erede, per rata e unità del defunto della rata (con la catena il primo defunto), in ordine di giorno.
        $versamenti = [];
        foreach ($versati as $v) {
            $versamenti[(int) ($v['di'] ?? 0) . '|' . $v['rata_id'] . '|' . $v['immobile_id']][] = $v;
        }
        $esito = [];
        foreach ($gruppi as $gk => $chiaviDefunto) {
            usort($chiaviDefunto, fn ($a, $b) => [$base[$a]['ordine'], $a] <=> [$base[$b]['ordine'], $b]);
            // Il tetto: la somma delle parti non supera le righe dell'arretrato dell'erede (se il defunto aveva un credito l'arretrato è più piccolo), tolto
            // dalle rate più recenti.
            $righe = $righePerGruppo[$gk] ?? collect();
            $tetto = max(0, (int) $righe->sum('saldo_iniziale'));
            $troppo = (int) array_sum(array_intersect_key($parti, array_flip($chiaviDefunto))) - $tetto;
            foreach (array_reverse($chiaviDefunto) as $k) {
                if ($troppo <= 0) {
                    break;
                }
                $togli = min($parti[$k], $troppo);
                $parti[$k] -= $togli;
                $troppo -= $togli;
            }
            $chiaviDefunto = array_values(array_filter($chiaviDefunto, fn ($k) => $parti[$k] > 0));
            if ($chiaviDefunto === []) {
                continue;
            }
            // Quanto l'erede ha versato su ogni parte, al più la parte: tutto per le rate nuove (il piano l'ha letto dalla coppia), fino alla data per i movimenti.
            $versamentiDi = [];
            $netti = [];
            foreach ($chiaviDefunto as $k) {
                $versamentiDi[$k] = $versamenti[(int) ($base[$k]['di'] ?? 0) . '|' . (int) $base[$k]['rata']->id . '|' . (int) $base[$k]['immobile_id']] ?? [];
                $netti[$k] = $parti[$k] - min($parti[$k], max(0, (int) array_sum(array_column($versamentiDi[$k], 'importo'))));
            }
            $totale = (int) array_sum($netti);
            $immobileId = (int) explode('|', $gk)[1];
            $tornati = max(0, (int) ($conLeCoppiePerGruppo[$gk] ?? collect())->filter(fn (Saldo $r) => ! $r->is_applicato && (int) $r->passaggio_id === $passaggioId)->sum('saldo_iniziale'));
            $esito[] = ['gruppo' => $gk, 'gestione_id' => (int) explode('|', $gk)[0], 'immobile_id' => $immobileId, 'chiavi' => $chiaviDefunto,
                'parti' => array_intersect_key($parti, array_flip($chiaviDefunto)), 'versamenti' => $versamentiDi, 'netti' => $netti, 'totale' => $totale,
                'tranche' => $this->tranche($erede, $immobileId, $conLeCoppiePerGruppo[$gk] ?? collect(), max(0, $totale - $tornati), $regole, $scelte, $coperte, $alla)];
        }

        return $esito;
    }

    /**
     * Le chiavi ereditate di un erede in una successione, con i loro movimenti e quelli della rata nuova che le prende ({@see chiaviAlGiorno()}).
     *
     * Decisione 529: i versamenti dell'erede sul debito ereditato ({@see versamentiDegliEredi()}) sono un movimento in meno sulla sua chiave il giorno del
     * versamento (il motore lo conta dal giorno dopo); la parte che le rate nuove prendono è al netto dei versamenti, e le rate nuove si leggono con le righe
     * del Wallet legate al passaggio (la coppia del versamento, `$conLeCoppie`): senza, l'uscita nella rata nuova supererebbe la riga, e la chiave andrebbe
     * sotto zero (un credito finto). Decisione 530: le chiavi della catena (`$catena`, chiave del defunto => da chi lui l'aveva ereditata) dicono anche il primo
     * defunto.
     *
     * @param array<int, array<string, mixed>> $esito
     * @param list<array{rata_id: int, immobile_id: int, erede: int, giorno: string, importo: int, di: int}> $versati
     * @param array<string, string> $catena
     */
    private function perErede(array &$esito, int $erede, Subentro $s, array $scelte, array $base, array $pesi, Collection $righeErede, Collection $regole,
        array $coperte, string $alla, array $meta, ?Collection $conLeCoppie = null, array $versati = [], array $catena = []): void
    {
        foreach ($this->chiaviAlGiorno($erede, $base, $pesi, $righeErede, $conLeCoppie, $versati, (int) $s->id, $regole, $scelte, $coperte, $alla) as $gruppo) {
            ['chiavi' => $chiaviDefunto, 'parti' => $parti, 'netti' => $netti, 'tranche' => $tranche, 'immobile_id' => $immobileId] = $gruppo;

            // Le parti della riga ereditata, rata del defunto per rata: entrano (al decesso, o con «continuano» all'emissione della rata nuova) ed escono
            // (quando la rata nuova comincia a contare), prima le più vecchie, per quello che i versamenti non hanno già chiuso.
            $entrate = [];
            $uscite = [];
            if ($meta['modo'] === Subentro::INTERESSI_AL_DECESSO) {
                foreach ($chiaviDefunto as $k) {
                    $entrate[$k][] = [self::prima($meta['inizio_base']), $parti[$k]];
                }
                $restano = $netti;
                foreach ($tranche as $t) {
                    if ($t['esce'] > 0) {
                        foreach (self::fifo($chiaviDefunto, $restano, $t['esce']) as $k => $y) {
                            $uscite[$k][] = [$t['ultimo'], $y, $t];
                        }
                    }
                }
            } else {
                // Con «continuano a suo nome» il defunto conta fino all'emissione della rata nuova che porta la sua parte a credito: l'erede conta quella parte
                // dall'emissione alla fine della sua tolleranza (scelta 7). Con la scelta 4 spenta, solo la prima rata.
                $restano = $netti;
                foreach ($tranche as $i => $t) {
                    if ($t['emessa_il'] === null || (! $scelte['rata_per_rata'] && $i > 0)) {
                        continue;
                    }
                    foreach (self::fifo($chiaviDefunto, $restano, $t['parte']) as $k => $y) {
                        $entrate[$k][] = [$t['emessa_il'], $y];
                        $uscite[$k][] = [$t['ultimo'], $y, $t];
                    }
                }
            }

            $esito[$erede] ??= ['chiavi' => [], 'movimenti' => [], 'aggiusta' => [], 'accessori' => []];
            foreach ($chiaviDefunto as $k) {
                if (! isset($entrate[$k])) {
                    continue;
                }
                $b = $base[$k];
                $chiave = $erede . '|' . (int) $b['rata']->id . '|' . $b['immobile_id'] . '|s' . (int) $s->id;
                $inizio = max($meta['inizio_base'], (string) ($b['inizio_pieno'] ?? $meta['inizio_base']));
                if ($b['gia'] !== null && $b['gia'] >= $inizio) {
                    $inizio = self::dopo($b['gia']);
                }
                if ($meta['modo'] === Subentro::INTERESSI_CONTINUANO) {
                    $inizio = max($inizio, self::dopo(min(array_column($entrate[$k], 0))));
                }
                foreach ($entrate[$k] as [$giorno, $y]) {
                    if ($giorno <= $alla) {
                        $esito[$erede]['movimenti'][] = ['giorno' => $giorno, 'chiave' => $chiave, 'importo' => $y, 'causa' => 'emissione'];
                    }
                }
                // Decisione 529: il versamento dell'erede chiude la sua parte dal giorno dopo (con «si fermano al decesso»: con «continuano» la parte entra
                // già al netto). Mai più della parte: un movimento in più farebbe della chiave un credito che copre altre rate.
                $versatiQui = [];
                if ($meta['modo'] === Subentro::INTERESSI_AL_DECESSO) {
                    $ancora = $parti[$k];
                    foreach ($gruppo['versamenti'][$k] ?? [] as $v) {
                        $y = min($ancora, (int) $v['importo']);
                        if ($y <= 0 || $v['giorno'] > $alla) {
                            continue;
                        }
                        $ancora -= $y;
                        $versatiQui[] = [$v['giorno'], $y];
                        $esito[$erede]['movimenti'][] = ['giorno' => $v['giorno'], 'chiave' => $chiave, 'importo' => -$y, 'causa' => 'versamento'];
                    }
                }
                $usciteFatte = array_values(array_filter($uscite[$k] ?? [], fn ($u) => $u[0] <= $alla));
                foreach ($usciteFatte as [$giorno, $y]) {
                    $esito[$erede]['movimenti'][] = ['giorno' => $giorno, 'chiave' => $chiave, 'importo' => -$y, 'causa' => 'storno'];
                }
                $uscito = (int) array_sum(array_column($usciteFatte, 1)) + (int) array_sum(array_column($versatiQui, 1));
                $tutta = $uscito >= (int) array_sum(array_column($entrate[$k], 1)) && $usciteFatte !== [];
                $ultima = $usciteFatte === [] ? null : $usciteFatte[count($usciteFatte) - 1];
                $esito[$erede]['chiavi'][$chiave] = [
                    'inizio' => $inizio, 'inizio_pieno' => $inizio, 'base' => true,
                    // Scelta 5: un versamento dell'erede copre prima il debito ereditato (l'ordine della rata del defunto, prima di una rata dell'erede con la
                    // stessa scadenza); spenta, per ultimo.
                    'ordine' => $scelte['versamento_prima'] ? [$b['ordine'][0], $b['ordine'][1], -1, $b['ordine'][3]] : [9, '9999-12-30', 9, (int) $b['rata']->id],
                    'tipo' => 'ereditata', 'scadenza' => $b['scadenza'], 'rata' => $b['rata'], 'immobile_id' => $b['immobile_id'], 'regola_inizio' => null,
                    'ereditata' => $meta + [
                        'parte' => (int) array_sum(array_column($entrate[$k], 1)),
                        'entrate' => array_values(array_unique(array_map(fn ($e) => self::dopo($e[0]), $entrate[$k]))),
                        'nel_piano' => array_values(array_unique(array_map(fn ($u) => self::dopo($u[0]), $usciteFatte))),
                        'in_parte' => $usciteFatte !== [] && ! $tutta,
                        'uscita' => $tutta ? ['al' => $ultima[0], 'rata' => $ultima[2]['nome'], 'piano' => $ultima[2]['piano']] : null,
                        'versati' => $versatiQui,
                        // Di chi è la rata a giornale (con la catena il primo defunto): la catena della catena la legge da qui.
                        'di' => (int) ($b['di'] ?? $s->anagrafica_uscente_id),
                    ] + (isset($catena[$k]) && $catena[$k] !== '' ? ['catena' => $catena[$k]] : []),
                ] + ($tutta ? ['fine' => $ultima[0]] : []);
            }
            // La rata nuova: il giorno dell'emissione la parte ereditata in meno, ripresa il giorno in cui esce dalla riga ereditata.
            foreach ($tranche as $t) {
                if ($t['nella_rata'] > 0 && $t['emessa_il'] !== null && $t['emessa_il'] <= $alla) {
                    $chiaveRata = $erede . '|' . $t['rata_id'] . '|' . $immobileId;
                    $esito[$erede]['aggiusta'][] = ['giorno' => $t['emessa_il'], 'chiave' => $chiaveRata, 'importo' => -$t['nella_rata'], 'causa' => 'emissione'];
                    if ($t['ultimo'] <= $alla) {
                        $esito[$erede]['aggiusta'][] = ['giorno' => $t['ultimo'], 'chiave' => $chiaveRata, 'importo' => $t['nella_rata'], 'causa' => 'emissione'];
                    }
                }
            }
        }
    }

    /**
     * Le rate dei piani che hanno assorbito le righe dell'arretrato dell'erede, sulla sua unità, nell'ordine: per ognuna la parte dell'arretrato che porta
     * (come il piano divide i saldi: tutto nella prima rata, nella rata 0, o una fettina per rata, {@see \App\Actions\PianoRate\GenerateRateQuotesAction::fettina()}),
     * e se è emessa il giorno dell'emissione e l'ultimo giorno della parte ereditata. Non la `saldo_usato` della quota: lì l'arretrato è sommato agli altri
     * saldi della persona nello stesso piano (il conguaglio dell'erede di riferimento, per esempio), e una quota a credito porta lo stesso l'arretrato.
     * La riga ereditata va alle rate più vecchie prima, al più la loro parte (`esce`, e `nella_rata` per la rata nuova).
     *
     * @return list<array{rata_id: int, nome: string, piano: string, scadenza: string, emessa_il: ?string, ultimo: string, esce: int, nella_rata: int, parte: int, bozza: bool}>
     */
    private function tranche(int $erede, int $immobileId, Collection $righe, int $totale, Collection $regole, array $scelte, array $coperte, string $alla): array
    {
        $perPiano = $righe->where('is_applicato', true)->filter(fn (Saldo $r) => $r->piano_rate_id !== null)->groupBy('piano_rate_id')
            ->map(fn ($g) => max(0, (int) $g->sum('saldo_iniziale')))->sortKeys();
        if ($perPiano->isEmpty()) {
            return [];
        }
        $lista = [];
        foreach ($perPiano as $pianoId => $arretrato) {
            ['piano' => $piano, 'rate' => $rate, 'emissioni' => $perQuota] = $this->pianoDelleTranche((int) $pianoId);
            if ($piano === null || $arretrato <= 0) {
                continue;
            }
            $ordinarie = $rate->where('numero_rata', '>', 0)->count();
            // Il giorno dell'emissione di ogni rata: quello della quota dell'erede, o della rata (una quota a zero non si scrive).
            $emissioni = [];
            foreach ($rate as $r) {
                $diRata = $perQuota[(int) $r->id] ?? [];
                $emissioni[(int) $r->id] = $diRata === [] ? null : (object) ['il' => min(array_values($diRata)), 'suo' => $diRata[$erede . '|' . $immobileId] ?? null];
            }
            foreach ($rate as $r) {
                $parte = match ($piano->metodo_distribuzione) {
                    'prima_rata' => (int) $r->numero_rata === 1 ? $arretrato : 0,
                    'tutte_rate' => (int) $r->numero_rata >= 1 ? \App\Actions\PianoRate\GenerateRateQuotesAction::fettina($arretrato, $ordinarie, (int) $r->numero_rata) : 0,
                    'rata_zero' => (int) $r->numero_rata === 0 ? $arretrato : 0,
                    default => 0,
                };
                if ($parte <= 0) {
                    continue;
                }
                $e = $emissioni[$r->id] ?? null;
                $emessa = $e === null ? null : substr((string) ($e->suo ?? $e->il), 0, 10);
                if ($emessa !== null && $emessa > $alla) {
                    $emessa = null;
                }
                $scadenza = substr((string) $r->data_scadenza, 0, 10);
                $ultimo = '9999-12-31';
                if ($emessa !== null) {
                    // Scelta 3: con gli «Interessi dopo la scadenza» della rata nuova il debito ereditato si ferma alla scadenza; di serie al giorno prima che la
                    // rata cominci a contare (quei giorni compresi: `giorni_decorrenza`, non la tolleranza del sollecito), mai prima del giorno dell'emissione.
                    $ultimo = $scelte['tolleranza_rata_nuova'] ? max($scadenza, $emessa) : self::prima(max(RegoleDelRecupero::primoGiorno($regole, $scadenza), self::dopo($emessa)));
                    // Un accordo di rientro dell'erede che copre la rata nuova: la parte ereditata esce il giorno prima (la regola della decisione 438).
                    $accordo = $coperte[\App\Services\Gestionale\Debiti\AccordiRientro::chiave($r->id, $immobileId)] ?? null;
                    if ($accordo !== null && ($accordo['protetto'] ?? null) === null) {
                        $ultimo = max($emessa, min($ultimo, self::prima((string) $accordo['dal'])));
                    }
                }
                $lista[] = ['rata_id' => (int) $r->id, 'capienza' => $parte, 'emessa_il' => $emessa, 'ultimo' => $ultimo, 'piano' => (string) $piano->nome, 'scadenza' => $scadenza,
                    'bozza' => $piano->stato === 'bozza',
                    'nome' => $piano->natura === PianoRate::NATURA_ADDEBITI ? trim((string) $r->descrizione) : ((int) $r->numero_rata === 0 ? 'Rata 0 (pregresso)' : 'Rata ' . (int) $r->numero_rata) . ' — ' . $piano->nome];
            }
        }
        if ($lista === []) {
            return [];
        }
        // E4 della seconda prova della beta.12: il debito ereditato si divide fra le rate nella proporzione in cui il piano divide l'arretrato, non a riempire
        // le prime. Le righe dell'arretrato portano anche altro (la parte delle bozze del defunto, per esempio): riempite per intero, le prime rate prendevano
        // più del debito ereditato che portano davvero, e la differenza diventava un credito finto sulla riga ereditata (€ 15,56 nella prova).
        $parti = MoneyHelper::ripartisciPerQuote($totale, array_map(fn ($t) => (float) $t['capienza'], $lista));
        foreach ($lista as $i => $t) {
            $lista[$i]['parte'] = (int) ($parti[$i] ?? 0);
            $lista[$i]['nella_rata'] = (int) ($parti[$i] ?? 0);
        }
        // Scelta 4: spenta, tutto il debito ereditato esce alla prima rata, e quella rata prende la sua parte; le altre rate portano la loro dall'emissione.
        foreach ($lista as $i => $t) {
            if (! $scelte['rata_per_rata']) {
                $lista[$i]['esce'] = $i === 0 && $t['emessa_il'] !== null ? $totale : 0;
                $lista[$i]['nella_rata'] = $i === 0 && $t['emessa_il'] !== null ? $t['nella_rata'] : 0;
            } else {
                $lista[$i]['esce'] = $t['emessa_il'] !== null ? $t['parte'] : 0;
                $lista[$i]['nella_rata'] = $t['emessa_il'] !== null ? $t['nella_rata'] : 0;
            }
        }

        return $lista;
    }

    /** @var array<int, array{piano: ?PianoRate, rate: Collection<int, object>, emissioni: array<int, array<string, string>>}> */
    private array $piani = [];

    /**
     * Un piano che ha assorbito l'arretrato di un erede, letto una volta per tutti gli eredi (P1 della lente P): il piano, le sue rate, e il giorno
     * dell'emissione di ogni quota (rata => persona|unità => giorno), dalle scritture di emissione non cancellate.
     *
     * @return array{piano: ?PianoRate, rate: Collection<int, object>, emissioni: array<int, array<string, string>>}
     */
    private function pianoDelleTranche(int $pianoId): array
    {
        if (isset($this->piani[$pianoId])) {
            return $this->piani[$pianoId];
        }
        $piano = PianoRate::find($pianoId);
        if ($piano === null) {
            return $this->piani[$pianoId] = ['piano' => null, 'rate' => collect(), 'emissioni' => []];
        }
        $rate = DB::table('rate')->where('piano_rate_id', $piano->id)->orderBy('numero_rata')->get(['id', 'numero_rata', 'data_scadenza', 'descrizione']);
        $emissioni = [];
        foreach (DB::table('rate_quote as q')->join('scritture_contabili as sc', 'sc.id', '=', 'q.scrittura_contabile_id')
            ->whereIn('q.rata_id', $rate->pluck('id')->all() ?: [0])->whereNull('sc.deleted_at')->where('sc.tipo_movimento', 'emissione_rata')
            ->groupBy('q.rata_id', 'q.anagrafica_id', 'q.immobile_id')->select(['q.rata_id', 'q.anagrafica_id', 'q.immobile_id'])->selectRaw('MIN(sc.data_competenza) as il')->get() as $e) {
            $emissioni[(int) $e->rata_id][(int) $e->anagrafica_id . '|' . (int) $e->immobile_id] = (string) $e->il;
        }

        return $this->piani[$pianoId] = ['piano' => $piano, 'rate' => $rate, 'emissioni' => $emissioni];
    }

    /**
     * Toglie una cifra dalle chiavi date, prima dalle più vecchie: chiave => quanto.
     *
     * @param list<string> $chiavi
     * @param array<string, int> $restano
     * @return array<string, int>
     */
    private static function fifo(array $chiavi, array &$restano, int $cifra): array
    {
        $esito = [];
        foreach ($chiavi as $k) {
            if ($cifra <= 0) {
                break;
            }
            $y = min($restano[$k] ?? 0, $cifra);
            if ($y > 0) {
                $esito[$k] = $y;
                $restano[$k] -= $y;
                $cifra -= $y;
            }
        }

        return $esito;
    }

    /** @var array<string, array<string, mixed>> */
    private array $letture = [];

    /**
     * Decisione 493 (12) e 494 (3): «Rate scadute» con gli eredi al posto del defunto. Decisione 536: **ogni persona dalle sue righe**, senza spostare il
     * residuo di oggi del defunto da una persona all'altra (Q1–Q4 della quarta prova della beta.12: ogni fatto del defunto che non toccava gli eredi — «Resta
     * a …», «Usa credito», lo storno dopo il piano, la rata nuova a debito — cambiava le loro righe).
     *
     * - **Gli eredi** dalle loro chiavi ereditate ({@see chiaviAlGiorno()}, la stessa fonte della proposta): per ogni rata del defunto della base al decesso la
     *   parte dell'erede, meno i suoi versamenti fino alla data, è una riga «ereditato» (`eredi`). Il capitale alla data è **lordo**: un credito dell'erede si
     *   mostra accanto, non si sottrae. Le parti che le rate nuove prendono restano righe «ereditato» finché corrono i loro interessi sulla chiave (decisione
     *   516, {@see ancoraEreditato()}): dopo l'emissione con «nella Rata 1 — … che scade il …», e in quei giorni la rata nuova dell'erede ha quella parte in
     *   meno (`nascosti` anche per l'erede), al più il suo residuo; dal giorno in cui la rata nuova conta la parte non c'è più. La coppia rovescia di uno
     *   storno dopo il piano resta «non ancora in una rata» (Q4). Con la catena (decisione 530) le chiavi di un erede morto a sua volta sono del suo erede,
     *   con la seconda successione ({@see baseEreditataDelDefunto()}): la riga dice «da Marco …, che l'aveva ereditato da Ettore Fabbri».
     * - **Il defunto** dal suo credito della successione non ancora usato ({@see sulDefunto()}): sulle sue rate vecchie delle unità del passaggio, dalla più
     *   vecchia, si nasconde al più quel credito (`nascosti`); dopo l'emissione del piano che assorbe, la parte presa dalla contropartita della rata nuova si
     *   sposta su quella rata, sulla somma della rata (`restituiti`), e il credito disponibile scende di quello che il credito della rata nuova perde (Q2).
     * - I saldi degli eredi non ancora in un piano approvato, che la riga a parte della pagina conta, scendono della parte scaduta mostrata come «ereditato»
     *   che sta in quei saldi (`saldi_mostrati`, `saldi_mostrati_per`): fuori da una rata, o in un piano in bozza.
     *
     * Sola lettura, sopra la lettura comune dei debiti: il giornale, la situazione patrimoniale, il rendiconto e le posizioni non cambiano. Le righe di
     * `saldi`, i nomi, le unità del passaggio, il giornale dei defunti, gli accordi degli eredi si leggono una volta per condominio (P1 della lente P); il
     * risultato resta nell'istanza per la data e la gestione.
     *
     * @return array{nascosti: array<int, array<string, int>>, eredi: array<int, list<array<string, mixed>>>, saldi_mostrati: int, saldi_mostrati_per: array<string, int>, restituiti: array<int, list<array{nuova: string, riga: array<string, mixed>, importo: int}>>}
     */
    public function perLaLettura(int $condominioId, string $alla, ?int $gestioneId = null): array
    {
        $chiave = $condominioId . '|' . $alla . '|' . ($gestioneId ?? '');
        if (isset($this->letture[$chiave])) {
            return $this->letture[$chiave];
        }
        $esito = ['nascosti' => [], 'eredi' => [], 'saldi_mostrati' => 0, 'saldi_mostrati_per' => [], 'restituiti' => []];
        $successioni = $this->successioni($condominioId)->filter(fn (Subentro $s) => $s->decorrenza !== null && $s->decorrenza->format('Y-m-d') <= $alla)->values();
        if ($successioni->isEmpty()) {
            return $this->letture[$chiave] = $esito;
        }
        $lettura = app(\App\Services\Gestionale\Debiti\LetturaDebiti::class);
        $regole = (new RegoleDelRecupero())->delCondominio($condominioId);
        $delCondominio = RegoleDegliEredi::delCondominio($condominioId);
        // Una lettura per condominio: le unità figlie di tutti i passaggi, le righe di `saldi` delle successioni (l'arretrato, il conguaglio e le righe legate),
        // i nomi.
        $figlie = Subentro::query()->whereIn('subentro_padre_id', $successioni->pluck('id')->all())->get(['subentro_padre_id', 'immobile_id'])->groupBy('subentro_padre_id');
        foreach ($successioni as $s) {
            $this->unita[(int) $s->id] ??= array_values(array_unique([(int) $s->immobile_id, ...($figlie[(int) $s->id] ?? collect())->pluck('immobile_id')->map(fn ($id) => (int) $id)->all()]));
        }
        $ids = $successioni->pluck('id')->map(fn ($id) => (int) $id)->all();
        $tutteLeRighe = Saldo::where(fn ($q) => $q->whereIn('id', $successioni->flatMap(fn (Subentro $s) => $s->saldiDellArretrato())->all() ?: [0])
            ->orWhereIn('passaggio_id', $ids)->orWhereIn('subentro_id', $ids))->get();
        $defunti = $successioni->pluck('anagrafica_uscente_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $nomi = DB::table('anagrafiche')->whereIn('id', $defunti)->pluck('nome', 'id');
        $lettureDi = function (array $persone) use ($lettura, $condominioId, $alla, $gestioneId): array {
            $esito = [];
            foreach ($lettura->perCondominioABlocchi($condominioId, $alla, 250, $gestioneId, $persone) as $blocco) {
                foreach ($blocco as $id => $l) {
                    $esito[(int) $id] = $l;
                }
            }

            return $esito;
        };
        $lettureDeiDefunti = $lettureDi($defunti);
        $this->caricaVersamenti($successioni);
        $statiDeiPiani = DB::table('piani_rate')->whereIn('id', $tutteLeRighe->pluck('piano_rate_id')->filter()->unique()->values()->all() ?: [0])->pluck('stato', 'id');
        $righeDi = function (Subentro $s) use ($tutteLeRighe): Collection {
            $arretrato = array_flip($s->saldiDellArretrato());

            return $tutteLeRighe->filter(fn (Saldo $r) => isset($arretrato[(int) $r->id]) || (int) $r->passaggio_id === (int) $s->id || (int) $r->subentro_id === (int) $s->id)->values();
        };

        // Il defunto: il suo credito della successione non ancora usato, sulle sue rate vecchie.
        foreach ($successioni as $s) {
            $this->sulDefunto($esito, $s, $righeDi($s), $lettureDeiDefunti[(int) $s->anagrafica_uscente_id] ?? null, $condominioId, $alla, $gestioneId);
        }

        // Gli eredi: le loro chiavi ereditate. Il giornale dei defunti si legge una volta per tutti, come la proposta ({@see PropostaInteressi::giornaleDelDefunto()}).
        $eredi = $successioni->flatMap(fn (Subentro $s) => array_map(fn ($e) => (int) ($e['anagrafica_id'] ?? 0), (array) ($s->registro['eredi'] ?? [])))->filter()->unique()->values()->all();
        $coperteDegliEredi = app(\App\Services\Gestionale\Debiti\AccordiRientro::class)->senzaInteressi($condominioId, $eredi, $alla);
        $proposta = app(PropostaInteressi::class);
        $letti = [];
        $giornale = function (array $persone) use ($proposta, $condominioId, $alla, $regole, &$letti): array {
            $mancano = array_values(array_diff(array_map('intval', $persone), array_keys($letti)));
            if ($mancano !== []) {
                foreach ($mancano as $id) {
                    $letti[$id] = null;
                }
                foreach ($proposta->giornaleDelDefunto($condominioId, $alla, $mancano, $regole) as $id => $g) {
                    $letti[(int) $id] = $g;
                }
            }

            return array_filter(array_intersect_key($letti, array_flip(array_map('intval', $persone))), fn ($g) => $g !== null);
        };
        $giornale($defunti);
        $lettureDegliEredi = null;
        $assorbiti = $tutteLeRighe->filter(fn (Saldo $r) => $r->is_applicato && $r->piano_rate_id !== null)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        // Il residuo delle rate nuove degli eredi ancora libero (una rata può portare il debito di più successioni): erede => rata|unità => centesimi.
        $liberoNellaRata = [];
        foreach ($successioni as $s) {
            $scelte = RegoleDegliEredi::perSuccessione($s, $delCondominio);
            $modo = $s->interessi_del_defunto ?? Subentro::INTERESSI_AL_DECESSO;
            $base = $this->baseDelDefunto($s, $scelte, $alla, $giornale, true);
            $catena = [];
            foreach ($this->baseEreditataDelDefunto($s, $condominioId, $regole, $giornale, $alla) as $k => $b) {
                $base[$k] = $b;
                $catena[$k] = ['da' => $b['da_prima'], 'successione_del' => $b['da_prima_del']];
            }
            if ($base === []) {
                continue;
            }
            $pesi = [];
            foreach ((array) ($s->registro['eredi'] ?? []) as $e) {
                $pesi[(int) $e['anagrafica_id']] = (float) $e['quota'];
            }
            $sueRighe = $righeDi($s);
            $arretrato = array_flip($s->saldiDellArretrato());
            $versati = collect($this->versamentiDegliEredi($s))->groupBy('erede');
            $nome = (string) ($nomi[(int) $s->anagrafica_uscente_id] ?? ($s->registro['nomi']['uscente'] ?? ''));
            foreach (array_keys($pesi) as $erede) {
                $righeErede = $sueRighe->filter(fn (Saldo $r) => isset($arretrato[(int) $r->id]) && (int) $r->anagrafica_id === $erede)->values();
                if ($righeErede->isEmpty()) {
                    continue;
                }
                $conLeCoppie = $sueRighe->filter(fn (Saldo $r) => (int) $r->anagrafica_id === $erede && (isset($arretrato[(int) $r->id]) || (int) $r->passaggio_id === (int) $s->id))->values();
                $gruppi = $this->chiaviAlGiorno($erede, $base, $pesi, $righeErede, $conLeCoppie, ($versati[$erede] ?? collect())->all(), (int) $s->id, $regole, $scelte,
                    $coperteDegliEredi[$erede] ?? [], $alla);
                foreach ($gruppi as $gruppo) {
                    $u = (int) $gruppo['immobile_id'];
                    // Decisione 530: un erede morto a sua volta, con l'arretrato ai suoi eredi, non ha più righe: le sue chiavi passano con la successione dopo.
                    if (($gestioneId !== null && (int) $gruppo['gestione_id'] !== $gestioneId) || $this->mortoDopo($erede, $s, $u, $successioni)) {
                        continue;
                    }
                    // Le righe: per ogni rata del defunto la parte dell'erede meno i suoi versamenti fino alla data.
                    $sueLinee = [];
                    foreach ($gruppo['chiavi'] as $k) {
                        $versato = (int) array_sum(array_column(array_filter($gruppo['versamenti'][$k] ?? [], fn ($v) => $v['giorno'] <= $alla), 'importo'));
                        $parte = $gruppo['parti'][$k] - min($gruppo['parti'][$k], max(0, $versato));
                        if ($parte > 0) {
                            $sueLinee[] = ['riga' => $this->rigaDellaRata($base[$k], $lettureDeiDefunti, $alla), 'parte' => $parte, 'chiave' => $k];
                        }
                    }
                    if ($sueLinee === []) {
                        continue;
                    }
                    $tranche = $gruppo['tranche'];
                    // Quanto di ogni parte resta nella riga «ereditato» alla data, e quanto la rata nuova ne cede (decisione 516).
                    $dentro = $this->ancoraEreditato($tranche, $scelte, $modo, $alla);
                    if (array_filter($tranche, fn ($t) => $t['emessa_il'] !== null) !== [] && ! isset($liberoNellaRata[$erede])) {
                        $liberoNellaRata[$erede] = [];
                        $lettureDegliEredi ??= $lettureDi($assorbiti);
                        foreach (($lettureDegliEredi[$erede] ?? $lettura->perPersona($condominioId, $erede, $alla, $gestioneId))['rate'] as $suaRiga) {
                            $kr = \App\Services\Gestionale\Debiti\AccordiRientro::chiave($suaRiga['rata_id'], $suaRiga['immobile_id']);
                            $liberoNellaRata[$erede][$kr] = ($liberoNellaRata[$erede][$kr] ?? 0) + max(0, (int) $suaRiga['residuo']);
                        }
                    }
                    $tenute = [];
                    foreach ($tranche as $i => $t) {
                        if (! $dentro[$i]) {
                            $tenute[$i] = 0;

                            continue;
                        }
                        if ($t['emessa_il'] === null) {
                            $tenute[$i] = $t['parte'];

                            continue;
                        }
                        $kRata = \App\Services\Gestionale\Debiti\AccordiRientro::chiave($t['rata_id'], $u);
                        $tenute[$i] = min($t['parte'], (int) ($liberoNellaRata[$erede][$kRata] ?? 0));
                        if ($tenute[$i] > 0) {
                            $liberoNellaRata[$erede][$kRata] -= $tenute[$i];
                            $esito['nascosti'][$erede][$kRata] = ($esito['nascosti'][$erede][$kRata] ?? 0) + $tenute[$i];
                        }
                    }
                    foreach (self::pezzi($sueLinee, $tranche, $tenute) as $j => $pezzi) {
                        $residuo = (int) array_sum(array_column($pezzi, 'parte'));
                        if ($residuo <= 0) {
                            continue;
                        }
                        $r = $sueLinee[$j]['riga'];
                        $k = $sueLinee[$j]['chiave'];
                        $ereditato = ['da' => $nome, 'da_anagrafica_id' => (int) $s->anagrafica_uscente_id, 'successione_del' => $s->decorrenza->format('Y-m-d'),
                            'subentro_id' => (int) $s->id, 'defunto_della_rata' => (int) ($base[$k]['di'] ?? $s->anagrafica_uscente_id), 'nelle_rate' => [], 'fuori_rata' => 0]
                            + (isset($catena[$k]) ? ['catena' => $catena[$k]] : []);
                        // La parte che sta nei saldi non ancora in un piano approvato (fuori da una rata, o in un piano in bozza): la riga a parte della pagina
                        // non la conta due volte.
                        $neiSaldi = 0;
                        foreach ($pezzi as $p) {
                            if ($p['tranche'] === null || $tranche[$p['tranche']]['emessa_il'] === null) {
                                $ereditato['fuori_rata'] += $p['parte'];
                                if ($p['tranche'] === null || ($tranche[$p['tranche']]['bozza'] ?? false)) {
                                    $neiSaldi += $p['parte'];
                                }
                            } else {
                                $t = $tranche[$p['tranche']];
                                $ereditato['nelle_rate'][] = ['rata' => $t['nome'], 'rata_id' => $t['rata_id'], 'scadenza' => $t['scadenza'], 'parte' => $p['parte']];
                            }
                        }
                        $ereditato['frase'] = self::fraseInElenco($ereditato, $alla);
                        $esito['eredi'][$erede][] = array_merge($r, ['dovuto' => $residuo, 'versato' => 0, 'residuo' => $residuo, 'credito' => 0, 'ereditato' => $ereditato]);
                        if ($neiSaldi > 0 && $r['scaduta']) {
                            $esito['saldi_mostrati'] += $neiSaldi;
                            $km = $erede . '|' . (int) $r['gestione_id'] . '|' . (int) $r['immobile_id'];
                            $esito['saldi_mostrati_per'][$km] = ($esito['saldi_mostrati_per'][$km] ?? 0) + $neiSaldi;
                        }
                    }
                }
            }
        }

        return $this->letture[$chiave] = $esito;
    }

    /**
     * Decisione 536, il defunto dalle sue righe. Il suo **credito della successione** in ogni gruppo (gestione e unità) è il rovescio delle sue righe di `saldi`
     * del passaggio (l'arretrato, il conguaglio, le coppie dei versamenti degli eredi), libere o già in un piano; meno quello che ha già compensato sulle rate
     * vecchie con il credito delle rate nuove («Usa credito», {@see compensatoDalPiano()}). Quel credito copre le sue rate vecchie del gruppo, dalla più
     * vecchia, al più il loro residuo: quella parte non è più sua (`nascosti`). Prima del piano il credito sta nel Wallet, che le pagine non mostrano; dopo
     * l'emissione del piano che assorbe sta nelle rate nuove (la contropartita, `saldo_usato` della quota): la parte nascosta presa da lì si sposta sulla rata
     * nuova, **sulla somma della rata** (`restituiti`): N1 della terza prova e Q1 della quarta (la rata nuova a debito), e il credito della rata nuova che
     * resta è quello vero (Q2). Quello che le rate vecchie non hanno (un «Resta a …») resta un credito del defunto nel Wallet o nella rata nuova.
     *
     * @param array<string, mixed> $esito
     * @param array<string, mixed>|null $suaLettura la lettura del defunto alla data
     */
    private function sulDefunto(array &$esito, Subentro $s, Collection $sueRighe, ?array $suaLettura, int $condominioId, string $alla, ?int $gestioneId): void
    {
        $defunto = (int) $s->anagrafica_uscente_id;
        $sue = $sueRighe->filter(fn (Saldo $r) => (int) $r->anagrafica_id === $defunto);
        if ($sue->isEmpty() || $suaLettura === null) {
            return;
        }
        $unita = $this->unitaDelPassaggio($s);
        $credito = $sue->groupBy(fn (Saldo $r) => (int) $r->gestione_id . '|' . (int) $r->immobile_id)->map(fn ($g) => max(0, -(int) $g->sum('saldo_iniziale')))
            ->filter(fn ($c, $gk) => $c > 0 && in_array((int) explode('|', $gk)[1], $unita, true) && ($gestioneId === null || (int) explode('|', $gk)[0] === $gestioneId));
        if ($credito->isEmpty()) {
            return;
        }
        // Le rate nuove dei piani che hanno assorbito le sue righe, già a giornale alla data, con la contropartita (la parte a credito dei saldi della quota):
        // prima dell'emissione la contropartita non c'è, e niente si sposta. La riga serve se la lettura non porta la rata nuova (a saldo zero).
        $piani = $sue->filter(fn (Saldo $r) => $r->is_applicato && $r->piano_rate_id !== null)->pluck('piano_rate_id')->unique()->values()->all();
        $nuove = [];
        $rateNuove = [];
        $compensato = [];
        if ($piani !== []) {
            $rateNuove = DB::table('rate')->whereIn('piano_rate_id', $piani)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $dopo = self::dopo($alla);
            $quoteNuove = DB::table('rate_quote as q')->join('rate as r', 'r.id', '=', 'q.rata_id')->join('piani_rate as p', 'p.id', '=', 'r.piano_rate_id')
                ->join('scritture_contabili as em', 'em.id', '=', 'q.scrittura_contabile_id')->whereNull('em.deleted_at')->where('em.data_competenza', '<', $dopo)
                ->whereIn('q.rata_id', $rateNuove ?: [0])->where('q.anagrafica_id', $defunto)->whereIn('q.immobile_id', $unita)
                ->orderBy('r.data_scadenza')->orderBy('r.numero_rata')->orderBy('q.rata_id')
                ->get(['q.rata_id', 'q.immobile_id', 'q.regole_calcolo', 'r.numero_rata', 'r.data_scadenza', 'r.piano_rate_id', 'p.gestione_id']);
            foreach ($quoteNuove as $q) {
                $regoleQuota = json_decode((string) $q->regole_calcolo, true) ?: [];
                $c = -(int) ($regoleQuota['importi']['saldo_usato'] ?? 0);
                if ($c <= 0) {
                    continue;
                }
                $gk = (int) $q->gestione_id . '|' . (int) $q->immobile_id;
                $k = \App\Services\Gestionale\Debiti\AccordiRientro::chiave($q->rata_id, $q->immobile_id);
                $scadenza = \App\Services\Gestionale\Debiti\Scadenza::giorno((string) $q->data_scadenza);
                $nuove[$gk][$k] = ['contropartita' => ($nuove[$gk][$k]['contropartita'] ?? 0) + $c, 'riga' => ['rata_id' => (int) $q->rata_id,
                    'immobile_id' => (int) $q->immobile_id, 'numero_rata' => (int) $q->numero_rata, 'piano_rate_id' => (int) $q->piano_rate_id,
                    'gestione_id' => (int) $q->gestione_id, 'scadenza' => $scadenza, 'dovuto' => 0, 'versato' => 0, 'residuo' => 0, 'credito' => 0,
                    'scaduta' => \App\Services\Gestionale\Debiti\Scadenza::scaduta($scadenza, $alla),
                    'giorni_ritardo' => \App\Services\Gestionale\Debiti\Scadenza::giorniDiRitardo($scadenza, $alla), 'pregresso' => null, 'pregresso_nella_rata' => false]];
            }
            $compensato = $nuove === [] ? [] : $this->compensatoDalPiano($condominioId, $defunto, $unita, $rateNuove, $alla);
        }
        $rateNuoveQui = array_flip($rateNuove);
        foreach ($credito as $gk => $c) {
            [$g, $u] = array_map('intval', explode('|', $gk));
            $comp = (int) ($compensato[$gk] ?? 0);
            $vecchie = collect($suaLettura['rate'])->filter(fn ($r) => (int) ($r['immobile_id'] ?? 0) === $u && (int) ($r['gestione_id'] ?? 0) === $g
                && ! isset($rateNuoveQui[(int) $r['rata_id']]) && (int) $r['residuo'] > 0)->sortBy(fn ($r) => [$r['scadenza'], $r['numero_rata'], $r['rata_id']])->values();
            $h = min(max(0, $c - $comp), (int) $vecchie->sum('residuo'));
            if ($h <= 0) {
                continue;
            }
            $resta = $h;
            foreach ($vecchie as $r) {
                $x = min($resta, (int) $r['residuo']);
                $k = \App\Services\Gestionale\Debiti\AccordiRientro::chiave($r['rata_id'], $r['immobile_id']);
                $esito['nascosti'][$defunto][$k] = ($esito['nascosti'][$defunto][$k] ?? 0) + $x;
                $resta -= $x;
                if ($resta <= 0) {
                    break;
                }
            }
            // Sulle rate nuove: la parte del credito che sta nella loro contropartita (meno quella già compensata), dalla prima.
            $nuoveQui = $nuove[$gk] ?? [];
            $daSpostare = min($h, max(0, min($c, (int) array_sum(array_column($nuoveQui, 'contropartita'))) - $comp));
            $giaUsato = $comp;
            foreach ($nuoveQui as $kNuova => $n) {
                if ($daSpostare <= 0) {
                    break;
                }
                $usato = min((int) $n['contropartita'], $giaUsato);
                $giaUsato -= $usato;
                $x = min($daSpostare, (int) $n['contropartita'] - $usato);
                if ($x > 0) {
                    $esito['restituiti'][$defunto][] = ['nuova' => $kNuova, 'riga' => $n['riga'], 'importo' => $x];
                    $daSpostare -= $x;
                }
            }
        }
    }

    /**
     * La riga di «Rate scadute» di una rata del defunto della base (con la catena, del primo defunto): la rata, l'unità, la scadenza alla data; il pregresso
     * dalla lettura del defunto, se la porta (una rata a saldo zero, chiusa da lui, non c'è più nella sua lettura: la parte degli eredi resta).
     *
     * @param array<string, mixed> $b
     * @param array<int, array<string, mixed>> $lettureDeiDefunti
     * @return array<string, mixed>
     */
    private function rigaDellaRata(array $b, array $lettureDeiDefunti, string $alla): array
    {
        $rata = $b['rata'];
        $u = (int) $b['immobile_id'];
        $sua = collect($lettureDeiDefunti[(int) ($b['di'] ?? 0)]['rate'] ?? [])->first(fn ($r) => (int) $r['rata_id'] === (int) $rata->id && (int) ($r['immobile_id'] ?? 0) === $u);
        $scadenza = (string) $b['scadenza'];

        return ['rata_id' => (int) $rata->id, 'immobile_id' => $u, 'numero_rata' => (int) $rata->numero_rata, 'piano_rate_id' => (int) $rata->piano_rate_id,
            'gestione_id' => (int) $b['gestione_id'], 'scadenza' => $scadenza, 'dovuto' => 0, 'versato' => 0, 'residuo' => 0, 'credito' => 0,
            'scaduta' => \App\Services\Gestionale\Debiti\Scadenza::scaduta($scadenza, $alla),
            'giorni_ritardo' => $sua !== null ? $sua['giorni_ritardo'] : \App\Services\Gestionale\Debiti\Scadenza::giorniDiRitardo($scadenza, $alla),
            'pregresso' => $sua['pregresso'] ?? null, 'pregresso_nella_rata' => (bool) ($sua['pregresso_nella_rata'] ?? false)];
    }

    /** Decisione 530: l'erede è il defunto di una successione dopo, sulla stessa unità, con l'arretrato agli eredi, entro la data. */
    private function mortoDopo(int $erede, Subentro $s, int $unita, Collection $successioni): bool
    {
        return $successioni->contains(fn (Subentro $x) => (int) $x->anagrafica_uscente_id === $erede && $x->decorrenza->format('Y-m-d') > $s->decorrenza->format('Y-m-d')
            && in_array($unita, $this->unitaDelPassaggio($x), true));
    }

    /**
     * N1, l'altra faccia: quello che il defunto ha compensato sulle sue rate vecchie con il credito delle rate nuove del piano che ha assorbito il suo
     * arretrato («Usa credito»): le righe AVERE delle compensazioni non annullate sulle rate vecchie, nelle scritture che prendono il credito (DARE) da una rata
     * nuova. Per gestione (del piano della rata vecchia) e unità => centesimi.
     *
     * @param list<int> $unita
     * @param list<int> $rateNuove
     * @return array<string, int>
     */
    private function compensatoDalPiano(int $condominioId, int $defunto, array $unita, array $rateNuove, string $alla): array
    {
        return DB::table('righe_scritture as av')->join('scritture_contabili as sc', 'sc.id', '=', 'av.scrittura_id')
            ->join('rate as rv', 'rv.id', '=', 'av.rata_id')->join('piani_rate as pv', 'pv.id', '=', 'rv.piano_rate_id')
            ->whereNull('sc.deleted_at')->where('sc.stato', '!=', 'annullata')->where('sc.condominio_id', $condominioId)->where('sc.tipo_movimento', 'storno_credito')
            ->where('sc.data_competenza', '<', self::dopo($alla))
            ->where('av.anagrafica_id', $defunto)->where('av.tipo_riga', 'avere')->whereIn('av.immobile_id', $unita)->whereNotIn('av.rata_id', $rateNuove ?: [0])
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('righe_scritture as da')->whereColumn('da.scrittura_id', 'sc.id')->where('da.tipo_riga', 'dare')
                ->where('da.anagrafica_id', $defunto)->whereIn('da.rata_id', $rateNuove ?: [0]))
            ->groupBy('pv.gestione_id', 'av.immobile_id')->select(['pv.gestione_id', 'av.immobile_id'])->selectRaw('SUM(av.importo) as y')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->gestione_id . '|' . (int) $r->immobile_id => (int) $r->y])->all();
    }

    /**
     * Decisione 516: per ogni parte del debito ereditato ({@see tranche()}), se alla data i suoi interessi corrono ancora nella riga ereditata della proposta.
     * Prima dell'emissione della rata che la porta sì; dopo, fino al giorno prima che quella rata cominci a contare (`ultimo`). Con «su più rate, scende rata
     * per rata» spento, tutto il debito esce alla prima rata; con «continuano a suo nome» i giorni fra emissione e scadenza li contano gli eredi solo con la
     * scelta 7, altrimenti nessuno, e la parte esce dall'emissione.
     *
     * @param list<array<string, mixed>> $tranche
     * @return array<int, bool>
     */
    private function ancoraEreditato(array $tranche, array $scelte, string $modo, string $alla): array
    {
        $esito = [];
        foreach ($tranche as $i => $t) {
            if ($modo === Subentro::INTERESSI_AL_DECESSO) {
                $fine = $scelte['rata_per_rata'] ? $t['ultimo'] : ($tranche[0]['emessa_il'] !== null ? $tranche[0]['ultimo'] : '9999-12-31');
            } elseif ($t['emessa_il'] === null) {
                $fine = '9999-12-31';
            } else {
                $fine = $scelte['buco_continuano'] && ($scelte['rata_per_rata'] || $i === 0) ? $t['ultimo'] : self::prima($t['emessa_il']);
            }
            $esito[$i] = $alla <= $fine;
        }

        return $esito;
    }

    /**
     * Le righe ereditate di un erede divise nelle parti delle rate nuove, dalle rate del defunto più vecchie (come le toglie la proposta), e di ogni parte la
     * cifra che resta nella riga: di una parte che resta solo in parte, resta quella delle rate del defunto più recenti. Senza un piano che assorbe, un pezzo
     * solo, fuori da una rata (`tranche` null).
     *
     * @param list<array{riga: array<string, mixed>, parte: int}> $linee
     * @param list<array<string, mixed>> $tranche
     * @param array<int, int> $tenute parte => quanto ne resta nella riga
     * @return array<int, list<array{tranche: ?int, parte: int}>> riga => i suoi pezzi
     */
    private static function pezzi(array $linee, array $tranche, array $tenute): array
    {
        $pezzi = array_fill_keys(array_keys($linee), []);
        if ($tranche === []) {
            foreach ($linee as $j => $l) {
                $pezzi[$j][] = ['tranche' => null, 'parte' => (int) $l['parte']];
            }

            return $pezzi;
        }
        $restano = array_map(fn ($l) => (int) $l['parte'], $linee);
        foreach ($tranche as $i => $t) {
            $daDare = (int) $t['parte'];
            $daTogliere = $daDare - (int) ($tenute[$i] ?? 0);
            foreach (array_keys($linee) as $j) {
                if ($daDare <= 0) {
                    break;
                }
                $y = min($restano[$j], $daDare);
                if ($y <= 0) {
                    continue;
                }
                $restano[$j] -= $y;
                $daDare -= $y;
                $via = min($y, max(0, $daTogliere));
                $daTogliere -= $via;
                if ($y - $via > 0) {
                    $pezzi[$j][] = ['tranche' => $i, 'parte' => $y - $via];
                }
            }
        }
        // Quello che nessuna parte prende (non dovrebbe esserci: le parti sommano il totale) resta fuori da una rata.
        foreach ($restano as $j => $r) {
            if ($r > 0) {
                $pezzi[$j][] = ['tranche' => null, 'parte' => $r];
            }
        }

        return $pezzi;
    }

    /**
     * Decisioni 494 (3) e 516: la riga «ereditato» di «Rate scadute» e delle lettere. «da Ettore Fabbri, successione del 17/11/2026, non ancora in una
     * rata»; con la rata nuova emessa «…, nella Rata 1 — Preventivo 2027 che scade il 31/03/2027» (dopo la scadenza «scaduta il …»); in più parti, ognuna
     * con la sua cifra.
     *
     * @param array{da: string, successione_del: string, nelle_rate: list<array{rata: string, scadenza: string, parte: int}>, fuori_rata: int} $e
     */
    public static function fraseInElenco(array $e, string $alla): string
    {
        $testa = sprintf('da %s, successione del %s', $e['da'], self::giorno($e['successione_del']));
        // Decisione 530: la catena, «da Marco, successione del 01/12/2026, che l'aveva ereditato da Ettore Fabbri».
        if (($e['catena']['da'] ?? '') !== '') {
            $testa .= sprintf(', che l\'aveva ereditato da %s', $e['catena']['da']);
        }
        $nella = fn (array $n) => sprintf('nella %s %s %s', $n['rata'], $n['scadenza'] >= $alla ? 'che scade il' : 'scaduta il', self::giorno($n['scadenza']));
        $pezzi = count($e['nelle_rate']) + ((int) $e['fuori_rata'] > 0 ? 1 : 0);
        if ($pezzi <= 1) {
            return $testa . ', ' . ($e['nelle_rate'] === [] ? 'non ancora in una rata' : $nella($e['nelle_rate'][0]));
        }
        $parti = array_map(fn (array $n) => MoneyHelper::format((int) $n['parte']) . ' ' . $nella($n), $e['nelle_rate']);
        if ((int) $e['fuori_rata'] > 0) {
            $parti[] = MoneyHelper::format((int) $e['fuori_rata']) . ' non ancora in una rata';
        }

        return $testa . ': ' . implode(', ', $parti);
    }

    /**
     * Le letture date con il debito ereditato al suo posto ({@see perLaLettura()}): al defunto il residuo delle rate passate agli eredi si toglie, e
     * all'erede la parte della sua rata nuova che è ancora una riga «ereditato» (decisione 516; la riga lo dice, `nelle_righe_ereditate`); agli eredi si
     * aggiungono le righe «ereditato», anche a chi non ha righe nel giornale, se è fra quelle da leggere. I totali si rifanno.
     *
     * Decisione 536 (Q1 e Q2 della quarta prova della beta.12): quello che si nasconde sulle rate vecchie del defunto preso dalla contropartita della sua rata
     * nuova si sposta su quella rata **sulla somma della rata** (dovuto − versato + la parte: il residuo se positiva, il credito se negativa), anche quando la
     * rata nuova è a debito; e il credito disponibile della lettura (quello di «Rate scadute» e delle lettere) scende di quello che il credito della rata
     * nuova perde: niente credito finto accanto a un debito.
     *
     * @param array<int, array<string, mixed>> $letture persona => la lettura ({@see \App\Services\Gestionale\Debiti\LetturaDebiti})
     * @param list<int>|null $aggiungi le persone da aggiungere se non ci sono (null: tutte quelle con righe «ereditato»)
     * @return array<int, array<string, mixed>>
     */
    public function sullaLettura(int $condominioId, string $alla, ?int $gestioneId, array $letture, ?array $aggiungi = null): array
    {
        $sopra = $this->perLaLettura($condominioId, $alla, $gestioneId);
        $toccate = [];
        // Il defunto (la rata passata agli eredi) e l'erede (la parte della sua rata nuova che è ancora una riga «ereditato», decisione 516).
        foreach ($sopra['nascosti'] as $persona => $nascosti) {
            if (! isset($letture[$persona])) {
                continue;
            }
            foreach ($letture[$persona]['rate'] as $i => $r) {
                $k = \App\Services\Gestionale\Debiti\AccordiRientro::chiave($r['rata_id'], $r['immobile_id']);
                if (isset($nascosti[$k])) {
                    $togli = min((int) $r['residuo'], $nascosti[$k]);
                    $letture[$persona]['rate'][$i]['residuo'] = (int) $r['residuo'] - $togli;
                    $letture[$persona]['rate'][$i]['nelle_righe_ereditate'] = $togli;
                }
            }
            $toccate[$persona] = true;
        }
        // N1 (decisione 528) e Q1, Q2 (decisione 536): sulla rata nuova del defunto, sulla somma della rata.
        foreach ($sopra['restituiti'] as $persona => $spostati) {
            if (! isset($letture[$persona])) {
                continue;
            }
            foreach ($spostati as $sp) {
                $i = collect($letture[$persona]['rate'])->search(fn ($r) => \App\Services\Gestionale\Debiti\AccordiRientro::chiave($r['rata_id'], $r['immobile_id']) === $sp['nuova']);
                if ($i === false) {
                    // A saldo zero la lettura non porta la rata nuova (la contropartita usata con «Usa credito»): la riga si aggiunge.
                    $letture[$persona]['rate'][] = $sp['riga'];
                    $i = array_key_last($letture[$persona]['rate']);
                }
                $r = $letture[$persona]['rate'][$i];
                $somma = (int) $r['dovuto'] - (int) $r['versato'] + (int) $sp['importo'];
                $credito = max(0, -$somma);
                $preso = max(0, (int) $r['credito'] - $credito);
                $letture[$persona]['rate'][$i]['residuo'] = max(0, $somma);
                $letture[$persona]['rate'][$i]['credito'] = $credito;
                $letture[$persona]['rate'][$i]['restituita_dalle_righe_ereditate'] = (int) $sp['importo'];
                $disponibile = $letture[$persona]['credito_disponibile'] ?? null;
                if ($preso > 0 && is_array($disponibile)) {
                    $g = (int) ($r['gestione_id'] ?? 0);
                    $daQui = min($preso, (int) ($disponibile['per_gestione'][$g] ?? 0));
                    if ($daQui > 0) {
                        $disponibile['per_gestione'][$g] = (int) $disponibile['per_gestione'][$g] - $daQui;
                        if ($disponibile['per_gestione'][$g] <= 0) {
                            unset($disponibile['per_gestione'][$g]);
                        }
                        $disponibile['totale'] = max(0, (int) $disponibile['totale'] - $daQui);
                    }
                    $letture[$persona]['credito_disponibile'] = $disponibile;
                }
            }
            $toccate[$persona] = true;
        }
        foreach (array_keys($toccate) as $persona) {
            usort($letture[$persona]['rate'], fn ($a, $b) => [$a['scadenza'], $a['numero_rata'], $a['immobile_id'] ?? 0] <=> [$b['scadenza'], $b['numero_rata'], $b['immobile_id'] ?? 0]);
            $letture[$persona]['totali'] = \App\Services\Gestionale\Debiti\LetturaDebiti::totaliDi($letture[$persona]);
        }
        foreach ($sopra['eredi'] as $erede => $linee) {
            if (! isset($letture[$erede])) {
                if ($aggiungi !== null && ! in_array($erede, $aggiungi, true)) {
                    continue;
                }
                $letture[$erede] = ['anagrafica_id' => $erede, 'alla' => $alla, 'rate' => [], 'non_emesse' => [], 'anticipi' => 0,
                    'credito_disponibile' => $alla === \App\Helpers\DateHelper::oggiUtente() ? ['totale' => 0, 'per_gestione' => []] : null];
            }
            $letture[$erede]['rate'] = [...$letture[$erede]['rate'], ...$linee];
            usort($letture[$erede]['rate'], fn ($a, $b) => [$a['scadenza'], $a['numero_rata'], $a['immobile_id'] ?? 0] <=> [$b['scadenza'], $b['numero_rata'], $b['immobile_id'] ?? 0]);
            $letture[$erede]['totali'] = \App\Services\Gestionale\Debiti\LetturaDebiti::totaliDi($letture[$erede]);
        }

        return $letture;
    }

    /**
     * La lettura a blocchi di persone ({@see \App\Services\Gestionale\Debiti\LetturaDebiti::perCondominioABlocchi()}) con il debito ereditato al suo posto: ogni
     * blocco con {@see sullaLettura()}, senza aggiungere persone; in fondo un blocco con gli eredi che hanno solo il debito ereditato (non sono in nessun blocco
     * della lettura del giornale), fra quelli di `$solo` se c'è. Per «Rate scadute», il riquadro dei sei mesi e la tesoreria.
     *
     * @param iterable<int, array<int, array<string, mixed>>> $blocchi
     * @param list<int>|null $solo
     * @return \Generator<int, array<int, array<string, mixed>>>
     */
    public function aBlocchi(iterable $blocchi, int $condominioId, string $alla, ?int $gestioneId, ?array $solo = null): \Generator
    {
        $visti = [];
        foreach ($blocchi as $blocco) {
            $visti = [...$visti, ...array_keys($blocco)];
            yield $this->sullaLettura($condominioId, $alla, $gestioneId, $blocco, []);
        }
        $mancanti = array_values(array_diff(array_keys($this->perLaLettura($condominioId, $alla, $gestioneId)['eredi']), $visti));
        if ($solo !== null) {
            $mancanti = array_values(array_intersect($mancanti, array_map('intval', $solo)));
        }
        if ($mancanti !== []) {
            yield $this->sullaLettura($condominioId, $alla, $gestioneId, [], $mancanti);
        }
    }

    /**
     * Le righe della lettura di oggi di una persona con il debito ereditato al suo posto ({@see sullaLettura()}): la guardia delle lettere rilegge così
     * quello che la lettera ha letto da «Rate scadute».
     *
     * @param list<array<string, mixed>> $rate
     * @return list<array<string, mixed>>
     */
    public function rateConIlDebitoEreditato(int $condominioId, int $persona, array $rate, ?string $alla = null): array
    {
        $alla ??= \App\Helpers\DateHelper::oggiUtente();
        $lettura = [$persona => ['anagrafica_id' => $persona, 'alla' => $alla, 'rate' => $rate, 'non_emesse' => [], 'anticipi' => 0, 'credito_disponibile' => null]];

        return $this->sullaLettura($condominioId, $alla, null, $lettura, [$persona])[$persona]['rate'];
    }

    /** I dati della riga ereditata per la finestra e per la registrazione: da chi, per quale quota, il decesso, la parte. */
    public static function perLaRiga(array $meta): array
    {
        return array_intersect_key($meta, array_flip(['subentro_id', 'da_anagrafica_id', 'da', 'percentuale', 'decesso', 'modo', 'parte']))
            + ['accessorio' => (bool) ($meta['accessorio'] ?? false)];
    }

    /**
     * La frase sotto la riga ereditata (decisione 494 (4)): «Di Ettore Fabbri, passata a te per il 50 % il 17/11/2026: interessi su € 150,00 dal 17/11/2026 al
     * 31/12/2026.»; finita, «Dal 01/04/2027 il debito è nella tua Rata 1 — Preventivo 2027, con i suoi interessi.».
     */
    public static function frase(array $meta, ?string $dal, string $al, string $alla): string
    {
        // Decisione 530: «Di Marco, che l'aveva ereditato da Ettore Fabbri, passata a te …».
        $da = ($meta['catena'] ?? '') !== '' ? sprintf('%s, che l\'aveva ereditato da %s', $meta['da'], $meta['catena']) : $meta['da'];
        $testa = sprintf('Di %s, passata a te per il %s %% il %s', $da, $meta['percentuale'], self::giorno($meta['decesso']));
        if (($meta['accessorio'] ?? false) === true) {
            return $testa . sprintf(': interessi su questo addebito, %s, dal %s al %s, perché hai scelto gli interessi anche sugli interessi.', MoneyHelper::format((int) $meta['parte']),
                self::giorno((string) $dal), self::giorno($al));
        }
        $frase = $meta['modo'] === Subentro::INTERESSI_CONTINUANO
            ? $testa . sprintf(': con «continuano a suo nome» gli interessi restano a suo nome fino all\'emissione della tua rata nuova; tu li conti su %s dal %s al %s.',
                MoneyHelper::format((int) $meta['parte']), self::giorno((string) $dal), self::giorno($al))
            : $testa . sprintf(': interessi su %s dal %s al %s.', MoneyHelper::format((int) $meta['parte']), self::giorno((string) $dal), self::giorno($al));
        // Decisione 529: i versamenti dell'erede sul debito ereditato, che dal giorno dopo lo riducono.
        $versati = array_values(array_filter((array) ($meta['versati'] ?? []), fn ($v) => $v[0] <= $al));
        if ($versati !== []) {
            $pezzi = array_map(fn ($v) => sprintf('%s il %s', MoneyHelper::format((int) $v[1]), self::giorno((string) $v[0])), $versati);
            $pezzi[0] = preg_replace('/ il /', ' versati il ', $pezzi[0], 1);
            $elenco = count($pezzi) === 1 ? $pezzi[0] : implode(', ', array_slice($pezzi, 0, -1)) . ' e ' . end($pezzi);
            $frase .= sprintf(' %s: dal giorno dopo gli interessi sono su quello che resta.', $elenco);
        }
        if (($meta['uscita'] ?? null) !== null) {
            $frase .= sprintf(' Dal %s il debito è nella tua %s, con i suoi interessi.', self::giorno(self::dopo($meta['uscita']['al'])), $meta['uscita']['rata']);
        } elseif ($meta['in_parte'] ?? false) {
            $frase .= ' Una parte è già nelle tue rate nuove, con i loro interessi.';
        }

        return $frase;
    }

    /**
     * Le cause dei tratti della riga ereditata: dal decesso, dall'emissione della rata nuova (con «continuano»), e il giorno in cui una parte è entrata in una
     * rata nuova.
     *
     * @param list<array<string, mixed>> $tratti
     * @return list<array<string, mixed>>
     */
    public static function causeDeiTratti(array $tratti, array $meta): array
    {
        $decesso = (string) ($meta['decesso'] ?? '');
        $dopo = $decesso === '' ? '' : self::dopo($decesso);

        return array_map(function (array $t) use ($meta, $decesso, $dopo) {
            if (in_array($t['dal'], $meta['nel_piano'] ?? [], true)) {
                $t['causa'] = 'nel_piano';
            } elseif ($meta['modo'] === Subentro::INTERESSI_CONTINUANO && in_array($t['dal'], $meta['entrate'] ?? [], true)) {
                $t['causa'] = 'dopo_l_emissione';
            } elseif ($t['causa'] === 'scadenza' && $decesso !== '' && $t['dal'] === $decesso) {
                $t['causa'] = 'decesso';
            } elseif ($t['causa'] === 'scadenza' && $dopo !== '' && $t['dal'] === $dopo) {
                // E8 della seconda prova della beta.12: un tratto che comincia il giorno dopo il decesso (la scelta 2 spenta, o l'addebito passato all'erede
                // il giorno del decesso, che conta dal giorno dopo) non dice «dal giorno del decesso».
                $t['causa'] = 'dopo_il_decesso';
            }

            return $t;
        }, $tratti);
    }

    /** La quota di un erede in percentuale, come la dicono le frasi del debito ereditato («50», «33,33»); anche la ricevuta dell'erede (decisione 539). */
    public static function percentuale(float $p): string
    {
        $arrotondata = round($p, 2);

        return rtrim(rtrim(number_format($arrotondata, 2, ',', '.'), '0'), ',');
    }

    private static function dopo(string $giorno): string
    {
        return CarbonImmutable::parse($giorno, 'UTC')->addDay()->format('Y-m-d');
    }

    private static function prima(string $giorno): string
    {
        return CarbonImmutable::parse($giorno, 'UTC')->subDay()->format('Y-m-d');
    }

    private static function giorno(string $data): string
    {
        return substr($data, 8, 2) . '/' . substr($data, 5, 2) . '/' . substr($data, 0, 4);
    }
}
