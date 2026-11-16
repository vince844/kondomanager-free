<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Inbox;

use App\Models\Condominio;
use App\Models\Evento;

/**
 * La lettura aggregata dell'Action inbox: una query sola sui compiti aperti ({@see Evento::scopeCompitiAperti()}), del condominio scelto o di tutti, contati
 * per fascia di giorni ({@see FasceDeiCompiti}), per tipo e per condominio. Da lì vengono le cinque card, i due grafici e «Dove si accumulano»; i numeri
 * delle schede e dei gruppi dell'elenco, che seguono anche la ricerca, li conta {@see ElencoDellInbox} con la stessa regola. Nessun conteggio legge la pagina
 * caricata.
 *
 * Un compito con più condomini conta una volta, nel condominio con l'id più piccolo (il primo, come la riga dell'elenco); con un condominio scelto, in quello.
 * I compiti senza condominio sono «Generale». I compiti delle segnalazioni sono solo quelli che chi legge può aprire ({@see CompitoDellaSegnalazione::chiVede()}):
 * con «Visualizza segnalazioni» tutti, con «Visualizza proprie segnalazioni» quelli delle segnalazioni che ha scritto.
 *
 * Il peso (misurato il 15/11/2026, Apple M1 Pro, SQLite in memoria): 3.000 compiti aperti in 40 condomini in pochi millesimi di secondo e meno di un MB; le
 * righe lette sono le combinazioni di fascia, tipo e condominio, non i compiti.
 */
final class RiepilogoDellInbox
{
    /** I condomini di «Dove si accumulano». */
    public const DOVE = 5;

    /** Il nome dei compiti senza condominio. */
    public const GENERALE = 'Generale';

    /**
     * @param  bool|int  $segnalazioni  le segnalazioni che chi legge vede ({@see CompitoDellaSegnalazione::chiVede()})
     * @return array{counts: array<string, int|string|null>, grafici: array<string, mixed>, dove: list<array{condominio_id: ?int, nome: string, valore: int}>}
     */
    public function leggi(?int $condominioId, bool|int $segnalazioni, ?FasceDeiCompiti $fasce = null): array
    {
        $fasce ??= FasceDeiCompiti::oggi();
        $tipoSql = FasceDeiCompiti::sqlTipo();
        [$fasciaSql, $valori] = $fasce->sqlFascia($tipoSql);
        $condominioSql = $condominioId !== null
            ? (string) (int) $condominioId
            : '(SELECT MIN(ce.condominio_id) FROM condominio_evento ce WHERE ce.evento_id = eventi.id)';

        // Gli alias non hanno il nome di una colonna di `eventi`: nel GROUP BY MySQL cerca prima le colonne.
        $righe = Evento::query()
            ->compitiAperti()
            ->when($condominioId !== null, fn ($q) => $q->whereHas('condomini', fn ($c) => $c->where('condomini.id', $condominioId)))
            ->where(fn ($q) => CompitoDellaSegnalazione::soloQuelliCheVede($q, $segnalazioni))
            ->toBase()
            ->selectRaw("{$fasciaSql} as fascia_compito, {$tipoSql} as tipo_compito, {$condominioSql} as condominio_compito, COUNT(*) as quanti, MIN(eventi.start_time) as primo, "
                . 'SUM(CASE WHEN ' . FasceDeiCompiti::sqlPriorita() . " = 'urgente' THEN 1 ELSE 0 END) as urgenti", $valori)
            ->groupByRaw('fascia_compito, tipo_compito, condominio_compito')
            ->get()
            ->map(fn ($r) => ['fascia' => (string) $r->fascia_compito, 'tipo' => $r->tipo_compito === null ? null : (string) $r->tipo_compito,
                'condominio_id' => $r->condominio_compito === null ? null : (int) $r->condominio_compito, 'quanti' => (int) $r->quanti, 'primo' => (string) $r->primo,
                'urgenti' => (int) $r->urgenti])
            ->all();

        $counts = ['all' => 0, 'scaduti' => 0, 'settimana' => 0, 'entro_30' => 0, 'bonifici' => 0, 'segnalazioni' => 0, 'segnalazioni_urgenti' => 0, 'piu_vecchio' => null];
        $eta = array_fill_keys(FasceDeiCompiti::DEL_GRAFICO, 0);
        $fuori = ['segnalazioni' => 0, 'bonifici' => 0, 'oltre_30' => 0];
        $tipi = [];
        $perCondominio = [];
        foreach ($righe as $r) {
            $n = $r['quanti'];
            $counts['all'] += $n;
            if (in_array($r['fascia'], FasceDeiCompiti::SCADUTE, true)) {
                $counts['scaduti'] += $n;
                $giorno = substr($r['primo'], 0, 10);
                $counts['piu_vecchio'] = $counts['piu_vecchio'] === null ? $giorno : min($counts['piu_vecchio'], $giorno);
            }
            $counts['settimana'] += $r['fascia'] === 'prossimi_7' ? $n : 0;
            $counts['entro_30'] += $r['fascia'] === 'fra_8_e_30' ? $n : 0;
            $counts['bonifici'] += $r['fascia'] === FasceDeiCompiti::BONIFICI ? $n : 0;
            $counts['segnalazioni'] += $r['tipo'] === FasceDeiCompiti::SEGNALAZIONE ? $n : 0;
            $counts['segnalazioni_urgenti'] += $r['tipo'] === FasceDeiCompiti::SEGNALAZIONE ? $r['urgenti'] : 0;
            if (isset($eta[$r['fascia']])) {
                $eta[$r['fascia']] += $n;
            } elseif (isset($fuori[$r['fascia']])) {
                $fuori[$r['fascia']] += $n;
            }
            $chiave = TipiDeiCompiti::canonico($r['tipo']) ?? '';
            $tipi[$chiave] = ($tipi[$chiave] ?? 0) + $n;
            $c = $r['condominio_id'] ?? 0;
            $perCondominio[$c] = ($perCondominio[$c] ?? 0) + $n;
        }

        $tipi = collect($tipi)->map(fn ($n, $t) => ['tipo' => (string) $t, 'etichetta' => TipiDeiCompiti::nome($t === '' ? null : (string) $t), 'valore' => $n])
            ->sortBy([['valore', 'desc'], ['etichetta', 'asc']])->values()->all();

        $nomi = Condominio::whereIn('id', array_filter(array_keys($perCondominio)))->pluck('nome', 'id');
        $dove = collect($perCondominio)
            ->map(fn ($n, $id) => ['condominio_id' => $id === 0 ? null : (int) $id, 'nome' => $id === 0 ? self::GENERALE : (string) ($nomi[$id] ?? self::GENERALE), 'valore' => $n])
            ->sort(fn ($a, $b) => [$b['valore'], $a['nome']] <=> [$a['valore'], $b['nome']])
            ->take(self::DOVE)->values()->all();

        return [
            'counts' => $counts,
            'grafici' => [
                'eta' => array_map(fn ($f) => ['chiave' => $f, 'etichetta' => FasceDeiCompiti::ETICHETTE[$f], 'valore' => $eta[$f],
                    'scaduti' => in_array($f, FasceDeiCompiti::SCADUTE, true)], FasceDeiCompiti::DEL_GRAFICO),
                'tipi' => $tipi,
                'fuori' => $fuori,
            ],
            'dove' => $dove,
        ];
    }
}
