<?php

namespace App\Services\Subentro;

use App\Actions\Subentro\AnnullaPassaggioAction;
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\TitolaritaImmobile;
use App\Services\Riparto\RisolutoreTitolari;
use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * La fermata dopo «Scrivi il conguaglio» (decisione 77, punto 3, allargata dalla 79; 1.11.0-beta.50).
 *
 * Alla fine di una locazione senza un nuovo inquilino, con «Scrivi», chi paga al posto dell'inquilino prende con la coppia i giorni
 * dopo l'uscita. Se poi un passaggio abbassa il suo peso fra chi sta al posto dell'inquilino (la vendita, anche datata prima della fine
 * e registrata dopo, la costituzione e l'estinzione dell'usufrutto, anche con l'accrescimento — decisione 81.1 —, la successione con
 * l'arretrato a nome del defunto e «Scrivi»), la parte di quei giorni dal passaggio in poi non passa da sola a chi entra: il programma
 * registra il passaggio, non sposta quella parte, e lo dice con la persona e la cifra. È una fermata dichiarata, con la spunta del
 * cancello, non un rifiuto; il supporto completo viene dopo la 1.11. Chi è entrato con un passaggio fermato e poi esce a sua volta dice la
 * sua parte della fermata come la prima (decisione 81.2, `dopoUnaFermata`); sulla pertinenza conta la riga che la registrazione chiude
 * davvero (decisione 81.3).
 *
 * La condizione legge le righe di saldo di chi esce — la sua parte di una coppia di una fine «Scrivi» non annullata, assorbita o no
 * da un piano (`is_applicato`, `piano_rate_id`: `PianoRate::saldoAssorbito` non lo dice per le coppie) —, non le date dei passaggi. La
 * cifra è la coppia ridivisa con la titolarità di dopo il passaggio, meno quella di prima, sui giorni della coppia (`conguaglio.periodi`
 * nel registro della fine), per persona con tutte le sue righe e con la regola della coppia (`ChiPagaAlPostoDellInquilino`): a pari
 * resto il centesimo resta a chi esce. Conta solo i giorni senza un conduttore registrato: quelli li rimborsa il conduttore con il
 * prospetto degli oneri accessori, chiunque stia al posto dell'inquilino.
 *
 * Giro stretto della .50 (G1, G2): con i pezzi con il segno (un capitolo di entrata toglie) la parte di chi entra può essere negativa, un
 * credito, e le righe della coppia di chi sta al posto dell'inquilino possono avere segni diversi. La fermata si decide sulla variazione
 * delle parti, non sul calo del peso di chi esce; la coppia è la somma netta delle righe di chi sta al posto dell'inquilino, tranne chi è
 * uscito con la fine; e le frasi dicono un credito con le parole, con il verso giusto della riga del Wallet. Chiusura della .50 (punto 1):
 * la somma netta può essere negativa (la coppia tutta a credito), e vale come le altre.
 *
 * Dopo una fine «Scrivi», l'inizio di una locazione è un'informazione (decisione 45): chi rientra rimborsa con il prospetto i giorni che
 * la coppia ha dato a chi pagava al posto suo (`rientro`). Dopo una fermata, l'inizio di una locazione toglie dalla fermata i giorni del
 * nuovo conduttore, e dice quanto resta da regolare (`fermateRidotte`): quello sì è un motivo del cancello.
 */
final class FermataDopoScrivi
{
    /** Il prefisso delle chiavi dei conduttori nei pesi: rimborsano con il prospetto, e non sono mai chi riceve una fermata. */
    private const CONDUTTORE = 'c';

    private int $prossimoId = -1;

    public function __construct(
        private readonly ChiPagaAlPostoDellInquilino $chiPaga = new ChiPagaAlPostoDellInquilino(),
        private readonly RisolutoreTitolari $titolari = new RisolutoreTitolari(),
    ) {
    }

    /**
     * Le fermate di un passaggio: una per coppia di una fine «Scrivi» in cui chi esce ha una parte (a debito, o a credito con un'entrata) e
     * che il passaggio non sposta.
     *
     * @param list<array{anagrafica: Anagrafica, quota: float}> $eredi
     * @param list<int> $saldiPortati le righe che il passaggio stesso porta a chi entra (l'arretrato agli eredi, `fonti`)
     * @param Collection<int, TitolaritaImmobile>|null $nudi i nudi che tornano pieni all'estinzione
     * @return list<array<string, mixed>>
     */
    public function per(string $tipo, ?string $sottotipo, ?TitolaritaImmobile $uscente, ?Anagrafica $entrante, array $eredi, array $immobileIds, CarbonImmutable $decorrenza,
        array $saldiPortati = [], ?Collection $nudi = null, bool $accrescimento = false): array
    {
        if ($uscente === null || in_array($tipo, ['inizio_locazione', 'fine_locazione'], true)) {
            return [];
        }
        $chi = (int) $uscente->anagrafica_id;
        $nudiIds = ($nudi ?? collect())->map(fn (TitolaritaImmobile $t) => (int) $t->anagrafica_id)->unique()->values()->all();
        $out = [];
        foreach ($this->coppieScritte($chi, $immobileIds, alPosto: true, esclusi: $saldiPortati) as $c) {
            $righe = $this->chiPaga->righe($c['immobile_id']);
            $pesiPrima = $this->pesi($righe, $c['pezzi']);
            $pesiDopo = $this->pesi($this->conIlPassaggio($righe, $c['immobile_id'], $tipo, $sottotipo, $uscente, $entrante, $eredi, $nudiIds, $accrescimento, $decorrenza), $c['pezzi']);
            $prima = $this->chiPaga->dividi($c['totale'], $pesiPrima, $chi);
            $dopo = $this->chiPaga->dividi($c['totale'], $pesiDopo, $chi);
            $parti = self::chiRiceve($pesiPrima, $pesiDopo, $prima, $dopo, $chi);
            $cifra = (int) array_sum($parti);
            if ($cifra === 0) {
                continue;
            }
            $nomi = Anagrafica::whereIn('id', array_keys($parti))->pluck('nome', 'id');
            $dal = $c['pezzi'][0]['tratti']->dal();
            foreach ($c['pezzi'] as $p) {
                $dal = $p['tratti']->dal()->lt($dal) ? $p['tratti']->dal() : $dal;
            }
            $conUnSaldo = self::conUnSaldo([$chi, ...array_keys($parti)], $c['gestione_id'], $c['immobile_id'], $c['esercizio_id']);
            $out[] = [
                'fine_id' => $c['fine']->id, 'fine_il' => $c['fine']->decorrenza->toDateString(), 'inquilino' => self::nomeDiChiEsce($c['fine']),
                'saldo_id' => $c['saldo_id'], 'persona_id' => $chi, 'persona' => (string) $uscente->anagrafica?->nome,
                'immobile_id' => $c['immobile_id'], 'unita' => $c['unita'], 'gestione_id' => $c['gestione_id'], 'gestione' => $c['gestione'], 'esercizio_id' => $c['esercizio_id'],
                'coppia' => $c['riga'], 'cifra' => $cifra, 'dal' => ($decorrenza->gt($dal) ? $decorrenza : $dal)->toDateString(), 'assorbita' => $c['assorbita'],
                'verso' => array_values(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), array_keys($parti))),
                'parti' => array_map(fn (int $id) => ['anagrafica_id' => $id, 'nome' => (string) ($nomi[$id] ?? '?'), 'cifra' => $parti[$id]], array_keys($parti)),
                // Decisione 80: il saldo manuale dal Wallet solo se nessuna delle persone della riga ne ha già uno lì.
                'wallet' => $conUnSaldo === [], 'con_un_saldo' => $conUnSaldo,
            ];
        }

        return [...$out, ...$this->dopoUnaFermata($tipo, $sottotipo, $uscente, $entrante, $eredi, $immobileIds, $decorrenza, $nudiIds, $accrescimento)];
    }

    /**
     * Decisione 81.2: un passaggio di chi è entrato con un passaggio già fermato (la seconda vendita, Elsa a Carlo, dopo la vendita di Ugo a
     * Elsa). Chi esce non ha righe nella coppia — le ha ancora chi l'ha presa con «Scrivi» —, ma ha i giorni della coppia dal suo ingresso,
     * e la fermata di prima gli ha detto la sua parte. Il passaggio la abbassa: la parte di quei giorni che tocca a chi entra si dice come
     * nella prima fermata, con la stessa regola (la coppia ridivisa, il centesimo a pari resto a chi esce). La cifra resta a carico di chi
     * ha le righe della coppia, o di chi esce se la fermata di prima è già stata regolata fra le parti: il programma non lo sa, e per questo
     * non indica il Wallet. Resta fuori solo chi ha una riga nella coppia dalla parte di chi sta al posto dell'inquilino (quella fermata la
     * dice `per`): decisione 82.1, l'inquilino uscito con «Scrivi», che ha la riga dall'altra parte, compra e rivende, e la seconda vendita
     * dice la sua parte come per chiunque altro.
     *
     * Chi ha le righe della coppia lo dice `chiHaLaCoppia`: dopo una successione con l'arretrato agli eredi la coppia del defunto è passata
     * agli eredi. Se chi esce è uno di loro, la sua parte della coppia è sua, e la fermata è come la prima: a suo carico.
     *
     * @param list<array{anagrafica: Anagrafica, quota: float}> $eredi
     * @param list<int> $nudiIds
     * @return list<array<string, mixed>>
     */
    private function dopoUnaFermata(string $tipo, ?string $sottotipo, TitolaritaImmobile $uscente, ?Anagrafica $entrante, array $eredi, array $immobileIds, CarbonImmutable $decorrenza,
        array $nudiIds, bool $accrescimento): array
    {
        $chi = (int) $uscente->anagrafica_id;
        $righeDellaCoppia = Saldo::with('gestione')->whereIn('immobile_id', $immobileIds)->whereNotNull('subentro_id')->where('saldo_iniziale', '!=', 0)->orderBy('id')->get();
        $out = [];
        foreach ($righeDellaCoppia->groupBy(fn (Saldo $r) => $r->subentro_id . '|' . $r->immobile_id . '|' . $r->gestione_id . '|' . ($r->esercizio_id ?? '')) as $gruppo) {
            $r = $gruppo->first();
            $fine = Subentro::with('padre')->find((int) $r->subentro_id)?->capo();
            if ($fine === null || ! self::fineScritta($fine)) {
                continue;
            }
            $c = $this->coppia($fine, (int) $r->subentro_id, (int) $r->immobile_id, (int) $r->gestione_id, $r->esercizio_id !== null ? (int) $r->esercizio_id : null);
            // Chi ha una sua riga fra chi sta al posto dell'inquilino lo dice `per`.
            if ($c === null || isset($c['righe'][$chi])) {
                continue;
            }
            $hanno = $this->chiHaLaCoppia($c, (int) $fine->condominio_id);
            $suaParte = $hanno[$chi] ?? null;
            $righe = $this->chiPaga->righe($c['immobile_id']);
            $pesiPrima = $this->pesi($righe, $c['pezzi']);
            // Giro stretto della .50 (G1): anche chi è entrato con una parte negativa (un'entrata che nei suoi giorni supera la spesa) e
            // rivende dice la sua parte.
            if (abs($pesiPrima[$chi] ?? 0.0) <= 1e-9) {
                continue;
            }
            $pesiDopo = $this->pesi($this->conIlPassaggio($righe, $c['immobile_id'], $tipo, $sottotipo, $uscente, $entrante, $eredi, $nudiIds, $accrescimento, $decorrenza), $c['pezzi']);
            $prima = $this->chiPaga->dividi($c['totale'], $pesiPrima, $chi);
            $dopo = $this->chiPaga->dividi($c['totale'], $pesiDopo, $chi);
            // Chi riceve: come nella prima fermata, senza chi ha le righe della coppia, che quei giorni li ha già presi.
            $parti = self::chiRiceve($pesiPrima, $pesiDopo, $prima, $dopo, $chi, $hanno);
            $cifra = (int) array_sum($parti);
            if ($cifra === 0) {
                continue;
            }
            $nomi = Anagrafica::whereIn('id', [...array_keys($parti), ...array_keys($hanno)])->pluck('nome', 'id');
            $dal = $c['pezzi'][0]['tratti']->dal();
            foreach ($c['pezzi'] as $p) {
                $dal = $p['tratti']->dal()->lt($dal) ? $p['tratti']->dal() : $dal;
            }
            if ($suaParte !== null) {
                // Chi esce ha la sua parte della coppia (l'erede, con l'arretrato agli eredi): la fermata è la prima, a suo carico. Giro stretto
                // della .50 (G13): la coppia non l'ha presa lui, gli è arrivata con la successione, e la frase lo dice (`ereditata`).
                $conUnSaldo = self::conUnSaldo([$chi, ...array_keys($parti)], $c['gestione_id'], $c['immobile_id'], $c['esercizio_id']);
                $out[] = [
                    'fine_id' => $c['fine']->id, 'fine_il' => $c['fine']->decorrenza->toDateString(), 'inquilino' => self::nomeDiChiEsce($c['fine']),
                    'saldo_id' => (int) $r->id, 'persona_id' => $chi, 'persona' => (string) $uscente->anagrafica?->nome,
                    'immobile_id' => $c['immobile_id'], 'unita' => $c['unita'], 'gestione_id' => $c['gestione_id'], 'gestione' => $c['gestione'], 'esercizio_id' => $c['esercizio_id'],
                    'coppia' => $suaParte, 'cifra' => $cifra, 'dal' => ($decorrenza->gt($dal) ? $decorrenza : $dal)->toDateString(),
                    'assorbita' => $gruppo->contains(fn (Saldo $x) => (bool) $x->is_applicato),
                    'verso' => array_values(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), array_keys($parti))),
                    'parti' => array_map(fn (int $id) => ['anagrafica_id' => $id, 'nome' => (string) ($nomi[$id] ?? '?'), 'cifra' => $parti[$id]], array_keys($parti)),
                    'wallet' => $conUnSaldo === [], 'con_un_saldo' => $conUnSaldo,
                ] + self::ereditata($c, $chi, (int) $fine->condominio_id);
                continue;
            }
            // La fermata di prima ha detto a carico di chi è rimasta la parte di chi esce: la parte dei giorni che passa con questo passaggio
            // viene da lì, non da tutti quelli che hanno righe nella coppia (due comproprietari: la parte di Elsa veniva dalla riga di Ugo).
            $diPrima = $this->fermataDi($c, $chi);
            if (($diPrima['persona_id'] ?? null) !== null && isset($hanno[$diPrima['persona_id']])) {
                $hanno = [$diPrima['persona_id'] => $hanno[$diPrima['persona_id']]];
            }
            arsort($hanno);
            $out[] = [
                'fine_id' => $c['fine']->id, 'fine_il' => $c['fine']->decorrenza->toDateString(), 'inquilino' => self::nomeDiChiEsce($c['fine']),
                'saldo_id' => (int) $r->id, 'persona_id' => (int) array_key_first($hanno), 'persona' => self::elenco(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), array_keys($hanno))),
                'piu_persone' => count($hanno) > 1,
                // Chi esce, che non ha righe nella coppia, e la fermata che gli ha detto la sua parte.
                'uscente_id' => $chi, 'uscente' => (string) $uscente->anagrafica?->nome,
                'dopo_la_fermata' => $diPrima === null ? null : ['passaggio' => $diPrima['passaggio'], 'cifra' => $diPrima['cifra']],
                'immobile_id' => $c['immobile_id'], 'unita' => $c['unita'], 'gestione_id' => $c['gestione_id'], 'gestione' => $c['gestione'], 'esercizio_id' => $c['esercizio_id'],
                // G13: la riga di chi è rimasto a carico (di tutti, se sono più d'uno), non tutta la coppia.
                'coppia' => (int) array_sum($hanno), 'cifra' => $cifra, 'dal' => ($decorrenza->gt($dal) ? $decorrenza : $dal)->toDateString(),
                'assorbita' => $gruppo->contains(fn (Saldo $x) => (bool) $x->is_applicato),
                'verso' => array_values(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), array_keys($parti))),
                'parti' => array_map(fn (int $id) => ['anagrafica_id' => $id, 'nome' => (string) ($nomi[$id] ?? '?'), 'cifra' => $parti[$id]], array_keys($parti)),
                'wallet' => false, 'con_un_saldo' => [],
            ];
        }

        return $out;
    }

    /**
     * Chi ha le righe a debito di una coppia, in centesimi (anagrafica → centesimi). Una successione con l'arretrato agli eredi che ha preso
     * fra le sue fonti la riga di una persona ha passato agli eredi tutta la sua posizione, coppia compresa (decisione 65): quella riga è
     * degli eredi, ciascuno per la sua quota, con i resti maggiori.
     *
     * @param array{righe: array<int, int>, saldi: array<int, list<int>>} $c
     * @return array<int, int>
     */
    private function chiHaLaCoppia(array $c, int $condominioId): array
    {
        $hanno = $c['righe'];
        foreach ($c['saldi'] as $anagraficaId => $ids) {
            $successione = Subentro::successioneCheLeggeISaldi($ids, $condominioId);
            $eredi = $successione !== null && $successione->arretratoAgliEredi()
                ? collect($successione->destinatari())->mapWithKeys(fn (array $e) => [(int) $e['anagrafica_id'] => (float) $e['quota']])->filter(fn (float $q) => $q > 0)->all() : [];
            if ($eredi === [] || ! isset($hanno[$anagraficaId])) {
                continue;
            }
            $cifra = $hanno[$anagraficaId];
            unset($hanno[$anagraficaId]);
            foreach (MoneyHelper::ripartisciPerQuote($cifra, $eredi) as $erede => $parte) {
                $hanno[(int) $erede] = ($hanno[(int) $erede] ?? 0) + (int) $parte;
            }
        }

        return array_filter($hanno, fn (int $cent) => $cent !== 0);
    }

    /**
     * Chi riceve con un passaggio, e quanto: ogni persona diversa da chi esce (e da chi ha già le righe della coppia, `$esclusi`) di cui il
     * passaggio cambia il peso e la parte, non i conduttori. La parte è «dopo − prima», con il segno.
     *
     * Giro stretto della .50 (G1): con i pezzi con il segno (R3) la parte di chi entra può essere negativa (un'entrata che nei suoi giorni
     * supera la spesa), e il peso di chi esce può salire invece di calare: la fermata si decide sulla variazione delle parti, e la cifra si
     * dice anche quando è un credito di chi entra.
     *
     * @param array<int|string, float> $pesiPrima
     * @param array<int|string, float> $pesiDopo
     * @param array<int|string, int> $prima
     * @param array<int|string, int> $dopo
     * @param array<int, int> $esclusi
     * @return array<int, int>
     */
    private static function chiRiceve(array $pesiPrima, array $pesiDopo, array $prima, array $dopo, int $chi, array $esclusi = []): array
    {
        $parti = [];
        foreach ($pesiDopo as $id => $peso) {
            if (is_int($id) && $id !== $chi && ! isset($esclusi[$id]) && abs($peso - ($pesiPrima[$id] ?? 0.0)) > 1e-9 && ($dopo[$id] ?? 0) !== ($prima[$id] ?? 0)) {
                $parti[$id] = ($dopo[$id] ?? 0) - ($prima[$id] ?? 0);
            }
        }

        return $parti;
    }

    /**
     * Giro stretto della .50 (G13): da dove viene la parte della coppia di un erede con l'arretrato agli eredi. La coppia l'ha presa il
     * defunto con «Scrivi»; la successione ha passato all'erede la sua parte (decisione 65). Per la frase: chi l'aveva presa, quanto, e con
     * quale passaggio è arrivata. Vuoto se non si trova.
     *
     * @return array{ereditata?: array{da: string, coppia: int, passaggio: string}}
     */
    private static function ereditata(array $c, int $erede, int $condominioId): array
    {
        foreach ($c['saldi'] as $anagraficaId => $ids) {
            $successione = Subentro::successioneCheLeggeISaldi($ids, $condominioId);
            if ($successione !== null && $successione->arretratoAgliEredi()
                && collect($successione->destinatari())->contains(fn (array $e) => (int) $e['anagrafica_id'] === $erede)) {
                return ['ereditata' => ['da' => (string) Anagrafica::whereKey((int) $anagraficaId)->value('nome'), 'coppia' => (int) ($c['righe'][$anagraficaId] ?? 0),
                    'passaggio' => self::passaggioInParole($successione)]];
            }
        }

        return [];
    }

    /**
     * Decisione 81.2: la fermata registrata che ha detto a chi esce la sua parte di una coppia (l'ultima, se sono più d'una): il passaggio e
     * la cifra, per le frasi. Null se nessuna lo nomina.
     *
     * @return array{passaggio: string, cifra: int, persona_id: ?int}|null `persona_id`: chi è rimasto a carico della fermata di prima, se è uno
     */
    private function fermataDi(array $c, int $anagraficaId): ?array
    {
        $passaggi = Subentro::where('immobile_id', $c['immobile_id'])->whereNull('annullato_il')->orderByDesc('id')->get()->map(fn (Subentro $s) => $s->capo())->unique('id');
        foreach ($passaggi as $s) {
            foreach ($s->annullato() ? [] : ($s->registro['fermata_dopo_scrivi'] ?? []) as $f) {
                $parte = collect($f['parti'] ?? [])->first(fn ($p) => (int) ($p['anagrafica_id'] ?? 0) === $anagraficaId);
                if ($parte !== null && (int) ($f['fine_id'] ?? 0) === (int) $c['fine']->id && (int) ($f['immobile_id'] ?? 0) === (int) $c['immobile_id']
                    && (int) ($f['gestione_id'] ?? 0) === (int) $c['gestione_id']) {
                    return ['passaggio' => self::passaggioInParole($s), 'cifra' => (int) $parte['cifra'],
                        'persona_id' => empty($f['piu_persone']) && isset($f['persona_id']) ? (int) $f['persona_id'] : null];
                }
            }
        }

        return null;
    }

    /**
     * L'inizio di una locazione di chi è uscito con una fine «Scrivi» sulla stessa unità (il rinnovo, il rientro): i giorni della coppia
     * dal giorno dell'inizio li ha presi chi pagava al posto suo, e chi rientra glieli rimborsa con il prospetto degli oneri accessori
     * (decisione 45). La cifra è la parte del nuovo conduttore nella coppia ridivisa. Un'informazione, non una fermata.
     *
     * @return list<array<string, mixed>>
     */
    public function rientro(?Anagrafica $entrante, float $quota, array $immobileIds, CarbonImmutable $decorrenza): array
    {
        if ($entrante === null || $quota <= 0.0) {
            return [];
        }
        $out = [];
        $annullabile = [];
        foreach ($this->coppieScritte((int) $entrante->id, $immobileIds, alPosto: false) as $c) {
            if ((int) $c['fine']->anagrafica_uscente_id !== (int) $entrante->id) {
                continue;
            }
            $righe = $this->chiPaga->righe($c['immobile_id']);
            $con = $this->conIlConduttore($righe, $c['immobile_id'], (int) $entrante->id, $quota, $decorrenza);
            $prima = $this->chiPaga->dividi($c['totale'], $this->pesi($righe, $c['pezzi']));
            $dopo = $this->chiPaga->dividi($c['totale'], $this->pesi($con, $c['pezzi']));
            $cifra = (int) ($dopo[self::CONDUTTORE . $entrante->id] ?? 0) - (int) ($prima[self::CONDUTTORE . $entrante->id] ?? 0);
            // Giro stretto della .50 (G1): una parte negativa (un'entrata che nei giorni di chi rientra supera la spesa) si dice anche lei: è
            // chi ha preso la coppia che la restituisce.
            if ($cifra === 0) {
                continue;
            }
            // Chi li ha presi: chi ha le righe della coppia. Una persona senza righe (chi è entrato con un passaggio fermato dopo) non ha
            // preso niente: la sua parte l'ha ancora chi è rimasto a carico, che ha più della sua parte (`fermateRidotte` dice il resto). Il
            // verso è quello della cifra: con una cifra negativa la parte di chi ha la coppia sale.
            $calano = array_keys(array_filter($prima, fn (int $parte, int|string $id) => is_int($id) && ($parte - ($dopo[$id] ?? 0)) * $cifra > 0, ARRAY_FILTER_USE_BOTH));
            $senzaRighe = array_diff($calano, array_keys($c['righe'])) !== [];
            $pagatoDa = array_keys(array_filter($c['righe'], fn (int $riga, int $id) => in_array($id, $calano, true) || ($senzaRighe && ($riga - ($prima[$id] ?? 0)) * $cifra > 0), ARRAY_FILTER_USE_BOTH));
            $fineId = (int) $c['fine']->id;
            $out[] = [
                'fine_id' => $fineId, 'fine_il' => $c['fine']->decorrenza->toDateString(), 'persona' => (string) $entrante->nome,
                'immobile_id' => $c['immobile_id'], 'unita' => $c['unita'], 'gestione_id' => $c['gestione_id'], 'gestione' => $c['gestione'],
                'cifra' => $cifra, 'dal' => $decorrenza->toDateString(),
                'pagato_da' => Anagrafica::whereIn('id', $pagatoDa)->pluck('nome')->map(fn ($n) => (string) $n)->values()->all(),
                // L'alternativa del rinnovo si dice solo se la fine si può annullare adesso (decisione 27: dall'ultimo passaggio).
                'annullabile' => $annullabile[$fineId] ??= app(AnnullaPassaggioAction::class)->motivoBlocco($c['fine']) === null,
            ];
        }

        return $out;
    }

    /**
     * Ultimo giro della .50 (punto 2): l'inizio di una locazione di chi non è uscito con la fine, dopo una fine «Scrivi» di un altro
     * inquilino sulla stessa unità. La parte del nuovo conduttore nella coppia ridivisa, con il suo segno, per gestione e unità, con la
     * regola del rientro: positiva la rimborsa a chi ha preso la coppia; negativa (un'entrata che nei suoi giorni supera la spesa) è un
     * suo credito, e chi ha preso la coppia gli restituisce la differenza. Il blocco «Rate» del pannello ne prende il verso. Chiusura della
     * .50 (punto 1): anche con una coppia tutta a credito di chi sta al posto dell'inquilino (`coppia`).
     *
     * @return list<array{fine_id: int, immobile_id: int, gestione_id: int, cifra: int}>
     */
    public function partiDiChiEntra(?Anagrafica $entrante, float $quota, array $immobileIds, CarbonImmutable $decorrenza): array
    {
        if ($entrante === null || $quota <= 0.0) {
            return [];
        }
        $righe = Saldo::whereIn('immobile_id', $immobileIds)->whereNotNull('subentro_id')->where('saldo_iniziale', '!=', 0)->orderBy('id')->get();
        $out = [];
        foreach ($righe->groupBy(fn (Saldo $r) => $r->subentro_id . '|' . $r->immobile_id . '|' . $r->gestione_id . '|' . ($r->esercizio_id ?? '')) as $gruppo) {
            $r = $gruppo->first();
            $fine = Subentro::with('padre')->find((int) $r->subentro_id)?->capo();
            // Chi rientra lo dice `rientro`.
            if ($fine === null || ! self::fineScritta($fine) || (int) $fine->anagrafica_uscente_id === (int) $entrante->id) {
                continue;
            }
            $c = $this->coppia($fine, (int) $r->subentro_id, (int) $r->immobile_id, (int) $r->gestione_id, $r->esercizio_id !== null ? (int) $r->esercizio_id : null);
            if ($c === null) {
                continue;
            }
            $righeUnita = $this->chiPaga->righe($c['immobile_id']);
            $con = $this->conIlConduttore($righeUnita, $c['immobile_id'], (int) $entrante->id, $quota, $decorrenza);
            $prima = $this->chiPaga->dividi($c['totale'], $this->pesi($righeUnita, $c['pezzi']));
            $dopo = $this->chiPaga->dividi($c['totale'], $this->pesi($con, $c['pezzi']));
            $cifra = (int) ($dopo[self::CONDUTTORE . $entrante->id] ?? 0) - (int) ($prima[self::CONDUTTORE . $entrante->id] ?? 0);
            if ($cifra !== 0) {
                $out[] = ['fine_id' => (int) $fine->id, 'immobile_id' => (int) $c['immobile_id'], 'gestione_id' => (int) $c['gestione_id'], 'cifra' => $cifra];
            }
        }

        return $out;
    }

    /**
     * Dopo una fermata, l'inizio di una locazione: dal giorno dell'inizio i giorni della coppia li rimborsa il nuovo conduttore con il
     * prospetto degli oneri accessori, e la parte della fermata che cade lì non va più regolata fra chi è rimasto a carico e chi ha
     * ricevuto. La cifra della fermata non resta valida per sempre (caso S7 delle misure della Fase 1 della .50): si dice quanto ne
     * resta. È un motivo del cancello, come la fermata.
     *
     * @return list<array<string, mixed>>
     */
    public function fermateRidotte(?Anagrafica $entrante, float $quota, array $immobileIds, CarbonImmutable $decorrenza): array
    {
        if ($entrante === null || $quota <= 0.0) {
            return [];
        }
        $passaggi = Subentro::whereIn('immobile_id', $immobileIds)->get()->map(fn (Subentro $s) => $s->capo())
            ->filter(fn (Subentro $s) => ! $s->annullato() && ($s->registro['fermata_dopo_scrivi'] ?? []) !== [])->unique('id')->sortBy('id')->values();
        $out = [];
        foreach ($passaggi as $s) {
            foreach ($s->registro['fermata_dopo_scrivi'] as $f) {
                $u = (int) ($f['immobile_id'] ?? 0);
                if (! in_array($u, array_map('intval', $immobileIds), true) || empty($f['parti'])) {
                    continue;
                }
                $c = $this->coppiaDellaFermata($f);
                if ($c === null) {
                    continue;
                }
                // I conduttori entrati con un passaggio registrato dopo la fermata hanno già tolto i loro giorni: si parte dalle righe senza di
                // loro, e la parte che il nuovo conduttore toglie si confronta con quella che restava prima di lui.
                $righe = $this->chiPaga->righe($u);
                $entratiDopo = Subentro::where('immobile_id', $u)->whereIn('tipo_passaggio', ['inizio_locazione', 'fine_locazione'])->where('id', '>', $s->id)
                    ->whereNotNull('riga_entrante_id')->pluck('riga_entrante_id')->map(fn ($id) => (int) $id)->all();
                // A pari resto il centesimo a chi è uscito con quel passaggio, come nella fermata: dopo una fermata (decisione 81.2) non ha
                // righe nella coppia, e lo dice `uscente_id`.
                $chi = (int) ($f['uscente_id'] ?? $f['persona_id']);
                $pesiBase = $this->pesi($righe->reject(fn ($r) => in_array((int) $r->id, $entratiDopo, true))->values(), $c['pezzi']);
                $pesiOra = $this->pesi($righe, $c['pezzi']);
                $pesiCon = $this->pesi($this->conIlConduttore($righe, $u, (int) $entrante->id, $quota, $decorrenza), $c['pezzi']);
                $base = $this->chiPaga->dividi($c['totale'], $pesiBase, $chi);
                $ora = $this->chiPaga->dividi($c['totale'], $pesiOra, $chi);
                $con = $this->chiPaga->dividi($c['totale'], $pesiCon, $chi);
                // Fase 1-bis della .50 (il «€ 0,01» della ripresa): la parte di chi ha ricevuto scende solo se il conduttore gli toglie
                // giorni. Se il suo peso non cala, un centesimo in meno è un resto di arrotondamento che si è spostato (il pari resto con
                // un altro non c'è più), non un giorno suo: la fermata non si riduce per quello.
                // Giro stretto della .50 (G1): con una parte negativa (un credito di chi ha ricevuto) la riduzione va verso lo zero dall'altra
                // parte: conta se la parte si avvicina a zero, e la cifra che resta ha il suo segno.
                $resta = function (array $p, array $parti, array $pesi) use ($base, $pesiBase): int {
                    $id = (int) $p['anagrafica_id'];
                    if (abs($pesi[$id] ?? 0.0) >= abs($pesiBase[$id] ?? 0.0) - 1e-9) {
                        return (int) $p['cifra'];
                    }
                    $tolto = ($base[$id] ?? 0) - ($parti[$id] ?? 0);

                    return (int) $p['cifra'] >= 0 ? max(0, (int) $p['cifra'] - max(0, $tolto)) : min(0, (int) $p['cifra'] - min(0, $tolto));
                };
                $residue = array_map(fn (array $p) => ['anagrafica_id' => (int) $p['anagrafica_id'], 'nome' => (string) $p['nome'], 'cifra' => $resta($p, $con, $pesiCon)], $f['parti']);
                $residuo = (int) array_sum(array_column($residue, 'cifra'));
                $primaDiLui = (int) array_sum(array_map(fn (array $p) => $resta($p, $ora, $pesiOra), $f['parti']));
                if ($residuo === $primaDiLui) {
                    continue;
                }
                $out[] = [
                    'passaggio_id' => (int) $s->id, 'passaggio' => self::passaggioInParole($s), 'fine_id' => (int) $f['fine_id'], 'inquilino' => (string) ($f['inquilino'] ?? 'l\'inquilino'),
                    'persona_id' => (int) $f['persona_id'], 'persona' => (string) ($f['persona'] ?? '?'), 'immobile_id' => $u, 'unita' => (string) ($f['unita'] ?? ''),
                    'gestione_id' => (int) $f['gestione_id'], 'gestione' => (string) ($f['gestione'] ?? '?'), 'cifra' => (int) $f['cifra'], 'prima' => $primaDiLui, 'residuo' => $residuo,
                    'parti' => array_values(array_filter($residue, fn (array $p) => $p['cifra'] !== 0)), 'verso' => array_column($f['parti'], 'nome'),
                    'conduttore' => (string) $entrante->nome, 'dal' => $decorrenza->toDateString(),
                ];
            }
        }

        return $out;
    }

    /** Le chiavi di una fermata che il registro del passaggio conserva: lo storico, l'annullamento e il prospetto le leggono da lì. */
    public static function perIlRegistro(array $f): array
    {
        return array_intersect_key($f, array_flip(['fine_id', 'fine_il', 'inquilino', 'saldo_id', 'persona_id', 'persona', 'immobile_id', 'unita', 'gestione_id', 'gestione',
            'esercizio_id', 'coppia', 'cifra', 'dal', 'assorbita', 'verso', 'parti', 'wallet', 'passaggio_id', 'passaggio', 'prima', 'residuo', 'conduttore',
            'uscente_id', 'uscente', 'dopo_la_fermata', 'piu_persone', 'ereditata']));
    }

    /**
     * Decisione 80 (1.11.0-beta.50): la strada a mano di una fermata è il saldo manuale dal Wallet solo se si può fare davvero. Il Wallet
     * accetta un saldo per persona, gestione, unità ed esercizio (le righe dei passaggi non occupano il posto, `CreateSaldoRequest`): con
     * un saldo già lì la riga verrebbe rifiutata, e passerebbe solo quella dell'altra persona. Allora la parte si regola fra le parti.
     *
     * @param list<int> $persone chi resta a carico e chi riceve, quando si conosce
     */
    public static function stradaDelWallet(array $persone, int $gestioneId, int $immobileId, ?int $esercizioId): bool
    {
        return self::conUnSaldo($persone, $gestioneId, $immobileId, $esercizioId) === [];
    }

    /**
     * Decisione 77 (3): la frase del pannello, prima di registrare. Con `$seScrivi` la successione non ha ancora la scelta sul conguaglio,
     * e la fermata vale solo con «Scrivi».
     */
    public static function frasePannello(array $f, bool $seScrivi = false): string
    {
        $frase = isset($f['uscente_id'])
            ? self::frasePannelloDopoUnaFermata($f)
            : sprintf('La fine locazione di %s del %s ha scritto il conguaglio: dei giorni dopo la sua uscita %s sulla gestione %s (%s).%s Con questo passaggio la parte di quei giorni dal %s tocca %s, ma il programma non la sposta: %s. %s',
                $f['inquilino'], self::data($f['fine_il']),
                // Giro stretto della .50 (G13): l'erede non ha preso la coppia, gli è arrivata con la successione; la frase dice chi l'ha presa.
                isset($f['ereditata']) ? self::presa((string) $f['ereditata']['da'], (int) $f['ereditata']['coppia']) : self::presa((string) $f['persona'], (int) $f['coppia']),
                $f['gestione'], $f['unita'],
                isset($f['ereditata']) ? sprintf(' Con %s una parte, %s, è passata %s.', $f['ereditata']['passaggio'], self::cifraInParole((int) $f['coppia']), self::a((string) $f['persona'])) : '',
                self::data($f['dal']), self::a(self::elenco($f['verso'])),
                (int) $f['cifra'] < 0 ? 'in quei giorni l\'entrata supera la spesa, e ' . self::aCarico((int) $f['cifra'], '', (string) $f['persona']) : self::aCarico((int) $f['cifra'], '', (string) $f['persona']),
                self::stradaAMano($f));

        return $seScrivi ? 'Se scrivi il conguaglio della successione: ' . lcfirst($frase) : $frase;
    }

    /**
     * Decisione 81.2: la frase del pannello per la fermata di chi era entrato con un passaggio già fermato. La cifra resta a carico di chi ha
     * le righe della coppia, o di chi esce se la fermata di prima è già stata regolata fra le parti; il programma non sa quale delle due, e
     * il Wallet non lo indica.
     */
    private static function frasePannelloDopoUnaFermata(array $f): string
    {
        $prima = $f['dopo_la_fermata'] ?? null;

        return sprintf('La fine locazione di %s del %s ha scritto il conguaglio: dei giorni dopo la sua uscita %s sulla gestione %s (%s). %s Con questo passaggio la parte di quei giorni dal %s tocca %s, ma il programma non la sposta: %s. Se vanno %s, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata %s, e il programma non lo sa.',
            $f['inquilino'], self::data($f['fine_il']), self::presa((string) $f['persona'], (int) $f['coppia'], ! empty($f['piu_persone'])), $f['gestione'], $f['unita'],
            match (true) {
                $prima !== null && (int) $prima['cifra'] < 0 => sprintf('%s non ha spostato la parte di %s, un credito di %s, rimasto %s da regolare a mano.', ucfirst((string) $prima['passaggio']), $f['uscente'], MoneyHelper::format(-(int) $prima['cifra']), self::a((string) $f['persona'])),
                $prima !== null => sprintf('%s non ha spostato la parte di %s, %s, rimasta a carico di %s da regolare a mano.', ucfirst((string) $prima['passaggio']), $f['uscente'], MoneyHelper::format((int) $prima['cifra']), $f['persona']),
                (int) $f['cifra'] < 0 => sprintf('La parte di %s non è passata con la coppia, ed è rimasta %s.', $f['uscente'], self::a((string) $f['persona'])),
                default => sprintf('La parte di %s non è passata con la coppia, ed è rimasta a carico di %s.', $f['uscente'], $f['persona']),
            },
            self::data($f['dal']), self::a(self::elenco($f['verso'])),
            self::aCarico((int) $f['cifra'], '', (string) $f['persona'], oppure: $f['uscente'] . ' se ' . self::regolataPrima($f)),
            self::a(self::elenco($f['verso'])), $prima !== null ? 'la fermata ' . self::di((string) $prima['passaggio']) : 'la parte di ' . $f['uscente']);
    }

    /** Decisione 81.2: «la fermata della vendita del 1 settembre 2026 è già stata regolata», o la parte di chi esce. */
    private static function regolataPrima(array $f): string
    {
        $prima = $f['dopo_la_fermata'] ?? null;

        return $prima !== null ? sprintf('la fermata %s è già stata regolata', self::di((string) $prima['passaggio'])) : sprintf('la parte di %s è già stata regolata', $f['uscente']);
    }

    /** Il motivo del cancello: corto, con la persona e la cifra. */
    public static function motivoCancello(array $f, bool $seScrivi = false): string
    {
        $motivo = sprintf('%s (gestione %s): il conguaglio scritto alla fine locazione del %s non passa %s con questo passaggio',
            self::aCarico((int) $f['cifra'], 'dei giorni dopo l\'uscita di ' . $f['inquilino'], (string) $f['persona']), $f['gestione'], self::data($f['fine_il']), self::a(self::elenco($f['verso'])));
        if (isset($f['uscente_id'])) {
            // Decisione 81.2: come non era passato a chi esce con il passaggio fermato prima.
            $motivo .= isset($f['dopo_la_fermata']['passaggio'])
                ? sprintf(', come non era passato %s con %s', self::a((string) $f['uscente']), $f['dopo_la_fermata']['passaggio'])
                : sprintf(', come non era passato %s', self::a((string) $f['uscente']));
        }

        return $seScrivi ? 'se scrivi il conguaglio, ' . $motivo : $motivo;
    }

    /**
     * La frase dello storico, dal registro: anni dopo, «perché Ugo ha ancora quella parte?» ha una risposta. Decisione 82.2: se chi ha
     * ricevuto è uscito a sua volta con un passaggio fermato dopo (la seconda vendita), la frase dice la parte passata con quel passaggio
     * (`$passate`, da `passateDopo`), come un nuovo inquilino riduce una fermata.
     *
     * @param list<array{cifra: int, verso: list<string>, passaggio: string}> $passate
     */
    public static function fraseStorico(array $f, array $passate = []): string
    {
        $verso = self::elenco($f['verso'] ?? []) ?: 'chi è entrato';
        $frase = sprintf('Il conguaglio scritto alla fine locazione di %s del %s non è passato con questo passaggio: %s sulla gestione %s (%s), da regolare a mano con %s%s.',
            $f['inquilino'] ?? 'l\'inquilino', self::data($f['fine_il']), self::aCarico((int) $f['cifra'], 'dei giorni dal ' . self::data($f['dal']), (string) ($f['persona'] ?? '?'), passato: true),
            $f['gestione'] ?? '?', $f['unita'] ?? '', $verso,
            // Decisione 81.2: o fra chi esce e chi entra, se la fermata di prima era già stata regolata.
            isset($f['uscente_id']) ? sprintf(', o fra %s e %s se %s', $f['uscente'] ?? '?', $verso, str_replace('è già stata regolata', 'era già stata regolata', self::regolataPrima($f))) : '');
        if ($passate === []) {
            return $frase;
        }

        return rtrim($frase, '.') . sprintf('; di questi, %s.', self::elenco(array_map(fn (array $p) => sprintf('%s %s passati %s con %s',
            self::soldi((int) $p['cifra']), abs((int) $p['cifra']) === 1 ? 'è' : 'sono', self::a(self::elenco($p['verso'])), $p['passaggio']), $passate)));
    }

    /**
     * Decisione 82.2: le fermate registrate dopo una fermata, dai passaggi di chi l'aveva ricevuta (la seconda vendita dopo la prima): la
     * cifra passata, a chi e con quale passaggio. Solo i passaggi non annullati, registrati dopo, sulla stessa coppia; per ognuno la sua
     * parte (`dopo_la_fermata` del suo registro nomina questa, con le sue parole).
     *
     * @return list<array{cifra: int, verso: list<string>, passaggio: string}>
     */
    public static function passateDopo(Subentro $passaggio, array $f): array
    {
        $ricevuto = array_map(fn ($p) => (int) ($p['anagrafica_id'] ?? 0), (array) ($f['parti'] ?? []));
        $parole = self::passaggioInParole($passaggio->capo());

        return Subentro::where('immobile_id', (int) ($f['immobile_id'] ?? 0))->where('id', '>', $passaggio->id)->orderBy('id')->get()
            ->map(fn (Subentro $s) => $s->capo())->unique('id')
            ->flatMap(fn (Subentro $s) => collect($s->annullato() ? [] : ($s->registro['fermata_dopo_scrivi'] ?? []))
                ->filter(fn ($g) => isset($g['uscente_id'], $g['dopo_la_fermata']) && in_array((int) $g['uscente_id'], $ricevuto, true)
                    && (int) ($g['fine_id'] ?? 0) === (int) ($f['fine_id'] ?? 0) && (int) ($g['immobile_id'] ?? 0) === (int) ($f['immobile_id'] ?? 0)
                    && (int) ($g['gestione_id'] ?? 0) === (int) ($f['gestione_id'] ?? 0)
                    && ($g['dopo_la_fermata']['passaggio'] ?? null) === $parole)
                ->map(fn ($g) => ['cifra' => (int) $g['cifra'], 'verso' => (array) ($g['verso'] ?? []), 'passaggio' => self::passaggioInParole($s)]))
            ->values()->all();
    }

    /**
     * Decisione 83.2 (1.11.0-beta.50): la fine della fermata ha ancora il conguaglio scritto? Annullato il conguaglio (o la fine), la fermata
     * di un passaggio dopo non vale più: la coppia su cui si era fermato non c'è.
     */
    public static function fineSenzaConguaglio(array $f): bool
    {
        $fine = Subentro::conAnnullati()->find((int) ($f['fine_id'] ?? 0));

        return $fine !== null && ($fine->annullato() || $fine->conguaglioAnnullato());
    }

    /**
     * Decisione 83.2: la frase dello storico di una fermata la cui coppia è stata annullata dopo. Giro stretto della .50 (G12): con le parole
     * del registro, come `fraseStorico`: dopo una fermata (decisione 81.2) la parte è a carico di chi ha la coppia, o di chi è uscito.
     */
    public static function fraseStoricoAnnullata(array $f): string
    {
        $cifra = (int) $f['cifra'];

        return sprintf('Il conguaglio scritto alla fine locazione di %s del %s è stato annullato dopo questo passaggio: la sua fermata (%s dei giorni dal %s, rimasti %s da regolare a mano con %s) non vale più.',
            $f['inquilino'] ?? 'l\'inquilino', self::data($f['fine_il']), self::soldi($cifra), self::data($f['dal']),
            self::diChiResta($cifra, (string) ($f['persona'] ?? '?'), isset($f['uscente_id']) ? ($f['uscente'] ?? '?') . ' se ' . str_replace('è già stata regolata', 'era già stata regolata', self::regolataPrima($f)) . ',' : null),
            self::elenco($f['verso'] ?? []) ?: 'chi è entrato');
    }

    /** Decisione 83.2: la frase dello storico dell'inizio che riduceva una fermata la cui coppia è stata annullata dopo. */
    public static function fraseStoricoRidottaAnnullata(array $f): string
    {
        return sprintf('Il conguaglio scritto alla fine locazione di %s è stato annullato dopo questo inizio: la fermata %s (%s rimasti %s), che con questo inizio restava da regolare per %s, non vale più.',
            $f['inquilino'] ?? 'l\'inquilino', isset($f['passaggio']) ? self::di((string) $f['passaggio']) : 'di un passaggio di prima', self::soldi((int) ($f['cifra'] ?? 0)),
            self::diChiResta((int) ($f['cifra'] ?? 0), (string) ($f['persona'] ?? '?')), self::cifraInParole((int) ($f['residuo'] ?? 0)));
    }

    /**
     * Decisione 83.2 (1.11.0-beta.50): gli avvisi di «Annulla il conguaglio» di una fine «Scrivi», detti prima di confermare e nel messaggio. Un
     * passaggio registrato dopo si è fermato su questa coppia (la vendita, la costituzione, la successione…), e la sua fermata, con la strada a
     * mano che indicava (il saldo manuale dal Wallet), non vale più. Un avviso, non un rifiuto: il conguaglio resta annullabile.
     *
     * @return list<string>
     */
    public static function avvisiDelConguaglio(Subentro $fine): array
    {
        $capo = $fine->capo();
        if ($capo->tipo_passaggio !== 'fine_locazione' || $capo->conguaglioAnnullato()) {
            return [];
        }
        $avvisi = [];
        foreach (Subentro::where('condominio_id', $capo->condominio_id)->where('id', '>', $capo->id)->whereNull('subentro_padre_id')->orderBy('id')->get() as $s) {
            foreach ($s->registro['fermata_dopo_scrivi'] ?? [] as $f) {
                if ((int) ($f['fine_id'] ?? 0) !== (int) $capo->id) {
                    continue;
                }
                $verso = self::elenco($f['verso'] ?? []) ?: 'chi è entrato';
                $parole = self::passaggioInParole($s);
                // Giro stretto della .50 (G12): l'avviso legge il registro della fermata come il pannello e lo storico. Dopo una fermata
                // (decisione 81.2) la parte è a carico di chi ha la coppia, o di chi è uscito; il saldo manuale dal Wallet si nomina solo se il
                // pannello lo aveva indicato (decisione 80), altrimenti è l'accordo fra le parti da rivedere.
                $avvisi[] = sprintf('%s si è %s su questo conguaglio: %s sulla gestione %s, da regolare a mano con %s. Annullato il conguaglio, quella fermata non vale più: %s',
                    ucfirst($parole), str_starts_with($parole, 'il ') ? 'fermato' : 'fermata',
                    self::aCarico((int) $f['cifra'], 'dei giorni dal ' . self::data($f['dal']), (string) ($f['persona'] ?? '?'), passato: true,
                        oppure: isset($f['uscente_id']) ? ($f['uscente'] ?? '?') . ' se ' . str_replace('è già stata regolata', 'era già stata regolata', self::regolataPrima($f)) : null),
                    $f['gestione'] ?? '?', $verso,
                    ($f['wallet'] ?? true)
                        ? sprintf('se per questo hai registrato un saldo manuale dal Wallet (%s), rivedilo, perché l\'annullamento non lo toglie.', self::rigaDelWallet($f, (string) ($f['persona'] ?? '?')))
                        : 'se le parti l\'hanno regolata fra loro, quell\'accordo va rivisto.');
            }
        }

        return $avvisi;
    }

    /**
     * Fase 1-bis della .50 (R12): la frase dello storico della fermata del coinquilino (decisione 79, punto 2), dal registro della fine. Ha
     * chiesto la spunta: la scelta lascia traccia, come la fermata dopo «Scrivi».
     */
    public static function fraseStoricoCoinquilino(array $f): string
    {
        $restano = self::elenco((array) ($f['restano'] ?? [])) ?: 'un coinquilino';
        $frase = sprintf('La locazione continuava con %s: il conguaglio dei giorni dopo l\'uscita di %s non c\'è, e %s sulla gestione %s (%s) sono rimasti nelle rate di %s',
            $restano, $f['persona'] ?? '?', self::soldi((int) ($f['cifra'] ?? 0)), $f['gestione'] ?? '?', $f['unita'] ?? '', $f['persona'] ?? '?');
        // Giro stretto della .50 (G14): la strada che il pannello aveva indicato, dal registro (decisione 80): il saldo manuale dal Wallet per
        // chi non aveva già un saldo, fra le parti per chi lo aveva. Un registro senza i candidati, o senza il Wallet, dice la frase di prima.
        if (! isset($f['al_posto']) || empty($f['wallet'])) {
            return $frase . ', da regolare a mano fra le parti con chi li paga.';
        }
        $conUnSaldo = (array) ($f['con_un_saldo'] ?? []);
        $restanoNomi = (array) ($f['restano'] ?? []);
        $alPosto = (array) $f['al_posto'];
        $seLiPagava = function (array $coinquilini, array $altri): string {
            $chi = array_values(array_filter([self::elenco($coinquilini), self::elenco($altri)], fn (string $x) => $x !== ''));

            return sprintf('se %s %s', count($coinquilini) > 1 || ($coinquilini === [] && count($altri) > 1) ? 'li pagavano' : 'li pagava', implode(', o ', $chi));
        };
        $conWallet = [array_values(array_diff($restanoNomi, $conUnSaldo)), array_values(array_diff($alPosto, $conUnSaldo))];
        $parti = [$seLiPagava($conWallet[0], $conWallet[1]) . sprintf(', la strada indicata era il saldo manuale dal Wallet sulla stessa gestione: una riga a credito di %s e una a debito di %s',
            $f['persona'] ?? '?', count($conWallet[0]) > 1 || count($conWallet[1]) > 1 ? 'ciascuno che li pagava' : 'chi li pagava')];
        $senza = [array_values(array_intersect($restanoNomi, $conUnSaldo)), array_values(array_intersect($alPosto, $conUnSaldo))];
        if ($senza[0] !== [] || $senza[1] !== []) {
            $parti[] = $seLiPagava($senza[0], $senza[1]) . ', fra le parti, perché già c\'era un saldo';
        }

        return $frase . ': ' . implode('; ', $parti) . '.';
    }

    /** La frase del rientro: un'informazione, con l'alternativa del rinnovo (annullare la fine, l'ultimo passaggio, e rifarla). */
    public static function fraseRientro(array $f): string
    {
        $chi = self::elenco($f['pagato_da'] ?? []) ?: 'chi paga al posto dell\'inquilino';
        $piu = count($f['pagato_da'] ?? []) > 1;
        // Giro stretto della .50 (G1): con una parte negativa (un'entrata che nei giorni di chi rientra supera la spesa) è chi ha la coppia
        // che restituisce.
        $frase = (int) $f['cifra'] < 0
            ? sprintf('%s rientra dal %s dopo la sua fine locazione del %s, che ha scritto il conguaglio: nei giorni dal %s, sulla gestione %s (%s), l\'entrata supera la spesa, e con la coppia %s %s anche il credito di quei giorni, %s, che %s %s.',
                $f['persona'], self::data($f['dal']), self::data($f['fine_il']), self::data($f['dal']), $f['gestione'], $f['unita'], $chi, $piu ? 'hanno avuto' : 'ha avuto',
                MoneyHelper::format(-(int) $f['cifra']), $piu ? 'restituiscono' : 'restituisce', self::a((string) $f['persona']))
            : sprintf('%s rientra dal %s dopo la sua fine locazione del %s, che ha scritto il conguaglio: %s dei giorni dal %s, sulla gestione %s (%s), %s %s, e %s glieli rimborsa con il prospetto degli oneri accessori.',
                $f['persona'], self::data($f['dal']), self::data($f['fine_il']), MoneyHelper::format((int) $f['cifra']), self::data($f['dal']), $f['gestione'], $f['unita'],
                $piu ? 'li hanno presi' : 'li ha presi', $chi, $f['persona']);

        return ! empty($f['annullabile'])
            ? $frase . sprintf(' Se è un rinnovo, puoi invece annullare dallo storico dell\'unità la fine locazione del %s, che è l\'ultimo passaggio, e registrarla di nuovo con «Non scriverlo»: i giorni dopo l\'uscita restano nelle rate di %s.',
                self::data($f['fine_il']), $f['persona'])
            : $frase;
    }

    /** La frase del pannello dopo una fermata: quanto ne resta da regolare con l'inizio di questa locazione. */
    public static function fraseRidotta(array $f): string
    {
        $verso = self::elenco($f['verso']);
        $resta = match (true) {
            count($f['parti']) === 0 => 'non resta niente da regolare fra le parti',
            count($f['parti']) === 1 => sprintf('resta da regolare %s con %s', self::cifraInParole((int) $f['residuo']), $f['parti'][0]['nome']),
            (int) $f['residuo'] < 0 => sprintf('resta da regolare un credito di %s: %s', MoneyHelper::format(abs((int) $f['residuo'])),
                self::elenco(array_map(fn (array $p) => sprintf('%s con %s', MoneyHelper::format(abs((int) $p['cifra'])), $p['nome']), $f['parti']))),
            default => sprintf('restano da regolare %s: %s', MoneyHelper::format((int) $f['residuo']),
                self::elenco(array_map(fn (array $p) => sprintf('%s con %s', MoneyHelper::format((int) $p['cifra']), $p['nome']), $f['parti']))),
        };

        $prima = (int) ($f['prima'] ?? $f['cifra']);
        // Giro stretto della .50 (G1): con una fermata negativa (un credito di chi ha ricevuto, rimasto a chi ha la coppia) il verso della
        // restituzione è l'altro: se le parti hanno già regolato il credito, è chi l'ha ricevuto che restituisce la differenza.
        $credito = (int) $f['cifra'] < 0;

        return sprintf('%s ha lasciato %s %s%s dei giorni dopo l\'uscita di %s, da regolare a mano con %s%s. Con questo inizio %s: %s. Se %s e %s hanno già regolato %s, %s vanno restituiti %s.',
            ucfirst((string) $f['passaggio']), $credito ? self::a((string) $f['persona']) : 'a carico di ' . $f['persona'], MoneyHelper::format(abs((int) $f['cifra'])), $credito ? ' di credito' : '', $f['inquilino'], $verso,
            $prima !== (int) $f['cifra'] ? sprintf(' (con le locazioni registrate da allora, %s)', MoneyHelper::format(abs($prima))) : '',
            self::giorniDelConduttore($f), $resta, $f['persona'], $verso, MoneyHelper::format(abs($prima)), MoneyHelper::format(abs($prima - (int) $f['residuo'])),
            $credito ? self::a((string) $f['persona']) : self::a($verso));
    }

    /** Il motivo del cancello dopo una fermata: la cifra che resta da regolare, al posto di quella detta dal passaggio. */
    public static function motivoRidotta(array $f): string
    {
        // Giro stretto della .50 (G1): una fermata negativa è un credito, e si dice con le parole.
        return (int) $f['cifra'] < 0
            ? sprintf('della fermata %s resta da regolare fra %s e %s un credito di %s, non più di %s: %s',
                self::di((string) $f['passaggio']), $f['persona'], self::elenco($f['verso']), MoneyHelper::format(abs((int) $f['residuo'])), MoneyHelper::format(abs((int) ($f['prima'] ?? $f['cifra']))),
                self::giorniDelConduttore($f))
            : sprintf('della fermata %s restano da regolare fra %s e %s %s, non più %s: %s',
                self::di((string) $f['passaggio']), $f['persona'], self::elenco($f['verso']), MoneyHelper::format((int) $f['residuo']), MoneyHelper::format((int) ($f['prima'] ?? $f['cifra'])),
                self::giorniDelConduttore($f));
    }

    /** La frase dello storico dell'inizio dopo una fermata, dal registro. */
    public static function fraseStoricoRidotta(array $f): string
    {
        $credito = (int) ($f['cifra'] ?? 0) < 0;

        return sprintf('Con questo inizio, della fermata %s (%s rimasti %s) %s fra %s e %s %s: %s.',
            isset($f['passaggio']) ? self::di((string) $f['passaggio']) : 'di un passaggio di prima', self::soldi((int) ($f['cifra'] ?? 0)), self::diChiResta((int) ($f['cifra'] ?? 0), (string) ($f['persona'] ?? '?')),
            $credito ? 'resta da regolare' : 'restano da regolare', $f['persona'] ?? '?', self::elenco($f['verso'] ?? []) ?: 'chi è entrato',
            $credito ? 'un credito di ' . MoneyHelper::format(abs((int) ($f['residuo'] ?? 0))) : MoneyHelper::format((int) ($f['residuo'] ?? 0)), self::giorniDelConduttore($f));
    }

    /**
     * Ultimo giro della .50 (punto 2): i giorni del nuovo conduttore dopo una fermata, nelle tre frasi della fermata ridotta, con il verso
     * della sua parte, che è quanto toglie dalla fermata (`prima − residuo`). A debito li rimborsa con il prospetto degli oneri accessori;
     * a credito (un'entrata che nei suoi giorni supera la spesa) il prospetto glielo dà, e la frase non dice che rimborsa.
     */
    private static function giorniDelConduttore(array $f): string
    {
        $conduttore = (string) ($f['conduttore'] ?? 'il conduttore');

        return (int) ($f['prima'] ?? $f['cifra'] ?? 0) - (int) ($f['residuo'] ?? 0) < 0
            ? sprintf('il credito dei giorni dal %s lo riceve %s con il prospetto degli oneri accessori', self::data((string) $f['dal']), $conduttore)
            : sprintf('i giorni dal %s li rimborsa %s con il prospetto degli oneri accessori', self::data((string) $f['dal']), $conduttore);
    }

    // --- Il calcolo --------------------------------------------------------------------------------------------------------------

    /**
     * Le coppie delle fini «Scrivi» in cui la persona ha una riga, dalla parte di chi paga al posto dell'inquilino (`$alPosto`) o
     * dell'inquilino uscito: per gestione, unità ed esercizio, con il totale della coppia e i suoi giorni dopo l'uscita. Giro stretto della
     * .50 (G2): con i pezzi con il segno una riga di chi paga al posto dell'inquilino può essere a credito (un'entrata), e l'inquilino uscito
     * può avere una riga a debito: la parte si riconosce da chi è uscito con la fine, non dal segno della riga.
     *
     * @param list<int> $esclusi
     * @return list<array{fine: Subentro, saldo_id: int, riga: int, totale: int, righe: array<int, int>, immobile_id: int, unita: string, gestione_id: int, gestione: string, esercizio_id: ?int, assorbita: bool, pezzi: list<array{tratti: InsiemePeriodi, importo: int}>}>
     */
    private function coppieScritte(int $anagraficaId, array $immobileIds, bool $alPosto, array $esclusi = []): array
    {
        $righe = Saldo::with('gestione')->where('anagrafica_id', $anagraficaId)->whereIn('immobile_id', $immobileIds)->whereNotNull('subentro_id')
            ->where('saldo_iniziale', '!=', 0)->whereNotIn('id', $esclusi ?: [0])->orderBy('id')->get();
        $out = [];
        // Una coppia per gruppo: chi esce ha una riga per ciascuna controparte, e le righe della stessa persona si sommano.
        foreach ($righe->groupBy(fn (Saldo $r) => $r->subentro_id . '|' . $r->immobile_id . '|' . $r->gestione_id . '|' . ($r->esercizio_id ?? '')) as $gruppo) {
            $r = $gruppo->first();
            $fine = Subentro::with('padre')->find((int) $r->subentro_id)?->capo();
            if ($fine === null || ! self::fineScritta($fine) || ((int) $fine->anagrafica_uscente_id === $anagraficaId) === $alPosto) {
                continue;
            }
            $c = $this->coppia($fine, (int) $r->subentro_id, (int) $r->immobile_id, (int) $r->gestione_id, $r->esercizio_id !== null ? (int) $r->esercizio_id : null);
            if ($c !== null) {
                $out[] = $c + ['saldo_id' => (int) $r->id, 'riga' => (int) $gruppo->sum('saldo_iniziale'), 'assorbita' => $gruppo->contains(fn (Saldo $x) => (bool) $x->is_applicato)];
            }
        }

        return $out;
    }

    /** La coppia di una fermata registrata, se la fine c'è ancora con le sue righe. */
    private function coppiaDellaFermata(array $f): ?array
    {
        $fine = Subentro::find((int) ($f['fine_id'] ?? 0));
        if ($fine === null || ! self::fineScritta($fine)) {
            return null;
        }
        // Le righe della coppia su quell'unità e gestione: chi resta a carico può non averne una sua (l'erede, decisione 81.2).
        $subentroId = (int) (Saldo::where('immobile_id', (int) $f['immobile_id'])->where('gestione_id', (int) $f['gestione_id'])->where('saldo_iniziale', '!=', 0)
            ->whereIn('subentro_id', Subentro::where('id', $fine->id)->orWhere('subentro_padre_id', $fine->id)->pluck('id'))->value('subentro_id') ?? 0);

        return $subentroId === 0 ? null : $this->coppia($fine, $subentroId, (int) $f['immobile_id'], (int) $f['gestione_id'], isset($f['esercizio_id']) ? (int) $f['esercizio_id'] : null);
    }

    /** Una fine locazione senza un nuovo inquilino, con «Scrivi il conguaglio», non annullata e con il conguaglio in piedi. */
    private static function fineScritta(Subentro $fine): bool
    {
        return $fine->tipo_passaggio === 'fine_locazione' && ! $fine->annullato() && ! $fine->conguaglioAnnullato()
            && $fine->anagrafica_entrante_id === null && empty($fine->registro['nomi']['entrante'] ?? null)
            && ($fine->registro['conguaglio']['scelta'] ?? null) === Subentro::CONGUAGLIO_SCRITTO;
    }

    /**
     * La coppia della fine su una gestione, un'unità e un esercizio: il totale a debito di chi paga al posto dell'inquilino (le righe di
     * tutte le persone) e i suoi giorni, pezzo per pezzo, dal registro della fine (`conguaglio.periodi`, decisione 77). Senza i periodi,
     * i giorni dall'uscita alla fine della gestione.
     *
     * Giro stretto della .50 (G2): con i pezzi con il segno le parti possono avere segno diverso (Ugo a credito, Elsa a debito), e la riga
     * dell'inquilino uscito contro chi è a credito è a debito. Le righe sono quelle di tutte le persone tranne chi è uscito, con il segno, e
     * il totale è la loro somma netta.
     *
     * Chiusura della .50 (punto 1): anche una coppia tutta a credito di chi sta al posto dell'inquilino (un'entrata che nei giorni dopo
     * l'uscita supera la spesa: Ugo ha € 118,52 di credito, Ines di debito) è una coppia, con il totale negativo. La divisione lo regge
     * (`pesi` cambia il segno dei pesi con la somma negativa, `ChiPagaAlPostoDellInquilino::dividi` lo rimette dal totale): la fermata, il
     * rientro, la parte di chi entra e la fermata ridotta dicono la cifra con il suo verso. Prima della chiusura la coppia restituiva null, e
     * con lei sparivano la fermata (€ 209,26 restavano a Ugo in silenzio) e il verso della frase del blocco «Rate». Resta fuori solo la
     * coppia a zero.
     */
    private function coppia(Subentro $fine, int $subentroId, int $immobileId, int $gestioneId, ?int $esercizioId): ?array
    {
        $righe = Saldo::with('gestione')->where('subentro_id', $subentroId)->where('immobile_id', $immobileId)->where('gestione_id', $gestioneId)
            ->when($esercizioId !== null, fn ($q) => $q->where('esercizio_id', $esercizioId))->where('saldo_iniziale', '!=', 0)
            ->where('anagrafica_id', '!=', (int) $fine->anagrafica_uscente_id)->get();
        $totale = (int) $righe->sum('saldo_iniziale');
        if ($totale === 0) {
            return null;
        }
        $voce = collect($fine->registro['conguaglio']['periodi'] ?? [])->first(fn (array $v) => (int) $v['gestione_id'] === $gestioneId && (int) $v['immobile_id'] === $immobileId
            && ($v['esercizio_id'] ?? null) === $esercizioId);
        $pezzi = array_values(array_filter(array_map(fn (array $p) => ($p['tratti'] ?? []) === [] ? null : [
            'tratti' => new InsiemePeriodi(...array_map(fn (array $t) => new PeriodoCompetenza($t['dal'], $t['al']), $p['tratti'])),
            'importo' => (int) $p['importo'],
        ], $voce['periodi'] ?? [])));
        if ($pezzi === []) {
            $dal = CarbonImmutable::parse($fine->decorrenza->toDateString());
            $fino = $righe->first()?->gestione?->data_fine;
            $al = $fino !== null ? CarbonImmutable::parse(substr((string) $fino, 0, 10)) : $dal->endOfYear();
            if ($al->lt($dal)) {
                return null;
            }
            $pezzi = [['tratti' => InsiemePeriodi::uno(new PeriodoCompetenza($dal, $al)), 'importo' => $totale]];
        }
        $unita = Immobile::find($immobileId);

        return [
            'fine' => $fine, 'totale' => $totale, 'pezzi' => $pezzi,
            'righe' => $righe->groupBy(fn (Saldo $r) => (int) $r->anagrafica_id)->map(fn ($g) => (int) $g->sum('saldo_iniziale'))->all(),
            'saldi' => $righe->groupBy(fn (Saldo $r) => (int) $r->anagrafica_id)->map(fn ($g) => $g->pluck('id')->map(fn ($id) => (int) $id)->all())->all(),
            'immobile_id' => $immobileId, 'unita' => $unita === null ? '' : (trim((string) $unita->nome) !== '' ? (string) $unita->nome : (string) $unita->etichetta),
            'gestione_id' => $gestioneId, 'gestione' => (string) ($righe->first()?->gestione?->nome ?? '?'), 'esercizio_id' => $esercizioId,
        ];
    }

    /**
     * I pesi della coppia sui suoi giorni: i conduttori registrati per i giorni che coprono (chiave `c` e l'anagrafica: rimborsano con il
     * prospetto), chi paga al posto dell'inquilino per gli altri, con la regola della coppia (`ChiPagaAlPostoDellInquilino::pesi`). Con più
     * pezzi ognuno pesa con il suo importo, con il segno, come `ChiPagaAlPostoDellInquilino::dividiLaCoppia`.
     *
     * @param Collection<int, object> $righe
     * @param list<array{tratti: InsiemePeriodi, importo: int}> $pezzi
     * @return array<int|string, float>
     */
    private function pesi(Collection $righe, array $pezzi): array
    {
        $inquilini = $righe->filter(fn ($r) => ($r->tipologia ?? null) === 'inquilino' && (bool) ($r->attivo ?? false))->values();
        $pesi = [];
        foreach ($pezzi as $pezzo) {
            $anello = [];
            foreach ($inquilini as $r) {
                $g = (float) ($r->quota ?? 0) > 0.0 ? $this->titolari->giorniDiTitolarita($r, $pezzo['tratti'], $inquilini) : 0;
                if ($g > 0) {
                    $chiave = self::CONDUTTORE . (int) $r->anagrafica_id;
                    $anello[$chiave] = ($anello[$chiave] ?? 0.0) + (float) $r->quota * $g;
                }
            }
            $scoperti = $anello === [] ? $pezzo['tratti'] : $this->titolari->giorniScoperti($inquilini, $pezzo['tratti']);
            $coperti = $pezzo['tratti']->giorni() - ($scoperti?->giorni() ?? 0);
            $somma = array_sum($anello);
            $suoi = array_map(fn (float $p) => $p / $somma * $coperti, $anello);
            foreach ($scoperti === null ? [] : $this->chiPaga->pesi($righe, $scoperti) as $id => $p) {
                $suoi[$id] = ($suoi[$id] ?? 0.0) + $p;
            }
            if (count($pezzi) === 1) {
                return $suoi;
            }
            $tot = array_sum($suoi);
            foreach ($suoi as $k => $p) {
                $pesi[$k] = ($pesi[$k] ?? 0.0) + ($tot > 0 ? (int) $pezzo['importo'] * $p / $tot : 0.0);
            }
        }

        // Fase 1-bis della .50 (R3): ogni pezzo pesa con il suo importo, con il segno (un capitolo di entrata toglie), come la coppia
        // (`ChiPagaAlPostoDellInquilino::dividiLaCoppia`). Con la somma negativa i pesi cambiano segno: la divisione lo rimette dal totale.
        return array_sum($pesi) < 0 ? array_map(fn (float $p) => -$p, $pesi) : $pesi;
    }

    /**
     * Le righe dell'unità come le lascerebbe il passaggio, registrato (la forma di `RegistraSubentroAction::applicaRighe` per ciò che conta
     * al posto dell'inquilino): le righe di chi esce nel suo ruolo chiuse il giorno prima, quelle di chi entra dal giorno del passaggio.
     *
     * @param Collection<int, object> $righe
     * @param list<array{anagrafica: Anagrafica, quota: float}> $eredi
     * @param list<int> $nudiIds
     * @return Collection<int, object>
     */
    private function conIlPassaggio(Collection $righe, int $immobileId, string $tipo, ?string $sottotipo, TitolaritaImmobile $uscente, ?Anagrafica $entrante, array $eredi,
        array $nudiIds, bool $accrescimento, CarbonImmutable $decorrenza): Collection
    {
        $chi = (int) $uscente->anagrafica_id;
        $ruolo = (string) $uscente->tipologia;
        $chiuse = [];
        $nuove = [];
        $apri = function (int $anagraficaId, string $tipologia, float $quota, mixed $fino) use (&$nuove, $immobileId, $decorrenza): void {
            if ($quota > 0.0) {
                array_push($nuove, ...$this->rigaNuova($immobileId, $anagraficaId, $tipologia, $quota, $decorrenza, $fino));
            }
        };
        // La riga che il passaggio chiude: sull'unità principale quella del modulo; sulla pertinenza quella che la registrazione chiude
        // davvero (decisione 81.3, `rigaDellaPertinenza`). Le altre righe della stessa persona restano.
        $giornoPrima = $decorrenza->subDay();
        $rigaQui = $immobileId === (int) $uscente->immobile_id ? (int) $uscente->id : self::rigaDellaPertinenza($immobileId, $chi, $ruolo, $giornoPrima);
        $sue = $righe->filter(fn ($r) => $rigaQui !== null && (int) $r->id === $rigaQui);
        foreach ($sue as $r) {
            $chiuse[(int) $r->id] = true;
            $quota = (float) $r->quota;
            if ($tipo === 'vendita' && $sottotipo === Subentro::RISERVA_USUFRUTTO) {
                $apri($chi, 'usufruttuario', $quota, $r->data_fine);
                $entrante && $apri((int) $entrante->id, 'nuda_proprietario', $quota, $r->data_fine);
            } elseif ($tipo === 'vendita') {
                $entrante && $apri((int) $entrante->id, $ruolo, $quota, $r->data_fine);
            } elseif ($tipo === 'usufrutto' && $sottotipo === 'costituzione') {
                $apri($chi, 'nuda_proprietario', $quota, $r->data_fine);
                $entrante && $apri((int) $entrante->id, 'usufruttuario', $quota, $r->data_fine);
            } elseif ($tipo === 'usufrutto' && ! $accrescimento) {
                // L'estinzione: i nudi che tornano pieni, ciascuno con la sua quota. Con l'accrescimento restano gli altri usufruttuari.
                foreach ($righe->filter(fn ($n) => $n->tipologia === 'nuda_proprietario' && in_array((int) $n->anagrafica_id, $nudiIds, true) && self::inCorso($n, $giornoPrima)) as $n) {
                    $chiuse[(int) $n->id] = true;
                    $apri((int) $n->anagrafica_id, 'proprietario', (float) $n->quota, $n->data_fine);
                }
            } elseif ($tipo === 'usufrutto') {
                // Decisione 81.1: l'estinzione con l'accrescimento. L'usufrutto di chi esce va agli usufruttuari che restano, in proporzione
                // alla loro quota, in centesimi di punto con i resti maggiori, come `RegistraSubentroAction::applicaRighe`; ognuno somma la sua
                // parte dal giorno del passaggio. Il peso di chi esce cala, quello degli altri usufruttuari sale: è una fermata come le altre.
                $restano = $righe->filter(fn ($u) => $u->tipologia === 'usufruttuario' && (int) $u->id !== (int) $r->id && (int) $u->anagrafica_id !== $chi
                    && self::inCorso($u, $decorrenza) && self::inCorso($u, $giornoPrima))->values();
                $parti = $restano->isEmpty() ? [] : MoneyHelper::ripartisciPerQuote((int) round($quota * 100), $restano->mapWithKeys(fn ($u) => [(int) $u->id => (float) $u->quota])->all());
                foreach ($restano as $u) {
                    $apri((int) $u->anagrafica_id, 'usufruttuario', ((int) ($parti[(int) $u->id] ?? 0)) / 100, $r->data_fine);
                }
            } elseif ($tipo === 'successione') {
                // Gli eredi per la loro quota della parte del defunto, come `RegistraSubentroAction::quoteDegliEredi`.
                $somma = array_sum(array_map(fn ($e) => (float) $e['quota'], $eredi));
                foreach ($somma > 0 ? $eredi : [] as $e) {
                    $apri((int) $e['anagrafica']->id, $ruolo, $quota * (float) $e['quota'] / $somma, $r->data_fine);
                }
            } else {
                unset($chiuse[(int) $r->id]);
            }
        }

        return $righe->map(fn ($r) => isset($chiuse[(int) $r->id]) ? (object) array_replace((array) $r, ['data_fine' => $giornoPrima->toDateString()]) : $r)
            ->concat($nuove)->values();
    }

    /**
     * Le righe dell'unità con un nuovo conduttore dal giorno dell'inizio, come le lascerebbe l'inizio della locazione registrato.
     *
     * @param Collection<int, object> $righe
     * @return Collection<int, object>
     */
    private function conIlConduttore(Collection $righe, int $immobileId, int $anagraficaId, float $quota, CarbonImmutable $decorrenza): Collection
    {
        return $righe->concat($this->rigaNuova($immobileId, $anagraficaId, 'inquilino', $quota, $decorrenza, null))->values();
    }

    /**
     * Una riga che il passaggio aprirebbe, dal suo giorno. Registrata, la sua decorrenza conterebbe perché è entrata con un passaggio (D7,
     * `RisolutoreTitolari::haPredecessoreChiuso`, forma b); qui la stessa regola la dà la forma a: una riga della stessa tipologia, senza
     * quota, chiusa il giorno prima, che non pesa e non copre giorni.
     *
     * @return list<object>
     */
    private function rigaNuova(int $immobileId, int $anagraficaId, string $tipologia, float $quota, CarbonImmutable $dal, mixed $fino): array
    {
        $forma = ['immobile_id' => $immobileId, 'tipologia' => $tipologia, 'attivo' => true];

        return [
            (object) (['id' => $this->prossimoId--, 'anagrafica_id' => $anagraficaId, 'quota' => $quota, 'data_inizio' => $dal->toDateString(),
                'data_fine' => $fino === null ? null : substr((string) $fino, 0, 10)] + $forma),
            (object) (['id' => $this->prossimoId--, 'anagrafica_id' => 0, 'quota' => 0.0, 'data_inizio' => null, 'data_fine' => $dal->subDay()->toDateString()] + $forma),
        ];
    }

    /**
     * Decisione 81.3: sulla pertinenza la riga di chi esce che la registrazione chiude davvero (`RegistraSubentroAction::applicaAllaPertinenza`):
     * la prima nello stesso ruolo in corso il giorno prima e non chiusa da un passaggio, anche a quota zero. Con una riga a quota zero prima
     * di quella vera (un dato anomalo, Coda 246) la registrazione chiude quella, il peso di chi esce non cala, e il pannello non annuncia una
     * fermata che non succede.
     */
    private static function rigaDellaPertinenza(int $immobileId, int $anagraficaId, string $ruolo, CarbonImmutable $giornoPrima): ?int
    {
        $riga = TitolaritaImmobile::where('immobile_id', $immobileId)->where('anagrafica_id', $anagraficaId)->where('tipologia', $ruolo)->orderBy('id')->get()
            ->first(fn (TitolaritaImmobile $t) => $t->inCorsoIl($giornoPrima) && $t->passaggioCheLaChiude() === null);

        return $riga?->id !== null ? (int) $riga->id : null;
    }

    /** La riga è in corso il giorno dato (le date come le legge `TitolaritaImmobile::inCorsoIl`). */
    private static function inCorso(object $r, CarbonImmutable $giorno): bool
    {
        $g = $giorno->toDateString();
        $dal = $r->data_inizio !== null ? substr((string) $r->data_inizio, 0, 10) : null;
        $al = $r->data_fine !== null ? substr((string) $r->data_fine, 0, 10) : null;

        return (bool) ($r->attivo ?? false) && (float) ($r->quota ?? 0) > 0.0 && ($dal === null || $dal <= $g) && ($al === null || $al >= $g);
    }

    /**
     * Decisione 80: le persone che hanno già un saldo sulla gestione, sull'unità e sull'esercizio (le righe dei passaggi escluse).
     *
     * @param list<int> $persone
     * @return list<int>
     */
    public static function conUnSaldo(array $persone, int $gestioneId, int $immobileId, ?int $esercizioId): array
    {
        return Saldo::whereIn('anagrafica_id', $persone ?: [0])->where('gestione_id', $gestioneId)->where('immobile_id', $immobileId)->whereNull('subentro_id')
            ->when($esercizioId !== null, fn ($q) => $q->where('esercizio_id', $esercizioId))->distinct()->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    // --- Le parole ---------------------------------------------------------------------------------------------------------------

    /** Decisione 80: la strada a mano, con il verso delle righe del Wallet, o fra le parti con chi ha già un saldo. */
    private static function stradaAMano(array $f): string
    {
        $verso = self::elenco($f['verso']);
        if ($f['wallet'] ?? true) {
            // Giro stretto della .50 (G1): ogni riga con il suo verso. Con una parte negativa (un credito di chi entra) la riga di chi riceve è a
            // credito, e quella di chi resta a carico a debito.
            $debiti = array_map(fn (array $p) => sprintf('%s a %s di %s', MoneyHelper::format(abs((int) $p['cifra'])), (int) $p['cifra'] < 0 ? 'credito' : 'debito', $p['nome']), $f['parti'] ?? []);

            return sprintf('Se vanno %s, registra un saldo manuale dal Wallet sulla stessa gestione: %s a %s di %s%s %s.', self::a($verso), MoneyHelper::format(abs((int) $f['cifra'])),
                (int) $f['cifra'] < 0 ? 'debito' : 'credito', $f['persona'], count($debiti) > 1 ? ',' : ' e', self::elenco($debiti));
        }
        $nomi = Anagrafica::whereIn('id', $f['con_un_saldo'] ?? [])->pluck('nome')->map(fn ($n) => (string) $n)->values()->all();

        return sprintf('Se vanno %s, la parte si regola fra le parti: %s già un saldo su quella gestione e quell\'unità, e il programma non ne registra un secondo.',
            self::a($verso), $nomi === [] ? 'una delle persone ha' : self::elenco($nomi) . (count($nomi) > 1 ? ' hanno' : ' ha'));
    }

    /**
     * Giro stretto della .50 (G1): una cifra si dice con le parole, mai con il segno meno. «€ 90,74 restano a carico di Ugo», e per una parte
     * negativa (un'entrata che nei giorni di chi entra supera la spesa: un credito che resta a chi ha la coppia invece di passare a chi
     * entra) «€ 29,26 di credito restano a Ugo». Con `$oppure` l'alternativa della decisione 81.2: «…, o di Elsa se …».
     */
    private static function aCarico(int $cifra, string $diQuali, string $chi, bool $passato = false, ?string $oppure = null): string
    {
        $verbo = $passato ? 'sono rimasti' : 'restano';
        $soldi = self::soldi($cifra) . ($diQuali !== '' ? ' ' . $diQuali : '');

        return sprintf('%s %s %s', $soldi, $verbo, self::diChiResta($cifra, $chi, $oppure));
    }

    /** «a carico di Ugo» per una parte, «a Ugo» per un credito; con l'alternativa della decisione 81.2. */
    private static function diChiResta(int $cifra, string $chi, ?string $oppure = null): string
    {
        return $cifra < 0
            ? self::a($chi) . ($oppure !== null ? ', o ' . self::a($oppure) : '')
            : 'a carico di ' . $chi . ($oppure !== null ? ', o di ' . $oppure : '');
    }

    /** «Ugo ha preso € 181,48», o per una coppia a credito «Ugo ha avuto un credito di € 29,26». */
    private static function presa(string $chi, int $cifra, bool $piu = false): string
    {
        return $cifra < 0
            ? sprintf('%s %s un credito di %s', $chi, $piu ? 'hanno avuto' : 'ha avuto', MoneyHelper::format(-$cifra))
            : sprintf('%s %s %s', $chi, $piu ? 'hanno preso' : 'ha preso', MoneyHelper::format($cifra));
    }

    /** «€ 90,74», o per una cifra negativa «€ 29,26 di credito»: per le frasi con il verbo al plurale («… sono rimasti»). */
    private static function soldi(int $cifra): string
    {
        return MoneyHelper::format(abs($cifra)) . ($cifra < 0 ? ' di credito' : '');
    }

    /** «€ 19,40», o per una cifra negativa «un credito di € 19,40». */
    private static function cifraInParole(int $cifra): string
    {
        return $cifra < 0 ? 'un credito di ' . MoneyHelper::format(-$cifra) : MoneyHelper::format($cifra);
    }

    /**
     * Gli avvisi dell'annullamento del passaggio con le parole della fermata (`AnnullaPassaggioAction::avvisi`): «€ 90,74 dei giorni dopo
     * l'uscita di Ines sono rimasti a carico di Ugo sulla gestione …», o per un credito «€ 29,26 di credito … sono rimasti a Ugo …».
     */
    public static function rimastiDellaFermata(array $f): string
    {
        return sprintf('%s sulla gestione %s', self::aCarico((int) ($f['cifra'] ?? 0), 'dei giorni dopo l\'uscita di ' . ($f['inquilino'] ?? 'l\'inquilino'), (string) ($f['persona'] ?? 'chi esce'), passato: true),
            $f['gestione'] ?? '?');
    }

    /**
     * La riga del Wallet che la fermata indicava, con il verso della cifra: «€ 90,74 a credito di Ugo, a debito di Elsa», o per un credito di chi
     * è entrato «€ 29,26 a debito di Ugo, a credito di Elsa». `$chiResta`: chi è rimasto a carico, con l'alternativa se c'è.
     */
    public static function rigaDelWallet(array $f, string $chiResta): string
    {
        $credito = (int) ($f['cifra'] ?? 0) < 0;

        return sprintf('%s a %s di %s, a %s di %s', MoneyHelper::format(abs((int) ($f['cifra'] ?? 0))), $credito ? 'debito' : 'credito', $chiResta, $credito ? 'credito' : 'debito',
            self::elenco($f['verso'] ?? []) ?: 'chi è entrato');
    }

    /** «la vendita del 1 ottobre 2026», per le frasi dei passaggi di dopo. */
    private static function passaggioInParole(Subentro $s): string
    {
        $tipo = match (true) {
            $s->tipo_passaggio === 'vendita' && $s->riservaUsufrutto() => 'la vendita con riserva d\'usufrutto',
            $s->tipo_passaggio === 'vendita' => 'la vendita',
            $s->estinzioneUsufrutto() => 'l\'estinzione dell\'usufrutto',
            $s->tipo_passaggio === 'usufrutto' => 'la costituzione d\'usufrutto',
            $s->legato() => 'il legato',
            $s->successione() => 'la successione',
            default => 'il passaggio',
        };

        return sprintf('%s del %s', $tipo, self::data($s->decorrenza->toDateString()));
    }

    /** «della vendita», «dell'estinzione», «del legato»: la preposizione articolata davanti alle parole del passaggio. */
    private static function di(string $parole): string
    {
        return match (true) {
            str_starts_with($parole, 'la ') => 'della ' . substr($parole, 3),
            str_starts_with($parole, 'il ') => 'del ' . substr($parole, 3),
            str_starts_with($parole, 'l\'') => 'dell\'' . substr($parole, 2),
            default => 'di ' . $parole,
        };
    }

    private static function nomeDiChiEsce(Subentro $fine): string
    {
        return (string) ($fine->registro['nomi']['uscente'] ?? $fine->uscente?->nome ?? 'l\'inquilino');
    }

    private static function data(string $iso): string
    {
        return CarbonImmutable::parse(substr($iso, 0, 10))->locale('it')->translatedFormat('j F Y');
    }

    private static function a(string $nome): string
    {
        return (preg_match('/^[aàAÀ]/u', $nome) === 1 ? 'ad ' : 'a ') . $nome;
    }

    private static function elenco(array $voci): string
    {
        $voci = array_values(array_filter(array_map('strval', $voci), fn (string $v) => $v !== ''));
        if (count($voci) <= 1) {
            return $voci[0] ?? '';
        }
        $ultimo = array_pop($voci);

        return implode(', ', $voci) . ' e ' . $ultimo;
    }
}
