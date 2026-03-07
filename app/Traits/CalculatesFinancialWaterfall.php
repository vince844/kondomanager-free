<?php

namespace App\Traits;

use App\Models\Gestionale\RataQuote;

trait CalculatesFinancialWaterfall
{
    /**
     * Calcola gli arretrati e lo stato dei pagamenti storici.
     * Versione: 1.9.5 (Solo Lettura, NESSUNA alterazione del totale fattura)
     */
    public function applyFinancialWaterfall($events, $anagraficaId, $condominioIds = null, $pianoRateIds = null)
    {
        $condominioIds = is_numeric($condominioIds) ? [$condominioIds] : $condominioIds;
        $pianoRateIds = is_numeric($pianoRateIds) ? [$pianoRateIds] : $pianoRateIds;

        $tutteLeQuote = RataQuote::where('anagrafica_id', $anagraficaId)
            ->whereHas('rata.pianoRate', function ($q) use ($condominioIds, $pianoRateIds) {
                if (!empty($condominioIds)) {
                    $q->whereIn('condominio_id', (array)$condominioIds);
                }
                if (!empty($pianoRateIds)) {
                    $q->whereIn('id', (array)$pianoRateIds);
                }
            })
            ->with(['rata.pianoRate' => function($q) {
                $q->select('id', 'condominio_id'); 
            }])
            ->get();

        if ($tutteLeQuote->isEmpty()) {
            return $events;
        }

        $quotesByCondominio = $tutteLeQuote->groupBy(function ($quota) {
            return $quota->rata->pianoRate->condominio_id;
        });

        $globalRataStatus = [];

        foreach ($quotesByCondominio as $condominioId => $quotesDelCondominio) {
            
            $rateAggregate = $quotesDelCondominio->groupBy('rata_id')->map(function ($quotes) {
                $rata = $quotes->first()->rata;
                
                // Nella V1.9, il totale ufficiale è quello scritto nel DB (non facciamo più reverse engineering)
                $importoTotale = (int)$quotes->sum('importo');
                $importoPagato = (int)$quotes->sum('importo_pagato');

                return [
                    'rata_id'       => $rata->id,
                    'numero_rata'   => $rata->numero_rata,
                    'scadenza'      => $rata->data_scadenza,
                    'importo_netto' => $importoTotale, 
                    'pagato'        => $importoPagato, 
                    'insoluto_rata' => max(0, $importoTotale - $importoPagato), // Quanto NON è stato pagato di QUESTA rata
                ];
            })->sortBy('scadenza');

            $accumuloDebiti = 0;
            $accumuloCrediti = 0;
            $listaInsoluti = []; 

            foreach ($rateAggregate as $rata) {
                $id = $rata['rata_id'];
                
                // 1. FOTOGRAFIA DEL PASSATO: Cosa è successo PRIMA di questa rata?
                $snapshotDebiti = $accumuloDebiti;
                $snapshotCrediti = $accumuloCrediti;
                // FIX: Dobbiamo fotografare anche la lista dei nomi PRIMA di aggiornarla!
                $snapshotListaInsoluti = implode(', ', $listaInsoluti); 

                // 2. AGGIORNAMENTO DEL FUTURO: Cosa succede DOPO questa rata?
                if ($rata['importo_netto'] < 0) {
                    // Questa rata era a credito (es. Rata 0 negativa)
                    // Aggiungiamo al salvadanaio quello che NON è stato ancora rimborsato/usato
                    $creditoResiduo = abs($rata['importo_netto']) - $rata['pagato'];
                    if ($creditoResiduo > 0) {
                        $accumuloCrediti += $creditoResiduo;
                    }
                } elseif ($rata['insoluto_rata'] > 0) {
                    // Questa rata non è stata saldata completamente
                    $accumuloDebiti += $rata['insoluto_rata'];
                    // Aggiungiamo la rata alla lista PER IL FUTURO
                    $listaInsoluti[] = '#' . $rata['numero_rata']; 
                }

                // Se ho sia debiti che crediti vecchi, li compenso virtualmente solo per capire il VERO saldo finale
                $veroDebitoPregresso = max(0, $snapshotDebiti - $snapshotCrediti);
                $veroCreditoPregresso = max(0, $snapshotCrediti - $snapshotDebiti);

                // 3. SALVATAGGIO STATO
                $globalRataStatus[$id] = [
                    'arretrati_pregressi'   => $veroDebitoPregresso, 
                    'crediti_pregressi'     => $veroCreditoPregresso,
                    // FIX: Usiamo la fotografia presa all'inizio del loop!
                    'lista_rate_precedenti' => $snapshotListaInsoluti, 
                ];
            }
        }

        // Iniezione sicura nell'evento
        $collection = $events instanceof \Illuminate\Pagination\AbstractPaginator ? $events->getCollection() : $events;

        $processed = $collection->map(function ($event) use ($globalRataStatus) {
            $meta = $event->meta;
            if (is_string($meta)) $meta = json_decode($meta, true);
            if (!is_array($meta)) $meta = [];

            $rataId = $meta['context']['rata_id'] ?? ($meta['rata_id'] ?? null);

            if ($rataId && isset($globalRataStatus[$rataId])) {
                $status = $globalRataStatus[$rataId];
                
                // ⚠️ Aggiungiamo metadati informativi, SENZA toccare l'importo originale/restante!
                $meta['storico_arretrati'] = $status['arretrati_pregressi'];
                $meta['storico_crediti']   = $status['crediti_pregressi']; 
                $meta['storico_rate_rif']  = $status['lista_rate_precedenti']; 
                
                $event->setAttribute('meta', $meta);
            }
            return $event;
        });

        if ($events instanceof \Illuminate\Pagination\AbstractPaginator) {
            $events->setCollection($processed);
            return $events;
        }

        return $processed;
    }
}