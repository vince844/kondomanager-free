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

    /** La chiave della quota passata con le fermate a chi riceve, nei pesi (decisione 112.1, `laQuota`): nessuna anagrafica ha un id negativo. */
    private const QUOTA_DELLA_FERMATA = -1;

    /**
     * La strada a mano di una fermata registrata dalla 1.11.0-beta.53 (Coda 244): i saldi del Wallet legati al passaggio, che non occupano il
     * posto di un riporto e si tolgono se il passaggio si annulla. Un registro senza la chiave `strada` è di prima: il pannello indicava il
     * saldo manuale dal Wallet solo se nessuno aveva già un saldo lì (`wallet`), e le frasi che leggono quel registro lo dicono com'era.
     */
    public const STRADA_LEGATA = 'legata';

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
     * @param list<array<string, mixed>>|null $ridotte Coda 351: qui le fermate di prima che il passaggio riduce perché riporta i giorni all'unica persona a carico
     *                                                (`ridottaDaChiRicompra`), nella forma di `fermateRidotte`
     * @param list<array<string, mixed>>|null $senzaRiga Coda 353: qui gli avvisi dove chi compra ha una riga della coppia e non si sa in quale riga stavano i giorni di chi esce
     *                                                   (`fraseSenzaRiga`): senza l'avviso sarebbe silenzio, e non c'è una fermata da dire
     * @return list<array<string, mixed>>
     */
    public function per(string $tipo, ?string $sottotipo, ?TitolaritaImmobile $uscente, ?Anagrafica $entrante, array $eredi, array $immobileIds, CarbonImmutable $decorrenza,
        array $saldiPortati = [], ?Collection $nudi = null, bool $accrescimento = false, ?array &$ridotte = null, ?array &$senzaRiga = null): array
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
            if ((int) array_sum($parti) === 0) {
                continue;
            }
            $dal = $c['pezzi'][0]['tratti']->dal();
            foreach ($c['pezzi'] as $p) {
                $dal = $p['tratti']->dal()->lt($dal) ? $p['tratti']->dal() : $dal;
            }
            // Coda 354: la parte che chi entra prende dalla quota che una fermata aveva lasciato a chi esce nella riga di un altro non è a suo carico.
            [$parti, $dalleFermate] = $this->conLeFermateNellaRiga($c, $uscente, $decorrenza, $righe, $parti);
            $daqui = ($decorrenza->gt($dal) ? $decorrenza : $dal)->toDateString();
            $cifra = (int) array_sum($parti);
            $nomi = Anagrafica::whereIn('id', array_keys($parti))->pluck('nome', 'id');
            $out = [...$out, ...($cifra === 0 ? [] : [[
                'fine_id' => $c['fine']->id, 'fine_il' => $c['fine']->decorrenza->toDateString(), 'inquilino' => self::nomeDiChiEsce($c['fine']),
                'saldo_id' => $c['saldo_id'], 'persona_id' => $chi, 'persona' => (string) $uscente->anagrafica?->nome,
                'immobile_id' => $c['immobile_id'], 'unita' => $c['unita'], 'gestione_id' => $c['gestione_id'], 'gestione' => $c['gestione'], 'esercizio_id' => $c['esercizio_id'],
                'coppia' => $c['riga'], 'cifra' => $cifra, 'dal' => $daqui, 'assorbita' => $c['assorbita'],
                ...((int) ($c['resto_del_ripiego']['per'][$chi] ?? 0) !== 0 ? ['resto_del_ripiego' => ['cifra' => (int) $c['resto_del_ripiego']['per'][$chi],
                    'piano' => (int) $c['riga'] + (int) $c['resto_del_ripiego']['per'][$chi], 'con' => self::chiSiRegola((array) $c['resto_del_ripiego']['altri'], (int) $c['resto_del_ripiego']['per'][$chi])]] : []),
                'verso' => array_values(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), array_keys($parti))),
                'parti' => array_map(fn (int $id) => ['anagrafica_id' => $id, 'nome' => (string) ($nomi[$id] ?? '?'), 'cifra' => $parti[$id]], array_keys($parti)),
                // La strada a mano è il Wallet, con i saldi legati a questo passaggio: anche se una delle persone della riga ne ha già uno lì, la
                // riga legata non occupa il posto (un saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio
                // né quelli legati a un passaggio). Il Wallet c'è sempre.
                'wallet' => true, 'strada' => self::STRADA_LEGATA,
            ]]), ...array_map(fn (array $x) => $this->dallaFermataNellaRiga($c, $uscente, $x, $daqui), $dalleFermate)];
        }

        $ridotte = [];
        $senzaRiga = [];

        return [...$out, ...$this->dopoUnaFermata($tipo, $sottotipo, $uscente, $entrante, $eredi, $immobileIds, $decorrenza, $nudiIds, $accrescimento,
            agliEredi: $tipo === 'successione' && $saldiPortati !== [], ridotte: $ridotte, senzaRiga: $senzaRiga)];
    }

    /**
     * Coda 354 (1.11.0-beta.55): la riga che chi esce chiude può avere, oltre alla sua parte della coppia, la quota che una fermata gli ha lasciato nella riga di un
     * altro (Bruno, pieno della metà A, compra la nuda della B e l'usufrutto di Zeno si estingue: i giorni della B sono di Bruno, e stanno nella riga della coppia di
     * Zeno). La parte che chi entra prende da quella quota non è di chi esce: resta a carico di chi era a carico della fermata, o di chi esce se quella fermata è già
     * stata regolata, come dopo una fermata (decisione 81.2). Le quote della riga per origine sono quelle del registro dei passaggi (`quotePerOrigine`); la parte di
     * ognuno che riceve si divide per quota, la sua per prima (i giorni sono gli stessi: è la stessa riga). Contano le fermate che sanno chi è a carico, una persona o più
     * persone insieme.
     *
     * @param array<int, int> $parti la parte di chi riceve, tutta
     * @return array{0: array<int, int>, 1: list<array{fermata: array<string, mixed>, parti: array<int, int>}>} la parte dalla sua riga, e quella da ogni fermata
     */
    private function conLeFermateNellaRiga(array $c, TitolaritaImmobile $uscente, CarbonImmutable $decorrenza, Collection $righe, array $parti): array
    {
        $chi = (int) $uscente->anagrafica_id;
        $rigaId = self::rigaCheChiude((int) $c['immobile_id'], $uscente, $decorrenza);
        $riga = $righe->first(fn ($r) => (int) $r->id === $rigaId);
        $ricevute = array_values(array_filter($this->fermateDi($c, [$chi]), fn (array $x) => (int) $x['ricevuto_da'] === $chi
            && ! in_array(null, $x['persone_ids'], true) && ! in_array($chi, $x['persone_ids'], true)));
        if ($riga === null || $ricevute === []) {
            return [$parti, []];
        }
        $perOrigine = self::quotePerOrigine($righe, Subentro::where('immobile_id', (int) $c['immobile_id'])->orderBy('id')->get(), $chi,
            array_combine(array_column($ricevute, 'capo_id'), array_column($ricevute, 'capo_id')))[$rigaId] ?? [];
        $quote = [];
        foreach ($ricevute as $k => $x) {
            if ((float) ($perOrigine[$x['capo_id']] ?? 0.0) > 1e-9) {
                $quote[$k] = (float) $perOrigine[$x['capo_id']];
            }
        }
        // Con una riga cambiata a mano dopo la fermata le quote possono superarla: la sua parte allora è zero (il tetto della quota, come in `laQuota`).
        $sua = max(0.0, (float) $riga->quota - (float) array_sum($quote));
        $mie = [];
        $dalle = [];
        foreach ($parti as $id => $cent) {
            foreach (MoneyHelper::ripartisciPerQuote((int) $cent, ['sua' => $sua] + $quote) as $o => $x) {
                if ($o === 'sua') {
                    $mie[(int) $id] = (int) $x;
                } else {
                    $dalle[(int) $o][(int) $id] = (int) $x;
                }
            }
        }

        return [array_filter($mie, fn (int $x) => $x !== 0),
            array_values(array_filter(array_map(fn (int $k, array $p) => ['fermata' => $ricevute[$k], 'parti' => array_filter($p, fn (int $x) => $x !== 0), 'quota' => $quote[$k]],
                array_keys($dalle), $dalle), fn (array $x) => $x['parti'] !== []))];
    }

    /**
     * Coda 354: la voce della parte che viene dalla quota di una fermata nella riga di chi esce (`conLeFermateNellaRiga`), nella forma di `dopoUnaFermata`: a carico di chi
     * era a carico della fermata, o di chi esce se è già stata regolata (decisione 81.2), senza il Wallet.
     *
     * @param array{fermata: array<string, mixed>, parti: array<int, int>} $x
     * @return array<string, mixed>
     */
    private function dallaFermataNellaRiga(array $c, TitolaritaImmobile $uscente, array $x, string $dal, ?int $saldoId = null, ?bool $assorbita = null): array
    {
        $persone = array_map('intval', $x['fermata']['persone_ids']);
        $hanno = array_intersect_key($this->chiHaLaCoppia($c, (int) $c['fine']->condominio_id), array_flip($persone));
        $nomi = Anagrafica::whereIn('id', [...$persone, ...array_keys($x['parti'])])->pluck('nome', 'id');
        // Una fermata a carico di più persone insieme (decisione 86.1) le porta tutte, con i loro ids, come in `dopoUnaFermata`.
        $piu = count($persone) > 1;

        return [
            'fine_id' => $c['fine']->id, 'fine_il' => $c['fine']->decorrenza->toDateString(), 'inquilino' => self::nomeDiChiEsce($c['fine']),
            'saldo_id' => $saldoId ?? $c['saldo_id'], 'persona_id' => $persone[0], 'persona' => self::elenco(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), $persone)),
            'piu_persone' => $piu,
            ...($piu ? ['persone' => array_values(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), $persone)), 'persone_ids' => $persone] : []),
            'uscente_id' => (int) $uscente->anagrafica_id, 'uscente' => (string) $uscente->anagrafica?->nome,
            'dopo_la_fermata' => ['passaggio' => $x['fermata']['passaggio'], 'cifra' => $x['fermata']['cifra']],
            'immobile_id' => $c['immobile_id'], 'unita' => $c['unita'], 'gestione_id' => $c['gestione_id'], 'gestione' => $c['gestione'], 'esercizio_id' => $c['esercizio_id'],
            'coppia' => (int) array_sum($hanno), 'cifra' => (int) array_sum($x['parti']), 'dal' => $dal, 'assorbita' => $assorbita ?? $c['assorbita'],
            'verso' => array_values(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), array_keys($x['parti']))),
            'parti' => array_map(fn (int $id) => ['anagrafica_id' => $id, 'nome' => (string) ($nomi[$id] ?? '?'), 'cifra' => $x['parti'][$id]], array_keys($x['parti'])),
            'wallet' => false,
            // Decisione 112.1: la quota della fermata di prima nella riga che chiude, la sola che questa voce passa a chi riceve. Un passaggio dopo (l'inizio di un conduttore,
            // la rivendita di chi riceve) toglie alla voce i giorni di questa quota, non quelli della riga intera che chi riceve ha aperto (`quotePerOrigine`, il tetto).
            ...(isset($x['quota']) ? ['quota_della_riga' => (float) $x['quota']] : []),
        ];
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
     * fermata nuova è solo il resto (`max(0, variazione − salita)`, `residuiDelleFermate`). Con chi riceve che ha anche una sua parte della coppia
     * risalgono i soli giorni della quota della fermata (decisione 112.1). La salita non dovrebbe superare la variazione: il
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
                    // La parte di ognuno che riceve, anche di chi non varia, prima e dopo questa fine: le righe legate portano la fermata intera.
                    'parti_prima' => self::partiDeiRiceventi($g, $primaDiLui), 'parti_dopo' => self::partiDeiRiceventi($g, $dopoDiLui),
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
            $nuove[] = [
                'fine_id' => $c['fine']->id, 'fine_il' => $c['fine']->decorrenza->toDateString(), 'inquilino' => self::nomeDiChiEsce($c['fine']),
                'saldo_id' => (int) $r->id, 'persona_id' => $primo, 'persona' => self::elenco(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), array_keys($hanno))),
                'piu_persone' => count($hanno) > 1,
                // Più persone a carico insieme: ognuna con il suo id, perché le frasi delle righe legate leggono le righe di tutte, non della prima.
                ...(count($hanno) > 1 ? ['persone' => array_values(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), array_keys($hanno))), 'persone_ids' => array_keys($hanno)] : []),
                'immobile_id' => $c['immobile_id'], 'unita' => $c['unita'], 'gestione_id' => $c['gestione_id'], 'gestione' => $c['gestione'], 'esercizio_id' => $c['esercizio_id'],
                'coppia' => (int) array_sum($hanno), 'cifra' => $cifra, 'dal' => ($decorrenza->gt($dal) ? $decorrenza : $dal)->toDateString(),
                'assorbita' => $gruppo->contains(fn (Saldo $x) => (bool) $x->is_applicato),
                'verso' => array_values(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), array_keys($nuovePerPersona))),
                'parti' => array_map(fn (int $id) => ['anagrafica_id' => $id, 'nome' => (string) ($nomi[$id] ?? '?'), 'cifra' => $nuovePerPersona[$id]], array_keys($nuovePerPersona)),
                // La strada a mano è il Wallet, con i saldi legati a questo passaggio (la fine della locazione del conduttore).
                'wallet' => true, 'strada' => self::STRADA_LEGATA,
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
     * agli eredi. Le persone di una fermata di prima si leggono nello stesso modo (`alPostoDeiDefunti`): chi è morto con l'arretrato agli eredi ha
     * lasciato loro la sua riga, e con lei la fermata che aveva. Le quote del defunto passano con la loro storia, qualunque sia la sorte dell'arretrato: le fermate
     * dove riceveva lui (la sua riga nata da un'estinzione o dall'acquisto della quota di un altro, che `passaggiDellaRiga` segue) sono anche di chi esce. Se chi esce
     * è uno di loro, la parte che ha avuto con la successione è sua, ma non è detto che sia tutta la parte che vende: prima si legge la catena dei passaggi della sua
     * riga. Se la successione è nella catena (o la catena non si legge, una riga cambiata a mano) e nessun altro passaggio ha lasciato una fermata, l'erede non ha
     * comprato niente e la fermata è come la prima: a suo carico, con il Wallet. Se in catena ci sono anche fermate di altri passaggi (ha comprato una parte da chi
     * aveva la coppia, prima o dopo l'eredità) la cifra viene da più origini, ognuna a carico di chi ha la sua riga della coppia, e il programma non sa quale parte
     * venga da quale: resta a carico di chi esce e delle persone di quelle fermate insieme, e il Wallet non si indica. Una successione della catena con l'arretrato
     * a nome del defunto e «Non scriverlo» non ha fermata, ma è un'origine anche lei: i giorni dopo il decesso stanno nella riga che è rimasta al defunto, che è a
     * carico come lo sarebbe la sua fermata con «Scrivi». Se la successione non è nella catena (la parte avuta con la successione sta su un'altra riga di chi esce)
     * per questa riga chi esce non è a carico, e vale la regola di chi non ha una parte, salvo che la fermata di prima sia rimasta a lui soltanto (la vendita di Ugo a
     * Bruno, poi Ugo muore e Bruno è suo erede): allora la fermata è la sua, come quella di ogni erede. Quando il passaggio è la successione dell'erede, anche lui con
     * l'arretrato agli eredi (Coda 350), quella parte passa con la sua posizione e non c'è fermata.
     *
     * Chi riceve non è chi ha una riga qualunque nella coppia: è chi non ha già preso quei giorni. Le persone a carico (le persone delle fermate di prima, lette
     * come i loro eredi) dicono in quale riga stavano: se è una persona sola, e a ricevere è lei, i giorni sono già nella sua riga e non c'è niente da regolare (il
     * proprietario unico che vende a Elsa e la ricompra); se sono più persone insieme, o a ricevere è un'altra persona, anche se ha una sua riga nella coppia (Anna
     * vende a Elsa, Elsa vende a Ugo: i giorni stanno nella riga di Anna), la parte che sta nella riga di un altro si dice. Se le persone non si sanno (una fermata di prima
     * con più persone senza i loro id, nessuna fermata nella catena), chi ha una riga della coppia non riceve, come prima.
     *
     * @param list<array{anagrafica: Anagrafica, quota: float}> $eredi
     * @param list<int> $nudiIds
     * @return list<array<string, mixed>>
     */
    private function dopoUnaFermata(string $tipo, ?string $sottotipo, TitolaritaImmobile $uscente, ?Anagrafica $entrante, array $eredi, array $immobileIds, CarbonImmutable $decorrenza,
        array $nudiIds, bool $accrescimento, bool $agliEredi = false, array &$ridotte = [], array &$senzaRiga = []): array
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
            $righeDopo = $this->conIlPassaggio($righe, $c['immobile_id'], $tipo, $sottotipo, $uscente, $entrante, $eredi, $nudiIds, $accrescimento, $decorrenza);
            $pesiDopo = $this->pesi($righeDopo, $c['pezzi']);
            $prima = $this->chiPaga->dividi($c['totale'], $pesiPrima, $chi);
            $dopo = $this->chiPaga->dividi($c['totale'], $pesiDopo, $chi);
            $tuttiICheHanno = $hanno;
            // La parte che chi esce ha oggi viene dai passaggi della catena della sua riga (`passaggiDellaRiga`): contano le fermate di quei passaggi, e
            // quelle della fine di un conduttore registrate dopo il primo (i giorni che tornano non sono un passaggio della riga), non quelle di una parte
            // già rivenduta né di un'altra riga della stessa persona. Senza la catena (una riga cambiata a mano), l'ultima fermata, come prima.
            $rigaChiude = self::rigaCheChiude((int) $c['immobile_id'], $uscente, $decorrenza);
            $daiPassaggi = self::passaggiDellaRiga((int) $c['immobile_id'], $rigaChiude, $chi);
            // La parte che il defunto aveva ricevuto con una fermata passa all'erede con la successione: le fermate dove ha ricevuto lui sono anche di chi esce.
            $fermate = $this->fermateDi($c, [$chi, ...self::defuntiDellaCatena($daiPassaggi)]);
            $diPrimaTutte = $daiPassaggi === [] ? array_slice($fermate, 0, 1)
                : array_values(array_filter($fermate, fn (array $x) => in_array($x['capo_id'], $daiPassaggi, true)
                    || ($x['del_conduttore'] && $x['capo_id'] >= $daiPassaggi[0])));
            // Le persone di una fermata di prima si leggono come le righe della coppia: chi è morto con l'arretrato agli eredi ha lasciato loro la sua riga, e con lei la
            // fermata che aveva.
            $diPrimaTutte = self::coiLoroEredi($diPrimaTutte, self::alPostoDeiDefunti($c, (int) $fine->condominio_id));
            // Una successione della catena senza fermata (l'arretrato è rimasto a nome del defunto e il conguaglio non è stato scritto) non ha detto niente a nessuno: i
            // giorni di chi esce dopo il decesso stanno ancora nella riga che è rimasta al defunto, che è a carico come lo sarebbe la fermata della successione con «Scrivi».
            // È un'origine solo se il defunto ha una riga fra le persone a carico: senza (ha comprato la sua parte con una fermata, e i suoi giorni stanno nella riga di chi gliel'ha
            // venduta) la successione non aggiunge niente alla fermata di prima, che resta l'unica origine.
            $defuntiSenzaFermata = array_values(array_filter(self::defuntiSenzaFermata($daiPassaggi), fn (int $id) => isset($hanno[$id])));
            $personeDiPrima = [...array_merge([], ...array_column($diPrimaTutte, 'persone_ids')), ...$defuntiSenzaFermata];
            // Le origini della parte che chi esce vende: una per fermata di prima, una per ogni successione senza fermata.
            $nOrigini = count($diPrimaTutte) + count($defuntiSenzaFermata);
            // Chi esce ha una sua parte della coppia solo come erede (decisione 65), e la successione conta se è un passaggio della catena della sua riga. Se la
            // parte avuta con la successione sta su un'altra riga, per questa non è a carico e vale la regola di chi non ha una parte, salvo che sia fra le persone
            // della fermata di prima: la fermata di chi gli ha lasciato la coppia è sua.
            $successione = $suaParte === null ? null : self::successioneDellErede($c, $chi, (int) $fine->condominio_id);
            $dallaSuccessione = $successione !== null && ($daiPassaggi === [] || in_array((int) $successione[0]->capo()->id, $daiPassaggi, true));
            if ($suaParte !== null && ! $dallaSuccessione && ! in_array($chi, $personeDiPrima, true)) {
                unset($hanno[$chi]);
            }
            // Se la fermata di prima è rimasta a lui soltanto (il defunto di cui è l'unico erede), non ci sono altre origini: la fermata è la sua, come per l'erede che
            // non ha comprato niente. Lo stesso quando ha una sua parte della coppia e nella catena della riga non c'è nessuna fermata di prima: la riga l'ha comprata
            // da chi la coppia non l'aveva, quella vendita non si è fermata (chi la compra era già la persona a carico di quei giorni), e nessun'altra persona risponde
            // della parte. Se la catena della riga non si legge (`$daiPassaggi` vuoto: una riga cambiata o riaperta a mano, nel registro di nessun passaggio) il programma non sa da dove
            // vengano i giorni che vende, e li legge come suoi, come faceva la .53: anche se prima aveva ricevuto una fermata, di cui non può dire quanta parte sia rimasta in quella riga.
            $soloLui = $suaParte !== null && $personeDiPrima !== [] && ! in_array(null, $personeDiPrima, true) && array_values(array_unique($personeDiPrima)) === [$chi];
            $aSuoCarico = (($dallaSuccessione || $suaParte !== null) && ($daiPassaggi === [] || $nOrigini === 0)) || $soloLui;
            // Coda 350: con l'arretrato agli eredi la successione passa agli eredi tutta la posizione del defunto, e con lei la parte della coppia che aveva avuto da una
            // successione di prima, come la sua riga: l'arretrato che passa agli eredi la contiene già. Non resta a carico di nessuno.
            if ($agliEredi && $aSuoCarico) {
                continue;
            }
            // La fermata di prima ha detto a carico di chi è rimasta la parte di chi esce: la parte dei giorni che passa con questo passaggio
            // viene da lì, non da tutti quelli che hanno righe nella coppia (due comproprietari: la parte di Elsa veniva dalla riga di Ugo). Con una sola origine, se è
            // una fermata, la frase la nomina («o di Elsa se la fermata della vendita del 1 ottobre è già stata regolata»); con più origini nessuna è «la» fermata di prima,
            // e l'alternativa è la parte di chi esce.
            $diPrima = $nOrigini === 1 && $diPrimaTutte !== [] ? $diPrimaTutte[0] : null;
            $restretto = false;
            if (! $aSuoCarico) {
                // Se chi esce ha anche una parte avuta con la successione (l'erede che ha comprato), la cifra viene da quella, a suo carico, e dalle origini della catena,
                // a carico di chi le ha ricevute, e il programma non sa quale parte venga da quale: resta a carico di chi esce e delle persone di quelle origini insieme (la
                // successione di Ugo, la vendita di Anna: Bruno e Anna). Chi esce ha sommato parti avute con più origini (Elsa compra la parte di Anna, poi quella di Ugo, e
                // rivende tutto): la stessa cosa, senza chi esce, o con lui se quella fermata è regolata. Con una origine sola, a carico delle sue persone, una o più.
                $origini = $dallaSuccessione ? [$chi, ...$personeDiPrima] : $personeDiPrima;
                if (! in_array(null, $origini, true) && ($dallaSuccessione || array_intersect_key($hanno, array_flip($origini)) !== [])) {
                    $hanno = array_intersect_key($hanno, array_flip($origini));
                    $restretto = true;
                }
            }
            // Chi riceve, e quanto: come nella prima fermata, ma non chi ha già preso quei giorni. I giorni che chi esce passa stanno nelle righe delle persone a carico
            // (`$hanno`, ristretto alle persone della fermata di prima): se è una persona sola, e a ricevere è lei, li ha già nella sua riga e non c'è niente da regolare (il
            // proprietario unico che vende e ricompra); se sono più persone insieme, o è a ricevere un'altra persona, anche se ha una riga sua nella coppia, la parte che sta
            // nella riga di un altro si dice. Se chi esce è lui a carico, chi riceve non ha la riga dei suoi giorni. Se le persone non si sanno (una fermata di prima con più
            // persone senza i loro id, nessuna fermata nella catena) resta la regola della .50: chi ha una riga della coppia non riceve, e se la fermata sarebbe stata sua il programma
            // dice che non sa nella riga di chi stavano i giorni (Coda 353, `$senzaRiga`).
            $esclusi = match (true) {
                $aSuoCarico => [],
                ! $restretto => $hanno,
                count($hanno) === 1 => $hanno,
                default => [],
            };
            $parti = self::chiRiceve($pesiPrima, $pesiDopo, $prima, $dopo, $chi, $esclusi);
            $cifra = (int) array_sum($parti);
            if ($cifra === 0) {
                // Coda 351: i giorni che chi esce passa tornano all'unica persona che ha la riga di quei giorni, che li ha già nella sua parte (chi ricompra la sua stessa
                // parte), e non nasce una fermata: si riduce quella di prima, come con l'inizio di una locazione. Con più persone che potrebbero averli non si sa nella riga
                // di chi stavano, e la ridotta non si dice (vedi sotto l'avviso). Coda 356: anche la successione di chi riceveva, quando l'erede è l'unica persona a carico;
                // con l'arretrato agli eredi la posizione di chi muore, con la sua parte della fermata, passa all'erede, e la parte si estingue.
                if (count($hanno) === 1) {
                    array_push($ridotte, ...$this->ridottaDaChiRicompra($c, $chi, (int) array_key_first($hanno), $righe, $righeDopo, $decorrenza,
                        successione: $tipo === 'successione', agliEredi: $tipo === 'successione' && $agliEredi));
                }
                // Coda 353: con più persone che potrebbero averli, non si sa nella riga di chi stavano i giorni di chi esce (nessun passaggio registrato che lo dica), e chi compra ha una riga
                // della coppia: non si inventa una fermata, ma il silenzio sarebbe una scelta. Si dice che il programma non lo sa, con quanto sarebbe da regolare se i giorni stessero in un'altra riga.
                // Con più persone a ricevere (la successione a due eredi che hanno la coppia) ognuna potrebbe dover regolare con l'altra, e il programma non lo dice.
                $dellaSuaRiga = count($hanno) > 1 ? self::chiRiceve($pesiPrima, $pesiDopo, $prima, $dopo, $chi) : [];
                if (count($dellaSuaRiga) === 1 && array_sum($dellaSuaRiga) !== 0) {
                    $nomiSenzaRiga = Anagrafica::whereIn('id', [...array_keys($hanno), ...array_keys($dellaSuaRiga)])->pluck('nome', 'id');
                    $dalSenzaRiga = $c['pezzi'][0]['tratti']->dal();
                    foreach ($c['pezzi'] as $p) {
                        $dalSenzaRiga = $p['tratti']->dal()->lt($dalSenzaRiga) ? $p['tratti']->dal() : $dalSenzaRiga;
                    }
                    $senzaRiga[] = [
                        'fine_id' => $c['fine']->id, 'fine_il' => $c['fine']->decorrenza->toDateString(), 'inquilino' => self::nomeDiChiEsce($c['fine']),
                        'unita' => $c['unita'], 'gestione' => $c['gestione'], 'uscente' => (string) $uscente->anagrafica?->nome,
                        'persona' => self::elenco(array_map(fn (int $id) => (string) ($nomiSenzaRiga[$id] ?? '?'), array_keys($hanno))), 'coppia' => (int) array_sum($hanno),
                        'verso' => array_values(array_map(fn (int $id) => (string) ($nomiSenzaRiga[$id] ?? '?'), array_keys($dellaSuaRiga))), 'cifra' => (int) array_sum($dellaSuaRiga),
                        'dal' => ($decorrenza->gt($dalSenzaRiga) ? $decorrenza : $dalSenzaRiga)->toDateString(),
                    ];
                }
                continue;
            }
            $nomi = Anagrafica::whereIn('id', [...array_keys($parti), ...array_keys($tuttiICheHanno)])->pluck('nome', 'id');
            $dal = $c['pezzi'][0]['tratti']->dal();
            foreach ($c['pezzi'] as $p) {
                $dal = $p['tratti']->dal()->lt($dal) ? $p['tratti']->dal() : $dal;
            }
            // Coda 355: l'erede che ha anche comprato una quota fermata muore con l'arretrato agli eredi. La parte che aveva avuto con la successione passa ai suoi eredi
            // con la sua posizione (Coda 350); resta da dire la parte delle quote che le fermate di prima gli avevano lasciato nella riga di un altro, per quota della riga
            // che chiude (`conLeFermateNellaRiga`), come la fermata di chi aveva comprato senza la coppia. Se le quote non si leggono, la regola di prima.
            if ($agliEredi && $dallaSuccessione && ! $aSuoCarico && $defuntiSenzaFermata === []) {
                [, $dalle] = $this->conLeFermateNellaRiga($c, $uscente, $decorrenza, $righe, $parti);
                if ($dalle !== []) {
                    foreach ($dalle as $x) {
                        // `agli_eredi`: chi esce è morto e la sua posizione è passata a chi riceve, le frasi non lo nominano come chi risponde.
                        $out[] = $this->dallaFermataNellaRiga($c, $uscente, $x, ($decorrenza->gt($dal) ? $decorrenza : $dal)->toDateString(), (int) $r->id,
                            $gruppo->contains(fn (Saldo $y) => (bool) $y->is_applicato)) + ['agli_eredi' => true];
                    }
                    continue;
                }
            }
            if ($aSuoCarico) {
                // L'erede non ha altre origini: la fermata è la prima, a suo carico. Giro stretto della .50 (G13): la coppia non l'ha presa lui, gli è
                // arrivata con la successione, e la frase lo dice (`ereditata`).
                $out[] = [
                    'fine_id' => $c['fine']->id, 'fine_il' => $c['fine']->decorrenza->toDateString(), 'inquilino' => self::nomeDiChiEsce($c['fine']),
                    'saldo_id' => (int) $r->id, 'persona_id' => $chi, 'persona' => (string) $uscente->anagrafica?->nome,
                    'immobile_id' => $c['immobile_id'], 'unita' => $c['unita'], 'gestione_id' => $c['gestione_id'], 'gestione' => $c['gestione'], 'esercizio_id' => $c['esercizio_id'],
                    'coppia' => $suaParte, 'cifra' => $cifra, 'dal' => ($decorrenza->gt($dal) ? $decorrenza : $dal)->toDateString(),
                    'assorbita' => $gruppo->contains(fn (Saldo $x) => (bool) $x->is_applicato),
                    'verso' => array_values(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), array_keys($parti))),
                    'parti' => array_map(fn (int $id) => ['anagrafica_id' => $id, 'nome' => (string) ($nomi[$id] ?? '?'), 'cifra' => $parti[$id]], array_keys($parti)),
                    'wallet' => true, 'strada' => self::STRADA_LEGATA,
                ] + self::ereditata($c, $chi, (int) $fine->condominio_id);
                continue;
            }
            arsort($hanno);
            // Chi riceve è una delle persone a carico (Elsa ha comprato da Anna e da Ugo e rivende tutto a Ugo): una parte dei giorni sta già nella sua riga, e la cifra intera non è a carico di tutti
            // allo stesso modo. Dove le origini sono fermate della catena di chi esce, la frase dice la cifra per persona.
            $cifre = array_intersect_key($parti, $hanno) !== [] ? $this->cifrePerPersona($c, $chi, $rigaChiude, $diPrimaTutte, $hanno, $cifra, $nomi) : null;
            // Con l'erede la frase dice anche di chi era rimasta la fermata di prima (`persona`, una persona o più, se il registro le sa), perché le persone a carico sono
            // quelle di tutte le origini. Le fermate la cui persona è solo chi esce sono già sue (il defunto di cui è l'unico erede): non c'è niente da regolare, e non si dicono.
            // Con una fermata sola da dire (e nessuna successione senza fermata fra le origini) è «la» fermata di prima; con più, nessuna lo è: la frase le dice una per una, dalla
            // più vecchia.
            $daDire = $dallaSuccessione ? array_values(array_filter(array_reverse($diPrimaTutte), fn (array $x) => ! self::tuttaDiChiEsce($x, $chi))) : [];
            if ($dallaSuccessione) {
                $diPrima = count($daDire) === 1 && $defuntiSenzaFermata === [] ? $daDire[0] : null;
            }
            // Coda 346 (1.11.0-beta.55): la fermata che aveva ricevuto il defunto si dice con il suo nome, e con la successione che ne ha passato la parte (`di`, `passata`).
            $dellaFermata = fn (array $x): array => ['passaggio' => $x['passaggio'], 'cifra' => $x['cifra']]
                + ($dallaSuccessione && ! in_array(null, $x['persone_ids'], true) ? ['persona' => self::elenco(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), $x['persone_ids']))] : [])
                + ($x['ricevuto_da'] !== $chi ? ['di' => (string) Anagrafica::whereKey($x['ricevuto_da'])->value('nome'), 'passata' => self::passataAgliEredi($x['ricevuto_da'], $daiPassaggi)] : []);
            $out[] = [
                'fine_id' => $c['fine']->id, 'fine_il' => $c['fine']->decorrenza->toDateString(), 'inquilino' => self::nomeDiChiEsce($c['fine']),
                'saldo_id' => (int) $r->id, 'persona_id' => (int) array_key_first($hanno), 'persona' => self::elenco(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), array_keys($hanno))),
                'piu_persone' => count($hanno) > 1,
                // Più persone a carico insieme: ognuna con il suo id, perché le frasi delle righe legate leggono le righe di tutte, non della prima.
                ...(count($hanno) > 1 ? ['persone' => array_values(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), array_keys($hanno))), 'persone_ids' => array_keys($hanno)] : []),
                // Chi esce, che non ha righe nella coppia, e la fermata che gli ha detto la sua parte.
                'uscente_id' => $chi, 'uscente' => (string) $uscente->anagrafica?->nome,
                'dopo_la_fermata' => $diPrima === null ? null : $dellaFermata($diPrima),
                ...($dallaSuccessione && $diPrima === null && $daDire !== [] ? ['dopo_le_fermate' => array_map($dellaFermata, $daDire)] : []),
                // Le successioni della catena senza fermata (l'arretrato a nome del defunto e «Non scriverlo»): la parte avuta con loro è rimasta a carico del defunto, e la frase lo dice.
                ...($dallaSuccessione && $defuntiSenzaFermata !== [] ? ['dopo_le_successioni' => self::successioniSenzaFermata($daiPassaggi, $defuntiSenzaFermata)] : []),
                'immobile_id' => $c['immobile_id'], 'unita' => $c['unita'], 'gestione_id' => $c['gestione_id'], 'gestione' => $c['gestione'], 'esercizio_id' => $c['esercizio_id'],
                // G13: la riga di chi è rimasto a carico (di tutti, se sono più d'uno), non tutta la coppia.
                'coppia' => (int) array_sum($hanno), 'cifra' => $cifra, 'dal' => ($decorrenza->gt($dal) ? $decorrenza : $dal)->toDateString(),
                'assorbita' => $gruppo->contains(fn (Saldo $x) => (bool) $x->is_applicato),
                'verso' => array_values(array_map(fn (int $id) => (string) ($nomi[$id] ?? '?'), array_keys($parti))),
                'parti' => array_map(fn (int $id) => ['anagrafica_id' => $id, 'nome' => (string) ($nomi[$id] ?? '?'), 'cifra' => $parti[$id]], array_keys($parti)),
                'wallet' => false,
            ] + ($cifre !== null ? ['cifre_per_persona' => $cifre] : [])
                // Coda 353: le persone a carico non si sanno (nessuna origine registrata che le dica) e più di una potrebbe avere i giorni: le frasi non ne nominano nessuna.
                + (! $restretto && ! $dallaSuccessione && count($hanno) > 1 ? ['riga_non_nota' => true] : [])
                + ($dallaSuccessione ? self::ereditata($c, $chi, (int) $fine->condominio_id) : []);
        }

        return $out;
    }

    /**
     * La cifra di una fermata dove chi riceve è una delle persone a carico, divisa fra loro: per origine, in proporzione alla quota che ogni fermata di prima ha dato alla riga che chi
     * esce vende (`quotePerOrigine`), e dentro l'origine fra le sue persone (gli eredi di un defunto), in proporzione alla loro riga della coppia. Il centesimo che avanza va alla fermata
     * più vecchia. Null se non si sa dividere: la riga di chi esce non si legge, o le quote delle fermate non sono tutta la sua riga (una parte viene da altro: un acquisto senza fermata,
     * una successione, una riga cambiata a mano).
     *
     * @param array<string, mixed> $c la coppia
     * @param list<array<string, mixed>> $diPrimaTutte le fermate di prima, dall'ultima (`fermateDi`)
     * @param array<int, int> $hanno le persone a carico, con la loro riga della coppia
     * @param Collection<int, string> $nomi
     * @return list<array{anagrafica_id: int, nome: string, cifra: int}>|null nell'ordine delle persone a carico
     */
    private function cifrePerPersona(array $c, int $chi, ?int $rigaId, array $diPrimaTutte, array $hanno, int $cifra, Collection $nomi): ?array
    {
        $righe = $this->chiPaga->righe((int) $c['immobile_id']);
        $riga = $righe->first(fn ($r) => (int) $r->id === $rigaId);
        $origini = array_values(array_reverse($diPrimaTutte));
        $perPassaggio = self::quotePerOrigine($righe, Subentro::where('immobile_id', (int) $c['immobile_id'])->orderBy('id')->get(), $chi,
            array_combine(array_column($origini, 'capo_id'), array_column($origini, 'capo_id')))[$rigaId] ?? [];
        $quote = array_map(fn (array $x) => (float) ($perPassaggio[(int) $x['capo_id']] ?? 0.0), $origini);
        if (abs(array_sum($quote) - (float) ($riga->quota ?? -1)) > 1e-6) {
            return null;
        }
        $perOrigine = MoneyHelper::ripartisciPerQuote($cifra, $quote);
        $perPersona = [];
        foreach ($origini as $i => $x) {
            $righeDelleSue = [];
            foreach ($x['persone_ids'] as $id) {
                $righeDelleSue[(int) $id] = (float) ($hanno[(int) $id] ?? 0.0);
            }
            foreach (MoneyHelper::ripartisciPerQuote($perOrigine[$i], $righeDelleSue) as $id => $cent) {
                $perPersona[$id] = ($perPersona[$id] ?? 0) + $cent;
            }
        }

        // Nell'ordine delle persone a carico (la riga più grande per prima), come le dice la frase.
        $ordine = array_filter(array_unique([...array_keys($hanno), ...array_keys($perPersona)]), fn (int $id) => isset($perPersona[$id]));

        return array_values(array_map(fn (int $id) => ['anagrafica_id' => $id, 'nome' => (string) ($nomi[$id] ?? '?'), 'cifra' => $perPersona[$id]], $ordine));
    }

    /**
     * Chi ha le righe a debito di una coppia, in centesimi (anagrafica → centesimi). Una successione con l'arretrato agli eredi che ha preso
     * fra le sue fonti la riga di una persona ha passato agli eredi tutta la sua posizione, coppia compresa (decisione 65): quella riga è
     * degli eredi, ciascuno per la sua quota, con i resti maggiori. Coda 350: le successioni si leggono in catena (`catena`), e la riga di chi è
     * morto dopo, anche lui con l'arretrato agli eredi, è dei suoi eredi.
     *
     * @param array{righe: array<int, int>, saldi: array<int, list<int>>} $c
     * @return array<int, int>
     */
    private function chiHaLaCoppia(array $c, int $condominioId): array
    {
        $hanno = $c['righe'];
        foreach ($c['saldi'] as $anagraficaId => $ids) {
            $catena = self::catena((int) $anagraficaId, (int) $c['righe'][$anagraficaId], $ids, $condominioId);
            if ($catena[0]['successione'] === null) {
                continue;
            }
            unset($hanno[$anagraficaId]);
            foreach ($catena as $x) {
                $hanno[$x['chi']] = ($hanno[$x['chi']] ?? 0) + $x['cifra'];
            }
        }

        return array_filter($hanno, fn (int $cent) => $cent !== 0);
    }

    /**
     * Coda 350: chi ha oggi la riga della coppia di una persona, con le successioni con l'arretrato agli eredi lette in catena. La successione che ha preso fra le sue
     * fonti una riga della persona ha passato ai suoi eredi tutta la sua posizione (decisione 65), ciascuno per la sua quota con i resti maggiori; se un erede è morto a sua
     * volta con l'arretrato agli eredi, la successione che ha preso fra le sue fonti le righe dell'arretrato che la prima gli aveva scritto ha passato la sua parte ai suoi
     * eredi, e così avanti: la riga di Mario è di Ugo dopo la prima successione, di Bruno dopo la seconda. Ogni voce dice chi l'ha ora, quanto, l'ultima successione che
     * gliel'ha passata (null se nessuna: la riga è ancora della persona) e i defunti per cui è passata, dal primo.
     *
     * @param list<int> $saldoIds le righe della persona da cui si parte
     * @return non-empty-list<array{chi: int, cifra: int, successione: ?Subentro, defunti: list<int>}>
     */
    private static function catena(int $persona, int $cifra, array $saldoIds, int $condominioId): array
    {
        $successione = Subentro::successioneCheLeggeISaldi($saldoIds, $condominioId);
        $eredi = $successione !== null && $successione->arretratoAgliEredi() ? self::quoteDegliEredi($successione) : [];
        if ($eredi === []) {
            return [['chi' => $persona, 'cifra' => $cifra, 'successione' => null, 'defunti' => []]];
        }
        $righeDegliEredi = Saldo::whereIn('id', $successione->saldiDellArretrato())->get(['id', 'anagrafica_id'])->groupBy(fn (Saldo $x) => (int) $x->anagrafica_id);
        $out = [];
        foreach (MoneyHelper::ripartisciPerQuote($cifra, $eredi) as $erede => $parte) {
            $suoi = ($righeDegliEredi[(int) $erede] ?? collect())->map(fn (Saldo $x) => (int) $x->id)->values()->all();
            foreach (self::catena((int) $erede, (int) $parte, $suoi, $condominioId) as $x) {
                $out[] = ['chi' => $x['chi'], 'cifra' => $x['cifra'], 'successione' => $x['successione'] ?? $successione, 'defunti' => [$persona, ...$x['defunti']]];
            }
        }

        return $out;
    }

    /** Le quote degli eredi di una successione (persona → quota ereditata), nell'ordine del modulo e senza chi ha quota zero. @return array<int, float> */
    private static function quoteDegliEredi(Subentro $successione): array
    {
        return collect($successione->destinatari())->mapWithKeys(fn (array $e) => [(int) $e['anagrafica_id'] => (float) $e['quota']])->filter(fn (float $q) => $q > 0)->all();
    }

    /**
     * Chi ha preso il posto di chi è morto con l'arretrato agli eredi, sulle righe di questa coppia: il defunto e i suoi eredi, nell'ordine del modulo. È la
     * stessa lettura di `chiHaLaCoppia`, per le persone che le fermate di prima nominano; con le successioni in catena (Coda 350) al posto di ogni defunto della
     * catena ci sono gli eredi di oggi: anche al posto di Ugo, erede di Mario e morto a sua volta, che una fermata può nominare (la sua successione).
     *
     * @param array{righe: array<int, int>, saldi: array<int, list<int>>} $c
     * @return array<int, list<int>> defunto → eredi
     */
    private static function alPostoDeiDefunti(array $c, int $condominioId): array
    {
        $out = [];
        foreach ($c['saldi'] as $anagraficaId => $ids) {
            foreach (self::catena((int) $anagraficaId, (int) $c['righe'][$anagraficaId], $ids, $condominioId) as $x) {
                foreach ($x['defunti'] as $defunto) {
                    $out[$defunto] = array_values(array_unique([...($out[$defunto] ?? []), $x['chi']]));
                }
            }
        }

        return $out;
    }

    /**
     * Chi ha preso il posto dei defunti nelle righe della coppia di una fine, su un'unità e una gestione (la lettura di `alPostoDeiDefunti`): per i testi che nominano chi ha pagato
     * i giorni dopo l'uscita, come il pannello dell'inizio di una locazione, dove chi è morto con l'arretrato agli eredi ha lasciato loro la sua riga.
     *
     * @return array<int, list<int>> defunto → eredi di oggi
     */
    public static function eredi(Subentro $fine, int $immobileId, int $gestioneId): array
    {
        $righe = Saldo::where('subentro_id', $fine->id)->where('immobile_id', $immobileId)->where('gestione_id', $gestioneId)->where('saldo_iniziale', '!=', 0)
            ->where('anagrafica_id', '!=', (int) $fine->anagrafica_uscente_id)->get(['id', 'anagrafica_id', 'saldo_iniziale'])->groupBy(fn (Saldo $r) => (int) $r->anagrafica_id);

        return self::alPostoDeiDefunti([
            'righe' => $righe->map(fn ($g) => (int) $g->sum('saldo_iniziale'))->all(),
            'saldi' => $righe->map(fn ($g) => $g->pluck('id')->map(fn ($id) => (int) $id)->all())->all(),
        ], (int) $fine->condominio_id);
    }

    /**
     * Le fermate di prima (`fermateDi`) con il defunto letto come i suoi eredi: le persone a carico (`persone_ids`) e, se è una sola, `persona_id`. Una fermata di
     * prima della 1.11.0-beta.53 con più persone senza i loro ids (`[null]`) resta com'è.
     *
     * @param list<array<string, mixed>> $fermate
     * @param array<int, list<int>> $alPosto defunto → eredi (`alPostoDeiDefunti`)
     * @return list<array<string, mixed>>
     */
    private static function coiLoroEredi(array $fermate, array $alPosto): array
    {
        if ($alPosto === []) {
            return $fermate;
        }

        return array_map(function (array $x) use ($alPosto) {
            if (in_array(null, $x['persone_ids'], true)) {
                return $x;
            }
            $ids = array_values(array_unique(array_merge(...array_map(fn (int $id) => $alPosto[$id] ?? [$id], $x['persone_ids']))));

            return ['persone_ids' => $ids, 'persona_id' => count($ids) === 1 ? $ids[0] : null] + $x;
        }, $fermate);
    }

    /**
     * La successione con l'arretrato agli eredi che ha passato all'erede la sua parte della coppia (decisione 65), con il defunto che aveva la riga e la parte: la
     * stessa lettura di `chiHaLaCoppia`, dalle righe della coppia, con le successioni in catena (Coda 350: l'ultima, quella che ha aperto la riga dell'erede). Null se
     * l'erede non ha preso la coppia da nessuna successione.
     *
     * @return array{0: Subentro, 1: int, 2: int}|null la successione, l'id del defunto che aveva la riga e la parte dell'erede
     */
    private static function successioneDellErede(array $c, int $erede, int $condominioId): ?array
    {
        foreach ($c['saldi'] as $anagraficaId => $ids) {
            $suoi = array_values(array_filter(self::catena((int) $anagraficaId, (int) $c['righe'][$anagraficaId], $ids, $condominioId),
                fn (array $x) => $x['chi'] === $erede && $x['successione'] !== null));
            if ($suoi !== []) {
                return [$suoi[0]['successione'], (int) $anagraficaId, (int) array_sum(array_column($suoi, 'cifra'))];
            }
        }

        return null;
    }

    /** La fermata di prima è a carico solo di chi esce (il defunto di cui è l'unico erede): la sua parte è già sua, e non c'è niente da regolare con nessuno. */
    private static function tuttaDiChiEsce(array $fermata, int $chi): bool
    {
        return ! in_array(null, $fermata['persone_ids'], true) && array_values(array_unique($fermata['persone_ids'])) === [$chi];
    }

    /**
     * Chi riceve con un passaggio, e quanto: ogni persona diversa da chi esce (e da chi ha già preso quei giorni, `$esclusi`) di cui il
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
     * Coda 341: anche la parte che la successione ha passato all'erede (`sua`), per la frase dell'erede che ha anche comprato.
     *
     * @return array{ereditata?: array{da: string, coppia: int, passaggio: string, sua: int}}
     */
    private static function ereditata(array $c, int $erede, int $condominioId): array
    {
        $daLui = self::successioneDellErede($c, $erede, $condominioId);
        if ($daLui === null) {
            return [];
        }
        [$successione, $defuntoId, $sua] = $daLui;
        // Coda 357: l'erede che ha avuto con le successioni più righe della coppia (quella di Mario, e quella di Ugo che gliel'aveva lasciata) le dice tutte: chi le
        // aveva prese e la loro somma, perché la parte passata all'erede non sia più grande di quella che la frase dice presa.
        $da = [];
        $coppia = 0;
        foreach ($c['saldi'] as $anagraficaId => $ids) {
            $suoi = array_filter(self::catena((int) $anagraficaId, (int) $c['righe'][$anagraficaId], $ids, $condominioId), fn (array $x) => $x['chi'] === $erede && $x['successione'] !== null);
            if ($suoi !== []) {
                $da[] = (string) Anagrafica::whereKey((int) $anagraficaId)->value('nome');
                $coppia += (int) $c['righe'][$anagraficaId];
            }
        }

        return ['ereditata' => ['da' => self::elenco($da), 'coppia' => $coppia, 'piu' => count($da) > 1,
            'passaggio' => self::passaggioInParole($successione), 'sua' => (int) $sua]];
    }

    /**
     * Coda 346: la successione della catena con cui la parte del defunto è passata ai suoi eredi, e a chi («la successione del 15 ottobre 2026 a Bruno e Dora»); se
     * uno di loro è morto a sua volta, anche la sua («e con la successione del … a …»).
     *
     * @param list<int> $passaggi i passaggi della catena della riga di chi esce (`passaggiDellaRiga`)
     */
    private static function passataAgliEredi(int $defunto, array $passaggi): string
    {
        $successioni = self::successioniDellaCatena($passaggi);
        $parole = [];
        $chi = [$defunto];
        while (($s = $successioni->first(fn (Subentro $x) => in_array((int) $x->anagrafica_uscente_id, $chi, true))) !== null) {
            $successioni = $successioni->reject(fn (Subentro $x) => (int) $x->id === (int) $s->id);
            $chi = array_map(fn (array $e) => (int) $e['anagrafica_id'], $s->destinatari());
            $parole[] = sprintf('%s %s', self::passaggioInParole($s), FrasiObbligati::a(self::elenco(Anagrafica::whereIn('id', $chi)->pluck('nome', 'id')->only($chi)->values()->all())));
        }

        return implode(' e con ', $parole);
    }

    /**
     * Le successioni della catena della riga di chi esce (`passaggiDellaRiga`): la catena arriva a una successione solo se ha aperto una riga di chi esce, o la riga di
     * un defunto che gli ha lasciato la sua posizione.
     *
     * @param list<int> $passaggi
     * @return Collection<int, Subentro>
     */
    private static function successioniDellaCatena(array $passaggi): Collection
    {
        return Subentro::whereIn('id', $passaggi)->where('tipo_passaggio', 'successione')->orderBy('id')->get();
    }

    /**
     * I defunti delle successioni della catena: le quote che hanno lasciato a chi esce sono sue con la loro storia, comprese le fermate dove il defunto riceveva (la riga
     * nata da un'estinzione, o dall'acquisto della quota di un altro).
     *
     * @param list<int> $passaggi
     * @return list<int> gli id dei defunti
     */
    private static function defuntiDellaCatena(array $passaggi): array
    {
        return array_values(array_unique(self::successioniDellaCatena($passaggi)->map(fn (Subentro $s) => (int) $s->anagrafica_uscente_id)->all()));
    }

    /**
     * I defunti che hanno lasciato la parte di chi esce con una successione della sua catena senza fermata: il conguaglio non è stato scritto («Non scriverlo», che si
     * chiede solo con l'arretrato a nome del defunto), quindi la posizione del defunto è com'era e la sua riga della coppia è ancora sua. Con l'arretrato agli eredi la
     * riga è passata a loro, e con «Scrivi» la successione ha la sua fermata: né l'uno né l'altro caso sono qui.
     *
     * @param list<int> $passaggi i passaggi della catena della riga di chi esce (`passaggiDellaRiga`)
     * @return list<int> gli id dei defunti
     */
    private static function defuntiSenzaFermata(array $passaggi): array
    {
        return array_values(array_unique(self::successioniDellaCatena($passaggi)
            ->filter(fn (Subentro $s) => ($s->registro['conguaglio']['scelta'] ?? null) === Subentro::CONGUAGLIO_NON_SCRITTO)
            ->map(fn (Subentro $s) => (int) $s->anagrafica_uscente_id)->all()));
    }

    /**
     * Le successioni della catena senza fermata dei defunti dati (`defuntiSenzaFermata`), dalla più vecchia: la successione in parole e il defunto, a carico del quale è rimasta la
     * parte che chi esce ha avuto con lei. Per la frase e il motivo della rivendita dell'erede.
     *
     * @param list<int> $passaggi i passaggi della catena della riga di chi esce (`passaggiDellaRiga`)
     * @param list<int> $defunti
     * @return list<array{passaggio: string, di: string}>
     */
    private static function successioniSenzaFermata(array $passaggi, array $defunti): array
    {
        return self::successioniDellaCatena($passaggi)
            ->filter(fn (Subentro $s) => in_array((int) $s->anagrafica_uscente_id, $defunti, true) && ($s->registro['conguaglio']['scelta'] ?? null) === Subentro::CONGUAGLIO_NON_SCRITTO)
            ->map(fn (Subentro $s) => ['passaggio' => self::passaggioInParole($s), 'di' => (string) ($s->uscente?->nome ?? ($s->registro['nomi']['uscente'] ?? '?'))])
            ->values()->all();
    }

    /**
     * Decisione 81.2: le fermate registrate che hanno detto a chi esce una sua parte della coppia (o, passata con una successione, a chi è morto prima di lui e gli ha
     * lasciato la sua posizione: `$anagraficaIds`), dall'ultima: una per passaggio, con il passaggio e la cifra per le
     * frasi, il passaggio padre (`capo_id`), se è la fermata della fine di un conduttore (`del_conduttore`), chi è rimasto a carico (`persona_id` se è
     * uno; `persone_ids` tutti, o `[null]` se una fermata di prima della 1.11.0-beta.53 ha più persone senza i loro ids) e chi aveva ricevuto (`ricevuto_da`:
     * chi esce, o il defunto che gli ha lasciato la parte).
     *
     * @return list<array{passaggio: string, cifra: int, ricevuto_da: int, persona_id: ?int, capo_id: int, del_conduttore: bool, persone_ids: list<?int>}>
     */
    private function fermateDi(array $c, array $anagraficaIds): array
    {
        $out = [];
        foreach (Subentro::where('immobile_id', $c['immobile_id'])->whereNull('annullato_il')->orderByDesc('id')->get() as $qui) {
            $s = $qui->capo();
            if (isset($out[(int) $s->id]) || $s->annullato()) {
                continue;
            }
            foreach ($s->registro['fermata_dopo_scrivi'] ?? [] as $f) {
                $parte = collect($f['parti'] ?? [])->first(fn ($p) => in_array((int) ($p['anagrafica_id'] ?? 0), $anagraficaIds, true));
                if ($parte !== null && (int) ($f['fine_id'] ?? 0) === (int) $c['fine']->id && (int) ($f['immobile_id'] ?? 0) === (int) $c['immobile_id']
                    && (int) ($f['gestione_id'] ?? 0) === (int) $c['gestione_id']) {
                    $persone = match (true) {
                        empty($f['piu_persone']) => [isset($f['persona_id']) ? (int) $f['persona_id'] : null],
                        isset($f['persone_ids']) => array_map('intval', (array) $f['persone_ids']),
                        default => [null],
                    };
                    // Coda 354: due fermate dello stesso passaggio verso la stessa persona (la parte dalla riga di chi esce e quella dalla quota di una fermata che lui aveva
                    // ricevuto) sono una fermata sola, a carico delle persone di tutte e due.
                    if (isset($out[(int) $s->id]) && $out[(int) $s->id]['ricevuto_da'] === (int) $parte['anagrafica_id']) {
                        $out[(int) $s->id]['cifra'] += (int) $parte['cifra'];
                        $out[(int) $s->id]['persone_ids'] = array_values(array_unique([...$out[(int) $s->id]['persone_ids'], ...$persone], SORT_REGULAR));
                        $out[(int) $s->id]['persona_id'] = count($out[(int) $s->id]['persone_ids']) === 1 ? $out[(int) $s->id]['persone_ids'][0] : null;
                        continue;
                    }
                    $out[(int) $s->id] ??= ['passaggio' => self::passaggioInParole($s), 'cifra' => (int) $parte['cifra'], 'ricevuto_da' => (int) $parte['anagrafica_id'],
                        'persona_id' => empty($f['piu_persone']) && isset($f['persona_id']) ? (int) $f['persona_id'] : null, 'capo_id' => (int) $s->id,
                        'del_conduttore' => isset($f['conduttore_uscente']), 'persone_ids' => $persone];
                }
            }
        }

        return array_values($out);
    }

    /** La riga che questo passaggio chiude sull'unità della coppia: la riga del modulo, o sulla pertinenza quella che la registrazione chiude davvero (decisione 81.3). */
    private static function rigaCheChiude(int $immobileId, TitolaritaImmobile $uscente, CarbonImmutable $decorrenza): ?int
    {
        return $immobileId === (int) $uscente->immobile_id ? (int) $uscente->id
            : self::rigaDellaPertinenza($immobileId, (int) $uscente->anagrafica_id, (string) $uscente->tipologia, $decorrenza->subDay());
    }

    /**
     * I passaggi da cui viene la parte che una persona ha oggi su un'unità, dalla sua riga: il passaggio che l'ha aperta (o che ci ha sommato una quota,
     * lo stesso giorno) e, se per aprirla ha chiuso una riga di prima della stessa persona (chi compra l'altra metà: la riga si chiude e se ne apre una
     * con la somma), i passaggi di quella riga, e così indietro, come li scrive il registro di ogni passaggio (`righe`). Le parti avute prima di
     * un'uscita non ci sono: la loro riga l'ha chiusa la rivendita, non un acquisto. Se la riga l'ha aperta una successione, la riga che il defunto aveva e che si è
     * chiusa con lei è di chi eredita: si segue anche quella, con i passaggi che l'hanno aperta (l'estinzione dell'usufrutto che l'ha fatto tornare proprietario pieno,
     * l'acquisto della quota di un altro). I passaggi padre, dal primo; vuoto se la riga non si trova.
     *
     * @return list<int>
     */
    private static function passaggiDellaRiga(int $immobileId, ?int $rigaId, int $anagraficaId): array
    {
        if ($rigaId === null) {
            return [];
        }
        $passaggi = Subentro::where('immobile_id', $immobileId)->orderBy('id')->get();
        $righeSu = fn (int $persona): array => TitolaritaImmobile::where('immobile_id', $immobileId)->where('anagrafica_id', $persona)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $sue = array_flip($righeSu($anagraficaId));
        $origini = [];
        $daLeggere = [$rigaId];
        $lette = [];
        while (($riga = array_pop($daLeggere)) !== null) {
            if (isset($lette[$riga])) {
                continue;
            }
            $lette[$riga] = true;
            foreach ($passaggi as $p) {
                $operazioni = (array) ($p->registro['righe'] ?? []);
                $haLaRiga = collect($operazioni)->contains(fn ($o) => in_array($o['operazione'] ?? null, ['aperta', 'modificata'], true) && (int) ($o['id'] ?? 0) === $riga);
                if (! $haLaRiga || $p->capo()->annullato()) {
                    continue;
                }
                $origini[(int) $p->capo()->id] = true;
                // La successione passa a chi eredita le quote del defunto con la loro storia, qualunque sia la sorte dell'arretrato: le righe del defunto che si chiudono con
                // lei (quella nata da un'estinzione, o da un acquisto) si seguono come le righe di chi esce.
                if ($p->capo()->successione()) {
                    $sue += array_flip($righeSu((int) $p->capo()->anagrafica_uscente_id));
                }
                foreach ($operazioni as $o) {
                    if (($o['operazione'] ?? null) === 'chiusa' && isset($sue[(int) ($o['id'] ?? 0)])) {
                        $daLeggere[] = (int) $o['id'];
                    }
                }
            }
        }
        ksort($origini);

        return array_keys($origini);
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
            // Coda 245: sull'anello di una fine con la regola del piano la parte di chi rientra la dice la regola del piano (`RegolaDelPiano::dopoUnaFine`).
            if ((int) $c['fine']->anagrafica_uscente_id !== (int) $entrante->id || self::gruppoDelPiano($c['fine'], (int) $c['immobile_id'], (int) $c['gestione_id'], $c['esercizio_id']) !== null) {
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
            // Chi rientra lo dice `rientro`. Coda 245: sull'anello di una fine con la regola del piano la parte di chi entra la dice la regola del
            // piano (`RegolaDelPiano::dopoUnaFine`), anche quella dei giorni che nessun conduttore copriva.
            if ($fine === null || ! self::fineScritta($fine) || (int) $fine->anagrafica_uscente_id === (int) $entrante->id
                || self::gruppoDelPiano($fine, (int) $r->immobile_id, (int) $r->gestione_id, $r->esercizio_id !== null ? (int) $r->esercizio_id : null) !== null) {
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
                        'pagato_da' => $this->conChiNeRisponde(self::chiHaPagato($c, $prima, $dopo, $cifra), $fine, $c, $righeUnita, $decorrenza)];
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
     * Coda 352 (decisione 112.1): fra chi ha pagato i giorni del nuovo conduttore c'è anche chi è rimasto a carico di una fermata, per la quota che la
     * fermata ha passato a chi riceve (`chiHaPagatoIlTratto`, sui giorni della coppia dal giorno dell'inizio): la stessa lettura del prospetto.
     *
     * @param list<string> $nomi chi ha pagato, da `chiHaPagato`
     * @param array<string, mixed> $c la coppia (`coppia`)
     * @param Collection<int, object> $righeUnita
     * @return list<string>
     */
    private function conChiNeRisponde(array $nomi, Subentro $fine, array $c, Collection $righeUnita, CarbonImmutable $decorrenza): array
    {
        $giorni = null;
        foreach ($c['pezzi'] as $pz) {
            foreach ($pz['tratti']->periodi() as $t) {
                $x = $t->al->lt($decorrenza) ? null : $t->intersezione(new PeriodoCompetenza($decorrenza, $t->al));
                if ($x !== null) {
                    $giorni = $giorni === null ? InsiemePeriodi::uno($x) : $giorni->unione(InsiemePeriodi::uno($x));
                }
            }
        }
        if ($giorni === null) {
            return $nomi;
        }
        foreach ($this->chiHaPagatoIlTratto($fine, (int) $c['immobile_id'], (int) $c['gestione_id'], $righeUnita, $giorni)['fermate'] as $x) {
            foreach (Anagrafica::whereIn('id', $x['persone'])->pluck('nome') as $nome) {
                $nomi[] = (string) $nome;
            }
        }

        return array_values(array_unique($nomi));
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
     * Decisione 112.1 (1.11.0-beta.55): se chi riceve ha anche una sua parte della coppia, il conduttore toglie alla fermata solo i giorni della quota
     * che la fermata gli ha passato, non quelli della sua riga; una fermata che per questa via non è più azzerata torna a essere un motivo del cancello.
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
                // La parte di ognuno prima di questo inizio: annullato l'inizio, torna questa (`avvisoRidottaAnnullando`).
                'parti_prima' => self::partiDeiRiceventi($g, $ora),
                'conduttore' => (string) $entrante->nome, 'dal' => $decorrenza->toDateString(),
            ];
        }

        return $out;
    }

    /**
     * Coda 351 (1.11.0-beta.55): un passaggio che riporta i giorni di chi esce all'unica persona a carico di quei giorni (Elsa rivende a Ugo, che le aveva venduto
     * tutto; l'erede unico che ricompra; l'usufrutto che Ugo aveva costituito a Elsa si estingue) non fa nascere una fermata, perché quei giorni stanno già nella riga
     * di chi li riceve; ma la fermata di prima, che li aveva lasciati a suo carico verso chi esce, si riduce ai giorni che chi esce ha avuto. Si dice come la riduce
     * l'inizio di una locazione (`fermateRidotte`): quanto ne resta, con la spunta del cancello e, con le righe legate, la strada per correggerle dal Wallet. La
     * voce porta chi ricompra (`ricompra`) e il giorno (`dal`) al posto del conduttore.
     *
     * Coda 356 (1.11.0-beta.55): lo stesso con la successione di chi riceveva, quando l'erede è l'unica persona a carico (Elsa compra da Ugo e muore, erede Ugo). Con
     * l'arretrato a nome di chi muore restano a suo carico i suoi giorni fino alla morte; con l'arretrato agli eredi la sua posizione, con la sua parte della fermata,
     * passa all'erede, che è la persona a carico, e non resta niente. La voce porta chi muore (`erede_di`) e, agli eredi, `agli_eredi`.
     *
     * @param array<string, mixed> $c la coppia (`coppia`)
     * @param Collection<int, object> $righe le righe dell'unità di adesso
     * @param Collection<int, object> $righeDopo le righe con il passaggio (`conIlPassaggio`)
     * @return list<array<string, mixed>>
     */
    private function ridottaDaChiRicompra(array $c, int $chi, int $persona, Collection $righe, Collection $righeDopo, CarbonImmutable $decorrenza, bool $successione = false,
        bool $agliEredi = false): array
    {
        $out = [];
        foreach ($this->gruppiDiFermate([(int) $c['immobile_id']]) as $g) {
            if ((int) $g['c']['fine']->id !== (int) $c['fine']->id || (int) $g['c']['gestione_id'] !== (int) $c['gestione_id'] || ($g['c']['esercizio_id'] ?? null) !== ($c['esercizio_id'] ?? null)
                || ! collect($g['riceventi'])->contains(fn (array $r) => (int) $r['anagrafica_id'] === $chi)) {
                continue;
            }
            $ora = $this->residuiDelleFermate($g, $righe);
            $con = $this->residuiDelleFermate($g, $righe, $righeDopo);
            if ($agliEredi) {
                $con[$chi] = 0;
            }
            if ((int) array_sum($con) === (int) array_sum($ora)) {
                continue;
            }
            $out[] = self::voceDelGruppo($g) + [
                'dal_wallet' => self::saldiDalWallet($g),
                'prima' => (int) array_sum($ora), 'residuo' => (int) array_sum($con),
                'parti' => array_values(array_filter(self::partiDeiRiceventi($g, $con), fn (array $p) => $p['cifra'] !== 0)),
                'parti_prima' => self::partiDeiRiceventi($g, $ora),
                'ricompra' => (string) Anagrafica::whereKey($persona)->value('nome'), 'dal' => $decorrenza->toDateString(),
            ] + ($successione ? ['erede_di' => (string) Anagrafica::whereKey($chi)->value('nome')] : []) + ($agliEredi ? ['agli_eredi' => true] : []);
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
     * Decisione 112.1 (1.11.0-beta.55): chi riceve e ha anche una sua parte della coppia (Ugo con la sua riga che ricompra da Elsa la quota di Anna; Anna, Zeno
     * o l'altro erede che comprano dall'erede; Anna che compra la metà di Ugo) si divide in due, la parte che aveva e la quota che gli hanno passato i passaggi
     * delle fermate (`quoteDelleFermate`, dalle righe del loro registro): il conduttore toglie alla fermata solo i giorni della quota (`laQuota`). Prima toglieva
     * la differenza della parte intera, anche dei giorni della sua riga, che con la fermata non c'entrano: la fermata risultava azzerata, o ridotta troppo.
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
        $quote = $this->quoteDelleFermate($g, $righe);
        // Le righe senza i conduttori entrati con un passaggio registrato dopo quello dato: quei conduttori hanno già tolto i loro giorni. A pari resto il
        // centesimo a chi è uscito con la fermata, come nella fermata: dopo una fermata (decisione 81.2) non ha righe nella coppia, e lo dice `uscente_id`.
        $base = function (int $dopo, int $chi) use ($righe, $u, $c): array {
            $entratiDopo = Subentro::where('immobile_id', $u)->whereIn('tipo_passaggio', ['inizio_locazione', 'fine_locazione'])->where('id', '>', $dopo)
                ->whereNotNull('riga_entrante_id')->pluck('riga_entrante_id')->map(fn ($id) => (int) $id)->all();
            $senza = $righe->reject(fn ($r) => in_array((int) $r->id, $entratiDopo, true))->values();
            $pesi = $this->pesi($senza, $c['pezzi']);

            return ['righe' => $senza, 'pesi' => $pesi, 'parti' => $this->chiPaga->dividi($c['totale'], $pesi, $chi)];
        };
        $basi = [];
        foreach ($g['fermate'] as $k => $x) {
            $basi[$k] = $base((int) $x['s']->id, (int) ($x['f']['uscente_id'] ?? $x['f']['persona_id']));
        }
        // Coda 351, la forma piena (decisione 113.1): una ricompera registrata dopo una fermata del gruppo (`fermata_ridotta` con `ricompra`) ha già detto
        // quanto ne restava a ognuno di chi riceveva, con i giorni tornati a chi ricompra: da lì si riparte, con le righe senza i conduttori entrati dopo di lei.
        // È la stessa voce che legge lo storico (`tornateDopo`). Ogni ricompera sta, nell'ordine di registrazione, dopo le fermate registrate prima di lei.
        $ricompere = [];
        foreach ($this->ricompereDelGruppo($g) as $r) {
            $prima = array_values(array_filter(array_keys($g['fermate']), fn (int $k) => (int) $g['fermate'][$k]['s']->id < $r['id']));
            $k = end($prima);
            $ricompere[$k][] = $r + ['base' => $base($r['id'], (int) ($g['fermate'][$k]['f']['uscente_id'] ?? $g['fermate'][$k]['f']['persona_id']))];
        }
        $pesiOra = $this->pesi($target ?? $righe, $c['pezzi']);
        $ora = ['righe' => $target ?? $righe, 'pesi' => $pesiOra,
            'parti' => $this->chiPaga->dividi($c['totale'], $pesiOra, (int) ($g['fermate'][0]['f']['uscente_id'] ?? $g['fermate'][0]['f']['persona_id']))];
        // Quanto un cambio di righe toglie a una persona: la differenza delle sue parti, solo se il suo peso cala. Per chi si divide (decisione 112.1), la
        // differenza della parte della quota: è la parte dei suoi giorni al centesimo più vicino, e un centesimo non si sposta senza un giorno.
        $tolto = fn (int $id, array $da, array $a, array $quota): int => $quota === []
            ? (abs($a['pesi'][$id] ?? 0.0) >= abs($da['pesi'][$id] ?? 0.0) - 1e-9 ? 0 : ($da['parti'][$id] ?? 0) - ($a['parti'][$id] ?? 0))
            : $this->laQuota($da['righe'], $id, $quota, $c) - $this->laQuota($a['righe'], $id, $quota, $c);
        $riduci = fn (int $somma, int $togli): int => $somma >= 0 ? max(0, $somma - max(0, $togli)) : min(0, $somma - min(0, $togli));

        $out = [];
        foreach ($g['riceventi'] as $r) {
            $id = (int) $r['anagrafica_id'];
            $somma = 0;
            $ultima = null;
            $da = null;
            foreach ($g['fermate'] as $k => $x) {
                if ($ultima !== null) {
                    $somma = $riduci($somma, $tolto($id, $da, $basi[$k], $quote[$id][$ultima] ?? []));
                }
                $parte = collect($x['f']['parti'])->first(fn (array $p) => (int) $p['anagrafica_id'] === $id);
                $somma += (int) ($parte['cifra'] ?? 0);
                $ultima = $k;
                $da = $basi[$k];
                foreach ($ricompere[$k] ?? [] as $q) {
                    if (array_key_exists($id, $q['prima'])) {
                        $somma = (int) ($q['parti'][$id] ?? 0);
                        $da = $q['base'];
                    }
                }
            }
            $out[$id] = $riduci($somma, $tolto($id, $da, $ora, $quote[$id][$ultima] ?? []));
        }

        return $out;
    }

    /**
     * Coda 351, la forma piena: le ricompere registrate dopo le fermate di un gruppo, non annullate, sulla stessa coppia, la cui voce `fermata_ridotta` con `ricompra`
     * nomina una fermata del gruppo (`passaggio_id`, o fra i `passaggi_ids`). Per ognuna, chi riceveva allora (`prima`, la parte di prima della ricompera) e quanto
     * gliene restava (`parti`).
     *
     * @return list<array{id: int, prima: array<int, int>, parti: array<int, int>}>
     */
    private function ricompereDelGruppo(array $g): array
    {
        $fermate = array_map(fn (array $x) => (int) $x['s']->id, $g['fermate']);
        $c = $g['c'];
        $out = [];
        foreach (Subentro::where('immobile_id', (int) $g['immobile_id'])->where('id', '>', min($fermate))->orderBy('id')->get()->map(fn (Subentro $s) => $s->capo())->unique('id') as $s) {
            foreach ($s->registro['fermata_ridotta'] ?? [] as $v) {
                if (! isset($v['ricompra']) || (int) ($v['fine_id'] ?? 0) !== (int) $c['fine']->id || (int) ($v['immobile_id'] ?? 0) !== (int) $g['immobile_id']
                    || (int) ($v['gestione_id'] ?? 0) !== (int) $c['gestione_id']
                    || array_intersect($fermate, [(int) ($v['passaggio_id'] ?? 0), ...array_map('intval', (array) ($v['passaggi_ids'] ?? []))]) === []) {
                    continue;
                }
                $out[] = ['id' => (int) $s->id,
                    'prima' => collect((array) ($v['parti_prima'] ?? []))->mapWithKeys(fn (array $p) => [(int) $p['anagrafica_id'] => (int) $p['cifra']])->all(),
                    'parti' => collect((array) ($v['parti'] ?? []))->mapWithKeys(fn (array $p) => [(int) $p['anagrafica_id'] => (int) $p['cifra']])->all()];
            }
        }

        return $out;
    }

    /**
     * Decisione 112.1: per chi riceve in un gruppo di fermate e ha anche una sua parte della coppia, la quota che gli hanno passato i passaggi di quelle fermate,
     * riga per riga, da ognuna delle fermate in poi (persona → fermata del gruppo → riga → quota: la somma delle fermate fino a quella). La quota di una fermata è
     * quella che il suo passaggio ha aperto a chi riceve, come la scrive il registro del passaggio (`righe`): la riga che gli apre, meno la sua riga dello stesso
     * ruolo che il passaggio chiude per sommarla (chi ha già il 75 % e compra il 25 % ha una riga sola da 100; il nudo che torna pieno e lo era già di un'altra parte),
     * o la quota che somma alla sua riga nata lo stesso giorno. Un passaggio dopo che chiude una riga con una quota e ne apre o cambia una a chi riceve (un altro
     * acquisto, che somma) le porta la quota. Chi riceve senza una sua parte della coppia non si divide: quello che ha viene tutto dalle fermate, come prima.
     *
     * @param array{immobile_id: int, c: array<string, mixed>, fermate: list<array{s: Subentro, f: array<string, mixed>}>, riceventi: list<array{anagrafica_id: int, nome: string}>} $g
     * @param Collection<int, object> $righe le righe dell'unità
     * @return array<int, array<int, array<int, float>>>
     */
    private function quoteDelleFermate(array $g, Collection $righe): array
    {
        $hanno = $this->chiHaLaCoppia($g['c'], (int) $g['c']['fine']->condominio_id);
        // Coda 352 oltre la successione: chi riceveva ed è morto con l'arretrato agli eredi ha lasciato loro la sua riga della coppia, con le quote delle fermate.
        $defunti = self::alPostoDeiDefunti($g['c'], (int) $g['c']['fine']->condominio_id);
        $passaggi = null;
        $out = [];
        foreach ($g['riceventi'] as $r) {
            $persona = (int) $r['anagrafica_id'];
            if (! isset($hanno[$persona]) && ! isset($defunti[$persona])) {
                continue;
            }
            // Le fermate del gruppo in cui riceve questa persona, per passaggio padre.
            $diQuale = [];
            $tetti = [];
            foreach ($g['fermate'] as $k => $x) {
                if (collect($x['f']['parti'])->contains(fn (array $p) => (int) $p['anagrafica_id'] === $persona)) {
                    $diQuale[(int) $x['s']->id] ??= $k;
                    if (isset($x['f']['quota_della_riga'])) {
                        $tetti[$k] = (float) $x['f']['quota_della_riga'];
                    }
                }
            }
            $passaggi ??= Subentro::where('immobile_id', (int) $g['immobile_id'])->orderBy('id')->get();
            $perRiga = self::quotePerOrigine($righe, $passaggi, $persona, $diQuale, $tetti);
            foreach (array_keys($g['fermate']) as $k) {
                $out[$persona][$k] = array_filter(array_map(fn (array $perFermata) => (float) array_sum(array_filter($perFermata, fn (int $j) => $j <= $k, ARRAY_FILTER_USE_KEY)), $perRiga),
                    fn (float $q) => $q > 1e-9);
            }
        }

        return $out;
    }

    /**
     * Le quote che i passaggi hanno dato a una persona, riga per riga, per origine: la lettura di `quoteDelleFermate`, che qui ha l'origine (l'indice di una fermata del gruppo) e che
     * `cifrePerPersona` usa con il passaggio stesso. La quota di un passaggio è quella che ha aperto alla persona, come la scrive il registro (`righe`), meno la sua riga dello
     * stesso ruolo che chiude per sommarla, o la quota che somma alla sua riga nata lo stesso giorno; le quote delle righe che chiude passano alla prima riga che le apre o le cambia.
     * I passaggi che non sono origini passano le quote delle righe che chiudono, senza aggiungerne. Coda 354: un passaggio con più fermate verso la stessa persona (la parte
     * dalla riga di chi esce e quella da una fermata che lui aveva ricevuto) ha più origini, con un peso ciascuna: la quota si divide fra loro in proporzione.
     *
     * @param Collection<int, object> $righe le righe dell'unità
     * @param Collection<int, Subentro> $passaggi i passaggi dell'unità, dal primo
     * Decisione 112.1: una voce della quota di una fermata nella riga di chi esce (`dallaFermataNellaRiga`) passa a chi riceve solo quella quota (`quota_della_riga`), anche se il
     * passaggio gli apre una riga più grande (l'erede che prende tutta la riga del defunto con l'arretrato agli eredi, Coda 355): `$tetti` dice, per origine, la quota più grande.
     *
     * @param array<int, int|array<int, float>> $origini passaggio padre → origine (o origine → peso)
     * @param array<int|string, float> $tetti origine → la quota più grande che il passaggio le dà
     * @return array<int, array<int, float>> riga → origine → quota
     */
    private static function quotePerOrigine(Collection $righe, Collection $passaggi, int $persona, array $origini, array $tetti = []): array
    {
        $perId = $righe->keyBy(fn ($r) => (int) $r->id);
        // Coda 352 oltre la successione (1.11.0-beta.55): con l'arretrato agli eredi la riga di chi muore passa agli eredi con la sua storia (decisione 65), e con lei le
        // quote che le fermate le avevano dato: da lì si seguono anche le righe degli eredi, in proporzione alla quota che ognuno riceve.
        $seguite = [$persona => true];
        $sua = function ($id) use ($perId, &$seguite): bool {
            return isset($seguite[(int) ($perId[(int) $id]->anagrafica_id ?? 0)]);
        };
        $perRiga = [];
        foreach ($passaggi as $s) {
            $k = $origini[(int) $s->capo()->id] ?? null;
            $operazioni = collect((array) ($s->registro['righe'] ?? []));
            $chiuse = $operazioni->filter(fn ($o) => ($o['operazione'] ?? null) === 'chiusa' && $sua($o['id'] ?? 0))->values();
            $portate = [];
            foreach ($chiuse as $o) {
                foreach ($perRiga[(int) $o['id']] ?? [] as $j => $q) {
                    $portate[$j] = ($portate[$j] ?? 0.0) + $q;
                }
            }
            $agliEredi = $portate !== [] && $s->capo()->successione() && $s->capo()->arretratoAgliEredi() && isset($seguite[(int) $s->capo()->anagrafica_uscente_id]);
            $eredi = $agliEredi ? array_fill_keys(array_map(fn (array $e) => (int) $e['anagrafica_id'], $s->capo()->eredi()), true) : [];
            $seguite += $eredi;
            $aperte = $operazioni->filter(fn ($o) => ($o['operazione'] ?? null) === 'aperta' && isset($seguite[(int) ($o['dopo']['anagrafica_id'] ?? 0)]))->values();
            $cambiate = $operazioni->filter(fn ($o) => ($o['operazione'] ?? null) === 'modificata' && $sua($o['id'] ?? 0))->values();
            // Con l'arretrato agli eredi le quote portate vanno alle righe degli eredi, in proporzione a quanto ognuna riceve; altrimenti alla prima riga.
            $aChi = [];
            foreach ($aperte as $i => $o) {
                if ($agliEredi && isset($eredi[(int) ($o['dopo']['anagrafica_id'] ?? 0)])) {
                    $aChi[$i] = (float) ($o['dopo']['quota'] ?? 0);
                }
            }
            $aChi = array_filter($aChi, fn (float $q) => $q > 1e-9);
            foreach ([...$aperte, ...$cambiate] as $i => $o) {
                $id = (int) $o['id'];
                $mappa = $perRiga[$id] ?? [];
                // Le quote delle righe che il passaggio chiude a chi riceve passano alla prima riga che gli apre o gli cambia.
                if ($aChi !== [] ? isset($aChi[$i]) : $i === 0) {
                    foreach ($portate as $j => $q) {
                        $mappa[$j] = ($mappa[$j] ?? 0.0) + ($aChi !== [] ? $q * $aChi[$i] / (float) array_sum($aChi) : $q);
                    }
                }
                if ($k !== null) {
                    $nuova = ($o['operazione'] ?? null) === 'aperta'
                        ? (float) ($o['dopo']['quota'] ?? 0) - (float) $chiuse->filter(fn ($x) => ($perId[(int) $x['id']]->tipologia ?? null) === ($o['dopo']['tipologia'] ?? null))
                            ->sum(fn ($x) => (float) $perId[(int) $x['id']]->quota)
                        // La riga cambiata sul posto: la quota sommata alla sua riga nata lo stesso giorno.
                        : (float) ($o['dopo']['quota'] ?? 0) - (float) ($o['prima']['quota'] ?? 0);
                    $pesi = is_array($k) ? $k : [$k => 1.0];
                    $somma = (float) array_sum($pesi);
                    foreach ($nuova > 1e-9 && $somma > 0.0 ? $pesi : [] as $j => $peso) {
                        $parte = $nuova * (float) $peso / $somma;
                        $mappa[$j] = ($mappa[$j] ?? 0.0) + (isset($tetti[$j]) ? min($parte, (float) $tetti[$j]) : $parte);
                    }
                }
                if ($mappa !== []) {
                    $perRiga[$id] = $mappa;
                }
            }
        }

        return $perRiga;
    }

    /**
     * Decisione 112.1: la parte della coppia della quota passata con le fermate a chi riceve (`quoteDelleFermate`), con le righe date. Le righe della
     * quota si dividono in due, la quota con una chiave sua e il resto a chi riceve, con le stesse date: la regola della decorrenza (D7) le legge come la riga intera.
     * La parte è quella dei giorni della quota, al centesimo più vicino; a pari resto il centesimo non va alla quota, come nella fermata, dove a pari resto va a chi
     * esce e non a chi riceve. Il resto della ripartizione non cambia.
     *
     * @param Collection<int, object> $righe
     * @param array<int, float> $quota riga → quota
     * @param array{totale: int, pezzi: list<array{tratti: InsiemePeriodi, importo: int}>} $c la coppia
     * @return int la parte della quota, in centesimi
     */
    private function laQuota(Collection $righe, int $persona, array $quota, array $c): int
    {
        // Coda 352 oltre la successione: la quota segue anche le righe degli eredi (`quotePerOrigine`), che non sono di chi riceve.
        $divise = $righe->flatMap(function ($r) use ($quota) {
            $q = min((float) ($quota[(int) $r->id] ?? 0.0), (float) $r->quota);
            if ($q <= 1e-9) {
                return [$r];
            }

            return [(object) array_replace((array) $r, ['anagrafica_id' => self::QUOTA_DELLA_FERMATA, 'quota' => $q]),
                ...((float) $r->quota - $q > 1e-9 ? [(object) array_replace((array) $r, ['quota' => (float) $r->quota - $q])] : [])];
        })->values();
        $pesi = $this->pesi($divise, $c['pezzi']);
        // I pezzi della coppia sono i giorni che qualcuno copriva alla fine (`ChiPagaAlPostoDellInquilino::dividiLaCoppia`): la somma dei pesi non è zero.
        $esatto = $c['totale'] * (float) ($pesi[self::QUOTA_DELLA_FERMATA] ?? 0.0) / array_sum($pesi);

        return (int) (($esatto <=> 0) * ceil(abs($esatto) - 0.5 - 1e-9));
    }

    /**
     * Coda 352 (decisione 112.1, la forma piena): chi ha pagato i giorni di un tratto dopo l'uscita, per la coppia di una fine (unità e gestione). I giorni
     * li paga chi sta al posto dell'inquilino in quel tratto, con la regola della coppia (`ChiPagaAlPostoDellInquilino::pesi`); ma la quota che una
     * fermata ha passato a chi riceve (le righe dei registri dei passaggi, `quotePerOrigine`) sta nella riga di chi è rimasto a carico della fermata, che
     * con la coppia l'ha pagata: ha una chiave sua (negativa), con chi è rimasto a carico e la catena delle fermate (dalla prima: se chi la passa l'aveva
     * avuta con un'altra fermata, anche quella), per le alternative di chi ha ricevuto se le parti le hanno regolate. Dopo che chi riceve la riporta a chi
     * ne risponde (la ricompera, Coda 351) la riga della quota è chiusa, e nei giorni dopo non pesa.
     *
     * @param Collection<int, object> $righe le righe dell'unità
     * @return array{pesi: array<int, float>, fermate: array<int, array{persone: list<int>, passaggi: list<int>}>} per chiave (l'anagrafica, o la quota di una
     *                                                                                                      fermata) il peso nel tratto; per le chiavi delle fermate chi
     *                                                                                                      ne risponde e i passaggi della catena
     */
    public function chiHaPagatoIlTratto(Subentro $fine, int $immobileId, int $gestioneId, Collection $righe, InsiemePeriodi $tratto): array
    {
        $passaggi = Subentro::where('immobile_id', $immobileId)->orderBy('id')->get();
        $fermate = [];
        foreach ($passaggi->map(fn (Subentro $s) => $s->capo())->unique('id')->sortBy('id') as $s) {
            foreach ($s->registro['fermata_dopo_scrivi'] ?? [] as $f) {
                if ((int) ($f['fine_id'] ?? 0) === (int) $fine->id && (int) ($f['immobile_id'] ?? 0) === $immobileId && (int) ($f['gestione_id'] ?? 0) === $gestioneId && ! empty($f['parti'])) {
                    $fermate[] = ['s' => $s, 'f' => $f];
                }
            }
        }
        $dal = $tratto->dal()->toDateString();
        $fermate = array_values(array_filter($fermate, fn (array $x) => substr((string) $x['f']['dal'], 0, 10) <= $dal));
        $chiavi = [];
        $divise = $righe;
        if ($fermate !== []) {
            $riceventi = [];
            foreach ($fermate as $k => $x) {
                foreach ($x['f']['parti'] as $p) {
                    // Coda 354: un passaggio con due fermate verso la stessa persona (la parte dalla riga di chi esce, e quella dalla quota di una fermata che lui aveva
                    // ricevuto) le passa la quota in proporzione alle loro cifre.
                    $riceventi[(int) $p['anagrafica_id']][(int) $x['s']->id][$k] = abs((float) $p['cifra']);
                }
            }
            $riceventi = array_map(fn (array $perPassaggio) => array_map(fn (array $pesi) => count($pesi) === 1 ? (int) array_key_first($pesi) : $pesi, $perPassaggio), $riceventi);
            $tetti = [];
            foreach ($fermate as $k => $x) {
                if (isset($x['f']['quota_della_riga'])) {
                    $tetti[$k] = (float) $x['f']['quota_della_riga'];
                }
            }
            $perRiga = [];
            foreach ($riceventi as $persona => $origini) {
                foreach (self::quotePerOrigine($righe, $passaggi, $persona, $origini, $tetti) as $riga => $quote) {
                    $perRiga[(int) $riga] = $quote;
                }
            }
            $divise = $righe->flatMap(function ($r) use ($perRiga) {
                $resta = (float) $r->quota;
                $pezzi = [];
                foreach ($perRiga[(int) $r->id] ?? [] as $k => $q) {
                    $q = min((float) $q, $resta);
                    if ($q > 1e-9) {
                        $pezzi[] = (object) array_replace((array) $r, ['anagrafica_id' => self::QUOTA_DELLA_FERMATA - 1 - (int) $k, 'quota' => $q]);
                        $resta -= $q;
                    }
                }

                return $pezzi === [] ? [$r] : [...$pezzi, ...($resta > 1e-9 ? [(object) array_replace((array) $r, ['quota' => $resta])] : [])];
            })->values();
            // La catena di una fermata: se chi ha passato la quota l'aveva avuta con un'altra fermata dello stesso tratto, anche quella, dalla prima.
            $catena = function (int $k) use (&$catena, $fermate): array {
                $uscente = (int) ($fermate[$k]['f']['uscente_id'] ?? 0);
                foreach (array_keys($fermate) as $j) {
                    if ($j < $k && $uscente !== 0 && collect($fermate[$j]['f']['parti'])->contains(fn (array $p) => (int) $p['anagrafica_id'] === $uscente)) {
                        return [...$catena($j), (int) $fermate[$k]['s']->id];
                    }
                }

                return [(int) $fermate[$k]['s']->id];
            };
            foreach (array_keys($fermate) as $k) {
                $f = $fermate[$k]['f'];
                $chiavi[self::QUOTA_DELLA_FERMATA - 1 - $k] = ['persone' => array_values(array_map('intval', (array) ($f['persone_ids'] ?? [$f['persona_id']]))), 'passaggi' => $catena($k)];
            }
        }
        $pesi = $this->chiPaga->pesi($divise, $tratto);

        return ['pesi' => $pesi, 'fermate' => array_intersect_key($chiavi, array_filter($pesi, fn (float $p) => $p > 1e-9))];
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
        // Decisione 81.2: la fermata di chi era entrato con un passaggio già fermato non dice chi risponde della parte, se chi ha le righe della
        // coppia o chi esce: la voce porta chi è uscito, e le frasi delle righe legate nominano tutte e due. Solo se tutte le fermate del gruppo sono
        // di quel tipo e di una persona uscita sola: con una fermata normale accanto, l'alternativa riguarderebbe anche la sua parte.
        $uscito = self::uscitoDelleFermate(array_column($g['fermate'], 'f'));
        if ($uscito !== null) {
            $voce += ['uscente_id' => (int) $uscito['uscente_id'], 'uscente' => (string) ($uscito['uscente'] ?? '?'), 'dopo_la_fermata' => $uscito['dopo_la_fermata'] ?? null];
        }
        if (count($g['fermate']) === 1) {
            // Coda 353: la fermata che non sa nella riga di chi stavano i giorni lo dice anche all'inizio di un conduttore.
            $voce += ! empty($f['riga_non_nota']) ? ['riga_non_nota' => true] : [];

            // Una fermata con più persone a carico insieme (decisione 81.2) le porta tutte, con i loro ids.
            return isset($f['persone'], $f['persone_ids']) ? $voce + ['persone' => array_values((array) $f['persone']), 'persone_ids' => array_values(array_map('intval', (array) $f['persone_ids']))] : $voce;
        }
        $persone = [];
        $aCarico = [];
        foreach ($g['fermate'] as $x) {
            // Una fermata a carico di più persone insieme le porta tutte (`persone`, `persone_ids`): le persone della voce sono loro, e il carico di
            // quella fermata è di tutte insieme.
            $suoi = isset($x['f']['persone'], $x['f']['persone_ids']) && count((array) $x['f']['persone']) === count((array) $x['f']['persone_ids'])
                ? array_combine(array_map('intval', (array) $x['f']['persone_ids']), array_map('strval', (array) $x['f']['persone']))
                : [(int) $x['f']['persona_id'] => (string) ($x['f']['persona'] ?? '?')];
            foreach ($suoi as $id => $nome) {
                $persone[$id] ??= $nome;
            }
            $chiave = implode(',', array_keys($suoi));
            $aCarico[$chiave] = ['persona' => self::elenco(array_values($suoi)), 'cifra' => ($aCarico[$chiave]['cifra'] ?? 0) + (int) $x['f']['cifra']];
        }

        return array_replace($voce, [
            'persona' => self::elenco(array_values($persone)), 'persone' => array_values($persone), 'persone_ids' => array_keys($persone),
            'cifra' => (int) array_sum(array_map(fn (array $x) => (int) $x['f']['cifra'], $g['fermate'])),
            // La cifra che ogni persona a carico aveva lasciato con le sue fermate: il residuo è uno solo per tutte, e la parte di ognuna la decide l'amministratore.
            'a_carico' => array_values($aCarico),
            'verso' => array_column($g['riceventi'], 'nome'),
            // Coda 354: due fermate dello stesso passaggio (la parte dalla riga di chi esce e quella dalla quota di una fermata ricevuta) sono un passaggio solo.
            'passaggi_ids' => array_values(array_unique(array_map(fn (array $x) => (int) $x['s']->id, $g['fermate']))),
            'passaggi' => array_values(array_unique(array_map(fn (array $x) => self::passaggioInParole($x['s']), $g['fermate']))),
        ]);
    }

    /**
     * La parte di ognuno che riceve in un gruppo di fermate, con le cifre date (`residuiDelleFermate`), anche a zero: per le frasi delle righe legate.
     *
     * @param array{riceventi: list<array{anagrafica_id: int, nome: string}>} $g
     * @param array<int, int> $cifre anagrafica → centesimi
     * @return list<array{anagrafica_id: int, nome: string, cifra: int}>
     */
    private static function partiDeiRiceventi(array $g, array $cifre): array
    {
        return array_map(fn (array $r) => ['anagrafica_id' => (int) $r['anagrafica_id'], 'nome' => (string) $r['nome'], 'cifra' => (int) ($cifre[(int) $r['anagrafica_id']] ?? 0)], $g['riceventi']);
    }

    /**
     * I saldi che le fermate del gruppo hanno indicato di registrare dal Wallet (decisione 80), per le fermate registrate prima della 1.11.0-beta.53
     * (senza la chiave `strada`): per ognuna, la persona a carico, la cifra e le parti di chi riceve. Le fermate che hanno detto «si regola fra le
     * parti» non ne hanno. Serve a dire, quando l'inizio di una locazione riduce o azzera la fermata, di correggere il saldo che l'amministratore ha
     * registrato su quell'indicazione, al condizionale: il saldo di allora non era legato al passaggio. Dalla .53 le righe sono legate al
     * passaggio, e `codaDelWallet` le legge dal Wallet.
     *
     * @param array{fermate: list<array{s: Subentro, f: array<string, mixed>}>} $g
     * @return list<array{persona: string, cifra: int, parti: list<array{nome: string, cifra: int}>}>
     */
    private static function saldiDalWallet(array $g): array
    {
        $out = [];
        foreach ($g['fermate'] as $x) {
            if (! ($x['f']['wallet'] ?? true) || isset($x['f']['strada'])) {
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
            self::elenco((array) ($f['persone'] ?? array_column($carico, 'persona'))), self::elenco(array_map(fn (array $x) => sprintf('%s per %s', self::soldi((int) $x['cifra']), $x['persona']), $carico)));
    }

    /**
     * La coda della frase di una fermata ridotta o azzerata: i fatti dei saldi del Wallet legati ai passaggi della voce (`fattiDeiLegati`), con la
     * parte che resta di ognuno. Senza saldi legati, per le fermate registrate prima della 1.11.0-beta.53 che indicavano il Wallet, la coda di
     * allora, al condizionale: quel saldo non era legato al passaggio, e il programma non sa se quello che c'è sia il saldo della fermata o un
     * riporto. Senza saldi legati e con un registro nuovo, niente.
     *
     * @param array<string, mixed> $f la voce di `fermateRidotte`
     */
    private static function codaDelWallet(array $f): string
    {
        $righe = self::righeDellaVoce($f);
        if ($righe->isNotEmpty()) {
            return ' ' . self::fattiDeiLegati($righe, self::partiConChiLePorta($f, (array) ($f['parti'] ?? [])), 'la parte che resta', chiNonLiCambia: 'il programma',
                passaggiDellaVoce: self::passaggiIds($f));
        }
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
            'esercizio_id', 'coppia', 'cifra', 'dal', 'assorbita', 'verso', 'parti', 'wallet', 'strada', 'passaggio_id', 'passaggio', 'prima', 'residuo', 'conduttore',
            'uscente_id', 'uscente', 'dopo_la_fermata', 'piu_persone', 'ereditata', 'passaggi_ids', 'passaggi', 'persone', 'persone_ids', 'dopo', 'conduttore_uscente', 'conduttore_uscente_id',
            'parti_prima', 'parti_dopo', 'ricompra', 'erede_di', 'agli_eredi', 'cifre_per_persona', 'riga_non_nota', 'quota_della_riga']));
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
            : sprintf('La fine locazione di %s del %s ha scritto il conguaglio: dei giorni dopo la sua uscita %s sulla %s (%s)%s.%s Con questo passaggio la parte di quei giorni dal %s tocca %s, ma il programma non la sposta: %s. %s',
                $f['inquilino'], self::data($f['fine_il']),
                // Giro stretto della .50 (G13): l'erede non ha preso la coppia, gli è arrivata con la successione; la frase dice chi l'ha presa.
                isset($f['ereditata']) ? self::presa((string) $f['ereditata']['da'], (int) $f['ereditata']['coppia'], ! empty($f['ereditata']['piu'])) : self::presa((string) $f['persona'], (int) $f['coppia']),
                FrasiObbligati::gestione($f['gestione']), $f['unita'],
                // Coda 245: la parte del piano di chi sta al posto dell'inquilino, con il resto che la coppia ha lasciato fuori (`coppia`).
                isset($f['resto_del_ripiego']) ? sprintf('; con la regola del piano la sua parte è %s, e %s %s fuori dal conguaglio, da regolare con %s', self::cifraInParole((int) $f['resto_del_ripiego']['piano']),
                    MoneyHelper::format(abs((int) $f['resto_del_ripiego']['cifra'])), (int) $f['resto_del_ripiego']['cifra'] > 0 ? 'sono rimasti' : 'di credito sono rimasti',
                    self::elenco((array) $f['resto_del_ripiego']['con']) ?: 'chi era già uscito') : '',
                isset($f['ereditata']) ? sprintf(' Con %s una parte, %s, è passata %s.', $f['ereditata']['passaggio'], self::cifraInParole((int) $f['coppia']), FrasiObbligati::a((string) $f['persona'])) : '',
                self::data($f['dal']), FrasiObbligati::a(self::elenco($f['verso'])),
                (int) $f['cifra'] < 0 ? 'in quei giorni l\'entrata supera la spesa, e ' . self::aCarico((int) $f['cifra'], '', (string) $f['persona']) : self::aCarico((int) $f['cifra'], '', (string) $f['persona']),
                self::stradaAMano($f));

        return $seScrivi ? 'Se scrivi il conguaglio della successione: ' . lcfirst($frase) : $frase;
    }

    /**
     * Coda 254 (1.11.0-beta.51): la frase del pannello per la fermata della fine di un conduttore. Il passaggio che si ferma è la fine della
     * locazione di chi esce adesso (`conduttore_uscente`), non una vendita: i giorni che copriva tornano a chi paga al posto dell'inquilino da quel
     * giorno, e se questo non è più chi ha preso la coppia la parte non si sposta da sola. La coppia non si ricalcola più (l'ha presa la fine
     * di chi era uscito prima): le frasi «tornano a …» valgono per i piani futuri.
     */
    private static function frasePannelloFineDelConduttore(array $f): string
    {
        return sprintf('La fine locazione di %s del %s ha scritto il conguaglio: dei giorni dopo la sua uscita %s sulla %s (%s). Con la fine della locazione di %s i giorni dal %s tornano a chi paga al posto dell\'inquilino, e la parte di quei giorni tocca %s, ma il programma non la sposta: %s. %s',
            $f['inquilino'], self::data($f['fine_il']), self::presa((string) $f['persona'], (int) $f['coppia'], ! empty($f['piu_persone'])), FrasiObbligati::gestione($f['gestione']), $f['unita'],
            $f['conduttore_uscente'], self::data($f['dal']), FrasiObbligati::a(self::elenco($f['verso'])),
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
        if (isset($f['ereditata'])) {
            return self::frasePannelloDellErede($f);
        }
        $prima = $f['dopo_la_fermata'] ?? null;

        return sprintf('La fine locazione di %s del %s ha scritto il conguaglio: dei giorni dopo la sua uscita %s sulla %s (%s). %s Con questo passaggio la parte di quei giorni dal %s tocca %s, ma il programma non la sposta: %s. Se vanno %s, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata %s, e il programma non lo sa.',
            $f['inquilino'], self::data($f['fine_il']), self::presa((string) $f['persona'], (int) $f['coppia'], ! empty($f['piu_persone'])), FrasiObbligati::gestione($f['gestione']), $f['unita'],
            match (true) {
                // Come nella frase dell'erede: se a ricevere era il defunto che ha lasciato la parte a chi esce, la frase dice la parte del defunto e la successione che l'ha passata.
                $prima !== null => self::laFermataDiPrima($prima + ['persona' => ! empty($f['riga_non_nota']) ? self::chiResta($f) : (string) $f['persona']], (string) $f['uscente']) . self::nonSaNellaRigaDiChi($f),
                // Coda 353: non si sa nella riga di chi stanno i giorni di chi esce, e la frase non nomina nessuno fra chi è a carico.
                ! empty($f['riga_non_nota']) => self::nonSaNellaRigaDiChi($f, false),
                (int) $f['cifra'] < 0 => sprintf('La parte di %s non è passata con la coppia, ed è rimasta %s.', $f['uscente'], FrasiObbligati::a((string) $f['persona'])),
                default => sprintf('La parte di %s non è passata con la coppia, ed è rimasta a carico di %s.', $f['uscente'], $f['persona']),
            },
            self::data($f['dal']), FrasiObbligati::a(self::elenco($f['verso'])),
            self::aCarico((int) $f['cifra'], '', self::chiResta($f), oppure: self::oppureChi($f)),
            FrasiObbligati::a(self::elenco($f['verso'])), $prima !== null ? 'la fermata ' . self::di((string) $prima['passaggio']) : 'la parte di ' . $f['uscente']);
    }

    /**
     * Coda 341: la frase del pannello per la fermata di un erede con l'arretrato agli eredi che ha anche comprato (`ereditata` e `uscente_id`). Dice da
     * dove viene la parte di chi esce (la coppia di chi l'aveva presa, passata con la successione), che cosa le fermate di prima avevano lasciato (una, o una
     * per una con più acquisti: `lePartiDiPrima`), e che la cifra di adesso è a carico di tutti insieme: il programma non divide la cifra per origine, e per
     * questo non indica il Wallet.
     */
    private static function frasePannelloDellErede(array $f): string
    {
        $prima = $f['dopo_la_fermata'] ?? null;

        return sprintf('La fine locazione di %s del %s ha scritto il conguaglio: dei giorni dopo la sua uscita %s sulla %s (%s), e con %s una parte, %s, è passata %s.%s Con questo passaggio la parte di quei giorni dal %s tocca %s, ma il programma non la sposta: %s. Se vanno %s, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata %s, e il programma non lo sa.',
            $f['inquilino'], self::data($f['fine_il']), self::presa((string) $f['ereditata']['da'], (int) $f['ereditata']['coppia'], ! empty($f['ereditata']['piu'])), FrasiObbligati::gestione($f['gestione']), $f['unita'],
            $f['ereditata']['passaggio'], self::cifraInParole((int) ($f['ereditata']['sua'] ?? 0)), FrasiObbligati::a((string) $f['uscente']),
            self::lePartiDiPrima($f) . self::leSuccessioniSenzaFermata($f),
            self::data($f['dal']), FrasiObbligati::a(self::elenco($f['verso'])),
            self::aCarico((int) $f['cifra'], '', (string) $f['persona'], oppure: $f['uscente'] . ' se ' . self::regolataPrima($f)),
            FrasiObbligati::a(self::elenco($f['verso'])), $prima !== null ? 'la fermata ' . self::di((string) $prima['passaggio']) : 'la parte di ' . $f['uscente']);
    }

    /**
     * Coda 353: la fermata di chi esce che il registro non sa nella riga di chi stava (`riga_non_nota`: nessuna origine registrata che dica le persone, e più di una ha la coppia).
     * Con `$conSpazio` la frase segue un'altra («La vendita … non ha spostato la parte di Elsa …»).
     */
    private static function nonSaNellaRigaDiChi(array $f, bool $conSpazio = true): string
    {
        if (empty($f['riga_non_nota'])) {
            return '';
        }

        // Con la fermata di prima nominata (il suo registro è di una beta che non diceva le persone) la frase dice che è quel registro a non dirlo.
        $prima = $f['dopo_la_fermata'] ?? null;

        return ($conSpazio ? ' ' : '') . ($prima !== null
            ? sprintf('Il registro %s non dice in quale riga stanno i giorni di %s, e il programma non lo sa.', self::di((string) $prima['passaggio']), $f['uscente'])
            : sprintf('La parte di %s non viene da un passaggio registrato che dica in quale riga stanno i suoi giorni, e il programma non lo sa.', $f['uscente']));
    }

    /**
     * Coda 353: l'avviso dove chi compra ha una riga della coppia e il programma non sa se i giorni di chi esce stanno nella sua o in quella di un'altra persona: non c'è una fermata da
     * dire (sarebbe una cifra inventata), ma il silenzio sarebbe una scelta. Un'informazione, senza la spunta del cancello.
     *
     * @param array<string, mixed> $a l'avviso di `dopoUnaFermata`
     */
    public static function fraseSenzaRiga(array $a): string
    {
        $chiCompra = (string) $a['verso'][0];

        return sprintf('La fine locazione di %s del %s ha scritto il conguaglio: dei giorni dopo la sua uscita %s sulla %s (%s). La parte di %s non viene da un passaggio registrato che dica in '
            . 'quale riga stanno i suoi giorni, e il programma non lo sa. Con questo passaggio la parte di quei giorni dal %s tocca %s, che ha già una sua riga della coppia, e il programma non sposta niente: '
            . 'se i giorni di %s stanno nella riga di un\'altra persona, fino a %s si regolano a mano con %s: da chi li ha nella sua riga, o da %s se la parte di %s è già stata regolata; se stanno nella riga di %s, non c\'è niente da regolare.',
            $a['inquilino'], self::data($a['fine_il']), self::presa((string) $a['persona'], (int) $a['coppia'], true), FrasiObbligati::gestione($a['gestione']), $a['unita'], $a['uscente'],
            self::data($a['dal']), FrasiObbligati::a($chiCompra), $a['uscente'], MoneyHelper::format(abs((int) $a['cifra'])), $chiCompra, $a['uscente'], $a['uscente'], $chiCompra);
    }

    /**
     * Le fermate di prima che non hanno spostato a chi esce la parte avuta con quei passaggi, ognuna con la sua cifra e di chi è rimasta: la frase dice perché fra le
     * persone a carico ci sono anche loro. Una sola è `dopo_la_fermata`; con più fermate sono `dopo_le_fermate`, dalla più vecchia.
     */
    private static function lePartiDiPrima(array $f): string
    {
        $fermate = isset($f['dopo_la_fermata']) ? [$f['dopo_la_fermata']] : (array) ($f['dopo_le_fermate'] ?? []);

        return implode('', array_map(fn (array $prima) => ' ' . self::laFermataDiPrima($prima, (string) $f['uscente']), $fermate));
    }

    /** Le successioni senza fermata fra le origini della parte che chi esce vende: la parte avuta con ognuna è rimasta a carico del defunto, che ha ancora la sua riga della coppia. */
    private static function leSuccessioniSenzaFermata(array $f): string
    {
        return implode('', array_map(fn (array $s) => sprintf(' La parte avuta con %s, senza il conguaglio scritto, è rimasta a carico di %s.', $s['passaggio'], $s['di']), (array) ($f['dopo_le_successioni'] ?? [])));
    }

    /**
     * La fermata di prima che non ha spostato a chi esce la parte avuta con quel passaggio, con di chi era rimasta quando il registro lo dice; un credito si dice con le parole.
     * Coda 346: se a ricevere era il defunto che gli ha lasciato la parte, la frase dice la parte del defunto e la successione che l'ha passata (`di`, `passata`).
     */
    private static function laFermataDiPrima(array $prima, string $uscente): string
    {
        $rimasta = isset($prima['persona']) ? (string) $prima['persona'] : null;

        return (int) $prima['cifra'] < 0
            ? sprintf('%s non ha spostato la parte di %s, un credito di %s,%s rimasto%s da regolare a mano.', ucfirst((string) $prima['passaggio']), $prima['di'] ?? $uscente,
                MoneyHelper::format(-(int) $prima['cifra']), self::passataCon($prima), $rimasta !== null ? ' ' . FrasiObbligati::a($rimasta) : '')
            : sprintf('%s non ha spostato la parte di %s, %s,%s%s da regolare a mano.', ucfirst((string) $prima['passaggio']), $prima['di'] ?? $uscente, MoneyHelper::format((int) $prima['cifra']),
                self::passataCon($prima), $rimasta !== null ? ' rimasta a carico di ' . $rimasta : '');
    }

    /** « passata con la successione del 15 ottobre 2026 a Bruno e Dora,» se la parte della fermata di prima era del defunto (Coda 346); niente altrimenti. */
    private static function passataCon(array $prima): string
    {
        return isset($prima['passata']) ? sprintf(' passata con %s,', $prima['passata']) : '';
    }

    /** Decisione 81.2: «la fermata della vendita del 1 settembre 2026 è già stata regolata», o la parte di chi esce. */
    private static function regolataPrima(array $f): string
    {
        $prima = $f['dopo_la_fermata'] ?? null;

        return $prima !== null ? sprintf('la fermata %s è già stata regolata', self::di((string) $prima['passaggio'])) : sprintf('la parte di %s è già stata regolata', $f['uscente']);
    }

    /**
     * Decisione 81.2: chi risponde della parte se la fermata di prima è già stata regolata: «Elsa se la fermata della vendita del 1 ottobre 2026 è già stata regolata». Con
     * l'arretrato agli eredi (Coda 355) chi esce è morto e la sua posizione, con quel che gli spetta, è passata a chi riceve i giorni: l'alternativa è la loro.
     */
    private static function oppureChi(array $f): string
    {
        if (empty($f['agli_eredi'])) {
            return $f['uscente'] . ' se ' . self::regolataPrima($f);
        }

        return sprintf('%s, che %s la posizione di %s, se %s', self::elenco($f['verso']), count($f['verso']) > 1 ? 'hanno' : 'ha', $f['uscente'], self::regolataPrima($f));
    }

    /** Il motivo del cancello: corto, con la persona e la cifra. */
    public static function motivoCancello(array $f, bool $seScrivi = false): string
    {
        // Decisione 81.2: dopo una fermata la parte è a carico di chi ha le righe della coppia, o di chi esce se la fermata di prima è regolata, come nel pannello.
        $motivo = sprintf('%s (%s): il conguaglio scritto alla fine locazione del %s non passa %s %s',
            self::aCarico((int) $f['cifra'], 'dei giorni dopo l\'uscita di ' . $f['inquilino'], self::chiResta($f), oppure: isset($f['uscente_id']) ? self::oppureChi($f) : null),
            FrasiObbligati::gestione($f['gestione']), self::data($f['fine_il']), FrasiObbligati::a(self::elenco($f['verso'])),
            self::conQuestoPassaggio($f));
        if (isset($f['uscente_id'])) {
            // Decisione 81.2: come non era passato a chi esce con il passaggio fermato prima.
            // L'erede con più fermate di prima: la parte avuta con la successione gli è passata, sono le parti dei passaggi che le fermate nominano (una vendita, ma anche
            // l'estinzione di un usufrutto) che no. Coda 346: ogni fermata a chi aveva ricevuto, chi esce o il defunto che gli ha lasciato la parte.
            $fermate = isset($f['dopo_la_fermata']['passaggio']) ? [$f['dopo_la_fermata']] : (array) ($f['dopo_le_fermate'] ?? []);
            $perChi = [];
            foreach ($fermate as $prima) {
                $perChi[(string) ($prima['di'] ?? $f['uscente'])][] = 'con ' . $prima['passaggio'];
            }
            // Le successioni senza fermata fra le origini: la parte avuta con loro non è passata a chi esce con questo passaggio.
            foreach ((array) ($f['dopo_le_successioni'] ?? []) as $successione) {
                $perChi[(string) $f['uscente']][] = 'con ' . $successione['passaggio'];
            }
            $motivo .= $perChi === [] ? sprintf(', come non era passato %s', FrasiObbligati::a((string) $f['uscente']))
                : ', come non era passato ' . self::elenco(array_map(fn (string $chi, array $con) => FrasiObbligati::a($chi) . ' ' . self::elenco($con), array_keys($perChi), array_values($perChi)));
        }

        return $seScrivi ? 'se scrivi il conguaglio, ' . $motivo : $motivo;
    }

    /**
     * La frase dello storico, dal registro: anni dopo, «perché Ugo ha ancora quella parte?» ha una risposta. Decisione 82.2: se chi ha
     * ricevuto è uscito a sua volta con un passaggio fermato dopo (la seconda vendita), la frase dice la parte passata con quel passaggio
     * (`$passate`, da `passateDopo`), come un nuovo inquilino riduce una fermata. Coda 351: se un passaggio dopo ha riportato i giorni a chi li aveva
     * nella sua riga (chi ricompra la sua stessa parte), la frase lo ricorda, con quanto ne resta (`$tornate`, da `tornateDopo`).
     *
     * @param list<array{cifra: int, verso: list<string>, passaggio: string}> $passate
     * @param list<array{passaggio: string, ricompra: string, dal: string, residuo: int}> $tornate
     */
    public static function fraseStorico(array $f, array $passate = [], array $tornate = []): string
    {
        $verso = self::elenco($f['verso'] ?? []) ?: 'chi è entrato';
        $frase = sprintf('Il conguaglio scritto alla fine locazione di %s del %s non è passato %s: %s sulla %s (%s), da regolare a mano con %s%s.',
            $f['inquilino'] ?? 'l\'inquilino', self::data($f['fine_il']), self::conQuestoPassaggio($f), self::aCarico((int) $f['cifra'], 'dei giorni dal ' . self::data($f['dal']), self::chiResta($f, passato: true), passato: true),
            FrasiObbligati::gestione($f['gestione'] ?? '?'), $f['unita'] ?? '', $verso,
            // Decisione 81.2: o fra chi esce e chi entra, se la fermata di prima era già stata regolata. Con l'arretrato agli eredi (Coda 355) chi esce è morto e la sua posizione è
            // passata a chi entra: se la fermata è regolata non resta niente da regolare.
            ! empty($f['agli_eredi']) && isset($f['uscente_id'])
                ? sprintf('; se %s, non resta niente da regolare: la posizione di %s è passata %s', str_replace('è già stata regolata', 'era già stata regolata', self::regolataPrima($f)), $f['uscente'] ?? '?', FrasiObbligati::a($verso))
                : (isset($f['uscente_id']) ? sprintf(', o fra %s e %s se %s', $f['uscente'] ?? '?', $verso, str_replace('è già stata regolata', 'era già stata regolata', self::regolataPrima($f))) : ''));
        if ($passate === [] && $tornate === []) {
            return $frase;
        }

        return rtrim($frase, '.')
            . ($passate === [] ? '' : sprintf('; di questi, %s', self::elenco(array_map(fn (array $p) => sprintf('%s %s passati %s con %s',
                self::soldi((int) $p['cifra']), abs((int) $p['cifra']) === 1 ? 'è' : 'sono', FrasiObbligati::a(self::elenco($p['verso'])), $p['passaggio']), $passate))))
            . implode('', array_map(fn (array $t) => match (true) {
                // Coda 356: la successione di chi riceveva, con l'erede che era a carico.
                ! empty($t['agli_eredi']) => sprintf('; con %s la posizione di %s è passata %s, e non resta niente da regolare', $t['passaggio'], $t['erede_di'] ?? '?', FrasiObbligati::a($t['ricompra'])),
                isset($t['erede_di']) => sprintf('; con %s i giorni dal %s sono passati %s, e ne restano da regolare %s', $t['passaggio'], self::data($t['dal']),
                    FrasiObbligati::a($t['ricompra']), self::cifraInParole((int) $t['residuo'])),
                default => sprintf('; con %s i giorni dal %s sono tornati %s, e ne restano da regolare %s', $t['passaggio'], self::data($t['dal']),
                    FrasiObbligati::a($t['ricompra']), self::cifraInParole((int) $t['residuo'])),
            }, $tornate)) . '.';
    }

    /**
     * Coda 351: le fermate ridotte dopo da un passaggio che ha riportato i giorni all'unica persona a carico (`ridottaDaChiRicompra`), per lo storico del passaggio che le
     * aveva lasciate: i passaggi registrati dopo (un passaggio annullato non si legge: lo toglie lo scope del modello), sulla stessa coppia, la cui voce nomina questo
     * passaggio (`passaggio_id`, o fra i `passaggi_ids` di una voce di più fermate).
     *
     * @return list<array{passaggio: string, ricompra: string, dal: string, residuo: int}>
     */
    public static function tornateDopo(Subentro $passaggio, array $f): array
    {
        $questo = (int) $passaggio->capo()->id;

        return Subentro::where('immobile_id', (int) ($f['immobile_id'] ?? 0))->where('id', '>', $passaggio->id)->orderBy('id')->get()
            ->map(fn (Subentro $s) => $s->capo())->unique('id')
            ->flatMap(fn (Subentro $s) => collect($s->registro['fermata_ridotta'] ?? [])
                ->filter(fn ($g) => isset($g['ricompra']) && in_array($questo, [(int) ($g['passaggio_id'] ?? 0), ...array_map('intval', (array) ($g['passaggi_ids'] ?? []))], true)
                    && (int) ($g['fine_id'] ?? 0) === (int) ($f['fine_id'] ?? 0) && (int) ($g['immobile_id'] ?? 0) === (int) ($f['immobile_id'] ?? 0)
                    && (int) ($g['gestione_id'] ?? 0) === (int) ($f['gestione_id'] ?? 0))
                ->map(fn ($g) => ['passaggio' => self::passaggioInParole($s), 'ricompra' => (string) $g['ricompra'], 'dal' => (string) $g['dal'], 'residuo' => (int) $g['residuo']]
                    + array_intersect_key($g, array_flip(['erede_di', 'agli_eredi']))))
            ->values()->all();
    }

    /**
     * Decisione 82.2: le fermate registrate dopo una fermata, dai passaggi di chi l'aveva ricevuta (la seconda vendita dopo la prima): la
     * cifra passata, a chi e con quale passaggio. Solo i passaggi non annullati, registrati dopo, sulla stessa coppia; per ognuno la sua
     * parte (`dopo_la_fermata` del suo registro nomina questa, con le sue parole). Decisione 108 (2): non conta la fermata di un erede che ha anche
     * comprato (`ereditata`): la sua cifra viene da più origini, la parte avuta con la successione e quella comprata, e il programma non sa quanta ne venga
     * dalla fermata di prima. Lo storico della prima vendita non dice allora una parte passata; la rivendita dice già a carico di chi resta la cifra.
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
                ->filter(fn ($g) => isset($g['uscente_id'], $g['dopo_la_fermata']) && ! isset($g['ereditata']) && in_array((int) $g['uscente_id'], $ricevuto, true)
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
            self::diChiResta($cifra, self::chiResta($f, passato: true), isset($f['uscente_id']) ? ($f['uscente'] ?? '?') . ' se ' . str_replace('è già stata regolata', 'era già stata regolata', self::regolataPrima($f)) . ',' : null),
            self::elenco($f['verso'] ?? []) ?: 'chi è entrato');
    }

    /** Decisione 83.2: la frase dello storico dell'inizio che riduceva una fermata la cui coppia è stata annullata dopo. */
    public static function fraseStoricoRidottaAnnullata(array $f): string
    {
        $piu = count(self::passaggiDellaVoce($f)) > 1;

        return sprintf('Il conguaglio scritto alla fine locazione di %s è stato annullato dopo %s: %s %s (%s rimasti %s), che %s %s da regolare per %s, %s.',
            $f['inquilino'] ?? 'l\'inquilino', isset($f['ricompra']) ? 'questo passaggio' : 'questo inizio', $piu ? 'le fermate' : 'la fermata', self::elenco(array_map(fn (string $p) => self::di($p), self::passaggiDellaVoce($f, 'un passaggio di prima'))),
            self::soldi((int) ($f['cifra'] ?? 0)),
            self::diChiResta((int) ($f['cifra'] ?? 0), self::chiResta($f, passato: true), isset($f['uscente_id']) ? ($f['uscente'] ?? '?') . ' se ' . str_replace('è già stata regolata', 'era già stata regolata', self::regolataPrima($f)) : null),
            self::conQuesto($f), $piu ? 'restavano' : 'restava',
            self::cifraInParole((int) ($f['residuo'] ?? 0)), $piu ? 'non valgono più' : 'non vale più');
    }

    /**
     * Gli avvisi di «Annulla il conguaglio», detti prima di confermare e nel messaggio. Un avviso, non un rifiuto: il conguaglio resta annullabile.
     *
     * - Decisione 83.2 (1.11.0-beta.50), per una fine «Scrivi»: un passaggio registrato dopo si è fermato su questa coppia (la vendita, la
     *   costituzione, la successione…), e la sua fermata non vale più: la sua parte è zero. Dalla 1.11.0-beta.53 l'avviso dice i fatti dei saldi
     *   del Wallet legati a quel passaggio (`fattiDeiLegati`).
     * - Dalla 1.11.0-beta.53 (Coda 244), per ogni passaggio: i saldi del Wallet legati al passaggio stesso restano, e l'avviso ne dice i fatti
     *   (`avvisoDeiLegati`).
     *
     * @return list<string>
     */
    public static function avvisiDelConguaglio(Subentro $fine): array
    {
        $capo = $fine->capo();
        if ($capo->conguaglioAnnullato()) {
            return [];
        }
        $suiLegati = self::avvisoDeiLegati($capo);
        if ($capo->tipo_passaggio !== 'fine_locazione') {
            return $suiLegati === null ? [] : [$suiLegati];
        }
        $avvisi = [];
        foreach (Subentro::where('condominio_id', $capo->condominio_id)->where('id', '>', $capo->id)->whereNull('subentro_padre_id')->orderBy('id')->get() as $s) {
            // Coda 254: la fine di un conduttore che ha fatto risalire la parte di una fermata di prima. Annullato il conguaglio anche quella
            // parte, e il saldo o l'accordo che le parti avevano adeguato, non valgono più.
            foreach ($s->registro['fermata_risale'] ?? [] as $f) {
                if ((int) ($f['fine_id'] ?? 0) !== (int) $capo->id) {
                    continue;
                }
                $righe = self::righeDellaVoce($f);
                $avvisi[] = sprintf('%s ha fatto risalire la parte verso %s %s da %s a %s. Annullato il conguaglio, quella parte non vale più: %s',
                    ucfirst(self::passaggioInParole($s)), self::elenco($f['verso'] ?? []) ?: 'chi è entrato', self::dellaFermata($f), self::cifraInParole((int) ($f['prima'] ?? 0)), self::cifraInParole((int) ($f['dopo'] ?? 0)),
                    $righe->isNotEmpty()
                        ? 'se le parti hanno regolato la differenza fra loro, quell\'accordo va rivisto. ' . self::fattiDeiLegati($righe, [], 'la parte della sua fermata', chiNonLiCambia: 'l\'annullamento del conguaglio',
                            passaggiDellaVoce: self::passaggiIds($f))
                        : 'se per questo hai registrato un saldo manuale dal Wallet, o le parti hanno regolato la differenza fra loro, rivedilo, perché l\'annullamento non lo toglie.');
            }
            // Coda 245: un passaggio dopo sullo stesso anello che ha letto questa fine con la regola del piano (la fine di chi restava, il cambio).
            // La sua coppia resta; annullato questo conguaglio, il prospetto dice le cifre del piano di oggi.
            foreach ($s->registro['conguaglio']['piano'] ?? [] as $g) {
                if (is_array($g) && (int) ($g['segue_fine_id'] ?? 0) === (int) $capo->id) {
                    $avvisi[] = RegolaDelPiano::avvisoDelConguaglio($s, $g);
                }
            }
            // I saldi del Wallet legati al passaggio sono del passaggio, non di una voce: con più voci sulla stessa unità e gestione i loro fatti si dicono una volta, sotto la
            // prima (la sua è la voce con il Wallet: l'altra, della quota che una fermata aveva lasciato nella riga di un altro, non lo ha).
            $primaVoce = [];
            foreach ($s->registro['fermata_dopo_scrivi'] ?? [] as $i => $f) {
                if ((int) ($f['fine_id'] ?? 0) === (int) $capo->id) {
                    $primaVoce[(int) ($f['immobile_id'] ?? 0) . '|' . (int) ($f['gestione_id'] ?? 0)] ??= $i;
                }
            }
            foreach ($s->registro['fermata_dopo_scrivi'] ?? [] as $i => $f) {
                if ((int) ($f['fine_id'] ?? 0) !== (int) $capo->id) {
                    continue;
                }
                $verso = self::elenco($f['verso'] ?? []) ?: 'chi è entrato';
                $parole = self::passaggioInParole($s);
                // Giro stretto della .50 (G12): l'avviso legge il registro della fermata come il pannello e lo storico. Dopo una fermata
                // (decisione 81.2) la parte è a carico di chi ha la coppia, o di chi è uscito. Dalla 1.11.0-beta.53 l'avviso dice i fatti dei saldi
                // del Wallet legati a quel passaggio, che restano (sono del passaggio, che c'è ancora): senza il conguaglio la parte della fermata è
                // zero. Senza saldi legati, per un registro di prima che indicava il Wallet la frase di allora, al condizionale, altrimenti l'accordo
                // fra le parti da rivedere.
                $righe = $primaVoce[(int) ($f['immobile_id'] ?? 0) . '|' . (int) ($f['gestione_id'] ?? 0)] === $i
                    ? self::righeDellaVoce(['passaggio_id' => (int) $s->id] + array_intersect_key($f, array_flip(['immobile_id', 'gestione_id']))) : collect();
                $avvisi[] = sprintf('%s si è %s su questo conguaglio: %s sulla %s, da regolare a mano con %s. Annullato il conguaglio, quella fermata non vale più%s',
                    ucfirst($parole), str_starts_with($parole, 'il ') ? 'fermato' : 'fermata',
                    self::aCarico((int) $f['cifra'], 'dei giorni dal ' . self::data($f['dal']), self::chiResta($f, passato: true), passato: true,
                        oppure: isset($f['uscente_id']) ? ($f['uscente'] ?? '?') . ' se ' . str_replace('è già stata regolata', 'era già stata regolata', self::regolataPrima($f)) . ',' : null),
                    FrasiObbligati::gestione($f['gestione'] ?? '?'), $verso,
                    match (true) {
                        $righe->isNotEmpty() => '. ' . self::fattiDeiLegati($righe, [], 'la parte della sua fermata', chiNonLiCambia: 'l\'annullamento del conguaglio'),
                        ! isset($f['strada']) && ($f['wallet'] ?? true) => sprintf(': se per questo hai registrato un saldo manuale dal Wallet (%s), rivedilo, perché l\'annullamento non lo toglie.', self::rigaDelWallet($f, (string) ($f['persona'] ?? '?'))),
                        default => ': se le parti l\'hanno regolata fra loro, quell\'accordo va rivisto.',
                    });
            }
        }

        return $suiLegati === null ? $avvisi : [...$avvisi, $suiLegati];
    }

    /**
     * L'avviso di «Annulla il conguaglio» sui saldi del Wallet legati al passaggio stesso (1.11.0-beta.53, Coda 244). Il rifiuto del conguaglio
     * assorbito da un piano che non si riscrive più indica la correzione con un saldo legato a quel passaggio: tolta la coppia, la correzione
     * conta da sola. Fra le righe legate allo stesso passaggio ci sono anche quelle della sua fermata, e i dati non le distinguono: l'avviso non
     * le tocca, e ne dice i fatti per gestione e unità (`fattiDeiLegati`), con la parte della fermata di questo passaggio come è oggi
     * (`parteDellaFermata`): zero se il passaggio non ne ha una, se il conguaglio su cui si era fermata è stato annullato, o se un inizio l'ha
     * azzerata (`chiLaAzzera`, la regola del selettore del modale). Null senza righe legate.
     */
    private static function avvisoDeiLegati(Subentro $capo): ?string
    {
        $righe = Saldo::with(['gestione:id,nome', 'immobile:id,nome'])->where('passaggio_id', (int) $capo->id)->orderBy('id')->get();
        if ($righe->isEmpty()) {
            return null;
        }
        $gruppi = $righe->groupBy(fn (Saldo $x) => $x->immobile_id . '|' . $x->gestione_id);
        // L'unità si nomina quando il passaggio ne ha più d'una (le pertinenze), anche se le righe legate stanno tutte su una: «non ha una fermata su
        // questa gestione» sarebbe falso per un box, la cui vendita ha la fermata sull'unità.
        $piuUnita = collect([(int) $capo->immobile_id, ...$capo->pertinenze()->pluck('immobile_id')->all(), ...$righe->pluck('immobile_id')->all()])
            ->filter()->map(fn ($id) => (int) $id)->unique()->count() > 1;
        $fatti = $gruppi->map(function (Collection $g) use ($capo, $piuUnita) {
            $x = $g->first();
            $parte = self::parteDellaFermata($capo, (int) $x->immobile_id, (int) $x->gestione_id, $piuUnita);

            return self::fattiDeiLegati($g, $parte['parti'], $parte['quale'], chiNonLiCambia: 'l\'annullamento del conguaglio',
                soggetto: sprintf('a questo passaggio sulla %s%s', FrasiObbligati::gestione($x->gestione?->nome ?? '?'), $piuUnita ? sprintf(' (%s)', $x->immobile?->nome ?? '?') : ''), perche: $parte['perche']);
        })->values()->all();

        return 'Annullato il conguaglio, un saldo del Wallet legato a questo passaggio che lo correggeva conta da solo. ' . implode(' ', $fatti);
    }

    /**
     * La parte della fermata che un passaggio ha lasciato su un'unità e una gestione, come è oggi, nella forma di `fattiDeiLegati`. Senza una
     * fermata lì, o con una fermata che non vale più (il conguaglio su cui si era fermata è stato annullato) o che un inizio ha azzerato, la parte
     * è zero, e la frase dice perché. Altrimenti è quanto ne resta per ogni persona che riceve, con le righe di adesso (`residuiDelleFermate`: un
     * inizio dopo la riduce, la fine del conduttore la fa risalire), e chi resta a carico con la cifra opposta. `parti` null se non si legge da
     * sola: le fermate di più passaggi sulle stesse persone (decisione 86.1), la fermata di chi era entrato con un passaggio già fermato (decisione
     * 81.2), più persone a carico, la fermata del coinquilino.
     *
     * @param bool $piuUnita il passaggio ha più unità (la principale e le pertinenze): la frase dice anche l'unità
     * @return array{parti: list<array{ids: list<int>, nomi: list<string>, cifra: int}>|null, quale: string, perche: ?string}
     */
    private static function parteDellaFermata(Subentro $capo, int $immobileId, int $gestioneId, bool $piuUnita = false): array
    {
        $fermate = collect(['fermata_dopo_scrivi', 'fermata_coinquilino'])
            ->flatMap(fn (string $k) => collect($capo->registro[$k] ?? [])->filter(fn ($f) => is_array($f) && (int) ($f['immobile_id'] ?? 0) === $immobileId
                && (int) ($f['gestione_id'] ?? 0) === $gestioneId)->map(fn (array $f) => [$k, $f]))->values();
        $quale = 'la parte della fermata di questo passaggio';
        if ($fermate->isEmpty()) {
            // Coda 245 (decisione 95.6 b): il resto della regola del piano che questo passaggio ha lasciato fuori dal conguaglio, con la strada del
            // Wallet: è la parte che le righe legate portano, per persona. È la differenza del piano del passaggio (decisione 111.3): «Annulla il
            // conguaglio» della fine di prima non la cambia.
            $resto = collect($capo->registro['resto_del_piano'] ?? [])->first(fn ($r) => is_array($r) && (int) ($r['immobile_id'] ?? 0) === $immobileId
                && (int) ($r['gestione_id'] ?? 0) === $gestioneId && ! empty($r['wallet']));
            if ($resto !== null) {
                return ['parti' => array_map(fn (array $p) => ['ids' => [(int) $p['anagrafica_id']], 'nomi' => [(string) $p['nome']], 'cifra' => (int) $p['cifra']], (array) $resto['parti']),
                    'quale' => 'il resto della regola del piano di questo passaggio', 'perche' => null];
            }

            return ['parti' => [], 'quale' => 'la parte da regolare a mano con questo passaggio', 'perche' => 'non ha una fermata su questa gestione' . ($piuUnita ? ' e su questa unità' : '')];
        }
        $dopo = Subentro::where('condominio_id', $capo->condominio_id)->whereNull('subentro_padre_id')->where('id', '>', $capo->id)->orderBy('id')->get();
        $valgono = $fermate->reject(fn (array $x) => self::fineSenzaConguaglio($x[1]) || self::chiLaAzzera($x[0], $x[1], $capo, $dopo) !== null)->values();
        if ($valgono->isEmpty()) {
            $azzeranti = $fermate->map(fn (array $x) => self::fineSenzaConguaglio($x[1]) ? null : self::chiLaAzzera($x[0], $x[1], $capo, $dopo))->filter()->unique('id')->values();

            return ['parti' => [], 'quale' => $quale, 'perche' => $azzeranti->isEmpty() || $fermate->contains(fn (array $x) => self::fineSenzaConguaglio($x[1]))
                ? 'il conguaglio su cui si era fermata è stato annullato'
                : sprintf('%s l\'ha azzerata', self::elenco($azzeranti->map(fn (Subentro $s) => self::passaggioInParole($s))->all()))];
        }
        $calcolo = new self();
        $parti = [];
        foreach ($valgono as [$chiave, $f]) {
            $sua = $chiave === 'fermata_dopo_scrivi' ? $calcolo->parteDiOggi($capo, $f) : null;
            if ($sua === null) {
                return ['parti' => null, 'quale' => $quale, 'perche' => null];
            }
            $parti = [...$parti, ...$sua];
        }

        return ['parti' => $parti, 'quale' => $quale, 'perche' => null];
    }

    /**
     * Quanto resta oggi di una fermata di un passaggio (`parteDellaFermata`): il suo gruppo (`gruppiDiFermate`), con le righe di adesso. Null se
     * il gruppo ha le fermate di più passaggi, o se chi resta a carico non è una persona sola.
     *
     * @param array<string, mixed> $f la voce `fermata_dopo_scrivi` del registro del passaggio
     * @return list<array{ids: list<int>, nomi: list<string>, cifra: int}>|null
     */
    private function parteDiOggi(Subentro $capo, array $f): ?array
    {
        if (! empty($f['piu_persone']) || isset($f['uscente_id'])) {
            return null;
        }
        foreach ($this->gruppiDiFermate([(int) ($f['immobile_id'] ?? 0)]) as $g) {
            $sua = collect($g['fermate'])->contains(fn (array $x) => (int) $x['s']->id === (int) $capo->id && (int) ($x['f']['fine_id'] ?? 0) === (int) ($f['fine_id'] ?? 0)
                && (int) ($x['f']['gestione_id'] ?? 0) === (int) ($f['gestione_id'] ?? 0));
            if (! $sua) {
                continue;
            }
            if (count($g['fermate']) > 1) {
                return null;
            }

            // Dopo una ricompera (Coda 351) `residuiDelleFermate` riparte da quanto ne restava allora.
            $residui = $this->residuiDelleFermate($g, $this->chiPaga->righe((int) $g['immobile_id']));

            return self::partiConChiLePorta($f, self::partiDeiRiceventi($g, $residui));
        }

        return null;
    }

    /**
     * Fase 1-bis della .50 (R12): la frase dello storico della fermata del coinquilino (decisione 79, punto 2), dal registro della fine. Ha
     * chiesto la spunta: la scelta lascia traccia, come la fermata dopo «Scrivi».
     */
    public static function fraseStoricoCoinquilino(array $f): string
    {
        $restano = self::elenco((array) ($f['restano'] ?? [])) ?: 'un coinquilino';
        $frase = sprintf('La locazione continuava con %s: il conguaglio dei giorni dopo l\'uscita di %s non c\'è, e %s sulla %s (%s) sono rimasti nelle rate di %s',
            $restano, $f['persona'] ?? '?', self::soldi((int) ($f['cifra'] ?? 0)), FrasiObbligati::gestione($f['gestione'] ?? '?'), $f['unita'] ?? '', $f['persona'] ?? '?');
        // Giro stretto della .50 (G14): la strada che il pannello aveva indicato, dal registro. Un registro di prima della 1.11.0-beta.53 (decisione 80):
        // il saldo manuale dal Wallet per chi non aveva già un saldo che un passaggio non ha scritto, fra le parti per chi lo aveva. Un registro senza
        // i candidati, o senza il Wallet, dice la frase di prima.
        if (! isset($f['al_posto']) || empty($f['wallet'])) {
            return $frase . ', da regolare a mano fra le parti con chi li paga.';
        }
        $restanoNomi = (array) ($f['restano'] ?? []);
        $alPosto = (array) $f['al_posto'];
        $seLiPagava = function (array $coinquilini, array $altri): string {
            $chi = array_values(array_filter([self::elenco($coinquilini), self::elenco($altri)], fn (string $x) => $x !== ''));

            return sprintf('se %s %s', count($coinquilini) > 1 || ($coinquilini === [] && count($altri) > 1) ? 'li pagavano' : 'li pagava', implode(', o ', $chi));
        };
        // Un registro dalla 1.11.0-beta.53: il pannello indicava a tutti i candidati i saldi dal Wallet legati al passaggio.
        if (($f['strada'] ?? null) === self::STRADA_LEGATA) {
            return $frase . ': ' . $seLiPagava($restanoNomi, $alPosto) . sprintf(', la strada indicata erano i saldi dal Wallet legati a questo passaggio, sulla stessa gestione: una riga a credito di %s e una a debito di %s.',
                $f['persona'] ?? '?', count($restanoNomi) > 1 || count($alPosto) > 1 ? 'ciascuno che li pagava' : 'chi li pagava');
        }
        $conUnSaldo = (array) ($f['con_un_saldo'] ?? []);
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
        return sprintf('Con la fine della locazione di %s i giorni dal %s tornano a chi paga al posto dell\'inquilino: la parte verso %s %s sale da %s a %s sulla %s (%s). La parte da regolare a mano fra %s è questa, e il programma non la sposta.',
            $f['conduttore_uscente'], self::data($f['dal']), self::elenco($f['verso']), self::dellaFermata($f), self::cifraInParole((int) $f['prima']), self::cifraInParole((int) $f['dopo']),
            FrasiObbligati::gestione($f['gestione']), $f['unita'], self::fraLeParti($f)) . self::codaDelRisale($f);
    }

    /**
     * La coda della frase della parte che risale, gemella di `codaDelWallet`: i fatti dei saldi del Wallet legati al passaggio della fermata
     * (`fattiDeiLegati`), con la parte nuova di ognuno. Senza saldi legati, niente: la frase resta quella di prima della 1.11.0-beta.53.
     *
     * @param array<string, mixed> $f la voce di `perLaFineDelConduttore()['risale']`
     */
    private static function codaDelRisale(array $f): string
    {
        $righe = self::righeDellaVoce($f);

        return $righe->isEmpty() ? '' : ' ' . self::fattiDeiLegati($righe, self::partiConChiLePorta($f, self::partiDellaVoce($f, 'parti_dopo', 'dopo')), 'la parte nuova', chiNonLiCambia: 'il programma',
            passaggiDellaVoce: self::passaggiIds($f));
    }

    /**
     * La parte di ognuno che riceve in una voce del registro, dalla chiave data (`parti_dopo`, `parti_prima`). Una voce scritta prima che il
     * registro avesse la chiave dice solo la cifra in tutto (`$totale`): chi riceve è allora una parte sola, con i nomi di `verso`.
     *
     * @return list<array{anagrafica_id: int|list<int>, nome: string|list<string>, cifra: int}>
     */
    private static function partiDellaVoce(array $f, string $chiave, string $totale): array
    {
        if (is_array($f[$chiave] ?? null)) {
            return array_values($f[$chiave]);
        }
        $verso = array_values(array_map('strval', (array) ($f['verso'] ?? [])));

        return [['anagrafica_id' => self::idsDalleRighe(self::righeDellaVoce($f), $verso), 'nome' => $verso, 'cifra' => (int) ($f[$totale] ?? 0)]];
    }

    /**
     * Gli ids delle persone con i nomi dati che hanno righe fra quelle date: chi non ne ha non ha un id, e per le frasi «non ne ha uno».
     *
     * @param Collection<int, Saldo> $righe
     * @param list<string> $nomi
     * @return list<int>
     */
    private static function idsDalleRighe(Collection $righe, array $nomi): array
    {
        return $righe->isEmpty() ? [] : $righe->load('anagrafica:id,nome')->filter(fn (Saldo $x) => in_array((string) ($x->anagrafica?->nome ?? ''), $nomi, true))
            ->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * La parte di ognuno di una voce, nella forma di `fattiDeiLegati`: ogni persona che riceve con la sua cifra, e chi resta a carico con la cifra
     * opposta della somma. Più persone a carico (decisione 86.1, `persone` e `persone_ids`) sono una parte sola, che fra loro decide l'amministratore.
     * Dopo una fermata (decisione 81.2) chi resta a carico è chi ha le righe della coppia, o chi è uscito se la fermata di prima è già stata regolata:
     * il programma non sa quale, e la voce nomina tutte e due con l'alternativa (`oppure`), purché tutte le sue fermate siano di quel tipo e di chi è
     * uscito sia sempre la stessa persona. In un gruppo che mescola fermate di quel tipo con altre la parte di ognuno non si legge dalla voce: null.
     *
     * @param list<array{anagrafica_id: int|list<int>, nome: string|list<string>, cifra: int}> $parti
     * @return list<array{ids: list<int>, nomi: list<string>, cifra: int, oppure?: string}>|null
     */
    private static function partiConChiLePorta(array $f, array $parti): ?array
    {
        if (! empty($f['riga_non_nota'])) {
            return null;
        }
        $fermate = self::fermateDellaVoce($f);
        $uscito = self::uscitoDelleFermate($fermate);
        if ($uscito === null && array_filter($fermate, fn (array $x) => isset($x['uscente_id'])) !== []) {
            return null;
        }
        $voci = array_map(fn (array $p) => ['ids' => array_map('intval', (array) $p['anagrafica_id']), 'nomi' => array_map('strval', (array) $p['nome']), 'cifra' => (int) $p['cifra']], $parti);
        $totale = (int) array_sum(array_column($voci, 'cifra'));
        $persone = array_values(array_map('strval', (array) ($f['persone'] ?? [])));
        $ids = array_values(array_map('intval', (array) ($f['persone_ids'] ?? [])));
        // Una voce scritta prima di `persone_ids` ha i nomi e non gli ids: si leggono dalle righe, e chi non ne ha non ha un id («non ne ha uno»).
        $carico = count($persone) > 1
            ? ['ids' => count($ids) === count($persone) ? $ids : self::idsDalleRighe(self::righeDellaVoce($f), $persone), 'nomi' => $persone, 'cifra' => -$totale]
            : ['ids' => [(int) ($f['persona_id'] ?? 0)], 'nomi' => [(string) ($f['persona'] ?? '?')], 'cifra' => -$totale];
        if ($uscito !== null) {
            $carico = ['ids' => [...$carico['ids'], (int) $uscito['uscente_id']], 'nomi' => [...$carico['nomi'], (string) ($uscito['uscente'] ?? '?')], 'oppure' => self::regolataPrima($uscito)] + $carico;
        }

        return [$carico, ...$voci];
    }

    /**
     * Decisione 81.2: la fermata che dice chi è uscito, se lo dicono tutte le fermate date e dicono la stessa persona. Un gruppo che mescola fermate
     * di chi era entrato con un passaggio già fermato e fermate normali, o con più persone uscite, non ha un uscito solo: a carico di chi sia ogni
     * parte non si legge dalla voce.
     *
     * @param list<array<string, mixed>> $fermate
     * @return array<string, mixed>|null la prima fermata, che porta `uscente_id`, `uscente` e `dopo_la_fermata`; senza `dopo_la_fermata` se le fermate
     *                                   vengono da fermate di prima diverse
     */
    private static function uscitoDelleFermate(array $fermate): ?array
    {
        $uscenti = array_map(fn (array $f) => $f['uscente_id'] ?? null, $fermate);
        if ($fermate === [] || in_array(null, $uscenti, true) || count(array_unique($uscenti)) !== 1) {
            return null;
        }
        // L'alternativa («o di Elsa se la fermata della vendita del … è già stata regolata») è della prima fermata: vale per tutte solo se tutte
        // vengono dalla stessa fermata di prima e lasciano la parte alla stessa persona. Elsa che rivende a Carlo la parte avuta da Anna e poi quella
        // avuta da Ugo ha due alternative diverse, e la voce non sa quale vale per quale cifra: ognuna è di Elsa se la sua fermata è regolata, e
        // l'alternativa è la parte di Elsa («o di Elsa se la parte di Elsa è già stata regolata»), senza la fermata di un passaggio solo.
        $prima = array_map(fn (array $f) => (string) ($f['dopo_la_fermata']['passaggio'] ?? ''), $fermate);
        $carico = array_map(fn (array $f) => (int) ($f['persona_id'] ?? 0), $fermate);

        return count(array_unique($prima)) === 1 && count(array_unique($carico)) === 1 ? $fermate[0] : ['dopo_la_fermata' => null] + $fermate[0];
    }

    /**
     * Le fermate di una voce, come le conserva il registro dei suoi passaggi (`passaggio_id`, e con più fermate `passaggi_ids`) sulla sua coppia. La
     * voce che porta chi è uscito (`voceDelGruppo`) ha tutte le fermate di quel tipo con la stessa persona uscita, ed è la fermata di tutte; una
     * scritta prima della 1.11.0-beta.53 non lo porta, e si legge dal registro.
     *
     * @return list<array<string, mixed>>
     */
    private static function fermateDellaVoce(array $f): array
    {
        if (isset($f['uscente_id'])) {
            return [$f];
        }
        $ids = self::passaggiIds($f);

        return $ids === [] ? [] : Subentro::conAnnullati()->whereIn('id', $ids)->orderBy('id')->get()
            ->flatMap(fn (Subentro $p) => collect($p->registro['fermata_dopo_scrivi'] ?? [])->filter(fn ($g) => is_array($g) && (int) ($g['fine_id'] ?? 0) === (int) ($f['fine_id'] ?? 0)
                && (int) ($g['immobile_id'] ?? 0) === (int) ($f['immobile_id'] ?? 0) && (int) ($g['gestione_id'] ?? 0) === (int) ($f['gestione_id'] ?? 0)))
            ->values()->all();
    }

    /**
     * I fatti delle righe del Wallet legate a un passaggio (decisione 100, punto 2), in una forma sola per ogni frase che ne parla: la fermata
     * ridotta, la parte che risale, l'annullamento di un inizio o di una fine, «Annulla il conguaglio», il rifiuto dell'eliminazione di una persona.
     * Il programma porta i fatti, l'amministratore decide (`decide_l_amministratore`); la frase non sceglie quale riga toccare né di quanto.
     *
     * - Per ogni persona, quanto sommano oggi le sue righe (anche a zero: «€ 0,00 per Elsa»), e quanto vale la sua parte, con il verso (positivo =
     *   debito). Chi non ha righe si nomina se la sua parte non è zero. Una parte di più persone insieme (la divisione la decide l'amministratore)
     *   si dice con la cifra del gruppo. Una parte che il programma non sa di chi sia (decisione 81.2) si dice con l'alternativa, e non è mai «già la
     *   parte». Se le righe sommano già la parte, la frase lo dice e finisce lì.
     * - Quali righe non si modificano più, e perché: in un piano che non si riscrive più, o in un esercizio che non è quello aperto, l'unico che la
     *   pagina dei saldi mostra (`libera`). Le righe a zero non contano, tranne quando si tolgono: ogni riga blocca l'eliminazione della persona.
     * - Dove si fa il passo dopo, con le regole della pagina: il Wallet corregge le righe che si modificano, e la porta (`SaldoInizialeController::
     *   scriviLegata`) accetta un saldo legato con la differenza da chi non ne ha una che si modifica (il doppione conta solo quelle), non per un
     *   passaggio annullato né su una gestione che non è attiva; senza un esercizio aperto la pagina dei saldi non si apre, e la frase dice di
     *   aprirlo. Con `$togli` (il rifiuto dell'eliminazione di una persona) le righe si tolgono: quelle che si modificano dal Wallet, quelle di un
     *   altro esercizio annullando il passaggio dallo storico dell'unità, che dice se prima c'è qualcosa da fare; quelle in un piano che non si
     *   riscrive più non si tolgono, e la persona resta.
     *
     * @param Collection<int, Saldo> $righe le righe legate, anche a zero
     * @param list<array{ids: list<int>, nomi: list<string>, cifra: int, oppure?: string}>|null $parte la parte di ognuno; `[]` vuol dire zero per tutti;
     *                                                                            null: non si sa (la frase la tace, e il passo dopo è al condizionale).
     *                                                                            Una voce con `oppure` è di una persona o dell'ultima dei suoi nomi, se
     *                                                                            vale la condizione data: non sapendo quale, non dice mai «già la parte»
     * @param string $quale «la parte nuova», «la parte che resta», «la parte che torna», «la parte della fermata di questo passaggio»
     * @param ?string $chiNonLiCambia «il programma», «annullare il passaggio»: apre il passo dopo («Il programma non li cambia: …»)
     * @param ?string $soggetto i passaggi come li dice la frase («a questo passaggio sulla gestione Ordinaria 2026»); senza, dalle righe
     * @param ?string $perche perché la parte è quella («il conguaglio su cui si era fermata è stato annullato»)
     * @param list<int> $passaggiDellaVoce i passaggi della voce (con più fermate sulle stesse persone, tutti): la parte è di tutti insieme, e un
     *                                     saldo legato in più si lega a uno di loro; senza, i passaggi delle righe
     */
    public static function fattiDeiLegati(Collection $righe, ?array $parte, string $quale, ?string $chiNonLiCambia = null, ?string $soggetto = null, ?string $perche = null, bool $togli = false,
        array $passaggiDellaVoce = []): string
    {
        $righe = Saldo::with(['anagrafica:id,nome', 'pianoRate', 'esercizio:id,nome,stato', 'gestione:id,attiva'])->whereIn('id', $righe->pluck('id')->all() ?: [0])->orderBy('id')->get();
        if ($righe->isEmpty()) {
            return '';
        }
        $libera = self::libera($righe);
        $passaggi = Subentro::conAnnullati()->whereIn('id', array_values(array_unique([...$righe->pluck('passaggio_id')->filter()->map(fn ($id) => (int) $id)->all(),
            ...array_map('intval', $passaggiDellaVoce)])) ?: [0])->orderBy('id')->get();
        $piu = $righe->count() > 1;
        $nomi = [];
        foreach ($righe as $x) {
            $nomi[(int) $x->anagrafica_id] ??= (string) ($x->anagrafica?->nome ?? '?');
        }
        $somme = $righe->groupBy(fn (Saldo $x) => (int) $x->anagrafica_id)->map(fn (Collection $g) => (int) $g->sum('saldo_iniziale'))->all();
        $sommaDi = fn (array $ids): int => (int) array_sum(array_map(fn (int $id) => $somme[$id] ?? 0, $ids));
        $voci = $parte === null ? null : self::vociDellaParte($parte, $nomi);

        // Quanto sommano oggi le righe di ognuno, e chi ha una parte e nessuna riga.
        $senzaRighe = [];
        foreach (array_filter($voci ?? [], fn (array $v) => $v['cifra'] !== 0) as $v) {
            foreach ($v['nomi'] as $k => $nome) {
                // Una voce scritta prima delle chiavi per persona ha i nomi e non sempre gli ids: chi ha righe si riconosce dal nome.
                $haRighe = count($v['ids']) === count($v['nomi']) ? isset($somme[$v['ids'][$k]]) : in_array($nome, $nomi, true);
                if (! $haRighe && ! in_array($nome, $senzaRighe, true)) {
                    $senzaRighe[] = $nome;
                }
            }
        }
        $testa = sprintf('%s %s %s %s', $piu ? 'I saldi del Wallet legati' : 'Il saldo del Wallet legato',
            $soggetto ?? self::elenco($passaggi->map(fn (Subentro $p) => self::al(self::passaggioInParole($p)))->all()), $piu ? 'sommano oggi' : 'è oggi di',
            self::elenco(array_map(fn (int $id) => self::cifraDi($somme[$id], [$nomi[$id]]), array_keys($somme))))
            . ($senzaRighe === [] ? '' : sprintf(', e %s non %s', self::elenco($senzaRighe), count($senzaRighe) > 1 ? 'ne hanno' : 'ne ha'));

        // Chi non ha già la sua parte: senza la parte, tutti quelli che hanno righe.
        $daVedere = $voci === null
            ? array_map(fn (int $id) => ['ids' => [$id], 'nomi' => [$nomi[$id]], 'cifra' => 0], array_keys($somme))
            : array_values(array_filter($voci, fn (array $v) => isset($v['oppure']) || $sommaDi($v['ids']) !== $v['cifra']));
        if ($daVedere === [] && ! $togli) {
            return $testa . sprintf(': %s già %s.', $piu ? 'sono' : 'è', $quale);
        }
        $frasi = [$testa . match (true) {
            $voci === null => '',
            collect($voci)->every(fn (array $v) => $v['cifra'] === 0) => sprintf('; %s è %s', $quale, MoneyHelper::format(0)),
            default => sprintf('; %s è %s', $quale, self::elenco(array_map(fn (array $v) => self::cifraDi($v['cifra'], $v['nomi'], insieme: true, oppure: $v['oppure'] ?? null), $voci))),
        } . ($voci !== null && $perche !== null ? ': ' . $perche : '') . '.'];

        // Le righe che non si modificano più, e perché; a zero non contano, tranne quando si tolgono: ogni riga blocca l'eliminazione della persona.
        $contano = $togli ? $righe : $righe->filter(fn (Saldo $x) => (int) $x->saldo_iniziale !== 0);
        [$neiPiani, $altrove] = $contano->reject($libera)->partition(fn (Saldo $x) => $x->eBloccato());
        $inParole = fn (Collection $g): string => $g->map(fn (Saldo $x) => self::rigaInParole($x))->implode(', ');
        $quelli = fn (Collection $g, string $dove): string => sprintf('%s %s (%s)', $g->count() > 1 ? 'quelli' : 'quello', $dove, $inParole($g));
        $piani = $neiPiani->map(fn (Saldo $x) => $x->pianoRate?->nome)->filter()->unique()->values()->all();
        $nelPiano = match (count($piani)) {
            0 => 'già applicati a un piano rate',
            1 => sprintf('nel piano «%s», che non si riscrive più', $piani[0]),
            default => sprintf('nei piani «%s», che non si riscrivono più', implode('», «', $piani)),
        };
        $nellEsercizio = fn (Collection $g): string => sprintf('nell\'esercizio «%s», %s', $g->first()->esercizio?->nome ?? '?',
            $g->first()->esercizio?->stato === 'chiuso' ? 'che è chiuso' : 'che non è quello aperto');
        $esercizi = $altrove->groupBy('esercizio_id')->values();

        if ($togli) {
            $libere = $righe->filter($libera);
            if ($libere->isNotEmpty()) {
                $frasi[] = $libere->count() === $righe->count()
                    ? sprintf('Se non %s più, %s dai saldi dell\'unità.', $piu ? 'servono' : 'serve', $piu ? 'toglili' : 'toglilo')
                    : sprintf('%s dell\'esercizio aperto (%s), se non %s più, si %s dai saldi dell\'unità.', $libere->count() > 1 ? 'Quelli' : 'Quello',
                        $libere->map(fn (Saldo $x) => self::rigaInParole($x))->implode(', '), $libere->count() > 1 ? 'servono' : 'serve', $libere->count() > 1 ? 'tolgono' : 'toglie');
            }
            foreach ($esercizi as $g) {
                $suoi = $passaggi->whereIn('id', $g->pluck('passaggio_id')->unique()->all())->values();
                $frasi[] = sprintf('%s i saldi dell\'unità non %s mostrano: se non %s più, si %s annullando %s dallo storico dell\'unità, che dice se prima c\'è qualcosa da fare, e un passaggio avvenuto davvero poi si registra di nuovo.',
                    ucfirst($quelli($g, $nellEsercizio($g))) . ',', $g->count() > 1 ? 'li' : 'lo', $g->count() > 1 ? 'servono' : 'serve', $g->count() > 1 ? 'tolgono' : 'toglie',
                    self::elenco($suoi->map(fn (Subentro $p) => self::passaggioInParole($p))->all()));
            }
            if ($neiPiani->isNotEmpty()) {
                $frasi[] = sprintf('Non si %s più %s: la persona resta.', $neiPiani->count() > 1 ? 'tolgono' : 'toglie', $quelli($neiPiani, $nelPiano));
            }

            return implode(' ', $frasi);
        }

        $ferme = array_values(array_filter([$neiPiani->isEmpty() ? null : $quelli($neiPiani, $nelPiano), ...$esercizi->map(fn (Collection $g) => $quelli($g, $nellEsercizio($g)))->all()]));
        if ($ferme !== []) {
            $frasi[] = sprintf('Non si %s più %s.', $neiPiani->count() + $altrove->count() > 1 ? 'modificano' : 'modifica', self::elenco($ferme));
        }

        // Il passo dopo, con le regole della pagina dei saldi e della porta.
        $apri = ($chiNonLiCambia !== null ? sprintf('%s non %s cambia: ', $chiNonLiCambia, $piu ? 'li' : 'lo') : '')
            . ($voci === null ? sprintf('se non %s %s, ', $piu ? 'sono' : 'è', $quale) : '');
        if (self::esercizioAperto((int) $righe->first()->condominio_id) === null) {
            $frasi[] = 'Oggi non c\'è un esercizio aperto, e la pagina dei saldi corregge e registra solo nell\'esercizio aperto: aprine uno prima.';

            return implode(' ', $frasi);
        }
        $haUnaLibera = fn (int $id): bool => $righe->contains(fn (Saldo $x) => (int) $x->anagrafica_id === $id && $libera($x));
        $correggi = collect($daVedere)->contains(fn (array $v) => collect($v['ids'])->contains($haUnaLibera));
        $senza = collect($daVedere)->contains(fn (array $v) => count($v['ids']) < count($v['nomi']) || collect($v['ids'])->contains(fn (int $id) => ! $haUnaLibera($id)));
        $rifiuto = match (true) {
            $passaggi->contains(fn (Subentro $p) => $p->annullato()) => 'per un passaggio annullato',
            $righe->contains(fn (Saldo $x) => ! (bool) ($x->gestione?->attiva ?? false)) => 'su una gestione che non è attiva',
            default => null,
        };
        $a = $passaggi->count() > 1 ? 'a uno di quei passaggi' : self::al(self::passaggioInParole($passaggi->first()));
        $correggiLi = $piu ? 'correggi dal Wallet i saldi che si modificano' : 'correggilo dal Wallet';
        $frasi[] = ucfirst($apri . match (true) {
            $correggi && $senza && $rifiuto === null => sprintf('%s, e per chi non ne ha uno registra la differenza con un saldo legato %s.', $correggiLi, $a),
            $correggi && $senza => sprintf('%s; un saldo legato in più il Wallet non lo accetta %s.', $correggiLi, $rifiuto),
            $correggi => $correggiLi . '.',
            $rifiuto === null => sprintf('registra dal Wallet la differenza con un saldo legato %s.', $a),
            default => sprintf('dal Wallet non si corregge niente: i saldi non si modificano, e un saldo legato in più il Wallet non lo accetta %s.', $rifiuto),
        });

        return implode(' ', $frasi);
    }

    /**
     * Le voci della parte nella forma di `fattiDeiLegati`, nell'ordine delle persone delle righe (le altre dopo): le voci di persone che non
     * hanno una cifra, o con gli stessi ids, si fondono sommando; chi ha righe e nessuna voce ha una parte di zero.
     *
     * @param list<array{ids: list<int>, nomi: list<string>, cifra: int, oppure?: string}> $parte
     * @param array<int, string> $nomi le persone delle righe, nel loro ordine
     * @return list<array{ids: list<int>, nomi: list<string>, cifra: int, oppure?: string}>
     */
    private static function vociDellaParte(array $parte, array $nomi): array
    {
        $voci = [];
        foreach ($parte as $v) {
            $ids = array_values(array_map('intval', $v['ids']));
            // Con i nomi: una voce scritta prima non ha gli ids di chi non ha righe, e due voci senza ids non sono la stessa.
            $chiave = implode(',', $ids) . '|' . implode(',', $v['nomi']);
            $voci[$chiave] ??= ['ids' => $ids, 'nomi' => array_values($v['nomi']), 'cifra' => 0] + (isset($v['oppure']) ? ['oppure' => $v['oppure']] : []);
            $voci[$chiave]['cifra'] += (int) $v['cifra'];
        }
        $coperti = array_merge([], ...array_map(fn (array $v) => $v['ids'], array_values($voci)));
        foreach ($nomi as $id => $nome) {
            if (! in_array($id, $coperti, true)) {
                $voci[$id . '|' . $nome] = ['ids' => [$id], 'nomi' => [$nome], 'cifra' => 0];
            }
        }
        $posto = array_flip(array_keys($nomi));
        $voci = array_values($voci);
        $primo = fn (array $v): int => min([PHP_INT_MAX, ...array_map(fn (int $id) => $posto[$id] ?? PHP_INT_MAX, $v['ids'])]);
        // La voce con l'alternativa chiude l'elenco: la condizione non si legge come se riguardasse la voce dopo.
        usort($voci, fn (array $x, array $y) => [isset($x['oppure']), $primo($x)] <=> [isset($y['oppure']), $primo($y)]);

        return $voci;
    }

    /**
     * «€ 30,58 a debito di Elsa», «€ 30,58 a credito di Ugo», «€ 0,00 per Elsa»; con `$insieme` un gruppo: «€ 30,58 a credito di Ugo e Anna insieme».
     * Con `$oppure` l'ultimo dei nomi è l'alternativa della decisione 81.2: «€ 30,58 a credito di Ugo, o di Elsa se la fermata della vendita del … è già
     * stata regolata».
     */
    private static function cifraDi(int $cifra, array $nomi, bool $insieme = false, ?string $oppure = null): string
    {
        if ($cifra === 0) {
            return MoneyHelper::format(0) . ' per ' . self::elenco($nomi);
        }
        $alternativa = $oppure !== null && count($nomi) > 1 ? array_pop($nomi) : null;

        return sprintf('%s a %s di %s%s%s', MoneyHelper::format(abs($cifra)), $cifra > 0 ? 'debito' : 'credito', self::elenco($nomi), $insieme && count($nomi) > 1 ? ' insieme' : '',
            $alternativa === null ? '' : sprintf(', o di %s se %s', $alternativa, $oppure));
    }

    /**
     * Le righe del Wallet legate ai passaggi di una voce del registro (`passaggio_id`, e con più fermate `passaggi_ids`), sulla sua unità e sulla sua
     * gestione, anche a zero: per la porta una riga a zero è una riga come le altre.
     *
     * @return Collection<int, Saldo>
     */
    private static function righeDellaVoce(array $f): Collection
    {
        $ids = self::passaggiIds($f);

        return $ids === [] ? collect() : Saldo::whereIn('passaggio_id', $ids)
            ->when(isset($f['immobile_id']), fn ($q) => $q->where('immobile_id', (int) $f['immobile_id']))
            ->when(isset($f['gestione_id']), fn ($q) => $q->where('gestione_id', (int) $f['gestione_id']))
            ->orderBy('id')->get();
    }

    /** I passaggi di una voce del registro: `passaggio_id` e, con più fermate sulle stesse persone, `passaggi_ids`. @return list<int> */
    private static function passaggiIds(array $f): array
    {
        return array_values(array_unique(array_filter([(int) ($f['passaggio_id'] ?? 0), ...array_map('intval', (array) ($f['passaggi_ids'] ?? []))])));
    }

    /**
     * La riga che la pagina dei saldi corregge, e che la porta conta come doppione: non ferma in un piano che non si riscrive più, e nell'esercizio
     * aperto del suo condominio.
     *
     * @param Collection<int, Saldo> $saldi le righe da leggere, per i loro condomini
     * @return \Closure(Saldo): bool
     */
    private static function libera(Collection $saldi): \Closure
    {
        $aperti = $saldi->pluck('condominio_id')->unique()->mapWithKeys(fn ($id) => [(int) $id => self::esercizioAperto((int) $id)]);

        return fn (Saldo $x): bool => ! $x->eBloccato() && (int) $x->esercizio_id === (int) ($aperti[(int) $x->condominio_id] ?? 0);
    }

    /** L'esercizio aperto del condominio, con la regola della pagina dei saldi e della porta (`HasEsercizio::getEsercizioCorrente`): il primo con lo stato «aperto». */
    private static function esercizioAperto(int $condominioId): ?int
    {
        return \App\Models\Condominio::find($condominioId)?->esercizi()->where('stato', 'aperto')->first()?->id;
    }

    /** Il motivo del cancello per la parte che risale: corto, con le due cifre. */
    public static function motivoRisale(array $f): string
    {
        return sprintf('la parte verso %s %s risale da %s a %s (%s): con la fine della locazione di %s quei giorni tornano a chi paga al posto dell\'inquilino, e il conguaglio scritto alla fine locazione del %s non li sposta',
            self::elenco($f['verso']), self::dellaFermata($f), self::cifraInParole((int) $f['prima']), self::cifraInParole((int) $f['dopo']), FrasiObbligati::gestione($f['gestione']), $f['conduttore_uscente'], self::data($f['fine_il']));
    }

    /** La frase dello storico della fine che ha fatto risalire la parte, dal registro. */
    public static function fraseStoricoRisale(array $f): string
    {
        return sprintf('Con questa fine la parte verso %s %s risale da %s a %s (%s, %s): dal %s, finita la locazione di %s, i giorni tornano a chi paga al posto dell\'inquilino, da regolare a mano fra %s.',
            self::elenco($f['verso'] ?? []) ?: 'chi è entrato', self::dellaFermata($f), self::cifraInParole((int) ($f['prima'] ?? 0)), self::cifraInParole((int) ($f['dopo'] ?? 0)),
            FrasiObbligati::gestione($f['gestione'] ?? '?'), $f['unita'] ?? '', self::data((string) ($f['dal'] ?? '')), $f['conduttore_uscente'] ?? 'il conduttore', self::fraLeParti($f));
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
        $testa = sprintf('Con questo passaggio la parte verso %s %s è risalita da %s a %s: annullandolo torna da %s a %s.',
            self::elenco($f['verso'] ?? []) ?: 'chi è entrato', self::dellaFermata($f), self::cifraInParole((int) ($f['prima'] ?? 0)), self::cifraInParole((int) ($f['dopo'] ?? 0)),
            self::cifraInParole((int) ($f['dopo'] ?? 0)), self::cifraInParole((int) ($f['prima'] ?? 0)));
        if (($righe = self::righeDellaVoce($f))->isNotEmpty()) {
            return $testa . ' Se le parti hanno regolato la differenza fra loro, quell\'accordo va rivisto. '
                . self::fattiDeiLegati($righe, self::partiConChiLePorta($f, self::partiDellaVoce($f, 'parti_prima', 'prima')), 'la parte che torna', chiNonLiCambia: 'annullare il passaggio',
                    passaggiDellaVoce: self::passaggiIds($f));
        }

        return $testa . ' Se per questo hai registrato un saldo manuale dal Wallet, o le parti hanno regolato la differenza fra loro, quel saldo o quell\'accordo vanno rivisti: annullare il passaggio non li toglie.';
    }

    /**
     * L'avviso dell'annullamento dell'inizio che ha ridotto o azzerato una fermata (il gemello di `avvisoRisaleAnnullando`): annullato l'inizio, la parte
     * da regolare torna quella di prima. Un accordo fra le parti adeguato al residuo nel frattempo si rivede; dei saldi del Wallet legati ai passaggi
     * della fermata l'avviso dice i fatti (`fattiDeiLegati`), con la parte che torna di ognuno.
     */
    public static function avvisoRidottaAnnullando(array $f): string
    {
        $testa = sprintf('%s la parte verso %s %s è scesa da %s a %s: annullandolo torna da %s a %s.',
            ucfirst(self::conQuesto($f)), self::elenco($f['verso'] ?? []) ?: 'chi riceve', self::dellaFermata($f), self::cifraInParole((int) ($f['prima'] ?? $f['cifra'] ?? 0)), self::cifraInParole((int) ($f['residuo'] ?? 0)),
            self::cifraInParole((int) ($f['residuo'] ?? 0)), self::cifraInParole((int) ($f['prima'] ?? $f['cifra'] ?? 0)));
        if (($righe = self::righeDellaVoce($f))->isNotEmpty()) {
            return $testa . ' Se le parti hanno già regolato la differenza fra loro, quell\'accordo va rivisto. '
                . self::fattiDeiLegati($righe, self::partiConChiLePorta($f, self::partiDellaVoce($f + ['prima' => (int) ($f['cifra'] ?? 0)], 'parti_prima', 'prima')), 'la parte che torna',
                    chiNonLiCambia: 'annullare il passaggio', passaggiDellaVoce: self::passaggiIds($f));
        }

        return $testa . ' Se le parti hanno già regolato la differenza, o per questo hai registrato un saldo manuale dal Wallet, rivedilo: annullare il passaggio non lo toglie.';
    }

    /** La frase del rientro: un'informazione, con l'alternativa del rinnovo (annullare la fine, l'ultimo passaggio, e rifarla). */
    public static function fraseRientro(array $f): string
    {
        $chi = self::elenco($f['pagato_da'] ?? []) ?: 'chi paga al posto dell\'inquilino';
        $piu = count($f['pagato_da'] ?? []) > 1;
        // Giro stretto della .50 (G1): con una parte negativa (un'entrata che nei giorni di chi rientra supera la spesa) è chi ha la coppia
        // che restituisce.
        $frase = (int) $f['cifra'] < 0
            ? sprintf('%s rientra dal %s dopo la sua fine locazione del %s, che ha scritto il conguaglio: nei giorni dal %s, sulla %s (%s), l\'entrata supera la spesa, e con la coppia %s %s anche il credito di quei giorni, %s, che %s %s.',
                $f['persona'], self::data($f['dal']), self::data($f['fine_il']), self::data($f['dal']), FrasiObbligati::gestione($f['gestione']), $f['unita'], $chi, $piu ? 'hanno avuto' : 'ha avuto',
                MoneyHelper::format(-(int) $f['cifra']), $piu ? 'restituiscono' : 'restituisce', FrasiObbligati::a((string) $f['persona']))
            : sprintf('%s rientra dal %s dopo la sua fine locazione del %s, che ha scritto il conguaglio: %s dei giorni dal %s, sulla %s (%s), %s %s, e %s glieli rimborsa con il prospetto degli oneri accessori.',
                $f['persona'], self::data($f['dal']), self::data($f['fine_il']), MoneyHelper::format((int) $f['cifra']), self::data($f['dal']), FrasiObbligati::gestione($f['gestione']), $f['unita'],
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
    public static function fraseRidotta(array $f, bool $seScrivi = false): string
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
        // Decisione 81.2: dopo una fermata chi risponde della parte è chi ha le righe della coppia, o chi è uscito se la fermata di prima è già stata
        // regolata; il programma non sa quale, e la frase non ne nomina uno solo.
        $dopoUnaFermata = isset($f['uscente_id']);

        // Coda 356: con l'arretrato agli eredi la posizione di chi riceveva è passata a chi era a carico: non c'è niente da restituire a nessuno.
        $regolata = ! empty($f['agli_eredi']) ? '' : sprintf(' Se %s hanno già regolato %s, %s vanno restituiti %s.', $dopoUnaFermata ? 'chi ne risponde e ' . $verso : self::fraLeParti($f),
            MoneyHelper::format(abs($prima)), MoneyHelper::format(abs($prima - (int) $f['residuo'])),
            $credito ? ($dopoUnaFermata ? 'a chi ne risponde' : FrasiObbligati::a((string) $f['persona'])) : FrasiObbligati::a($verso));

        return sprintf('%s %s lasciato %s %s%s dei giorni dopo l\'uscita di %s, da regolare a mano con %s%s. %s%s %s: %s.%s%s',
            ucfirst(self::elenco(self::passaggiDellaVoce($f))), count(self::passaggiDellaVoce($f)) > 1 ? 'hanno' : 'ha',
            self::diChiResta((int) $f['cifra'], self::chiResta($f), $dopoUnaFermata ? $f['uscente'] . ' se ' . self::regolataPrima($f) : null) . ($dopoUnaFermata ? ',' : ''),
            MoneyHelper::format(abs((int) $f['cifra'])), $credito ? ' di credito' : '', $f['inquilino'], $verso,
            // Coda 351: dopo una ricompera la cifra di prima non è cambiata per una locazione, ma per chi ha ricomprato: «i passaggi» dice tutti e due.
            $prima !== (int) $f['cifra'] ? sprintf(' (con i passaggi registrati da allora, %s)', MoneyHelper::format(abs($prima))) : '',
            // Coda 356: nella successione con l'arretrato a nome di chi muore, finché la scelta manca, la riduzione vale se il conguaglio si scrive.
            $seScrivi ? 'Se scrivi il conguaglio, ' : '', $seScrivi ? self::conQuesto($f) : ucfirst(self::conQuesto($f)), self::giorniDelConduttore($f), $resta,
            $regolata, self::codaDellePersoneACarico($f) . self::codaDelWallet($f));
    }

    /** Il motivo del cancello dopo una fermata: la cifra che resta da regolare, al posto di quella detta dal passaggio. */
    public static function motivoRidotta(array $f, bool $seScrivi = false): string
    {
        // Giro stretto della .50 (G1): una fermata negativa è un credito, e si dice con le parole. Coda 356: «se scrivi il conguaglio» finché la scelta manca.
        return ($seScrivi ? 'se scrivi il conguaglio, ' : '') . ((int) $f['cifra'] < 0
            ? sprintf('%s resta da regolare fra %s un credito di %s, non più di %s: %s',
                self::dellaFermata($f), self::fraLeParti($f), MoneyHelper::format(abs((int) $f['residuo'])), MoneyHelper::format(abs((int) ($f['prima'] ?? $f['cifra']))),
                self::giorniDelConduttore($f))
            : sprintf('%s restano da regolare fra %s %s, non più %s: %s',
                self::dellaFermata($f), self::fraLeParti($f), MoneyHelper::format((int) $f['residuo']), MoneyHelper::format((int) ($f['prima'] ?? $f['cifra'])),
                self::giorniDelConduttore($f)));
    }

    /**
     * Decisione 86.2: l'inizio che azzera la fermata (la fine di Luca e l'inizio di Marco lo stesso giorno) non lascia niente da regolare. Lo dice
     * la frase del pannello, come informazione; il cancello non chiede una seconda spunta per «€ 0,00, non più € 268,20».
     */
    public static function azzerata(array $f): bool
    {
        return (int) ($f['residuo'] ?? 0) === 0 && ($f['parti'] ?? []) === [];
    }

    /**
     * Della fermata di una voce del registro non resta niente da regolare: l'ultima voce scritta su quella fermata, da questo passaggio o da uno
     * non annullato registrato dopo, è l'inizio di una locazione che la azzera (decisione 86.2). La voce della fermata sta nel registro del suo
     * passaggio, quanto ne resta in quello dell'inizio che viene dopo; la fine del conduttore che la fa risalire la riapre. Una voce è sulla
     * stessa fermata se nomina lo stesso passaggio (`passaggio_id`, o fra i `passaggi_ids` di una voce di più fermate) sulla stessa coppia: la
     * stessa fine, unità e gestione. La fermata del coinquilino non ha voci dopo.
     *
     * @param string $chiave la chiave del registro della voce: `fermata_dopo_scrivi`, `fermata_ridotta`, `fermata_risale`, `fermata_coinquilino`
     * @param array<string, mixed> $f la voce
     * @param Subentro $s il passaggio padre nel cui registro sta la voce
     * @param iterable<Subentro> $passaggi i passaggi padre del condominio: contano quelli registrati dopo `$s`, non annullati
     */
    public static function nienteDaRegolare(string $chiave, array $f, Subentro $s, iterable $passaggi): bool
    {
        return self::chiLaAzzera($chiave, $f, $s, $passaggi) !== null;
    }

    /**
     * L'inizio che ha azzerato la fermata di una voce del registro, con la regola di `nienteDaRegolare`: il passaggio dell'ultima voce scritta su
     * quella fermata, se è un inizio che la azzera; null se della fermata resta qualcosa da regolare (o se l'ultima voce è la fine di un
     * conduttore che la fa risalire). Se la voce stessa è l'inizio che azzera, è `$s`.
     *
     * @param iterable<Subentro> $passaggi i passaggi padre del condominio: contano quelli registrati dopo `$s`, non annullati
     */
    public static function chiLaAzzera(string $chiave, array $f, Subentro $s, iterable $passaggi): ?Subentro
    {
        if ($chiave === 'fermata_coinquilino') {
            return null;
        }
        $nominati = fn (array $g): array => array_values(array_filter([(int) ($g['passaggio_id'] ?? 0), ...array_map('intval', (array) ($g['passaggi_ids'] ?? []))]));
        $fermate = $chiave === 'fermata_dopo_scrivi' ? [(int) $s->id] : $nominati($f);
        $stessa = fn (array $g): bool => array_intersect($fermate, $nominati($g)) !== [] && (int) ($g['fine_id'] ?? 0) === (int) ($f['fine_id'] ?? 0)
            && (int) ($g['immobile_id'] ?? 0) === (int) ($f['immobile_id'] ?? 0) && (int) ($g['gestione_id'] ?? 0) === (int) ($f['gestione_id'] ?? 0);
        $ultima = [$chiave, $f, $s];
        foreach (collect($passaggi)->filter(fn (Subentro $x) => (int) $x->id > (int) $s->id && ! $x->annullato())->sortBy('id') as $x) {
            foreach (['fermata_ridotta', 'fermata_risale'] as $k) {
                foreach ($x->registro[$k] ?? [] as $g) {
                    if (is_array($g) && $stessa($g)) {
                        $ultima = [$k, $g, $x];
                    }
                }
            }
        }

        return $ultima[0] === 'fermata_ridotta' && self::azzerata($ultima[1]) ? $ultima[2] : null;
    }

    /** La frase dello storico dell'inizio dopo una fermata, dal registro. */
    public static function fraseStoricoRidotta(array $f): string
    {
        $credito = (int) ($f['cifra'] ?? 0) < 0;
        // Decisione 86.2: se l'inizio ha azzerato la fermata non resta niente da regolare, e lo storico lo ricorda con la cifra di prima.
        $azzerata = self::azzerata($f);

        return sprintf('%s, %s (%s rimasti %s) %s fra %s%s: %s.',
            ucfirst(self::conQuesto($f)), self::dellaFermata($f, 'di un passaggio di prima'), self::soldi((int) ($f['cifra'] ?? 0)),
            self::diChiResta((int) ($f['cifra'] ?? 0), self::chiResta($f, passato: true), isset($f['uscente_id']) ? ($f['uscente'] ?? '?') . ' se ' . self::regolataPrima($f) : null),
            $azzerata ? 'non resta niente da regolare' : ($credito ? 'resta da regolare' : 'restano da regolare'), self::fraLeParti($f, false),
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

    /**
     * Chi è rimasto a carico e chi ha ricevuto, in una frase: «Ugo e Elsa»; con più persone a carico (decisione 86.1) «Ugo, Anna e Elsa». Dopo una
     * fermata (decisione 81.2) chi risponde della parte non è uno solo: «chi ne risponde (Ugo, o Elsa se la fermata … è già stata regolata) e Carlo»,
     * o senza la parentesi (`$diChi` falso) se la frase l'ha già detto.
     */
    private static function fraLeParti(array $f, bool $diChi = true): string
    {
        if (isset($f['uscente_id'])) {
            $riceve = self::elenco((array) ($f['verso'] ?? [])) ?: 'chi è entrato';

            return $diChi ? sprintf('chi ne risponde (%s, o %s se %s) e %s', self::chiResta($f), $f['uscente'] ?? '?', self::regolataPrima($f), $riceve) : 'chi ne risponde e ' . $riceve;
        }
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
        // Coda 351: la fermata ridotta da chi ricompra la sua stessa parte: i giorni tornano nella sua riga, e non c'è un conduttore.
        if (isset($f['ricompra'])) {
            // Coda 356: con la successione di chi riceveva i giorni passano all'erede; con l'arretrato agli eredi passa tutta la sua posizione.
            return match (true) {
                ! empty($f['agli_eredi']) => sprintf('la posizione di %s, con l\'arretrato, passa %s, che ha già i suoi giorni nella sua parte della coppia', (string) ($f['erede_di'] ?? '?'),
                    FrasiObbligati::a((string) $f['ricompra'])),
                isset($f['erede_di']) => sprintf('i giorni dal %s passano con la successione di %s %s, che li ha già nella sua parte della coppia', self::data((string) $f['dal']),
                    (string) $f['erede_di'], FrasiObbligati::a((string) $f['ricompra'])),
                default => sprintf('i giorni dal %s tornano %s, che li ha già nella sua parte della coppia', self::data((string) $f['dal']), FrasiObbligati::a((string) $f['ricompra'])),
            };
        }
        $conduttore = (string) ($f['conduttore'] ?? 'il conduttore');

        return (int) ($f['prima'] ?? $f['cifra'] ?? 0) - (int) ($f['residuo'] ?? 0) < 0
            ? sprintf('il credito dei giorni dal %s lo riceve %s con il prospetto degli oneri accessori', self::data((string) $f['dal']), $conduttore)
            : sprintf('i giorni dal %s li rimborsa %s con il prospetto degli oneri accessori', self::data((string) $f['dal']), $conduttore);
    }

    /** «con questo inizio», o per la fermata ridotta da chi ricompra la sua stessa parte (Coda 351) «con questo passaggio». */
    private static function conQuesto(array $f): string
    {
        return isset($f['ricompra']) ? 'con questo passaggio' : 'con questo inizio';
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
        // Coda 245 (1.11.0-beta.55): una coppia della regola del piano (la fine con un coinquilino che resta, o un passaggio dopo sullo stesso
        // anello) ha le righe dei conduttori, che non sono giorni dopo l'uscita: dalla parte del proprietario contano solo le righe di chi sta al
        // posto dell'inquilino nei giorni che nessun conduttore copre (il ripiego), con i loro giorni, che il registro ha nei periodi della coppia.
        $delPiano = self::gruppoDelPiano($fine, $immobileId, $gestioneId, $esercizioId);
        $restoDelRipiego = 0;
        $conChi = [];
        if ($delPiano !== null) {
            $ripiego = array_map('intval', (array) ($delPiano['ripiego'] ?? []));
            $righe = $righe->filter(fn (Saldo $r) => in_array((int) $r->anagrafica_id, $ripiego, true))->values();
            // Il resto della regola del piano (decisione 95.6 b): la coppia di un passaggio dopo può scrivere a chi sta al posto dell'inquilino meno della sua parte
            // del piano (la fine di Dora dopo quella di Ines: Ugo 7875 di 9074), e la differenza è fra lui e chi era già uscito, con il Wallet o con il prospetto, che la
            // dice «da restituire». La parte dei giorni che una vendita gli toglie è quella del piano: ciò che ha pagato con la coppia e ciò che deve con il resto. I pezzi dei
            // periodi portano la parte scritta (`RegolaDelPiano::fotografia`, in proporzione alla differenza del piano): pesano fra loro come la parte del piano.
            $resto = array_map('intval', (array) ($delPiano['resto'] ?? []));
            $restoDelRipiego = (int) array_sum(array_intersect_key($resto, array_flip($ripiego)));
            $conChi = array_filter(array_diff_key($resto, array_flip($ripiego)));
        }
        $scritto = (int) $righe->sum('saldo_iniziale');
        $totale = $scritto + $restoDelRipiego;
        if ($scritto === 0 || $totale === 0) {
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
            // Le altre persone del resto, con il loro nome e la loro cifra: la frase dice con chi si regola chi ha un resto di verso opposto (`chiSiRegola`).
            ...($restoDelRipiego !== 0 ? ['resto_del_ripiego' => ['per' => array_filter(array_intersect_key($resto, array_flip($ripiego))),
                'altri' => Anagrafica::whereIn('id', array_keys($conChi) ?: [0])->pluck('nome', 'id')->map(fn ($n, $id) => ['nome' => (string) $n, 'cifra' => (int) $conChi[$id]])->all()]] : []),
            'righe' => $righe->groupBy(fn (Saldo $r) => (int) $r->anagrafica_id)->map(fn ($g) => (int) $g->sum('saldo_iniziale'))->all(),
            'saldi' => $righe->groupBy(fn (Saldo $r) => (int) $r->anagrafica_id)->map(fn ($g) => $g->pluck('id')->map(fn ($id) => (int) $id)->all())->all(),
            'immobile_id' => $immobileId, 'unita' => $unita === null ? '' : (trim((string) $unita->nome) !== '' ? (string) $unita->nome : (string) $unita->etichetta),
            'gestione_id' => $gestioneId, 'gestione' => (string) ($righe->first()?->gestione?->nome ?? '?'), 'esercizio_id' => $esercizioId,
        ];
    }

    /**
     * Con chi si regola un resto: le persone del resto fuori dal ripiego che hanno la cifra di verso opposto (chi ha un credito si regola con chi ha un debito, e
     * viceversa). Chi ha anche lui un credito non deve niente a chi lo ha.
     *
     * @param array<int, array{nome: string, cifra: int}> $altri
     * @return list<string>
     */
    private static function chiSiRegola(array $altri, int $cifra): array
    {
        return array_values(array_map(fn (array $x) => $x['nome'], array_filter($altri, fn (array $x) => $x['cifra'] !== 0 && ($x['cifra'] > 0) !== ($cifra > 0))));
    }

    /**
     * Coda 245 (1.11.0-beta.55): la voce della regola del piano di una fine (`conguaglio.piano`) per un gruppo (unità, gestione, esercizio), o
     * null se la fine lì non l'ha: la sua coppia è dei giorni dopo l'uscita, come nella .50.
     *
     * @return ?array<string, mixed>
     */
    private static function gruppoDelPiano(Subentro $fine, int $immobileId, int $gestioneId, ?int $esercizioId): ?array
    {
        return collect($fine->registro['conguaglio']['piano'] ?? [])->first(fn ($g) => is_array($g) && (int) ($g['immobile_id'] ?? 0) === $immobileId
            && (int) ($g['gestione_id'] ?? 0) === $gestioneId && (($g['esercizio_id'] ?? null) === null ? null : (int) $g['esercizio_id']) === $esercizioId);
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
                // L'estinzione: i nudi che tornano pieni, ciascuno con la sua quota. Con l'accrescimento restano gli altri usufruttuari. Coda 354: anche il nudo la cui
                // riga comincia il giorno del passaggio (la nuda comprata il giorno dell'estinzione).
                foreach ($righe->filter(fn ($n) => $n->tipologia === 'nuda_proprietario' && in_array((int) $n->anagrafica_id, $nudiIds, true)
                    && (self::inCorso($n, $giornoPrima) || self::inCorso($n, $decorrenza))) as $n) {
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
            [$righe, $sua] = $this->conLaFine($righe, $immobileId, $chiFinisce, $decorrenza);
            if ($sua !== null) {
                $quota = $immobileId === (int) $chiFinisce->immobile_id ? $quota : (float) $sua->quota;
            }
        }

        return $quota > 0.0 ? $this->conIlConduttore($righe, $immobileId, $anagraficaId, $quota, $decorrenza) : $righe;
    }

    /**
     * Coda 245 (1.11.0-beta.55): le righe dell'unità dopo la fine della locazione di chi esce, senza un nuovo inquilino, come le lascia la
     * registrazione: la sua riga chiusa il giorno prima, sulla pertinenza quella che la registrazione chiude (decisione 81.3). È la forma di
     * `dopoIlConduttore` senza chi entra: la regola del piano le legge da qui, non da una sua copia.
     *
     * @param Collection<int, object> $righe
     * @return Collection<int, object>
     */
    public function righeDopoLaFine(Collection $righe, int $immobileId, TitolaritaImmobile $uscente, CarbonImmutable $decorrenza): Collection
    {
        return $this->conLaFine($righe, $immobileId, $uscente, $decorrenza)[0];
    }

    /**
     * Coda 245 (1.11.0-beta.55): le righe dell'unità dopo l'inizio di un conduttore (`conIlConduttore`), o dopo il cambio d'inquilino in un passo
     * (`$chiFinisce`: la sua locazione chiusa il giorno prima, chi entra con la sua quota, `dopoIlConduttore`), come le lascia la registrazione. La
     * regola del piano le legge da qui.
     *
     * @param Collection<int, object> $righe
     * @return Collection<int, object>
     */
    public function righeConIlConduttore(Collection $righe, int $immobileId, int $anagraficaId, float $quota, CarbonImmutable $decorrenza, ?TitolaritaImmobile $chiFinisce = null): Collection
    {
        return $this->dopoIlConduttore($righe, $immobileId, $anagraficaId, $quota, $decorrenza, $chiFinisce);
    }

    /**
     * La locazione di chi esce chiusa il giorno prima, come la chiude la registrazione: sull'unità la riga del modulo, sulla pertinenza quella che
     * chi esce ha lì (decisione 81.3). Le righe, e la riga chiusa (null se sull'unità chi esce non ne ha una).
     *
     * @param Collection<int, object> $righe
     * @return array{0: Collection<int, object>, 1: ?object}
     */
    private function conLaFine(Collection $righe, int $immobileId, TitolaritaImmobile $chiFinisce, CarbonImmutable $decorrenza): array
    {
        $giornoPrima = $decorrenza->subDay();
        $rigaQui = $immobileId === (int) $chiFinisce->immobile_id ? (int) $chiFinisce->id : self::rigaDellaPertinenza($immobileId, (int) $chiFinisce->anagrafica_id, 'inquilino', $giornoPrima);
        $sua = $righe->first(fn ($x) => $rigaQui !== null && (int) $x->id === $rigaQui);
        if ($sua === null) {
            return [$righe, null];
        }

        return [$righe->map(fn ($x) => (int) $x->id === $rigaQui ? (object) array_replace((array) $x, ['data_fine' => $giornoPrima->toDateString()]) : $x)->values(), $sua];
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
     * Le persone che hanno già un saldo sulla gestione, sull'unità e sull'esercizio, senza contare quelli scritti da un passaggio né quelli
     * legati a un passaggio: chi occupa il posto del Wallet. Non decide più la strada a mano (dalla 1.11.0-beta.53 è sempre il Wallet, con i
     * saldi legati al passaggio); serve al cancello del defunto, per dire se un saldo non legato può già comprendere la parte.
     *
     * @param list<int> $persone
     * @return list<int>
     */
    public static function conUnSaldo(array $persone, int $gestioneId, int $immobileId, ?int $esercizioId): array
    {
        return Saldo::whereIn('anagrafica_id', $persone ?: [0])->where('gestione_id', $gestioneId)->where('immobile_id', $immobileId)->whereNull('subentro_id')->whereNull('passaggio_id')
            ->when($esercizioId !== null, fn ($q) => $q->where('esercizio_id', $esercizioId))->distinct()->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    // --- I saldi del Wallet legati a un passaggio (1.11.0-beta.53, Coda 244) ----------------------------------------------------

    /**
     * Una riga del Wallet a parole, con il verso e mai il meno: «€ 60,16 a credito di Ugo», «€ 60,16 a debito di Elsa»; a zero non ha un verso:
     * «€ 0,00 per Dora». Con `$conPiano` anche il piano che l'ha già assorbita: «…, già nel piano «Preventivo 2026»».
     */
    public static function rigaInParole(Saldo $x, bool $conPiano = false): string
    {
        $cifra = (int) $x->saldo_iniziale;

        return ($cifra === 0 ? sprintf('%s per %s', MoneyHelper::format(0), (string) ($x->anagrafica?->nome ?? '?'))
            : sprintf('%s a %s di %s', MoneyHelper::format(abs($cifra)), $cifra < 0 ? 'credito' : 'debito', (string) ($x->anagrafica?->nome ?? '?')))
            . ($conPiano && $x->piano_rate_id !== null && $x->pianoRate !== null ? sprintf(', già nel piano «%s»', $x->pianoRate->nome) : '');
    }

    /**
     * Le righe in una frase, per gestione: «€ 60,16 a credito di Ugo e € 60,16 a debito di Elsa (gestione Ordinaria 2026)»; più gestioni con il
     * punto e virgola. La usano l'annullamento (le righe legate che si tolgono, i saldi a mano scritti dopo) e gli avvisi.
     *
     * @param Collection<int, Saldo> $saldi con la persona e la gestione
     */
    public static function righeInParole(Collection $saldi, bool $conPiano = false): string
    {
        return $saldi->groupBy('gestione_id')
            ->map(fn (Collection $g) => sprintf('%s (%s)', self::elenco($g->map(fn (Saldo $x) => self::rigaInParole($x, $conPiano))->all()), FrasiObbligati::gestione($g->first()->gestione?->nome ?? '?')))
            ->implode('; ');
    }

    /**
     * Le righe che la pagina dei saldi non mostra: stanno in un esercizio che non è quello aperto del loro condominio. La pagina legge solo
     * quello, con la regola di `HasEsercizio::getEsercizioCorrente` (il primo esercizio del condominio con lo stato «aperto»): lì una riga di
     * un altro esercizio non si modifica né si toglie, e la porta ne accetta una nuova nell'esercizio aperto (`fattiDeiLegati`).
     *
     * @param Collection<int, Saldo> $saldi con `condominio_id` ed `esercizio_id`
     * @return Collection<int, Saldo>
     */
    public static function fuoriDalWallet(Collection $saldi): Collection
    {
        $aperti = $saldi->pluck('condominio_id')->unique()
            ->mapWithKeys(fn ($id) => [(int) $id => self::esercizioAperto((int) $id)]);

        return $saldi->filter(fn (Saldo $x) => (int) $x->esercizio_id !== (int) ($aperti[(int) $x->condominio_id] ?? 0))->values();
    }

    /**
     * Gli avvisi dell'annullamento di un passaggio sui saldi scritti a mano dal Wallet dopo la sua registrazione, senza legarli a nessun passaggio:
     * la strada a mano di prima della 1.11.0-beta.53, o un saldo qualunque. La coincidenza non prova che una riga sia di quel passaggio: si
     * nominano, non si toccano. Sulle unità del passaggio (il padre e le pertinenze passate con lui), i saldi manuali di una persona che ha o
     * ha avuto una riga su quell'unità, non scritti da un passaggio né legati a uno, non dei fornitori, diversi da zero, scritti dopo la
     * registrazione (la data di creazione è al secondo: una riga scritta nello stesso secondo della registrazione non conta, perché non si
     * sa se è venuta prima o dopo; dalla pagina non succede, la registrazione e il Wallet sono due pagine). Un saldo scritto prima e
     * modificato dopo non si nomina: la data di modifica cambia anche quando un piano lo assorbe, e non dice chi l'ha cambiato. Un avviso per
     * unità, con la persona, la cifra a parole, la gestione e il piano che l'ha già assorbito.
     *
     * @return list<string>
     */
    public static function avvisiDeiSaldiAMano(Subentro $capo): array
    {
        $unita = Subentro::conAnnullati()->where(fn ($q) => $q->whereKey($capo->id)->orWhere('subentro_padre_id', $capo->id))->orderBy('id')->pluck('immobile_id')
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
        $saldi = Saldo::with(['anagrafica:id,nome', 'gestione:id,nome', 'pianoRate:id,nome', 'immobile:id,nome'])
            ->whereIn('immobile_id', $unita ?: [0])->where('origine', 'manuale')
            ->whereNull('subentro_id')->whereNull('passaggio_id')->whereNull('fornitore_id')->whereNotNull('anagrafica_id')
            ->where('saldo_iniziale', '!=', 0)->where('created_at', '>', $capo->created_at)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('anagrafica_immobile')
                ->whereColumn('anagrafica_immobile.anagrafica_id', 'saldi.anagrafica_id')->whereColumn('anagrafica_immobile.immobile_id', 'saldi.immobile_id'))
            ->orderBy('id')->get();
        $avvisi = [];
        foreach ($unita as $u) {
            $qui = $saldi->where('immobile_id', $u)->values();
            if ($qui->isEmpty()) {
                continue;
            }
            $piu = $qui->count() > 1;
            $avvisi[] = sprintf($piu
                ? 'Dalla registrazione di questo passaggio sono stati scritti a mano dal Wallet, su %s, saldi non legati a nessun passaggio: %s. Se erano per questo passaggio, annullarlo non li toglie: rivedili dal Wallet, prima o dopo.'
                : 'Dalla registrazione di questo passaggio è stato scritto a mano dal Wallet, su %s, un saldo non legato a nessun passaggio: %s. Se era per questo passaggio, annullarlo non lo toglie: rivedilo dal Wallet, prima o dopo.',
                $qui->first()->immobile?->nome ?? 'l\'unità', self::righeInParole($qui, conPiano: true));
        }

        return $avvisi;
    }

    // --- Le parole ---------------------------------------------------------------------------------------------------------------

    /** «con questo passaggio», o per la fermata della fine di un conduttore (Coda 254) «con la fine della locazione di Luca». */
    private static function conQuestoPassaggio(array $f): string
    {
        return isset($f['conduttore_uscente']) ? 'con la fine della locazione di ' . $f['conduttore_uscente'] : 'con questo passaggio';
    }

    /**
     * La strada a mano, con il verso delle righe del Wallet: i saldi legati a questo passaggio, che non occupano il posto di un riporto. Il Wallet
     * c'è sempre (dalla 1.11.0-beta.53): una persona con un saldo già lì può avere anche la riga legata.
     */
    private static function stradaAMano(array $f): string
    {
        $verso = self::elenco($f['verso']);
        // Giro stretto della .50 (G1): ogni riga con il suo verso. Con una parte negativa (un credito di chi entra) la riga di chi riceve è a
        // credito, e quella di chi resta a carico a debito.
        $debiti = array_map(fn (array $p) => sprintf('%s a %s di %s', MoneyHelper::format(abs((int) $p['cifra'])), (int) $p['cifra'] < 0 ? 'credito' : 'debito', $p['nome']), $f['parti'] ?? []);

        return sprintf('Se vanno %s, registra dal Wallet i saldi legati a questo passaggio, sulla stessa gestione: %s a %s di %s%s %s. Un saldo legato non occupa il posto di un riporto, e si toglie se annulli il passaggio.',
            FrasiObbligati::a($verso), MoneyHelper::format(abs((int) $f['cifra'])), (int) $f['cifra'] < 0 ? 'debito' : 'credito', $f['persona'], count($debiti) > 1 ? ',' : ' e', self::elenco($debiti));
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
            ? FrasiObbligati::a($chi) . ($oppure !== null ? ', o ' . FrasiObbligati::a($oppure) : '')
            : 'a carico di ' . $chi . ($oppure !== null ? ', o di ' . $oppure : '');
    }

    /**
     * Chi è rimasto a carico di una fermata, per le frasi che ne dicono la cifra: le persone, ognuna con la sua parte dove il programma la sa (`cifre_per_persona`: chi riceve è
     * una delle persone a carico, e una parte dei giorni sta già nella sua riga); dove non sa nella riga di chi stanno i giorni (`riga_non_nota`, Coda 353) chi li ha nella sua
     * riga, senza nominare nessuno.
     */
    private static function chiResta(array $f, bool $passato = false): string
    {
        if (! empty($f['riga_non_nota'])) {
            return sprintf('chi %s nella sua riga i giorni di %s', $passato ? 'aveva' : 'ha', $f['uscente']);
        }
        $cifre = (array) ($f['cifre_per_persona'] ?? []);

        return $cifre === [] ? (string) ($f['persona'] ?? '?')
            : self::elenco(array_map(fn (array $p) => sprintf('%s (%s)', $p['nome'], MoneyHelper::format(abs((int) $p['cifra']))), $cifre));
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
        return sprintf('%s sulla %s', self::aCarico((int) ($f['cifra'] ?? 0), 'dei giorni dopo l\'uscita di ' . ($f['inquilino'] ?? 'l\'inquilino'), (string) ($f['persona'] ?? 'chi esce'), passato: true),
            FrasiObbligati::gestione($f['gestione'] ?? '?'));
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
     * locazione di Luca», con l'inizio «l'inizio della locazione di Luca».
     */
    public static function passaggioInParole(Subentro $s, bool $conData = true): string
    {
        $tipo = match (true) {
            $s->tipo_passaggio === 'fine_locazione' => 'la fine della locazione di ' . self::nomeDiChiEsce($s),
            $s->tipo_passaggio === 'inizio_locazione' => 'l\'inizio della locazione di ' . (string) ($s->registro['nomi']['entrante'] ?? $s->entrante?->nome ?? 'chi entra'),
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

    /** «alla vendita», «all'estinzione», «al legato»: la preposizione articolata davanti alle parole del passaggio, gemella di `di()`. */
    public static function al(string $parole): string
    {
        return match (true) {
            str_starts_with($parole, 'la ') => 'alla ' . substr($parole, 3),
            str_starts_with($parole, 'il ') => 'al ' . substr($parole, 3),
            str_starts_with($parole, 'l\'') => 'all\'' . substr($parole, 2),
            default => FrasiObbligati::a($parole),
        };
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
