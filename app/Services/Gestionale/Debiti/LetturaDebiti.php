<?php

namespace App\Services\Gestionale\Debiti;

use App\Helpers\DateHelper;
use App\Services\Gestionale\CreditoService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * La lettura comune dei debiti dei condòmini: per un condominio, una data D (di serie l'oggi dell'amministratore) e, se data, una
 * gestione, la posizione di ogni persona letta dal giornale. Sola lettura.
 *
 * Le righe.
 * - **Le rate emesse alla data D**: per ogni (persona, unità, rata) con una scrittura di emissione legata alle sue quote
 *   (`emissione_rata`) con data ≤ D, il dovuto è il DARE e il versato l'AVERE delle righe su «Crediti verso condomini» con la
 *   persona, l'unità e la rata, di scritture non cancellate con data ≤ D, di tutti gli esercizi. Residuo = max(0, dovuto − versato);
 *   credito sulla rata = max(0, versato − dovuto). Lo storno sta alla data della sua rettifica.
 * - **Le righe su rate non ancora emesse alla data D** (un anticipo su una bozza, un rimborso o una compensazione su una quota non a
 *   giornale, una riga senza rata): un gruppo a parte, con il loro saldo; mai nel residuo né nello scaduto.
 * - **Gli anticipi**: le righe della persona su «Anticipi» (l'eccedenza senza una quota d'appoggio), con il loro saldo.
 * - **Il credito disponibile di oggi**: quando D è oggi, quello di {@see CreditoService} (lordo, per gestione: comprende il credito
 *   riportato della rata 0 in bozza). Per una data passata non c'è (null).
 * - Le righe su «Passate gestioni» **non entrano**: sono il rovescio di un credito riportato che il giornale non porta.
 *
 * Le misure, per persona e mai sommate fra persone: l'**emesso non pagato** (tutti i residui), i **crediti** sulle rate, lo
 * **scaduto** alla data D (il residuo delle rate con scadenza < D, {@see Scadenza}) con le fasce per giorni di ritardo; il netto
 * compare solo come ultima voce.
 *
 * **Il pregresso** (dalla 1.12.0-beta.4). Una riga è di pregresso quando le sue quote sono tutte quote di soli saldi (`tipo =
 * saldo_iniziale`: la rata 0, la gemella di chi esce in un passaggio). La rata 0 prende la scadenza della prima rata del piano, e un
 * debito del 2023 risultava «in ritardo di 5 giorni»: i giorni e la fascia seguono l'origine dichiarata sui saldi della quota (attraverso
 * `regole_calcolo.dettagli_saldo`, {@see OriginePregresso}). Con «dovuto con questo piano» i giorni sono quelli della rata; con un
 * esercizio, «prima del primo esercizio» o senza origine non c'è un numero (`giorni_ritardo` nullo), la fascia è «oltre 90 giorni» e il
 * ritardo più vecchio della persona non lo conta: `anzianita_non_nota` lo dice. Una riga ordinaria che porta anche una parte di pregresso
 * (i piani con il pregresso nella prima rata o in tutte) resta una rata, e lo dice `pregresso_nella_rata`.
 *
 * **La somma nel database.** Le righe si sommano nella query per (mastro, persona, unità, rata) quando la rata c'è, e per gestione solo
 * per le righe senza rata (un incasso con righe di due gestioni sulla stessa rata non divide la chiave); restano le chiavi a saldo
 * diverso da zero. Una chiave a zero non c'è più (con il suo dovuto e il suo versato), e una persona che ha solo chiavi a zero e nessun
 * credito di oggi non è fra le persone del condominio. Fino alla 1.12.0-beta.3 la lettura caricava in memoria tutte le righe di tutti gli
 * anni: con mille unità e dieci anni 335.210 righe.
 *
 * Che cosa la lettura non dice:
 * - il pregresso dei saldi non ancora in un piano, e quello della rata 0 non ancora emessa: non sono nel giornale e non hanno una
 *   data (la prova per persona li toglie dall'estratto conto, `LetturaDebitiTest`);
 * - il credito riportato fuori dal giornale, per una data passata (provato: il credito della rata 0 prima della sua emissione);
 * - la quota: il giornale ha persona, unità e rata, e due quote gemelle si sommano (non provato);
 * - un'emissione annullata e rifatta, che cancella la scrittura vecchia: una lettura a una data passata rifatta dopo può cambiare
 *   senza traccia (non provato qui; lo storico dell'annullamento è nel rapporto alla data della 1.12.0-beta.3);
 * - le rate di soli crediti che la 1.9 e la 1.10 hanno segnato «emesse» senza righe: il loro credito non c'è finché non si emettono
 *   (non provato);
 * - i prelievi dalla rata 0 in bozza degli esercizi chiusi che il riallineamento dei crediti lascia come sono (non provato).
 */
final class LetturaDebiti
{
    public function __construct(private readonly CreditoService $crediti) {}

    /**
     * Ogni riga di `rate` porta anche `pregresso` (null, o l'origine con l'esercizio) e `pregresso_nella_rata`; `giorni_ritardo` è nullo per
     * un pregresso scaduto senza un numero di giorni. I totali portano `anzianita_non_nota`.
     *
     * @return array{anagrafica_id:int, alla:string, rate:list<array<string,mixed>>, non_emesse:list<array<string,mixed>>, anticipi:int,
     *     totali:array<string,mixed>, credito_disponibile:array{totale:int, per_gestione:array<int,int>}|null}
     */
    public function perPersona(int $condominioId, int $anagraficaId, ?string $alla = null, ?int $gestioneId = null): array
    {
        $alla = Scadenza::giorno($alla ?? DateHelper::oggiUtente());

        return $this->leggi($condominioId, $alla, $gestioneId, [$anagraficaId])[$anagraficaId]
            ?? $this->vuota($anagraficaId, $alla, $this->creditoDi($condominioId, $alla, $gestioneId, [$anagraficaId])[$anagraficaId] ?? $this->nessunCredito($alla));
    }

    /** @return array<int, array<string,mixed>> per anagrafica: le persone con righe alla data o, oggi, con un credito disponibile. */
    public function perCondominio(int $condominioId, ?string $alla = null, ?int $gestioneId = null): array
    {
        return $this->leggi($condominioId, Scadenza::giorno($alla ?? DateHelper::oggiUtente()), $gestioneId, null);
    }

    /** @param list<int>|null $anagraficheIds */
    private function leggi(int $condominioId, string $alla, ?int $gestioneId, ?array $anagraficheIds): array
    {
        $dopo = CarbonImmutable::parse($alla, 'UTC')->addDay()->format('Y-m-d');

        // Le righe con la rata per (mastro, persona, unità, rata); quelle senza rata anche per gestione. Solo le chiavi a saldo diverso da zero.
        $righe = $this->righeSommate($condominioId, $dopo, $anagraficheIds, true)->concat($this->righeSommate($condominioId, $dopo, $anagraficheIds, false));

        $rataIds = $righe->pluck('rata_id')->filter()->unique()->values()->all();
        $rate = $rataIds === [] ? collect() : DB::table('rate')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')
            ->whereIn('rate.id', $rataIds)
            ->get(['rate.id', 'rate.numero_rata', 'rate.data_scadenza', 'rate.piano_rate_id', 'piani_rate.gestione_id', 'piani_rate.metodo_distribuzione'])
            ->keyBy('id');

        // Emessa entro D: la quota (rata, persona, unità) ha la sua scrittura di emissione con data ≤ D. Con il tipo di ogni quota: una chiave
        // fatta di sole quote di saldi è un pregresso.
        $emesse = [];
        $tipi = [];
        if ($rataIds !== []) {
            foreach (DB::table('rate_quote as q')
                ->leftJoin('scritture_contabili as em', 'em.id', '=', 'q.scrittura_contabile_id')
                ->whereIn('q.rata_id', $rataIds)
                ->get(['q.id', 'q.rata_id', 'q.anagrafica_id', 'q.immobile_id', 'q.tipo', 'em.deleted_at', 'em.tipo_movimento', 'em.data_competenza']) as $q) {
                $k = self::chiave($q->anagrafica_id, $q->immobile_id, $q->rata_id);
                $tipi[$k][(int) $q->id] = (string) $q->tipo;
                $tipo = $q->tipo_movimento instanceof \BackedEnum ? $q->tipo_movimento->value : $q->tipo_movimento;
                if ($q->data_competenza !== null && $q->deleted_at === null && $tipo === 'emissione_rata' && substr((string) $q->data_competenza, 0, 10) < $dopo) {
                    $emesse[$k] = true;
                }
            }
        }

        $gruppi = [];
        $anticipi = [];
        foreach ($righe as $r) {
            $rata = $r->rata_id !== null ? $rate->get($r->rata_id) : null;
            $gestione = $rata !== null ? (int) $rata->gestione_id : ($r->gestione_id !== null ? (int) $r->gestione_id : null);
            if ($gestioneId !== null && $gestione !== $gestioneId) {
                continue;
            }
            $persona = (int) $r->anagrafica_id;
            if ($r->ruolo === 'anticipi_condomini') {
                $anticipi[$persona] = ($anticipi[$persona] ?? 0) + (int) $r->dovuto - (int) $r->versato;

                continue;
            }
            $k = self::chiave($r->anagrafica_id, $r->immobile_id, $r->rata_id);
            // Le righe senza rata di due gestioni restano due gruppi: la chiave porta anche la gestione.
            $kg = $r->rata_id === null ? $k . '|' . ($gestione ?? '') : $k;
            $gruppi[$persona][$kg] ??= ['chiave' => $k, 'rata' => $rata, 'rata_id' => $r->rata_id === null ? null : (int) $r->rata_id,
                'immobile_id' => $r->immobile_id === null ? null : (int) $r->immobile_id, 'gestione_id' => $gestione, 'dovuto' => 0, 'versato' => 0];
            $gruppi[$persona][$kg]['dovuto'] += (int) $r->dovuto;
            $gruppi[$persona][$kg]['versato'] += (int) $r->versato;
        }

        $pregressi = $this->pregressi($gruppi, $emesse, $tipi);

        $persone = array_unique([...array_keys($gruppi), ...array_keys($anticipi)]);
        $crediti = $this->creditoDi($condominioId, $alla, $gestioneId, $anagraficheIds);
        if ($alla === DateHelper::oggiUtente()) {
            $persone = array_unique([...$persone, ...array_keys(array_filter($crediti, fn ($c) => $c['totale'] > 0))]);
        }

        $esito = [];
        foreach ($persone as $persona) {
            $l = $this->vuota($persona, $alla, $crediti[$persona] ?? $this->nessunCredito($alla));
            foreach ($gruppi[$persona] ?? [] as $g) {
                $k = $g['chiave'];
                if ($g['rata'] !== null && isset($emesse[$k])) {
                    $scadenza = Scadenza::giorno($g['rata']->data_scadenza);
                    $residuo = max(0, $g['dovuto'] - $g['versato']);
                    $scaduta = Scadenza::scaduta($scadenza, $alla);
                    $pregresso = $pregressi['origini'][$k] ?? null;
                    // Un pregresso senza un numero di giorni (un esercizio, prima del primo, non dichiarata): la lettura non dice un'età.
                    $giorni = $pregresso !== null && ! OriginePregresso::haGiorni($pregresso['origine']) && $scaduta
                        ? null
                        : Scadenza::giorniDiRitardo($scadenza, $alla);
                    $l['rate'][] = [
                        'rata_id' => $g['rata_id'], 'immobile_id' => $g['immobile_id'], 'numero_rata' => (int) $g['rata']->numero_rata,
                        'piano_rate_id' => (int) $g['rata']->piano_rate_id, 'gestione_id' => $g['gestione_id'], 'scadenza' => $scadenza,
                        'dovuto' => $g['dovuto'], 'versato' => $g['versato'], 'residuo' => $residuo, 'credito' => max(0, $g['versato'] - $g['dovuto']),
                        'scaduta' => $scaduta, 'giorni_ritardo' => $giorni,
                        'pregresso' => $pregresso, 'pregresso_nella_rata' => isset($pregressi['nella_rata'][$k]),
                    ];
                } else {
                    $l['non_emesse'][] = [
                        'rata_id' => $g['rata_id'], 'immobile_id' => $g['immobile_id'], 'numero_rata' => $g['rata'] !== null ? (int) $g['rata']->numero_rata : null,
                        'gestione_id' => $g['gestione_id'], 'dovuto' => $g['dovuto'], 'versato' => $g['versato'], 'saldo' => $g['dovuto'] - $g['versato'],
                    ];
                }
            }
            usort($l['rate'], fn ($a, $b) => [$a['scadenza'], $a['numero_rata'], $a['immobile_id'] ?? 0] <=> [$b['scadenza'], $b['numero_rata'], $b['immobile_id'] ?? 0]);
            $l['anticipi'] = $anticipi[$persona] ?? 0;
            $l['totali'] = $this->totali($l);
            $esito[$persona] = $l;
        }

        return $esito;
    }

    /**
     * Le righe del giornale sui due mastri della persona, sommate nel database: con la rata, per (mastro, persona, unità, rata); senza,
     * anche per gestione. Solo le chiavi a saldo diverso da zero.
     *
     * @param list<int>|null $anagraficheIds
     */
    private function righeSommate(int $condominioId, string $dopo, ?array $anagraficheIds, bool $conRata): \Illuminate\Support\Collection
    {
        // «Minore del giorno dopo», come lo stato patrimoniale: su SQLite le DATE sono testo, con o senza l'ora.
        $colonne = $conRata ? ['cc.ruolo', 'rs.anagrafica_id', 'rs.immobile_id', 'rs.rata_id'] : ['cc.ruolo', 'rs.anagrafica_id', 'rs.immobile_id', 'sc.gestione_id'];

        return DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->whereNull('sc.deleted_at')
            ->where('sc.condominio_id', $condominioId)
            ->where('sc.data_competenza', '<', $dopo)
            ->whereIn('cc.ruolo', ['crediti_condomini', 'anticipi_condomini'])
            ->whereNotNull('rs.anagrafica_id')
            ->when($conRata, fn ($q) => $q->whereNotNull('rs.rata_id'), fn ($q) => $q->whereNull('rs.rata_id'))
            ->when($anagraficheIds !== null, fn ($q) => $q->whereIn('rs.anagrafica_id', $anagraficheIds))
            ->groupBy($colonne)
            ->havingRaw("SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END) <> SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END)")
            ->select($colonne)
            ->selectRaw("SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE 0 END) as dovuto")
            ->selectRaw("SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE 0 END) as versato")
            ->get()
            ->map(function ($r) use ($conRata) {
                $r->rata_id = $conRata ? $r->rata_id : null;
                $r->gestione_id = $conRata ? null : $r->gestione_id;

                return $r;
            });
    }

    /**
     * Le chiavi emesse di pregresso (tutte le loro quote sono quote di saldi) con la loro origine, letta dai saldi della quota; e le chiavi
     * ordinarie che portano anche una parte di pregresso (la rata dei piani con il pregresso nella prima rata o in tutte). Si leggono solo
     * le chiavi con un residuo: il pregresso a credito non ha un'età da dire.
     *
     * @param array<int, array<string, array<string,mixed>>> $gruppi
     * @param array<string, true> $emesse
     * @param array<string, array<int,string>> $tipi
     * @return array{origini: array<string, array{origine:string, esercizio:?array{id:int, nome:string, data_inizio:?string, data_fine:?string}}>, nella_rata: array<string, true>}
     */
    private function pregressi(array $gruppi, array $emesse, array $tipi): array
    {
        $diSaldi = [];
        $miste = [];
        foreach ($gruppi as $chiavi) {
            foreach ($chiavi as $g) {
                $k = $g['chiave'];
                if ($g['rata'] === null || ! isset($emesse[$k]) || $g['dovuto'] - $g['versato'] <= 0 || ($tipi[$k] ?? []) === []) {
                    continue;
                }
                if (count(array_filter($tipi[$k], fn ($t) => $t !== 'saldo_iniziale')) === 0) {
                    $diSaldi[$k] = array_keys($tipi[$k]);
                } elseif (in_array($g['rata']->metodo_distribuzione, ['prima_rata', 'tutte_rate'], true)) {
                    $miste[$k] = array_keys($tipi[$k]);
                }
            }
        }
        if ($diSaldi === [] && $miste === []) {
            return ['origini' => [], 'nella_rata' => []];
        }

        $regole = DB::table('rate_quote')->whereIn('id', array_merge([], ...array_values($diSaldi), ...array_values($miste)))->pluck('regole_calcolo', 'id')
            ->map(fn ($r) => is_string($r) ? (json_decode($r, true) ?: []) : (array) $r);

        $nellaRata = [];
        foreach ($miste as $k => $ids) {
            foreach ($ids as $id) {
                if ((int) ($regole[$id]['importi']['saldo_usato'] ?? 0) !== 0) {
                    $nellaRata[$k] = true;
                }
            }
        }

        $saldiDi = [];
        foreach ($diSaldi as $k => $ids) {
            $saldiDi[$k] = [];
            foreach ($ids as $id) {
                $dettagli = $regole[$id]['dettagli_saldo'] ?? [];
                $dettagli = isset($dettagli['saldo_origine_id']) ? [$dettagli] : (array) $dettagli;
                foreach ($dettagli as $d) {
                    // L'origine si legge dai saldi a debito della quota: un saldo a credito (dell'unità, ripartito sulla persona) non ha
                    // un'età che faccia sottostimare il ritardo, e la pagina dei saldi non lo fa dichiarare.
                    if (is_array($d) && isset($d['saldo_origine_id']) && (! isset($d['importo_originale']) || (int) $d['importo_originale'] > 0)) {
                        $saldiDi[$k][] = (int) $d['saldo_origine_id'];
                    }
                }
            }
        }
        $tuttiSaldi = array_values(array_unique(array_merge([], ...array_values($saldiDi))));
        $saldi = $tuttiSaldi === [] ? collect() : DB::table('saldi as s')->leftJoin('esercizi as e', 'e.id', '=', 's.esercizio_origine_id')
            ->whereIn('s.id', $tuttiSaldi)
            ->get(['s.id', 's.origine_dichiarata', 'e.id as esercizio_id', 'e.nome as esercizio_nome', 'e.data_inizio', 'e.data_fine'])
            ->keyBy('id');

        $origini = [];
        foreach ($saldiDi as $k => $ids) {
            $voci = [];
            foreach (array_unique($ids) as $id) {
                $s = $saldi->get($id);
                $voci[] = $s === null ? ['origine' => null, 'esercizio' => null] : ['origine' => $s->origine_dichiarata, 'esercizio' => $s->esercizio_id === null ? null : [
                    'id' => (int) $s->esercizio_id, 'nome' => (string) $s->esercizio_nome,
                    'data_inizio' => $s->data_inizio === null ? null : Scadenza::giorno($s->data_inizio), 'data_fine' => $s->data_fine === null ? null : Scadenza::giorno($s->data_fine),
                ]];
            }
            $origini[$k] = OriginePregresso::piuVecchia($voci);
        }

        return ['origini' => $origini, 'nella_rata' => $nellaRata];
    }

    /** @return array<string,mixed> */
    private function totali(array $l): array
    {
        $fasce = Scadenza::FASCE;
        $scaduto = 0;
        $ritardo = 0;
        $nonNota = false;
        foreach ($l['rate'] as $r) {
            if ($r['residuo'] > 0 && $r['scaduta']) {
                $scaduto += $r['residuo'];
                if ($r['giorni_ritardo'] === null) {
                    // Il pregresso senza un numero di giorni: «oltre 90 giorni», la cifra che non sottostima; il ritardo più vecchio non lo conta.
                    $fasce['oltre_90'] += $r['residuo'];
                    $nonNota = true;

                    continue;
                }
                $fasce[Scadenza::fascia($r['giorni_ritardo'])] += $r['residuo'];
                $ritardo = max($ritardo, $r['giorni_ritardo']);
            }
        }
        $emesso = (int) array_sum(array_column($l['rate'], 'residuo'));
        $crediti = (int) array_sum(array_column($l['rate'], 'credito'));
        $nonEmesse = (int) array_sum(array_column($l['non_emesse'], 'saldo'));

        return [
            'emesso_non_pagato' => $emesso,
            'crediti' => $crediti,
            'scaduto' => $scaduto,
            'fasce' => $fasce,
            'ritardo_massimo' => $ritardo,
            'anzianita_non_nota' => $nonNota,
            'non_emesse' => $nonEmesse,
            'anticipi' => $l['anticipi'],
            'netto' => $emesso - $crediti + $nonEmesse + $l['anticipi'],
        ];
    }

    private function vuota(int $anagraficaId, string $alla, ?array $credito): array
    {
        $l = ['anagrafica_id' => $anagraficaId, 'alla' => $alla, 'rate' => [], 'non_emesse' => [], 'anticipi' => 0, 'credito_disponibile' => $credito];
        $l['totali'] = $this->totali($l);

        return $l;
    }

    /**
     * Il credito disponibile di oggi, per persona; vuoto per una data passata.
     *
     * @return array<int, array{totale:int, per_gestione:array<int,int>}>
     */
    private function creditoDi(int $condominioId, string $alla, ?int $gestioneId, ?array $anagraficheIds): array
    {
        if ($alla !== DateHelper::oggiUtente()) {
            return [];
        }
        $esito = [];
        foreach ($this->crediti->disponibilePerGestione($condominioId, $anagraficheIds) as $persona => $perGestione) {
            $perGestione = array_filter($perGestione, fn ($c, $g) => $c > 0 && ($gestioneId === null || $g === $gestioneId), ARRAY_FILTER_USE_BOTH);
            $esito[$persona] = ['totale' => (int) array_sum($perGestione), 'per_gestione' => $perGestione];
        }

        return $esito;
    }

    /** Oggi, una persona senza quote a credito ha un credito disponibile di zero; per una data passata il dato non c'è. */
    private function nessunCredito(string $alla): ?array
    {
        return $alla === DateHelper::oggiUtente() ? ['totale' => 0, 'per_gestione' => []] : null;
    }

    private static function chiave(mixed $anagraficaId, mixed $immobileId, mixed $rataId): string
    {
        return ((int) $anagraficaId) . '|' . ($immobileId === null ? '' : (int) $immobileId) . '|' . ($rataId === null ? '' : (int) $rataId);
    }
}
