<?php

namespace App\Services;

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Models\Tabella;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Costruisce la struttura dati per la stampa "Riparto Bilancio per Tabella × Soggetto".
 *
 * OUTPUT buildMatrice():
 * [
 *   'tabelle'   => [ tabella_id => ['nome' => ..., 'quota_label' => ..., 'tot_quota' => float, 'tot_importo' => int (cents)] ],
 *   'righe'     => [
 *     immobile_id => [
 *       'interno'  => string,
 *       'piano'    => string,
 *       'soggetti' => [
 *         anagrafica_id => [
 *           'nome'       => string,
 *           'ruolo'      => string,  // P / I / U / C
 *           'per_tabella'=> [ tabella_id => ['quota' => float, 'importo' => int (cents)] ],
 *           'totale'     => int (cents),
 *         ]
 *       ],
 *       'totale_immobile' => int (cents),
 *     ]
 *   ],
 *   'gran_totale' => int (cents),
 *   'tot_per_tabella' => [ tabella_id => int (cents) ],
 * ]
 *
 * ALGORITMO
 * ---------
 * Per ogni foglia del piano dei conti (conto con tabelleMillesimali configurate):
 *   1. Determina l'importo di quel conto nel piano rate (override pivot o importo live).
 *   2. Per ogni tabella collegata (con coefficiente%):
 *      - Parte di importo allocata a questa tabella = importo × coeff/100
 *      - Per ogni immobile in tabella: peso = valore_immobile / somma_valori_tabella
 *      - Per ogni ripartizione soggetto (proprietario, inquilino, ...):
 *          importo_soggetto_tabella = parte_tabella × peso_immobile × percentuale_soggetto/100
 *        → accumula in weights[tabella_id][anagrafica_id][immobile_id]
 * 3. Penny-perfect sull'importo totale del conto (globale), poi proporziona per tabella.
 *
 * NOTA: La quota millesimale mostrata nella colonna "mill." è letta direttamente da
 * quote_tabella.valore per ogni (immobile, tabella). Non viene ricalcolata.
 */
class RipartoTabelleService
{
    /**
     * Costruisce la matrice completa per la stampa.
     *
     * @param PianoRate $pianoRate
     * @return array
     */
    public function buildMatrice(PianoRate $pianoRate): array
    {
        // ─── 1. Carica la catena gestione → pianoConto → conti → tabelle ───────
        $pianoRate->load(['gestione.pianoConto', 'capitoli']);

        $gestione  = $pianoRate->gestione;
        $pianoConto = $gestione?->pianoConto;

        if (!$pianoConto) {
            Log::warning('RipartoTabelleService: pianoRate ID=' . $pianoRate->id . ' non ha pianoConto.');
            return $this->empty();
        }

        // Override importi: se straordinario su fatture, aggrega righe_fattura; altrimenti usa pivot capitoli
        $pivotOverrides = [];
        $hasFatture = false;
        
        if ($pianoRate->tipo === 'straordinario' && $pianoRate->fatture()->exists()) {
            $hasFatture = true;
            $fattureIds = $pianoRate->fatture->pluck('id')->toArray();
            $righe = \Illuminate\Support\Facades\DB::table('righe_fattura')
                ->whereIn('fattura_passiva_id', $fattureIds)
                ->whereNotNull('conto_id')
                ->get();

            foreach ($righe as $riga) {
                $contoId = $riga->conto_id;
                if (!isset($pivotOverrides[$contoId])) {
                    $pivotOverrides[$contoId] = 0;
                }
                // Importo riga (imponibile + IVA)
                $pivotOverrides[$contoId] += abs($riga->importo_imponibile + $riga->importo_iva);
            }
            $capitoliIds = array_keys($pivotOverrides);

            // Se tutte le righe sono addebiti diretti a immobile (nessun conto_id)
            if (empty($capitoliIds) && $pianoRate->capitoli->isEmpty()) {
                return $this->empty();
            }
        } 
        
        // Sempre, aggiungi anche i capitoli espliciti se ci sono (gestisce i piani "misti")
        foreach ($pianoRate->capitoli as $cap) {
            if (!is_null($cap->pivot->importo)) {
                if (!isset($pivotOverrides[$cap->id])) {
                    $pivotOverrides[$cap->id] = 0;
                }
                $pivotOverrides[$cap->id] += (int) $cap->pivot->importo;
            }
        }
        $capitoliIds = array_keys($pivotOverrides);

        // ─── 2. Carica tutti i conti foglia con tabelle ───────────────────────
        $queryConti = Conto::with([
            'tabelleMillesimali.tabella.quote.immobile.anagrafiche',
            'tabelleMillesimali.ripartizioni',
            'sottoconti.tabelleMillesimali.tabella.quote.immobile.anagrafiche',
            'sottoconti.tabelleMillesimali.ripartizioni',
        ])->where('piano_conto_id', $pianoConto->id);

        $contiImpegnatiIds = [];
        if (!empty($capitoliIds)) {
            // Piano specifico: filtra per capitoli inclusi
            $queryConti->whereIn('id', $capitoliIds);
        } else {
            $queryConti->whereNull('parent_id');
            if (!$hasFatture) {
                // Piano rate generale (catch-all): escludiamo i capitoli già assegnati ad ALTRI piani rate attivi
                $contiImpegnatiIds = \Illuminate\Support\Facades\DB::table('piano_rate_capitoli')
                    ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
                    ->where('piani_rate.gestione_id', $pianoRate->gestione_id)
                    ->where('piani_rate.attivo', true)
                    ->where('piani_rate.id', '!=', $pianoRate->id)
                    ->pluck('conto_id')
                    ->toArray();
            }
        }

        $conti = $queryConti->get();

        // ─── 3. Accumula pesi per tabella ────────────────────────────────────
        // weights[tabella_id][anagrafica_id . '|' . immobile_id] = float (peso normalizzato × importo)
        // quoteMill[tabella_id][immobile_id] = float (valore nella tabella)
        $weights  = [];   // tabella_id → "aid|iid" → float weight
        $quoteMill = [];  // tabella_id → immobile_id → float valore
        $tabelleInfo = []; // tabella_id → ['nome', 'quota_label']
        $processatiIds = [];

        $this->processaContiPerPesi($conti, $pivotOverrides, $capitoliIds, $weights, $quoteMill, $tabelleInfo, $pianoRate->created_at, $contiImpegnatiIds, $processatiIds);

        if (empty($weights)) {
            return $this->empty();
        }

        // ─── 4. Somma importi totali da rate_quote già emesse ─────────────────
        // Usiamo le rate_quote come fonte di verità per gli importi reali distribuiti.
        // I weights calcolati sopra servono SOLO per proporzionare per tabella.
        $pianoRate->load([
            'rate.rateQuote.anagrafica',
            'rate.rateQuote.immobile',
        ]);

        // Aggregazione: [anagrafica_id][immobile_id] = importo_totale_cents (su tutte le rate)
        $totaliReali = [];
        foreach ($pianoRate->rate as $rata) {
            foreach ($rata->rateQuote as $rq) {
                if (!$rq->anagrafica_id || !$rq->immobile_id) continue;
                $totaliReali[$rq->anagrafica_id][$rq->immobile_id] =
                    ($totaliReali[$rq->anagrafica_id][$rq->immobile_id] ?? 0) + (int) $rq->importo;
            }
        }

        // ─── 5. Costruisce struttura righe ────────────────────────────────────
        // Per ogni (anagrafica, immobile) con importo reale, distribui per tabella
        // usando le quote di peso calcolate.

        $righe       = [];
        $totPerTab   = array_fill_keys(array_keys($tabelleInfo), 0);
        $granTotale  = 0;

        // Mappa ruolo → sigla
        $sigleRuolo = [
            'proprietario'      => 'P',
            'inquilino'         => 'I',
            'usufruttuario'     => 'U',
        ];

        // Calcola pesi totali per immobile per ogni tabella, per poter proporzionare i millesimi
        // (codice rimosso perché passiamo ai millesimi accorpati per immobile)

        // Per ogni anagrafica × immobile presente nelle rate reali
        foreach ($totaliReali as $anagraficaId => $perImmobile) {
            foreach ($perImmobile as $immobileId => $importoTotale) {
                if ($importoTotale === 0) continue;

                // Recupera dati immobile e anagrafica (dalla prima rata_quote trovata)
                $rq = $pianoRate->rate->flatMap->rateQuote
                    ->where('anagrafica_id', $anagraficaId)
                    ->where('immobile_id', $immobileId)
                    ->first();

                if (!$rq) continue;

                $immobile   = $rq->immobile;
                $anagrafica = $rq->anagrafica;
                if (!$immobile || !$anagrafica) continue;

                // Ruolo e quota del soggetto su questo immobile (dalla pivot anagrafiche)
                $pivot = $immobile->anagrafiche
                    ->where('id', $anagraficaId)
                    ->first()?->pivot;
                $ruoloRaw = $pivot?->tipologia ?? 'proprietario';
                $quotaSogg = $pivot?->quota ?? 100;
                $siglRuolo = $sigleRuolo[$ruoloRaw] ?? strtoupper(substr($ruoloRaw, 0, 1));

                // Calcola pesi per tabella per questo (anagrafica × immobile)
                $pesiFlatKey = $anagraficaId . '|' . $immobileId;
                $pesPerTab   = [];
                $peseTot     = 0.0;

                foreach ($weights as $tabId => $flatMap) {
                    $w = $flatMap[$pesiFlatKey] ?? 0.0;
                    $pesPerTab[$tabId] = $w;
                    $peseTot += $w;
                }

                // Distribuisce l'importo reale proporzionalmente alle tabelle
                $importiPerTab = [];
                if ($peseTot > 0.0) {
                    $assegnato = 0;
                    $tabIds    = array_keys($pesPerTab);
                    $nTab      = count($tabIds);
                    foreach ($tabIds as $idx => $tabId) {
                        if ($idx === $nTab - 1) {
                            // Ultimo: il resto (penny-perfect)
                            $importiPerTab[$tabId] = $importoTotale - $assegnato;
                        } else {
                            $q = (int) round($importoTotale * ($pesPerTab[$tabId] / $peseTot));
                            $importiPerTab[$tabId] = $q;
                            $assegnato += $q;
                        }
                    }
                } else {
                    // Nessun peso configurato: mette tutto in "senza tabella"
                    foreach (array_keys($tabelleInfo) as $tabId) {
                        $importiPerTab[$tabId] = 0;
                    }
                }

                // Quota millesimale per tabella per questo immobile
                $quotaPerTab = [];
                foreach ($tabelleInfo as $tabId => $_) {
                    $quotaPerTab[$tabId] = [
                        'quota'   => $quoteMill[$tabId][$immobileId] ?? null,
                        'importo' => $importiPerTab[$tabId] ?? 0,
                    ];
                }

                // Accumula nella struttura righe
                if (!isset($righe[$immobileId])) {
                    $righe[$immobileId] = [
                        'interno'          => $immobile->interno ?? '',
                        'piano'            => $immobile->piano   ?? '',
                        'soggetti'         => [],
                        'totale_immobile'  => 0,
                    ];
                }

                $righe[$immobileId]['soggetti'][$anagraficaId] = [
                    'nome'        => $anagrafica->nome ?? '—',
                    'ruolo'       => $siglRuolo,
                    'ruolo_raw'   => $ruoloRaw,
                    'quota_sogg'  => $quotaSogg,
                    'per_tabella' => $quotaPerTab,
                    'totale'      => $importoTotale,
                ];
                $righe[$immobileId]['totale_immobile'] += $importoTotale;
                $granTotale += $importoTotale;

                // Aggiorna totali per colonna tabella
                foreach ($importiPerTab as $tabId => $imp) {
                    $totPerTab[$tabId] = ($totPerTab[$tabId] ?? 0) + $imp;
                }
            }
        }

        // Totali quota millesimale per tabella (somma valori tabella)
        $totQuotaPerTab = [];
        foreach ($quoteMill as $tabId => $perImmobile) {
            $totQuotaPerTab[$tabId] = array_sum($perImmobile);
        }

        // Ordina righe per interno
        uasort($righe, fn($a, $b) => ($a['interno'] ?? '') <=> ($b['interno'] ?? ''));

        // Ordina soggetti per ruolo (proprietario prima)
        $ordineRuoli = ['proprietario' => 0, 'usufruttuario' => 1, 'inquilino' => 2];
        foreach ($righe as &$riga) {
            uasort($riga['soggetti'], fn($a, $b) =>
                ($ordineRuoli[$a['ruolo_raw']] ?? 9) <=> ($ordineRuoli[$b['ruolo_raw']] ?? 9)
            );
        }

        unset($riga);

        return [
            'tabelle'         => $tabelleInfo,
            'righe'           => $righe,
            'gran_totale'     => $granTotale,
            'tot_per_tabella' => $totPerTab,
            'tot_quota_per_tabella' => $totQuotaPerTab,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Scorre i conti e accumula i pesi per tabella, mimando la logica di CalcoloQuoteService
     * ma producendo weights[tabella_id]["aid|iid"] invece di totali[$aid][$iid].
     */
    private function processaContiPerPesi(
        Collection $conti,
        array $pivotOverrides,
        array $capitoliIds,
        array &$weights,
        array &$quoteMill,
        array &$tabelleInfo,
        ?\Carbon\Carbon $snapshotAt,
        array $contiImpegnatiIds = [],
        array &$processatiIds = []
    ): void {
        foreach ($conti as $conto) {
            if (in_array($conto->id, $contiImpegnatiIds)) continue;
            if (in_array($conto->id, $processatiIds)) continue;
            $processatiIds[] = $conto->id;

            $hasOverride = isset($pivotOverrides[$conto->id]);

            if ($hasOverride) {
                $importo = $pivotOverrides[$conto->id];

                if ($conto->tabelleMillesimali->isNotEmpty()) {
                    $this->distribuisciConto($conto, $importo, $weights, $quoteMill, $tabelleInfo);
                    continue;
                }

                // Conto padre: propaga proporzionalmente ai sottoconti
                if ($conto->sottoconti->isNotEmpty()) {
                    $figli = $snapshotAt
                        ? $conto->sottoconti->filter(fn($s) => $s->created_at->lte($snapshotAt))
                        : $conto->sottoconti;

                    if ($figli->isEmpty()) {
                        $figli = $conto->sottoconti;
                    }

                    $totFigli = (int) $figli->sum('importo');
                    $assegnato = 0;
                    $n = $figli->count();
                    $i = 0;

                    foreach ($figli as $figlio) {
                        $i++;
                        if ($i === $n) {
                            $pivotOverrides[$figlio->id] = $importo - $assegnato;
                        } elseif ($totFigli > 0) {
                            $q = (int) round($importo * ($figlio->importo / $totFigli));
                            $pivotOverrides[$figlio->id] = $q;
                            $assegnato += $q;
                        } else {
                            $q = (int) round($importo / $n);
                            $pivotOverrides[$figlio->id] = $q;
                            $assegnato += $q;
                        }
                    }

                    $this->processaContiPerPesi($figli, $pivotOverrides, $capitoliIds, $weights, $quoteMill, $tabelleInfo, $snapshotAt, $contiImpegnatiIds, $processatiIds);
                }
                continue;
            }

            // Senza override: usa importo live
            $importo = (int) ($conto->importo ?? 0);
            if ($importo !== 0 && $conto->tabelleMillesimali->isNotEmpty()) {
                $this->distribuisciConto($conto, abs($importo), $weights, $quoteMill, $tabelleInfo);
            }

            if ($conto->sottoconti && $conto->sottoconti->isNotEmpty()) {
                $this->processaContiPerPesi($conto->sottoconti, $pivotOverrides, $capitoliIds, $weights, $quoteMill, $tabelleInfo, $snapshotAt, $contiImpegnatiIds, $processatiIds);
            }
        }
    }

    /**
     * Per un singolo conto foglia, accumula i pesi per tabella × soggetto × immobile.
     */
    private function distribuisciConto(
        Conto $conto,
        int $importo,
        array &$weights,
        array &$quoteMill,
        array &$tabelleInfo
    ): void {
        foreach ($conto->tabelleMillesimali as $ctm) {
            $tabella = $ctm->tabella ?? null;
            if (!$tabella) continue;

            $coeff = (float) $ctm->coefficiente;
            if ($coeff <= 0) continue;

            $quote = $tabella->quote;
            if ($quote->isEmpty()) continue;

            $sommaValori = (float) $quote->sum('valore');
            if ($sommaValori <= 0.0) continue;

            // Registra info tabella
            if (!isset($tabelleInfo[$tabella->id])) {
                $tabelleInfo[$tabella->id] = [
                    'nome'        => $tabella->nome,
                    'quota_label' => $tabella->quota_label ?? ucfirst($tabella->quota ?? 'mill.'),
                    'quota_tipo'  => $tabella->quota ?? 'millesimi',
                    'decimali'    => $tabella->numero_decimali ?? 3,
                ];
            }

            $parteSuTabella = $importo * ($coeff / 100.0);

            $ripartizioni = $ctm->ripartizioni->isNotEmpty()
                ? $ctm->ripartizioni
                : collect([(object) ['soggetto' => 'proprietario', 'percentuale' => 100.0]]);

            foreach ($quote as $quota) {
                $immobile = $quota->immobile ?? null;
                if (!$immobile) continue;

                $valore = (float) $quota->valore;
                if ($valore <= 0.0) continue;

                // Registra valore nella tabella per questo immobile
                $quoteMill[$tabella->id][$immobile->id] = $valore;

                $pesoImmobile = $valore / $sommaValori;

                foreach ($ripartizioni as $rip) {
                    $percent = (float) $rip->percentuale;
                    if ($percent <= 0.0) continue;

                    $anagrafiche = $immobile->anagrafiche
                        ->where('pivot.attivo', true)
                        ->where('pivot.tipologia', $rip->soggetto);

                    // Cascata ruolo (identica a CalcoloQuoteService)
                    if ($anagrafiche->isEmpty() && $rip->soggetto !== 'proprietario') {
                        $catenaGodimento = ['inquilino', 'usufruttuario', 'proprietario'];
                        $catenaCapitale  = ['proprietario'];
                        $catena = in_array($rip->soggetto, $catenaCapitale, true)
                            ? $catenaCapitale : $catenaGodimento;

                        $start     = array_search($rip->soggetto, $catena, true);
                        $candidati = $start === false ? $catena : array_slice($catena, $start + 1);

                        foreach ($candidati as $ruoloFallback) {
                            $anagrafiche = $immobile->anagrafiche
                                ->where('pivot.attivo', true)
                                ->where('pivot.tipologia', $ruoloFallback);
                            if ($anagrafiche->isNotEmpty()) break;
                        }
                    }

                    if ($anagrafiche->isEmpty()) continue;

                    $sommaQuote = (float) $anagrafiche->sum('pivot.quota');
                    if ($sommaQuote <= 0.0) $sommaQuote = 1.0;

                    foreach ($anagrafiche as $anag) {
                        $quotaAnag = (float) $anag->pivot->quota;
                        if ($quotaAnag <= 0.0) continue;

                        $pesoAnag = $pesoImmobile * ($percent / 100.0) * ($quotaAnag / $sommaQuote);
                        $key      = $anag->id . '|' . $immobile->id;

                        if (!isset($weights[$tabella->id])) {
                            $weights[$tabella->id] = [];
                        }
                        $weights[$tabella->id][$key] = ($weights[$tabella->id][$key] ?? 0.0) + $parteSuTabella * $pesoAnag;
                    }
                }
            }
        }
    }

    private function empty(): array
    {
        return [
            'tabelle'               => [],
            'righe'                 => [],
            'gran_totale'           => 0,
            'tot_per_tabella'       => [],
            'tot_quota_per_tabella' => [],
        ];
    }
}
