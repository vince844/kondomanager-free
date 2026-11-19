<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Ricevute;

use App\Enums\TipoMovimentoContabile;
use App\Models\Anagrafica;
use App\Services\Gestionale\Accessori\VersamentoDegliEredi;
use Illuminate\Support\Facades\DB;

/**
 * Per ogni incasso: la persona della posizione (per conto di chi) e chi ha versato, se è un'altra persona (decisione 30). Una regola sola per
 * la ricevuta, l'elenco delle ricevute e l'invio, letta con poche query anche su migliaia di incassi.
 *
 * - **Chi ha versato**: il `riferimento` delle righe dell'incasso che chiudono il debito.
 * - **La posizione**: la persona delle quote a debito su cui l'incasso ha messo denaro, fuori da chi ha versato; se l'incasso non ne ha (una
 *   compensazione a cassa zero), quella delle quote coperte dalle sue compensazioni; poi chi ha versato; poi la persona delle righe in AVERE.
 *   Fra più persone (incassi di versioni vecchie) la prima per identificativo.
 * - **Più persone**: se le quote su cui l'incasso ha messo denaro sono di più persone, fuori da chi ha versato, la ricevuta non può dire per
 *   conto di chi: l'incasso non ha una posizione sola e `piu_persone` le elenca (decisione 204). Con «Versato da» le quote di chi ha versato
 *   sono la sua parte in più, non una seconda posizione.
 * - **Il versamento di un erede sul debito ereditato** (decisione 539): a giornale sta sulla rata del defunto con il legame all'erede (decisione 529), e
 *   la regola di sopra direbbe «il defunto, versato dall'erede». Di serie la ricevuta è intestata all'erede che ha pagato: la posizione è lui, senza
 *   «Versato da» e senza più persone. Con la scelta «Eredi di …» o «il defunto» resta la regola di sopra (la ricevuta scrive «Eredi di …» al posto del
 *   nome). Chi ha pagato davvero si legge come nel dettaglio dell'incasso ({@see VersamentoDegliEredi::deiVersamenti()}), e `eredita` lo porta.
 */
class PersoneDegliIncassi
{
    /**
     * @param array<int, int> $ids gli incassi
     * @return array<int, array{posizione: ?int, versato_da: ?int, piu_persone: array<int, int>, eredita: ?array<string, mixed>}> `eredita`: la lettura
     *         del versamento di un erede ({@see VersamentoDegliEredi::deiVersamenti()}), null per gli altri incassi
     */
    public function di(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $esito = array_fill_keys($ids, ['posizione' => null, 'versato_da' => null, 'piu_persone' => [], 'eredita' => null]);

        foreach (array_chunk($ids, 500) as $blocco) {
            $versati = DB::table('righe_scritture')
                ->whereIn('scrittura_id', $blocco)
                ->where('riferimento_type', Anagrafica::class)
                ->whereNotNull('riferimento_id')
                ->groupBy('scrittura_id')
                ->selectRaw('scrittura_id, MIN(riferimento_id) as persona')
                ->pluck('persona', 'scrittura_id');

            $compensazione = TipoMovimentoContabile::STORNO_CREDITO->value;
            $pivot = DB::table('quota_scrittura')
                ->join('rate_quote as q', 'q.id', '=', 'quota_scrittura.rate_quota_id')
                ->join('scritture_contabili as s', 's.id', '=', 'quota_scrittura.scrittura_contabile_id')
                ->where(fn ($w) => $w->whereIn('s.id', $blocco)
                    ->orWhere(fn ($f) => $f->whereIn('s.scrittura_padre_id', $blocco)->where('s.tipo_movimento', $compensazione)))
                ->where('q.importo', '>', 0)
                ->where('quota_scrittura.importo_pagato', '>', 0)
                ->whereNotNull('q.anagrafica_id')
                ->get(['s.id as scrittura', 's.scrittura_padre_id as padre', 's.tipo_movimento as tipo', 'q.anagrafica_id as persona']);

            $avere = DB::table('righe_scritture')
                ->whereIn('scrittura_id', $blocco)
                ->where('tipo_riga', 'avere')
                ->whereNotNull('anagrafica_id')
                ->groupBy('scrittura_id')
                ->selectRaw('scrittura_id, MIN(anagrafica_id) as persona')
                ->pluck('persona', 'scrittura_id');

            $proprie = [];
            $dalCredito = [];
            foreach ($pivot as $r) {
                if ($r->tipo === $compensazione && $r->padre) {
                    $dalCredito[(int) $r->padre][] = (int) $r->persona;
                } else {
                    $proprie[(int) $r->scrittura][] = (int) $r->persona;
                }
            }

            foreach ($blocco as $id) {
                $versatoDa = isset($versati[$id]) ? (int) $versati[$id] : null;
                $fuori = fn (array $persone) => array_values(array_filter($persone, fn ($p) => $p !== $versatoDa));
                $candidati = $fuori($proprie[$id] ?? []) ?: $fuori($dalCredito[$id] ?? []) ?: ($proprie[$id] ?? []);
                $posizione = $candidati !== [] ? min($candidati) : (isset($avere[$id]) ? (int) $avere[$id] : $versatoDa);

                $proprieFuori = array_values(array_unique($fuori($proprie[$id] ?? [])));
                sort($proprieFuori);

                $esito[$id] = [
                    'posizione'   => $posizione,
                    'versato_da'  => $versatoDa !== null && $versatoDa !== $posizione ? $versatoDa : null,
                    'piu_persone' => count($proprieFuori) > 1 ? $proprieFuori : [],
                    'eredita'     => null,
                ];
            }
        }

        // Decisione 539: il versamento di un erede sul debito ereditato, con la ricevuta all'erede (di serie): la posizione è lui.
        foreach (app(VersamentoDegliEredi::class)->deiVersamenti($ids) as $id => $lettura) {
            if (! isset($esito[$id]) || $lettura['erede'] === null) {
                continue;
            }
            $esito[$id]['eredita'] = $lettura;
            if ($lettura['intestatario'] === VersamentoDegliEredi::RICEVUTA_ALL_EREDE) {
                $esito[$id] = ['posizione' => (int) $lettura['erede'], 'versato_da' => null, 'piu_persone' => [], 'eredita' => $lettura];
            }
        }

        return $esito;
    }
}
