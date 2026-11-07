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

        // «Minore del giorno dopo», come lo stato patrimoniale: su SQLite le DATE sono testo, con o senza l'ora.
        $righe = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->whereNull('sc.deleted_at')
            ->where('sc.condominio_id', $condominioId)
            ->where('sc.data_competenza', '<', $dopo)
            ->whereIn('cc.ruolo', ['crediti_condomini', 'anticipi_condomini'])
            ->whereNotNull('rs.anagrafica_id')
            ->when($anagraficheIds !== null, fn ($q) => $q->whereIn('rs.anagrafica_id', $anagraficheIds))
            ->get(['cc.ruolo', 'rs.anagrafica_id', 'rs.immobile_id', 'rs.rata_id', 'rs.tipo_riga', 'rs.importo', 'sc.gestione_id']);

        $rataIds = $righe->pluck('rata_id')->filter()->unique()->values()->all();
        $rate = $rataIds === [] ? collect() : DB::table('rate')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')
            ->whereIn('rate.id', $rataIds)
            ->get(['rate.id', 'rate.numero_rata', 'rate.data_scadenza', 'rate.piano_rate_id', 'piani_rate.gestione_id'])
            ->keyBy('id');

        // Emessa entro D: la quota (rata, persona, unità) ha la sua scrittura di emissione con data ≤ D.
        $emesse = $rataIds === [] ? [] : DB::table('rate_quote as q')
            ->join('scritture_contabili as em', 'em.id', '=', 'q.scrittura_contabile_id')
            ->whereIn('q.rata_id', $rataIds)
            ->whereNull('em.deleted_at')
            ->where('em.tipo_movimento', 'emissione_rata')
            ->where('em.data_competenza', '<', $dopo)
            ->get(['q.rata_id', 'q.anagrafica_id', 'q.immobile_id'])
            ->mapWithKeys(fn ($q) => [self::chiave($q->anagrafica_id, $q->immobile_id, $q->rata_id) => true])
            ->all();

        $gruppi = [];
        $anticipi = [];
        foreach ($righe as $r) {
            $rata = $r->rata_id !== null ? $rate->get($r->rata_id) : null;
            $gestione = $rata !== null ? (int) $rata->gestione_id : ($r->gestione_id !== null ? (int) $r->gestione_id : null);
            if ($gestioneId !== null && $gestione !== $gestioneId) {
                continue;
            }
            $segno = $r->tipo_riga === 'dare' ? 1 : -1;
            $persona = (int) $r->anagrafica_id;
            if ($r->ruolo === 'anticipi_condomini') {
                $anticipi[$persona] = ($anticipi[$persona] ?? 0) + $segno * (int) $r->importo;

                continue;
            }
            $k = self::chiave($r->anagrafica_id, $r->immobile_id, $r->rata_id);
            $gruppi[$persona][$k] ??= ['rata' => $rata, 'rata_id' => $r->rata_id === null ? null : (int) $r->rata_id,
                'immobile_id' => $r->immobile_id === null ? null : (int) $r->immobile_id, 'gestione_id' => $gestione, 'dovuto' => 0, 'versato' => 0];
            $gruppi[$persona][$k][$segno > 0 ? 'dovuto' : 'versato'] += (int) $r->importo;
        }

        $persone = array_unique([...array_keys($gruppi), ...array_keys($anticipi)]);
        $crediti = $this->creditoDi($condominioId, $alla, $gestioneId, $anagraficheIds);
        if ($alla === DateHelper::oggiUtente()) {
            $persone = array_unique([...$persone, ...array_keys(array_filter($crediti, fn ($c) => $c['totale'] > 0))]);
        }

        $esito = [];
        foreach ($persone as $persona) {
            $l = $this->vuota($persona, $alla, $crediti[$persona] ?? $this->nessunCredito($alla));
            foreach ($gruppi[$persona] ?? [] as $k => $g) {
                if ($g['rata'] !== null && isset($emesse[$k])) {
                    $scadenza = Scadenza::giorno($g['rata']->data_scadenza);
                    $residuo = max(0, $g['dovuto'] - $g['versato']);
                    $l['rate'][] = [
                        'rata_id' => $g['rata_id'], 'immobile_id' => $g['immobile_id'], 'numero_rata' => (int) $g['rata']->numero_rata,
                        'piano_rate_id' => (int) $g['rata']->piano_rate_id, 'gestione_id' => $g['gestione_id'], 'scadenza' => $scadenza,
                        'dovuto' => $g['dovuto'], 'versato' => $g['versato'], 'residuo' => $residuo, 'credito' => max(0, $g['versato'] - $g['dovuto']),
                        'scaduta' => Scadenza::scaduta($scadenza, $alla), 'giorni_ritardo' => Scadenza::giorniDiRitardo($scadenza, $alla),
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

    /** @return array<string,mixed> */
    private function totali(array $l): array
    {
        $fasce = Scadenza::FASCE;
        $scaduto = 0;
        $ritardo = 0;
        foreach ($l['rate'] as $r) {
            if ($r['residuo'] > 0 && $r['scaduta']) {
                $scaduto += $r['residuo'];
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
