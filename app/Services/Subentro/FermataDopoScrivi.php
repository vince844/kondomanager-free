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
use App\Support\ImportoInParole;
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
     * Coda 254 (DL7 e INT, 1.11.0-beta.51): la fine della locazione di un conduttore. Quando un conduttore entrato dopo la fine di Ines
     * (con «Scrivi») finisce a sua volta, i giorni che lui copriva e che nessun altro conduttore riprende tornano a chi paga al posto
     * dell'inquilino da quel giorno. Se questo non è più chi ha preso la coppia (ha venduto, l'usufrutto è passato, il proprietario è
     * morto) la parte non si sposta da sola: è una fermata come le altre, con la persona, la cifra, la strada a mano e la spunta.
     *
     * La cifra NON è «la parte del conduttore prima della fine meno quella dopo»: quella coincide con la cifra solo se tutti i giorni
     * liberati vanno a persone senza la coppia. Dove vanno a un altro conduttore (un coinquilino, il cambio d'inquilino) o a chi ha già la
     * coppia (la fine registrata prima della vendita) la differenza non è zero e la cifra sì. La cifra è la variazione della parte di chi
     * riceve e non ha la coppia, nella coppia ridivisa con la fine (dopo meno prima): la stessa ridivisione di `per`.
     *
     * Se una fermata di prima conteneva già quei giorni (INT: la vendita ha lasciato € 90,74 a Elsa, Luca ne ha tolti € 60,16 entrando, e
     * uscendo li restituisce) la parte che «risale» dentro quella fermata non è una fermata nuova: si dice da quanto a quanto sale, e la
     * fermata nuova è solo il resto (`max(0, variazione − salita)`, `residuiDelleFermate`). La salita non dovrebbe superare la variazione: il
     * residuo di una fermata è la parte della persona fermata fra zero e la cifra della fermata, quindi sale al più quanto sale la sua parte.
     * Nessun test costruisce il caso contrario; se per un dato anomalo capitasse, il `max(0, …)` lo porta a zero invece di scrivere una fermata
     * negativa (e la salita resta detta, da quanto a quanto).
     *
     * Nessuna esclusione di chi esce: la stessa persona uscita con «Scrivi», rientrata e uscita di nuovo è lo stesso caso con un nome solo.
     *
     * @return array{nuove: list<array<string, mixed>>, risale: list<array<string, mixed>>} le fermate nuove (nella forma di `per`, più
     *                                                                                      `conduttore_uscente`) e le parti che risalgono
     */
    public function perLaFineDelConduttore(?TitolaritaImmobile $uscente, ?Anagrafica $entrante, float $quota, array $immobileIds, CarbonImmutable $decorrenza): array
    {
        $vuoto = ['nuove' => [], 'risale' => []];
        if ($uscente === null || (string) $uscente->tipologia !== 'inquilino') {
            return $vuoto;
        }
        $chi = (int) $uscente->anagrafica_id;
        $giornoPrima = $decorrenza->subDay();
        $righeCoppie = Saldo::with('gestione')->whereIn('immobile_id', $immobileIds)->whereNotNull('subentro_id')->where('saldo_iniziale', '!=', 0)->orderBy('id')->get();
        $gruppiDiFermate = null;
        $nuove = [];
        $risale = [];
        foreach ($righeCoppie->groupBy(fn (Saldo $r) => $r->subentro_id . '|' . $r->immobile_id . '|' . $r->gestione_id . '|' . ($r->esercizio_id ?? '')) as $gruppo) {
            $r = $gruppo->first();
            $fine = Subentro::with('padre')->find((int) $r->subentro_id)?->capo();
            if ($fine === null || ! self::fineScritta($fine)) {
                continue;
            }
            $c = $this->coppia($fine, (int) $r->subentro_id, (int) $r->immobile_id, (int) $r->gestione_id, $r->esercizio_id !== null ? (int) $r->esercizio_id : null);
            if ($c === null) {
                continue;
            }
            $righe = $this->chiPaga->righe($c['immobile_id']);
            $pesiPrima = $this->pesi($righe, $c['pezzi']);
            // Il conduttore deve avere dei giorni in questa coppia: se non ne ha, la sua fine non cambia chi la paga.
            if (! isset($pesiPrima[self::CONDUTTORE . $chi])) {
                continue;
            }
            // La riga che la fine chiude: sull'unità principale quella del modulo, sulla pertinenza quella che la registrazione chiude davvero
            // (decisione 81.3). Chi entra nel cambio d'inquilino prende la quota di chi esce (decisione 37), sulla pertinenza quella che chi esce ha lì.
            $rigaQui = (int) $c['immobile_id'] === (int) $uscente->immobile_id ? (int) $uscente->id : self::rigaDellaPertinenza((int) $c['immobile_id'], $chi, 'inquilino', $giornoPrima);
            $sua = $righe->first(fn ($x) => $rigaQui !== null && (int) $x->id === $rigaQui);
            if ($sua === null) {
                continue;
            }
            $righeDopo = $righe->map(fn ($x) => (int) $x->id === $rigaQui ? (object) array_replace((array) $x, ['data_fine' => $giornoPrima->toDateString()]) : $x)->values();
            $quotaQui = (int) $c['immobile_id'] === (int) $uscente->immobile_id ? $quota : (float) $sua->quota;
            if ($entrante !== null && $quotaQui > 0.0) {
                $righeDopo = $this->conIlConduttore($righeDopo, (int) $c['immobile_id'], (int) $entrante->id, $quotaQui, $decorrenza);
            }
            $pesiDopo = $this->pesi($righeDopo, $c['pezzi']);
            // Chi ha già la coppia non riceve: il primo nei resti è lui (come nella fermata il primo è chi esce).
            $hanno = $this->chiHaLaCoppia($c, (int) $fine->condominio_id);
            arsort($hanno);
            $primo = (int) (array_key_first($hanno) ?? 0);
            $prima = $this->chiPaga->dividi($c['totale'], $pesiPrima, $primo ?: null);
            $dopo = $this->chiPaga->dividi($c['totale'], $pesiDopo, $primo ?: null);
            $parti = self::chiRiceve($pesiPrima, $pesiDopo, $prima, $dopo, $primo, $hanno);

            // Le fermate di prima su questa coppia: quanto ne resta prima e dopo questa fine. La salita di una persona è la differenza.
            $gruppiDiFermate ??= $this->gruppiDiFermate($immobileIds);
            $salita = [];
            foreach ($gruppiDiFermate as $g) {
                if ((int) $g['c']['fine']->id !== (int) $fine->id || (int) $g['immobile_id'] !== (int) $c['immobile_id'] || (int) $g['c']['gestione_id'] !== (int) $c['gestione_id']
                    || ($g['c']['esercizio_id'] ?? null) !== ($c['esercizio_id'] ?? null)) {
                    continue;
                }
                $primaDiLui = $this->residuiDelleFermate($g, $righe);
                $dopoDiLui = $this->residuiDelleFermate($g, $righe, $righeDopo);
                $variate = array_filter(array_keys($primaDiLui), fn (int $id) => ($dopoDiLui[$id] ?? 0) !== $primaDiLui[$id]);
                if ($variate === []) {
                    continue;
                }
                foreach ($variate as $id) {
                    $salita[$id] = ($salita[$id] ?? 0) + ($dopoDiLui[$id] ?? 0) - $primaDiLui[$id];
                }
                // La voce nomina solo le persone la cui parte sale, e le due cifre sono le loro: un ricevente della stessa fermata che non varia (il suo
                // peso non cambia con questa fine) resta fuori dai nomi e dalle somme. Sostituisce `verso` di `voceDelGruppo`, che li ha tutti.
                $chiVaria = array_flip(array_values($variate));
                $risale[] = array_replace(self::voceDelGruppo($g), [
                    'prima' => (int) array_sum(array_intersect_key($primaDiLui, $chiVaria)), 'dopo' => (int) array_sum(array_intersect_key($dopoDiLui, $chiVaria)),
                    'verso' => array_values(array_map(fn (int $id) => (string) collect($g['riceventi'])->firstWhere('anagrafica_id', $id)['nome'], array_values($variate))),
                    'conduttore_uscente' => (string) $uscente->anagrafica?->nome, 'conduttore_uscente_id' => $chi, 'dal' => $decorrenza->toDateString(),
                ]);
            }

            // La fermata nuova è la variazione della parte di chi riceve meno ciò che risale dentro le fermate di prima.
            $nuovePerPersona = [];
            foreach ($parti as $id => $parte) {
                $resto = $parte - ($salita[$id] ?? 0);
                $nuovePerPersona[$id] = $parte >= 0 ? max(0, $resto) : min(0, $resto);
            }
            $nuovePerPersona = array_filter($nuovePerPersona, fn (int $cent) => $cent !== 0);
            $cifra = (int) array_sum($nuovePerPersona);
            if ($cifra === 0) {
                continue;
            }
            $nomi = Anagrafica::whereIn('id', [...array_keys($nuovePerPersona), ...array_keys($hanno)])->pluck('nome', 'id');
            $dal = $c['pezzi'][0]['tratti']->dal();
            foreach ($c['pezzi'] as $p) {
                $dal = $p['tratti']->dal()->lt($dal) ? $p['tratti']->dal() : $dal;
            }
            $conUnSaldo = self::conUnSaldo([...array_keys($hanno), ...array_keys($nuovePerPersona)], $c['gestione_id'], $c['immobile_id'], $c['esercizio_id']);
            $nuove[] = [
                'fine_id' => $c['fine']->id, 'fine_il' => $c['fine']->decorrenza->toDateString(), 'inquilino' => self::nomeDiChiEsce($c['fine']),
                'saldo_id' => (int) $r->id, 'persona_id' => $primo, 'persona' => self::elenco(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), array_keys($hanno))),
                'piu_persone' => count($hanno) > 1,
                'immobile_id' => $c['immobile_id'], 'unita' => $c['unita'], 'gestione_id' => $c['gestione_id'], 'gestione' => $c['gestione'], 'esercizio_id' => $c['esercizio_id'],
                'coppia' => (int) array_sum($hanno), 'cifra' => $cifra, 'dal' => ($decorrenza->gt($dal) ? $decorrenza : $dal)->toDateString(),
                'assorbita' => $gruppo->contains(fn (Saldo $x) => (bool) $x->is_applicato),
                'verso' => array_values(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), array_keys($nuovePerPersona))),
                'parti' => array_map(fn (int $id) => ['anagrafica_id' => $id, 'nome' => (string) ($nomi[$id] ?? '?'), 'cifra' => $nuovePerPersona[$id]], array_keys($nuovePerPersona)),
                // Decisione 80: il saldo manuale dal Wallet solo se nessuna delle persone della riga ne ha già uno lì.
                'wallet' => $conUnSaldo === [], 'con_un_saldo' => $conUnSaldo,
                // Chi esce adesso: nella fermata di una fine di conduttore la chiave `conduttore` di `fermata_ridotta` (chi entra) non c'entra.
                'conduttore_uscente' => (string) $uscente->anagrafica?->nome, 'conduttore_uscente_id' => $chi,
            ];
        }

        return ['nuove' => $nuove, 'risale' => $risale];
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
    public function rientro(?Anagrafica $entrante, float $quota, array $immobileIds, CarbonImmutable $decorrenza, ?TitolaritaImmobile $chiFinisce = null): array
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
            $con = $this->dopoIlConduttore($righe, $c['immobile_id'], (int) $entrante->id, $quota, $decorrenza, $chiFinisce);
            $prima = $this->chiPaga->dividi($c['totale'], $this->pesi($this->primaDelConduttore($righe, $c['immobile_id'], (int) $entrante->id, $decorrenza, $chiFinisce), $c['pezzi']));
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
            $pagatoDa = self::chiHaPagato($c, $prima, $dopo, $cifra);
            $fineId = (int) $c['fine']->id;
            $out[] = [
                'fine_id' => $fineId, 'fine_il' => $c['fine']->decorrenza->toDateString(), 'persona' => (string) $entrante->nome,
                'immobile_id' => $c['immobile_id'], 'unita' => $c['unita'], 'gestione_id' => $c['gestione_id'], 'gestione' => $c['gestione'], 'esercizio_id' => $c['esercizio_id'],
                'cifra' => $cifra, 'dal' => $decorrenza->toDateString(),
                'pagato_da' => $pagatoDa,
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
    public function partiDiChiEntra(?Anagrafica $entrante, float $quota, array $immobileIds, CarbonImmutable $decorrenza, ?TitolaritaImmobile $chiFinisce = null): array
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
            $con = $this->dopoIlConduttore($righeUnita, $c['immobile_id'], (int) $entrante->id, $quota, $decorrenza, $chiFinisce);
            $prima = $this->chiPaga->dividi($c['totale'], $this->pesi($this->primaDelConduttore($righeUnita, $c['immobile_id'], (int) $entrante->id, $decorrenza, $chiFinisce), $c['pezzi']));
            $dopo = $this->chiPaga->dividi($c['totale'], $this->pesi($con, $c['pezzi']));
            $cifra = (int) ($dopo[self::CONDUTTORE . $entrante->id] ?? 0) - (int) ($prima[self::CONDUTTORE . $entrante->id] ?? 0);
            if ($cifra !== 0) {
                $out[] = ['fine_id' => (int) $fine->id, 'immobile_id' => (int) $c['immobile_id'], 'gestione_id' => (int) $c['gestione_id'], 'cifra' => $cifra]
                    + ['fine_il' => $fine->decorrenza->toDateString(), 'unita' => $c['unita'], 'gestione' => $c['gestione'], 'esercizio_id' => $c['esercizio_id'],
                        'pagato_da' => self::chiHaPagato($c, $prima, $dopo, $cifra)];
            }
        }

        return $out;
    }

    /**
     * Chi ha preso i giorni della coppia che un nuovo conduttore adesso rimborsa: chi ha le righe della coppia e la cui parte cala (o sale, con
     * una cifra negativa). Una persona senza righe (chi è entrato con un passaggio fermato dopo) non ha preso niente: la sua parte l'ha ancora
     * chi è rimasto a carico, che ha più della sua parte (`fermateRidotte` dice il resto). Il verso è quello della cifra.
     *
     * @param array<string, mixed> $c la coppia (`coppia`)
     * @param array<int|string, int> $prima la ripartizione prima dell'inizio
     * @param array<int|string, int> $dopo la ripartizione con il nuovo conduttore
     * @return list<string> i nomi
     */
    private static function chiHaPagato(array $c, array $prima, array $dopo, int $cifra): array
    {
        $calano = array_keys(array_filter($prima, fn (int $parte, int|string $id) => is_int($id) && ($parte - ($dopo[$id] ?? 0)) * $cifra > 0, ARRAY_FILTER_USE_BOTH));
        $senzaRighe = array_diff($calano, array_keys($c['righe'])) !== [];
        $pagatoDa = array_keys(array_filter($c['righe'], fn (int $riga, int $id) => in_array($id, $calano, true) || ($senzaRighe && ($riga - ($prima[$id] ?? 0)) * $cifra > 0), ARRAY_FILTER_USE_BOTH));

        return Anagrafica::whereIn('id', $pagatoDa)->pluck('nome')->map(fn ($n) => (string) $n)->values()->all();
    }

    /**
     * Decisione 85.4 (1.11.0-beta.51): la cifra dei giorni del conduttore, per il registro dell'inizio. L'inizio di una locazione scrive, per
     * ogni coppia di una fine «Scrivi» sull'unità, la parte del nuovo conduttore nella coppia ridivisa con lui e chi aveva preso quei giorni:
     * `rientro` per chi è uscito con quella fine (il rinnovo, il rientro) e `partiDiChiEntra` per tutti gli altri, unite in una voce sola.
     * Per il rientro la cifra è la stessa di `rinnovo_dopo_scrivi`: un array solo, due chiavi.
     *
     * È una fotografia, valida fino alla fine del conduttore: se dopo arriva un coinquilino la parte di Luca cambia e la cifra no (il nome della
     * chiave lo dice, con `dal`). Non c'è dove l'inizio è su un piano fermo senza una fine «Scrivi» prima (decisione 25): neanche il pannello ha una cifra.
     * La fine del conduttore ne ricava la parte che torna: la cifra dell'inizio meno la parte rimasta dopo la fine, entro un centesimo (le due
     * divisioni non hanno lo stesso primo nei resti).
     *
     * Il cambio d'inquilino in un passo è anche lui un inizio di locazione per chi entra: con `$chiFinisce` (la locazione di chi esce) la cifra è la
     * parte di chi entra nella coppia ridivisa con la locazione di chi esce chiusa il giorno prima, come la lascia la registrazione.
     *
     * @return list<array<string, mixed>>
     */
    public function giorniDelConduttoreAllInizio(?Anagrafica $entrante, float $quota, array $immobileIds, CarbonImmutable $decorrenza, ?TitolaritaImmobile $chiFinisce = null): array
    {
        if ($entrante === null || $quota <= 0.0) {
            return [];
        }

        return self::unisciIGiorni($entrante, $decorrenza, $this->rientro($entrante, $quota, $immobileIds, $decorrenza, $chiFinisce), $this->partiDiChiEntra($entrante, $quota, $immobileIds, $decorrenza, $chiFinisce));
    }

    /**
     * Le voci di `rientro` e di `partiDiChiEntra` nella forma del registro dell'inizio (`giorniDelConduttoreAllInizio`). Chi ha già calcolato le
     * due (il pannello) le passa, senza rifare le letture.
     *
     * @param list<array<string, mixed>> $rientro
     * @param list<array<string, mixed>> $parti
     * @return list<array<string, mixed>>
     */
    public static function unisciIGiorni(Anagrafica $entrante, CarbonImmutable $decorrenza, array $rientro, array $parti): array
    {
        return array_values(array_map(fn (array $x) => [
            'fine_id' => (int) $x['fine_id'], 'fine_il' => (string) ($x['fine_il'] ?? ''), 'immobile_id' => (int) $x['immobile_id'], 'unita' => (string) ($x['unita'] ?? ''),
            'gestione_id' => (int) $x['gestione_id'], 'gestione' => (string) ($x['gestione'] ?? ''), 'esercizio_id' => isset($x['esercizio_id']) ? (int) $x['esercizio_id'] : null,
            'conduttore' => (string) $entrante->nome, 'conduttore_id' => (int) $entrante->id, 'dal' => $decorrenza->toDateString(), 'cifra' => (int) $x['cifra'],
            'pagato_da' => array_values((array) ($x['pagato_da'] ?? [])),
        ], [...$rientro, ...$parti]));
    }

    /**
     * Dopo una fermata, l'inizio di una locazione: dal giorno dell'inizio i giorni della coppia li rimborsa il nuovo conduttore con il
     * prospetto degli oneri accessori, e la parte della fermata che cade lì non va più regolata fra chi è rimasto a carico e chi ha
     * ricevuto. La cifra della fermata non resta valida per sempre (caso S7 delle misure della Fase 1 della .50): si dice quanto ne
     * resta. È un motivo del cancello, come la fermata, tranne quando l'inizio la azzera (decisione 86.2): allora è un'informazione.
     *
     * Decisione 86.1 (1.11.0-beta.51): il residuo è uno per persona che riceve, non uno per fermata. Quando più fermate nominano la stessa
     * persona sulla stessa coppia (Ugo e Anna vendono a Elsa in due date), il conduttore toglie a quella persona i suoi giorni una volta sola,
     * e la voce è una: la cifra è la somma delle fermate, il residuo quello che resta di quella somma (`residuiDelleFermate`).
     *
     * @return list<array<string, mixed>>
     */
    public function fermateRidotte(?Anagrafica $entrante, float $quota, array $immobileIds, CarbonImmutable $decorrenza): array
    {
        if ($entrante === null || $quota <= 0.0) {
            return [];
        }
        $out = [];
        foreach ($this->gruppiDiFermate($immobileIds) as $g) {
            $righe = $this->chiPaga->righe($g['immobile_id']);
            $ora = $this->residuiDelleFermate($g, $righe);
            $con = $this->residuiDelleFermate($g, $righe, $this->conIlConduttore($righe, $g['immobile_id'], (int) $entrante->id, $quota, $decorrenza));
            $primaDiLui = (int) array_sum($ora);
            $residuo = (int) array_sum($con);
            if ($residuo === $primaDiLui) {
                continue;
            }
            $out[] = self::voceDelGruppo($g) + [
                'dal_wallet' => self::saldiDalWallet($g),
                'prima' => $primaDiLui, 'residuo' => $residuo,
                'parti' => array_values(array_filter(array_map(fn (array $p) => ['anagrafica_id' => (int) $p['anagrafica_id'], 'nome' => (string) $p['nome'], 'cifra' => (int) ($con[(int) $p['anagrafica_id']] ?? 0)], $g['riceventi']),
                    fn (array $p) => $p['cifra'] !== 0)),
                'conduttore' => (string) $entrante->nome, 'dal' => $decorrenza->toDateString(),
            ];
        }

        return $out;
    }

    /**
     * Le fermate registrate sulle unità del passaggio, raggruppate: per coppia (fine, unità, gestione, esercizio) e, dentro la coppia, per
     * persone che ricevono (le fermate che nominano la stessa persona sono un gruppo solo; quelle di persone diverse restano separate,
     * com'erano). Ogni gruppo porta le fermate in ordine di registrazione, la coppia e le persone che ricevono.
     *
     * @return list<array{immobile_id: int, c: array<string, mixed>, fermate: list<array{s: Subentro, f: array<string, mixed>}>, riceventi: list<array{anagrafica_id: int, nome: string}>}>
     */
    private function gruppiDiFermate(array $immobileIds): array
    {
        $passaggi = Subentro::whereIn('immobile_id', $immobileIds)->get()->map(fn (Subentro $s) => $s->capo())
            ->filter(fn (Subentro $s) => ! $s->annullato() && ($s->registro['fermata_dopo_scrivi'] ?? []) !== [])->unique('id')->sortBy('id')->values();
        $coppie = [];
        $gruppi = [];
        foreach ($passaggi as $s) {
            foreach ($s->registro['fermata_dopo_scrivi'] as $f) {
                $u = (int) ($f['immobile_id'] ?? 0);
                if (! in_array($u, array_map('intval', $immobileIds), true) || empty($f['parti'])) {
                    continue;
                }
                $chiave = (int) ($f['fine_id'] ?? 0) . '|' . $u . '|' . (int) ($f['gestione_id'] ?? 0) . '|' . ($f['esercizio_id'] ?? '');
                $coppie[$chiave] ??= $this->coppiaDellaFermata($f);
                if ($coppie[$chiave] === null) {
                    continue;
                }
                $riceventi = array_map(fn (array $p) => (int) $p['anagrafica_id'], $f['parti']);
                // Il gruppo che ha già una di queste persone sulla stessa coppia; se sono più d'uno si fondono.
                $trovati = array_keys(array_filter($gruppi, fn (array $g) => $g['chiave'] === $chiave && array_intersect($g['ids'], $riceventi) !== []));
                $primo = $trovati[0] ?? null;
                if ($primo === null) {
                    $gruppi[] = ['chiave' => $chiave, 'ids' => $riceventi, 'immobile_id' => $u, 'c' => $coppie[$chiave], 'fermate' => [['s' => $s, 'f' => $f]]];
                    continue;
                }
                foreach (array_slice($trovati, 1) as $altro) {
                    $gruppi[$primo]['ids'] = array_values(array_unique([...$gruppi[$primo]['ids'], ...$gruppi[$altro]['ids']]));
                    $gruppi[$primo]['fermate'] = [...$gruppi[$primo]['fermate'], ...$gruppi[$altro]['fermate']];
                    unset($gruppi[$altro]);
                }
                $gruppi[$primo]['ids'] = array_values(array_unique([...$gruppi[$primo]['ids'], ...$riceventi]));
                $gruppi[$primo]['fermate'][] = ['s' => $s, 'f' => $f];
                usort($gruppi[$primo]['fermate'], fn (array $x, array $y) => (int) $x['s']->id <=> (int) $y['s']->id);
            }
        }

        return array_values(array_map(function (array $g) {
            $nomi = [];
            foreach ($g['fermate'] as $x) {
                foreach ($x['f']['parti'] as $p) {
                    $nomi[(int) $p['anagrafica_id']] ??= (string) $p['nome'];
                }
            }

            return ['immobile_id' => $g['immobile_id'], 'c' => $g['c'], 'fermate' => $g['fermate'],
                'riceventi' => array_map(fn (int $id) => ['anagrafica_id' => $id, 'nome' => $nomi[$id]], array_keys($nomi))];
        }, $gruppi));
    }

    /**
     * Quanto resta, per persona che riceve, di un gruppo di fermate con le righe date (decisione 86.1; prima era il corpo di `fermateRidotte`).
     *
     * Le fermate che nominano la persona si sommano: la cifra è la sua parte di ciascuna, in ordine di registrazione. Ciò che toglie un
     * conduttore entrato dopo si sottrae una volta sola, dalla somma che c'è in quel momento: per ogni fermata la «base» è la ripartizione con
     * le righe senza i conduttori entrati dopo di lei, e fra una fermata e la successiva si toglie ciò che i conduttori entrati nel mezzo hanno
     * tolto alla persona; in fondo, ciò che toglie la differenza fra la base dell'ultima e le righe date. Con una fermata sola è la regola della
     * .50: quanto resta della cifra dopo che il conduttore ha preso i suoi giorni.
     *
     * Un conduttore toglie giorni a una persona solo se il suo peso cala: se non cala, un centesimo in meno è un resto di arrotondamento che si è
     * spostato, non un giorno suo. Con una parte negativa (un credito di chi ha ricevuto) la riduzione va verso lo zero
     * dall'altra parte, e la cifra che resta ha il suo segno (G1).
     *
     * @param array{c: array<string, mixed>, fermate: list<array{s: Subentro, f: array<string, mixed>}>, riceventi: list<array{anagrafica_id: int, nome: string}>} $g
     * @param Collection<int, object> $righe le righe dell'unità di adesso: da qui si ricavano le basi (senza i conduttori entrati dopo ogni fermata)
     * @param Collection<int, object>|null $target le righe da valutare (un nuovo conduttore, la fine di uno): senza, quelle di adesso
     * @return array<int, int> anagrafica → quanto resta
     */
    private function residuiDelleFermate(array $g, Collection $righe, ?Collection $target = null): array
    {
        $c = $g['c'];
        $u = (int) $g['immobile_id'];
        $basi = [];
        foreach ($g['fermate'] as $k => $x) {
            // I conduttori entrati con un passaggio registrato dopo la fermata hanno già tolto i loro giorni: si parte dalle righe senza di loro.
            $entratiDopo = Subentro::where('immobile_id', $u)->whereIn('tipo_passaggio', ['inizio_locazione', 'fine_locazione'])->where('id', '>', $x['s']->id)
                ->whereNotNull('riga_entrante_id')->pluck('riga_entrante_id')->map(fn ($id) => (int) $id)->all();
            // A pari resto il centesimo a chi è uscito con quel passaggio, come nella fermata: dopo una fermata (decisione 81.2) non ha righe
            // nella coppia, e lo dice `uscente_id`.
            $chi = (int) ($x['f']['uscente_id'] ?? $x['f']['persona_id']);
            $pesi = $this->pesi($righe->reject(fn ($r) => in_array((int) $r->id, $entratiDopo, true))->values(), $c['pezzi']);
            $basi[$k] = ['pesi' => $pesi, 'parti' => $this->chiPaga->dividi($c['totale'], $pesi, $chi)];
        }
        $pesiOra = $this->pesi($target ?? $righe, $c['pezzi']);
        $ora = $this->chiPaga->dividi($c['totale'], $pesiOra, (int) ($g['fermate'][0]['f']['uscente_id'] ?? $g['fermate'][0]['f']['persona_id']));
        // Quanto un cambio di righe toglie a una persona: la differenza delle sue parti, solo se il suo peso cala.
        $tolto = fn (int $id, array $da, array $a): int => abs($a['pesi'][$id] ?? 0.0) >= abs($da['pesi'][$id] ?? 0.0) - 1e-9 ? 0 : ($da['parti'][$id] ?? 0) - ($a['parti'][$id] ?? 0);
        $riduci = fn (int $somma, int $togli): int => $somma >= 0 ? max(0, $somma - max(0, $togli)) : min(0, $somma - min(0, $togli));

        $out = [];
        foreach ($g['riceventi'] as $r) {
            $id = (int) $r['anagrafica_id'];
            $somma = 0;
            $ultima = null;
            foreach ($g['fermate'] as $k => $x) {
                if ($ultima !== null) {
                    $somma = $riduci($somma, $tolto($id, $basi[$ultima], $basi[$k]));
                }
                $parte = collect($x['f']['parti'])->first(fn (array $p) => (int) $p['anagrafica_id'] === $id);
                $somma += (int) ($parte['cifra'] ?? 0);
                $ultima = $k;
            }
            $out[$id] = $riduci($somma, $tolto($id, $basi[$ultima], ['pesi' => $pesiOra, 'parti' => $ora]));
        }

        return $out;
    }

    /**
     * La voce di un gruppo di fermate, nella forma di `fermateRidotte` (e di `fermata_ridotta` nel registro) prima del residuo: con una fermata
     * sola è quella della .50, con più fermate sulla stessa persona il primo passaggio, la lista dei passaggi, la somma delle cifre e le
     * persone a carico (decisione 86.1).
     *
     * @param array{immobile_id: int, fermate: list<array{s: Subentro, f: array<string, mixed>}>, riceventi: list<array{anagrafica_id: int, nome: string}>} $g
     * @return array<string, mixed>
     */
    private static function voceDelGruppo(array $g): array
    {
        $primo = $g['fermate'][0];
        $f = $primo['f'];
        $voce = [
            'passaggio_id' => (int) $primo['s']->id, 'passaggio' => self::passaggioInParole($primo['s']), 'fine_id' => (int) $f['fine_id'], 'inquilino' => (string) ($f['inquilino'] ?? 'l\'inquilino'),
            'persona_id' => (int) $f['persona_id'], 'persona' => (string) ($f['persona'] ?? '?'), 'immobile_id' => (int) $g['immobile_id'], 'unita' => (string) ($f['unita'] ?? ''),
            'gestione_id' => (int) $f['gestione_id'], 'gestione' => (string) ($f['gestione'] ?? '?'), 'cifra' => (int) $f['cifra'], 'verso' => array_column($f['parti'], 'nome'),
            // Il giorno della fine «Scrivi» che ha scritto la coppia: tutte le fermate di un gruppo stanno sulla stessa coppia.
            'fine_il' => $g['c']['fine']->decorrenza->toDateString(),
        ];
        if (count($g['fermate']) === 1) {
            return $voce;
        }
        $persone = [];
        $aCarico = [];
        foreach ($g['fermate'] as $x) {
            $persone[(int) $x['f']['persona_id']] ??= (string) ($x['f']['persona'] ?? '?');
            $aCarico[(int) $x['f']['persona_id']] = ($aCarico[(int) $x['f']['persona_id']] ?? 0) + (int) $x['f']['cifra'];
        }

        return array_replace($voce, [
            'persona' => self::elenco(array_values($persone)), 'persone' => array_values($persone), 'cifra' => (int) array_sum(array_map(fn (array $x) => (int) $x['f']['cifra'], $g['fermate'])),
            // La cifra che ogni persona a carico aveva lasciato con le sue fermate: il residuo è uno solo per tutte, e la parte di ognuna la decide l'amministratore.
            'a_carico' => array_values(array_map(fn (int $id) => ['persona' => $persone[$id], 'cifra' => $aCarico[$id]], array_keys($persone))),
            'verso' => array_column($g['riceventi'], 'nome'),
            'passaggi_ids' => array_map(fn (array $x) => (int) $x['s']->id, $g['fermate']),
            'passaggi' => array_map(fn (array $x) => self::passaggioInParole($x['s']), $g['fermate']),
        ]);
    }

    /**
     * I saldi che le fermate del gruppo hanno indicato di registrare dal Wallet (decisione 80): per ognuna, la persona a carico, la cifra e le parti di
     * chi riceve, come le dice `stradaAMano`. Le fermate che hanno detto «si regola fra le parti» non ne hanno. Serve a dire, quando l'inizio di una
     * locazione riduce o azzera la fermata, di correggere il saldo che l'amministratore ha registrato su quell'indicazione: il programma non lo toglie.
     *
     * @param array{fermate: list<array{s: Subentro, f: array<string, mixed>}>} $g
     * @return list<array{persona: string, cifra: int, parti: list<array{nome: string, cifra: int}>}>
     */
    private static function saldiDalWallet(array $g): array
    {
        $out = [];
        foreach ($g['fermate'] as $x) {
            if (! ($x['f']['wallet'] ?? true)) {
                continue;
            }
            $out[] = ['persona' => (string) ($x['f']['persona'] ?? '?'), 'cifra' => (int) $x['f']['cifra'],
                'parti' => array_map(fn (array $p) => ['nome' => (string) $p['nome'], 'cifra' => (int) $p['cifra']], $x['f']['parti'] ?? [])];
        }

        return $out;
    }

    /**
     * La coda della frase di una voce con più persone a carico (decisione 86.1): il residuo è uno solo per tutte, e il programma non lo divide fra loro.
     * Lo dice, con la cifra che ognuna aveva lasciato con la sua fermata, perché la parte di ciascuna la decide l'amministratore.
     *
     * @param array<string, mixed> $f la voce di `fermateRidotte`
     */
    private static function codaDellePersoneACarico(array $f): string
    {
        $carico = $f['a_carico'] ?? [];
        if (count($carico) < 2) {
            return '';
        }

        return sprintf(' Il residuo è uno solo per %s insieme: la parte di ognuno la decidi tu, partendo da quella della sua fermata (%s).',
            self::elenco(array_column($carico, 'persona')), self::elenco(array_map(fn (array $x) => sprintf('%s per %s', self::soldi((int) $x['cifra']), $x['persona']), $carico)));
    }

    /**
     * La coda della frase di una fermata ridotta o azzerata: se per quella fermata si è registrato il saldo dal Wallet, va corretto. Al condizionale: il
     * Wallet tiene un saldo solo per persona, gestione, unità ed esercizio, e il programma non sa se quello che c'è sia il saldo della fermata o un riporto.
     *
     * @param array<string, mixed> $f la voce di `fermateRidotte`
     */
    private static function codaDelWallet(array $f): string
    {
        $saldi = $f['dal_wallet'] ?? [];
        if ($saldi === []) {
            return '';
        }
        $righe = array_map(fn (array $x) => self::elenco(array_merge(
            [sprintf('%s a %s di %s', MoneyHelper::format(abs($x['cifra'])), $x['cifra'] < 0 ? 'debito' : 'credito', $x['persona'])],
            array_map(fn (array $p) => sprintf('%s a %s di %s', MoneyHelper::format(abs($p['cifra'])), $p['cifra'] < 0 ? 'credito' : 'debito', $p['nome']), $x['parti']),
        )), $saldi);

        return sprintf(' Se per %s hai registrato il saldo dal Wallet (%s), correggilo: il programma non lo toglie.', count($saldi) === 1 ? 'quella fermata' : 'quelle fermate', implode('; ', $righe));
    }

    /** Le chiavi di una fermata che il registro del passaggio conserva: lo storico, l'annullamento e il prospetto le leggono da lì. */
    public static function perIlRegistro(array $f): array
    {
        return array_intersect_key($f, array_flip(['fine_id', 'fine_il', 'inquilino', 'saldo_id', 'persona_id', 'persona', 'immobile_id', 'unita', 'gestione_id', 'gestione',
            'esercizio_id', 'coppia', 'cifra', 'dal', 'assorbita', 'verso', 'parti', 'wallet', 'passaggio_id', 'passaggio', 'prima', 'residuo', 'conduttore',
            'uscente_id', 'uscente', 'dopo_la_fermata', 'piu_persone', 'ereditata', 'passaggi_ids', 'passaggi', 'persone', 'dopo', 'conduttore_uscente', 'conduttore_uscente_id']));
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
        if (isset($f['conduttore_uscente'])) {
            return self::frasePannelloFineDelConduttore($f);
        }
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
     * Coda 254 (1.11.0-beta.51): la frase del pannello per la fermata della fine di un conduttore. Il passaggio che si ferma è la fine della
     * locazione di chi esce adesso (`conduttore_uscente`), non una vendita: i suoi giorni tornano a chi paga al posto dell'inquilino da quel
     * giorno, e se questo non è più chi ha preso la coppia la parte non si sposta da sola. La coppia non si ricalcola più (l'ha presa la fine
     * di chi era uscito prima): le frasi «tornano a …» valgono per i piani futuri.
     */
    private static function frasePannelloFineDelConduttore(array $f): string
    {
        return sprintf('La fine locazione di %s del %s ha scritto il conguaglio: dei giorni dopo la sua uscita %s sulla gestione %s (%s). Con la fine della locazione di %s i suoi giorni dal %s tornano a chi paga al posto dell\'inquilino, e la parte di quei giorni tocca %s, ma il programma non la sposta: %s. %s',
            $f['inquilino'], self::data($f['fine_il']), self::presa((string) $f['persona'], (int) $f['coppia'], ! empty($f['piu_persone'])), $f['gestione'], $f['unita'],
            $f['conduttore_uscente'], self::data($f['dal']), self::a(self::elenco($f['verso'])),
            (int) $f['cifra'] < 0 ? 'in quei giorni l\'entrata supera la spesa, e ' . self::aCarico((int) $f['cifra'], '', (string) $f['persona']) : self::aCarico((int) $f['cifra'], '', (string) $f['persona']),
            self::stradaAMano($f));
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
        $motivo = sprintf('%s (gestione %s): il conguaglio scritto alla fine locazione del %s non passa %s %s',
            self::aCarico((int) $f['cifra'], 'dei giorni dopo l\'uscita di ' . $f['inquilino'], (string) $f['persona']), $f['gestione'], self::data($f['fine_il']), self::a(self::elenco($f['verso'])),
            self::conQuestoPassaggio($f));
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
        $frase = sprintf('Il conguaglio scritto alla fine locazione di %s del %s non è passato %s: %s sulla gestione %s (%s), da regolare a mano con %s%s.',
            $f['inquilino'] ?? 'l\'inquilino', self::data($f['fine_il']), self::conQuestoPassaggio($f), self::aCarico((int) $f['cifra'], 'dei giorni dal ' . self::data($f['dal']), (string) ($f['persona'] ?? '?'), passato: true),
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
        $piu = count(self::passaggiDellaVoce($f)) > 1;

        return sprintf('Il conguaglio scritto alla fine locazione di %s è stato annullato dopo questo inizio: %s %s (%s rimasti %s), che con questo inizio %s da regolare per %s, %s.',
            $f['inquilino'] ?? 'l\'inquilino', $piu ? 'le fermate' : 'la fermata', self::elenco(array_map(fn (string $p) => self::di($p), self::passaggiDellaVoce($f, 'un passaggio di prima'))),
            self::soldi((int) ($f['cifra'] ?? 0)), self::diChiResta((int) ($f['cifra'] ?? 0), (string) ($f['persona'] ?? '?')), $piu ? 'restavano' : 'restava',
            self::cifraInParole((int) ($f['residuo'] ?? 0)), $piu ? 'non valgono più' : 'non vale più');
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
            // Coda 254: la fine di un conduttore che ha fatto risalire la parte di una fermata di prima. Annullato il conguaglio anche quella
            // parte, e il saldo o l'accordo che le parti avevano adeguato, non valgono più.
            foreach ($s->registro['fermata_risale'] ?? [] as $f) {
                if ((int) ($f['fine_id'] ?? 0) !== (int) $capo->id) {
                    continue;
                }
                $avvisi[] = sprintf('%s ha fatto risalire la parte verso %s %s da %s a %s. Annullato il conguaglio, quella parte non vale più: se per questo hai registrato un saldo manuale dal Wallet, o le parti hanno regolato la differenza fra loro, rivedilo, perché l\'annullamento non lo toglie.',
                    ucfirst(self::passaggioInParole($s)), self::elenco($f['verso'] ?? []) ?: 'chi è entrato', self::dellaFermata($f), self::cifraInParole((int) ($f['prima'] ?? 0)), self::cifraInParole((int) ($f['dopo'] ?? 0)));
            }
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

    /**
     * Coda 254 (INT, 1.11.0-beta.51): la parte che risale. Alla fine di un conduttore, una fermata di prima conteneva già i suoi giorni (la
     * vendita aveva lasciato € 90,74 a Elsa, Luca ne ha tolti entrando, e uscendo li restituisce): la parte da regolare sale, e il pannello
     * dice da quanto a quanto. Non è una fermata nuova, ma cambia ciò che l'amministratore deve regolare a mano: è un motivo del cancello.
     */
    public static function fraseRisale(array $f): string
    {
        return sprintf('Con la fine della locazione di %s i suoi giorni dal %s tornano a chi paga al posto dell\'inquilino: la parte verso %s %s sale da %s a %s sulla gestione %s (%s). La parte da regolare a mano fra %s è questa, e il programma non la sposta.',
            $f['conduttore_uscente'], self::data($f['dal']), self::elenco($f['verso']), self::dellaFermata($f), self::cifraInParole((int) $f['prima']), self::cifraInParole((int) $f['dopo']),
            $f['gestione'], $f['unita'], self::fraLeParti($f));
    }

    /** Il motivo del cancello per la parte che risale: corto, con le due cifre. */
    public static function motivoRisale(array $f): string
    {
        return sprintf('la parte verso %s %s risale da %s a %s (gestione %s): con la fine della locazione di %s quei giorni tornano a chi paga al posto dell\'inquilino, e il conguaglio scritto alla fine locazione del %s non li sposta',
            self::elenco($f['verso']), self::dellaFermata($f), self::cifraInParole((int) $f['prima']), self::cifraInParole((int) $f['dopo']), $f['gestione'], $f['conduttore_uscente'], self::data($f['fine_il']));
    }

    /** La frase dello storico della fine che ha fatto risalire la parte, dal registro. */
    public static function fraseStoricoRisale(array $f): string
    {
        return sprintf('Con questa fine, %s la parte verso %s risale da %s a %s (gestione %s, %s): i giorni di %s dal %s tornano a chi paga al posto dell\'inquilino, da regolare a mano fra %s.',
            self::dellaFermata($f), self::elenco($f['verso'] ?? []) ?: 'chi è entrato', self::cifraInParole((int) ($f['prima'] ?? 0)), self::cifraInParole((int) ($f['dopo'] ?? 0)),
            $f['gestione'] ?? '?', $f['unita'] ?? '', $f['conduttore_uscente'] ?? 'il conduttore', self::data((string) ($f['dal'] ?? '')), self::fraLeParti($f));
    }

    /** La frase dello storico della fine che ha fatto risalire la parte, quando il conguaglio della fine di prima è stato annullato dopo. */
    public static function fraseStoricoRisaleAnnullata(array $f): string
    {
        return sprintf('Il conguaglio scritto alla fine locazione di %s è stato annullato dopo questa fine: la parte verso %s %s, risalita da %s a %s, non vale più.',
            $f['inquilino'] ?? 'l\'inquilino', self::elenco($f['verso'] ?? []) ?: 'chi è entrato', self::dellaFermata($f), self::cifraInParole((int) ($f['prima'] ?? 0)), self::cifraInParole((int) ($f['dopo'] ?? 0)));
    }

    /** L'avviso dell'annullamento della fine di un conduttore: la parte che era risalita torna com'era. */
    public static function avvisoRisaleAnnullando(array $f): string
    {
        return sprintf('Con questo passaggio la parte verso %s %s è risalita da %s a %s: annullandolo torna da %s a %s. Se per questo hai registrato un saldo manuale dal Wallet, o le parti hanno regolato la differenza fra loro, quel saldo o quell\'accordo vanno rivisti: annullare il passaggio non li toglie.',
            self::elenco($f['verso'] ?? []) ?: 'chi è entrato', self::dellaFermata($f), self::cifraInParole((int) ($f['prima'] ?? 0)), self::cifraInParole((int) ($f['dopo'] ?? 0)),
            self::cifraInParole((int) ($f['dopo'] ?? 0)), self::cifraInParole((int) ($f['prima'] ?? 0)));
    }

    /**
     * L'avviso dell'annullamento dell'inizio che ha ridotto o azzerato una fermata (il gemello di `avvisoRisaleAnnullando`): annullato l'inizio, la parte
     * da regolare torna quella di prima. Un accordo fra le parti, o un saldo dal Wallet, adeguati al residuo nel frattempo si rivedono.
     */
    public static function avvisoRidottaAnnullando(array $f): string
    {
        return sprintf('Con questo inizio la parte verso %s %s è scesa da %s a %s: annullandolo torna da %s a %s. Se le parti hanno già regolato la differenza, o per questo hai registrato un saldo manuale dal Wallet, rivedilo: annullare il passaggio non lo toglie.',
            self::elenco($f['verso'] ?? []) ?: 'chi riceve', self::dellaFermata($f), self::cifraInParole((int) ($f['prima'] ?? $f['cifra'] ?? 0)), self::cifraInParole((int) ($f['residuo'] ?? 0)),
            self::cifraInParole((int) ($f['residuo'] ?? 0)), self::cifraInParole((int) ($f['prima'] ?? $f['cifra'] ?? 0)));
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

    /**
     * La frase del pannello dopo una fermata: quanto ne resta da regolare con l'inizio di questa locazione. Con più fermate sulla stessa persona
     * (decisione 86.1) è una frase sola, che le nomina tutte e dice il residuo vero.
     */
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

        return sprintf('%s %s lasciato %s %s%s dei giorni dopo l\'uscita di %s, da regolare a mano con %s%s. Con questo inizio %s: %s. Se %s hanno già regolato %s, %s vanno restituiti %s.%s',
            ucfirst(self::elenco(self::passaggiDellaVoce($f))), count(self::passaggiDellaVoce($f)) > 1 ? 'hanno' : 'ha', $credito ? self::a((string) $f['persona']) : 'a carico di ' . $f['persona'],
            MoneyHelper::format(abs((int) $f['cifra'])), $credito ? ' di credito' : '', $f['inquilino'], $verso,
            $prima !== (int) $f['cifra'] ? sprintf(' (con le locazioni registrate da allora, %s)', MoneyHelper::format(abs($prima))) : '',
            self::giorniDelConduttore($f), $resta, self::fraLeParti($f), MoneyHelper::format(abs($prima)), MoneyHelper::format(abs($prima - (int) $f['residuo'])),
            $credito ? self::a((string) $f['persona']) : self::a($verso), self::codaDellePersoneACarico($f) . self::codaDelWallet($f));
    }

    /** Il motivo del cancello dopo una fermata: la cifra che resta da regolare, al posto di quella detta dal passaggio. */
    public static function motivoRidotta(array $f): string
    {
        // Giro stretto della .50 (G1): una fermata negativa è un credito, e si dice con le parole.
        return (int) $f['cifra'] < 0
            ? sprintf('%s resta da regolare fra %s un credito di %s, non più di %s: %s',
                self::dellaFermata($f), self::fraLeParti($f), MoneyHelper::format(abs((int) $f['residuo'])), MoneyHelper::format(abs((int) ($f['prima'] ?? $f['cifra']))),
                self::giorniDelConduttore($f))
            : sprintf('%s restano da regolare fra %s %s, non più %s: %s',
                self::dellaFermata($f), self::fraLeParti($f), MoneyHelper::format((int) $f['residuo']), MoneyHelper::format((int) ($f['prima'] ?? $f['cifra'])),
                self::giorniDelConduttore($f));
    }

    /**
     * Decisione 86.2: l'inizio che azzera la fermata (la fine di Luca e l'inizio di Marco lo stesso giorno) non lascia niente da regolare. Lo dice
     * la frase del pannello, come informazione; il cancello non chiede una seconda spunta per «€ 0,00, non più € 268,20».
     */
    public static function azzerata(array $f): bool
    {
        return (int) ($f['residuo'] ?? 0) === 0 && ($f['parti'] ?? []) === [];
    }

    /** La frase dello storico dell'inizio dopo una fermata, dal registro. */
    public static function fraseStoricoRidotta(array $f): string
    {
        $credito = (int) ($f['cifra'] ?? 0) < 0;
        // Decisione 86.2: se l'inizio ha azzerato la fermata non resta niente da regolare, e lo storico lo ricorda con la cifra di prima.
        $azzerata = self::azzerata($f);

        return sprintf('Con questo inizio, %s (%s rimasti %s) %s fra %s%s: %s.',
            self::dellaFermata($f, 'di un passaggio di prima'), self::soldi((int) ($f['cifra'] ?? 0)), self::diChiResta((int) ($f['cifra'] ?? 0), (string) ($f['persona'] ?? '?')),
            $azzerata ? 'non resta niente da regolare' : ($credito ? 'resta da regolare' : 'restano da regolare'), self::fraLeParti($f),
            $azzerata ? '' : ' ' . ($credito ? 'un credito di ' . MoneyHelper::format(abs((int) ($f['residuo'] ?? 0))) : MoneyHelper::format((int) ($f['residuo'] ?? 0))),
            self::giorniDelConduttore($f));
    }

    /**
     * I passaggi che hanno lasciato la fermata di una voce, ognuno con le sue parole («la vendita del 12 marzo 2026»): uno, o più (decisione 86.1:
     * le fermate sulla stessa persona sono una voce sola). Una voce senza passaggio (un registro vecchio) dà il ripiego.
     *
     * @return list<string>
     */
    private static function passaggiDellaVoce(array $f, string $ripiego = 'un passaggio di prima'): array
    {
        $lista = array_values(array_filter((array) ($f['passaggi'] ?? []), fn ($p) => is_string($p) && $p !== ''));

        return count($lista) > 1 ? $lista : [isset($f['passaggio']) ? (string) $f['passaggio'] : $ripiego];
    }

    /** «della fermata della vendita del 12 marzo 2026», o «delle fermate della vendita del … e della vendita del …». */
    private static function dellaFermata(array $f, ?string $senzaPassaggio = null): string
    {
        $passaggi = self::passaggiDellaVoce($f);
        if (! isset($f['passaggio']) && $senzaPassaggio !== null) {
            return 'della fermata ' . $senzaPassaggio;
        }

        return (count($passaggi) > 1 ? 'delle fermate ' : 'della fermata ') . self::elenco(array_map(fn (string $p) => self::di($p), $passaggi));
    }

    /** Chi è rimasto a carico e chi ha ricevuto, in una frase: «Ugo e Elsa»; con più persone a carico (decisione 86.1) «Ugo, Anna e Elsa». */
    private static function fraLeParti(array $f): string
    {
        $persone = array_values(array_filter((array) ($f['persone'] ?? []), fn ($p) => is_string($p) && $p !== ''));
        $verso = (array) ($f['verso'] ?? []);

        return count($persone) > 1 ? self::elenco([...$persone, ...$verso]) : (string) ($f['persona'] ?? '?') . ' e ' . (self::elenco($verso) ?: 'chi è entrato');
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
     * Le righe dell'unità dopo l'inizio di un conduttore. Nel cambio d'inquilino in un passo (`$chiFinisce`) la locazione di chi esce è chiusa il
     * giorno prima, come la chiude la registrazione: sulla pertinenza la riga che chi esce ha lì (decisione 81.3), e chi entra prende la sua quota
     * (decisione 37). Senza `$chiFinisce` è `conIlConduttore`.
     *
     * @param Collection<int, object> $righe
     * @return Collection<int, object>
     */
    private function dopoIlConduttore(Collection $righe, int $immobileId, int $anagraficaId, float $quota, CarbonImmutable $decorrenza, ?TitolaritaImmobile $chiFinisce): Collection
    {
        if ($chiFinisce !== null && (string) $chiFinisce->tipologia === 'inquilino') {
            $giornoPrima = $decorrenza->subDay();
            $rigaQui = $immobileId === (int) $chiFinisce->immobile_id ? (int) $chiFinisce->id : self::rigaDellaPertinenza($immobileId, (int) $chiFinisce->anagrafica_id, 'inquilino', $giornoPrima);
            $sua = $righe->first(fn ($x) => $rigaQui !== null && (int) $x->id === $rigaQui);
            if ($sua !== null) {
                $righe = $righe->map(fn ($x) => (int) $x->id === $rigaQui ? (object) array_replace((array) $x, ['data_fine' => $giornoPrima->toDateString()]) : $x)->values();
                $quota = $immobileId === (int) $chiFinisce->immobile_id ? $quota : (float) $sua->quota;
            }
        }

        return $quota > 0.0 ? $this->conIlConduttore($righe, $immobileId, $anagraficaId, $quota, $decorrenza) : $righe;
    }

    /**
     * Le righe dell'unità prima del nuovo conduttore, per dire chi ha pagato i giorni che lui adesso rimborsa. Nel cambio d'inquilino in un passo
     * (`$chiFinisce`) la locazione di chi esce è ancora aperta nelle righe di adesso e copre i giorni dopo il cambio: «prima» è lo stato dopo la
     * fine, senza nessuno al posto di chi esce, come nei due passi (la fine, poi l'inizio lo stesso giorno). Senza `$chiFinisce` sono le righe di adesso.
     *
     * @param Collection<int, object> $righe
     * @return Collection<int, object>
     */
    private function primaDelConduttore(Collection $righe, int $immobileId, int $anagraficaId, CarbonImmutable $decorrenza, ?TitolaritaImmobile $chiFinisce): Collection
    {
        return $chiFinisce === null ? $righe : $this->dopoIlConduttore($righe, $immobileId, $anagraficaId, 0.0, $decorrenza, $chiFinisce);
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

    /** «con questo passaggio», o per la fermata della fine di un conduttore (Coda 254) «con la fine della locazione di Luca». */
    private static function conQuestoPassaggio(array $f): string
    {
        return isset($f['conduttore_uscente']) ? 'con la fine della locazione di ' . $f['conduttore_uscente'] : 'con questo passaggio';
    }

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
        return ImportoInParole::soldi($cifra);
    }

    /** «€ 19,40», o per una cifra negativa «un credito di € 19,40». */
    private static function cifraInParole(int $cifra): string
    {
        return ImportoInParole::cifraInParole($cifra);
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

    /**
     * «la vendita del 1 ottobre 2026», per le frasi dei passaggi di dopo: l'unica copia del sostantivo, che chiamano anche lo storico e il
     * prospetto degli oneri accessori (che lo dice con la sua data, `$conData` falso). Con la fine di una locazione (Coda 254) è «la fine della
     * locazione di Luca».
     */
    public static function passaggioInParole(Subentro $s, bool $conData = true): string
    {
        $tipo = match (true) {
            $s->tipo_passaggio === 'fine_locazione' => 'la fine della locazione di ' . self::nomeDiChiEsce($s),
            $s->tipo_passaggio === 'vendita' && $s->riservaUsufrutto() => 'la vendita con riserva d\'usufrutto',
            $s->tipo_passaggio === 'vendita' => 'la vendita',
            $s->estinzioneUsufrutto() => 'l\'estinzione dell\'usufrutto',
            $s->tipo_passaggio === 'usufrutto' => 'la costituzione d\'usufrutto',
            $s->legato() => 'il legato',
            $s->successione() => 'la successione',
            default => 'il passaggio',
        };

        return $conData ? sprintf('%s del %s', $tipo, self::data($s->decorrenza->toDateString())) : $tipo;
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
