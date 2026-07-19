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
 *       'nome_immobile' => string,  // nome unità (allineato alla vista a schermo), fallback codice
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
 * ALGORITMO (per-conto, allineato a CalcoloQuoteService)
 * ------------------------------------------------------
 * Le celle della matrice sono ricostruite CONTO PER CONTO con lo stesso
 * identico algoritmo penny-perfect usato da CalcoloQuoteService per generare
 * le rate (pesi identici, stessa cascata ruolo, stessa decurtazione scoperti,
 * stesso metodo del resto più grande). Ne seguono due garanzie:
 *
 *   1. COLONNE: ogni tabella somma esattamente al budget dei suoi conti
 *      (per i conti collegati a una sola tabella — il caso normale), e i
 *      centesimi di arrotondamento restano DENTRO i partecipanti del conto.
 *   2. RIGHE: il totale di ogni soggetto coincide con quanto realmente
 *      addebitato in contabilità (rate_quote) perché le allocazioni sono le
 *      stesse che hanno generato le rate. Se i dati sono cambiati dopo la
 *      generazione (o per quote extra come saldi), un riallineamento di
 *      sicurezza corregge il residuo sulla tabella a peso maggiore del
 *      soggetto: la garanzia legale (riga = rate_quote) vale SEMPRE.
 *
 * Storia: v1.9.1 distribuiva il totale di riga proporzionalmente ai pesi e
 * scaricava il resto sull'ultima tabella registrata (anche a peso zero →
 * "€0,01 su TUNNEL a chi non c'entra"); vedi
 * docs/ripartotabelle_discrepanza_centesimale.md.
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
                $pivotOverrides[$cap->id] += (int) round($cap->pivot->importo);
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

        // ─── 3. Ricostruisce le allocazioni esatte per (tabella, soggetto) ────
        // cells[tabella_id][aid|iid]   = int cents (stesse allocazioni del motore rate)
        // weights[tabella_id][aid|iid] = float (peso × importo, solo per il fallback residuo)
        // quoteMill[tabella_id][immobile_id] = float (valore nella tabella)
        $cells    = [];
        $weights  = [];
        $quoteMill = [];  // tabella_id → immobile_id → float valore
        $tabelleInfo = []; // tabella_id → ['nome', 'quota_label']
        $processatiIds = [];

        $this->processaConti($conti, $pivotOverrides, $cells, $weights, $quoteMill, $tabelleInfo, $pianoRate->created_at, $contiImpegnatiIds, $processatiIds);

        if (empty($weights)) {
            return $this->empty();
        }

        // ─── 4. Somma importi totali da rate_quote già emesse ─────────────────
        // Le rate_quote restano la fonte di verità per il totale di ogni soggetto:
        // le celle per-conto vengono riallineate a questo totale in caso di residuo.
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
                    ($totaliReali[$rq->anagrafica_id][$rq->immobile_id] ?? 0) + (int) round($rq->importo);
            }
        }

        // ─── 5. Costruisce struttura righe ────────────────────────────────────

        $righe       = [];
        $totPerTab   = array_fill_keys(array_keys($tabelleInfo), 0);
        $granTotale  = 0;

        // Mappa ruolo → sigla
        $sigleRuolo = [
            'proprietario'      => 'P',
            'inquilino'         => 'I',
            'usufruttuario'     => 'U',
        ];

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

                $pesiFlatKey = $anagraficaId . '|' . $immobileId;

                // Celle esatte per-conto (stesse allocazioni che hanno generato le rate)
                $importiPerTab = [];
                $rowSum    = 0;
                $pesPerTab = [];
                $peseTot   = 0.0;
                foreach ($tabelleInfo as $tabId => $_info) {
                    $cent = $cells[$tabId][$pesiFlatKey] ?? 0;
                    $importiPerTab[$tabId] = $cent;
                    $rowSum += $cent;

                    $w = $weights[$tabId][$pesiFlatKey] ?? 0.0;
                    $pesPerTab[$tabId] = $w;
                    $peseTot += $w;
                }

                // Riallineamento di sicurezza: la riga stampata deve SEMPRE
                // coincidere con rate_quote (garanzia legale). Un residuo ≠ 0
                // indica quote extra (saldi/conguagli) o dati modificati dopo
                // la generazione: va sulla tabella a peso maggiore del soggetto.
                $residuo = $importoTotale - $rowSum;
                if ($residuo !== 0 && $peseTot > 0.0) {
                    arsort($pesPerTab);
                    $tabMax = array_key_first($pesPerTab);
                    $importiPerTab[$tabMax] += $residuo;
                    Log::debug("RipartoTabelleService: residuo di {$residuo} cent riallineato a rate_quote.", [
                        'piano_rate_id' => $pianoRate->id,
                        'anagrafica_id' => $anagraficaId,
                        'immobile_id'   => $immobileId,
                    ]);
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
                        'nome_immobile'    => $immobile->nome ?: ($immobile->codice_immobile ?? ''),
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
     * Scorre i conti replicando la traversal di CalcoloQuoteService::processaConti
     * (overrides propagati ai sottoconti, snapshot temporale, esclusioni).
     */
    private function processaConti(
        Collection $conti,
        array $pivotOverrides,
        array &$cells,
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
                    $importoConto = in_array($conto->tipo ?? 'spesa', ['spesa', 'uscita'], true)
                        ? abs($importo)
                        : -abs($importo);
                    $this->distribuisciConto($conto, $importoConto, $cells, $weights, $quoteMill, $tabelleInfo);
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

                    $this->processaConti($figli, $pivotOverrides, $cells, $weights, $quoteMill, $tabelleInfo, $snapshotAt, $contiImpegnatiIds, $processatiIds);
                }
                continue;
            }

            // Senza override: usa importo live
            $importo = (int) ($conto->importo ?? 0);
            if ($importo !== 0 && $conto->tabelleMillesimali->isNotEmpty()) {
                $importoConto = in_array($conto->tipo ?? 'spesa', ['spesa', 'uscita'], true)
                    ? abs($importo)
                    : -abs($importo);
                $this->distribuisciConto($conto, $importoConto, $cells, $weights, $quoteMill, $tabelleInfo);
            }

            if ($conto->sottoconti && $conto->sottoconti->isNotEmpty()) {
                $this->processaConti($conto->sottoconti, $pivotOverrides, $cells, $weights, $quoteMill, $tabelleInfo, $snapshotAt, $contiImpegnatiIds, $processatiIds);
            }
        }
    }

    /**
     * Distribuisce un singolo conto sulle sue tabelle × soggetti con lo STESSO
     * algoritmo di CalcoloQuoteService::distribuisciSuTabelle (pesi identici,
     * cascata ruolo identica, decurtazione scoperti identica, penny-perfect
     * identico), accumulando le celle intere in $cells[tabella_id][aid|iid].
     */
    private function distribuisciConto(
        Conto $conto,
        int $importoConto,
        array &$cells,
        array &$weights,
        array &$quoteMill,
        array &$tabelleInfo
    ): void {
        // wPerTab[tabella_id][key] e wMerged[key]: pesi puri di QUESTO conto
        $wPerTab = [];
        $wMerged = [];
        $pesoScopertoTotale = 0.0;

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

            $weightCoeff = $coeff / 100.0;

            foreach ($quote as $quota) {
                $immobile = $quota->immobile ?? null;
                if (!$immobile) continue;

                $valore = (float) $quota->valore;
                if ($valore <= 0.0) continue;

                // Registra valore nella tabella per questo immobile
                $quoteMill[$tabella->id][$immobile->id] = $valore;

                $weightImmobile = $weightCoeff * ($valore / $sommaValori);

                $ripartizioni = $ctm->ripartizioni->isNotEmpty()
                    ? $ctm->ripartizioni
                    : collect([(object) ['soggetto' => 'proprietario', 'percentuale' => 100.0]]);

                foreach ($ripartizioni as $rip) {
                    $percent = (float) $rip->percentuale;
                    if ($percent <= 0.0) continue;

                    $weightRip = $weightImmobile * ($percent / 100.0);

                    $anagrafiche = $immobile->anagrafiche
                        ->where('pivot.attivo', true)
                        ->where('pivot.tipologia', $rip->soggetto);

                    // Cascata ruolo (identica a CalcoloQuoteService)
                    if ($anagrafiche->isEmpty() && $rip->soggetto !== 'proprietario') {
                        $catenaGodimento = ['inquilino', 'usufruttuario', 'proprietario'];
                        $catenaCapitale  = ['nuda_proprietario', 'proprietario'];
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

                    // Cascata esaurita: peso scoperto (decurtato come nel motore rate)
                    if ($anagrafiche->isEmpty()) {
                        $pesoScopertoTotale += $weightRip;
                        continue;
                    }

                    $sommaQuote = (float) $anagrafiche->sum('pivot.quota');
                    if ($sommaQuote <= 0.0) $sommaQuote = 1.0;

                    foreach ($anagrafiche as $anag) {
                        $quotaAnag = (float) $anag->pivot->quota;
                        if ($quotaAnag <= 0.0) continue;

                        $weightAnagrafica = $weightRip * ($quotaAnag / $sommaQuote);
                        $key = $anag->id . '|' . $immobile->id;

                        $wPerTab[$tabella->id][$key] = ($wPerTab[$tabella->id][$key] ?? 0.0) + $weightAnagrafica;
                        $wMerged[$key] = ($wMerged[$key] ?? 0.0) + $weightAnagrafica;

                        // Peso × importo per il fallback residuo (ordinamento tabelle)
                        $weights[$tabella->id][$key] =
                            ($weights[$tabella->id][$key] ?? 0.0) + abs($importoConto) * $weightAnagrafica;
                    }
                }
            }
        }

        if (empty($wMerged)) return;

        // Decurtazione scoperti — identica a CalcoloQuoteService
        $pesoSoggetti = array_sum($wMerged);
        $pesoTotaleInclScoperto = $pesoSoggetti + $pesoScopertoTotale;
        if ($pesoTotaleInclScoperto <= 0.0) return;

        $importoDaDistribuire = abs($importoConto);
        if ($pesoScopertoTotale > 0.0) {
            $totaleScopertoInt = (int) round(abs($importoConto) * ($pesoScopertoTotale / $pesoTotaleInclScoperto));
            $importoDaDistribuire = abs($importoConto) - $totaleScopertoInt;
        }
        $importoContoSegno = $importoConto < 0 ? -$importoDaDistribuire : $importoDaDistribuire;

        $wMergedNorm = [];
        foreach ($wMerged as $key => $w) {
            $wMergedNorm[$key] = $w / $pesoSoggetti;
        }

        $alloc = $this->distribuisciImporto($wMergedNorm, $importoContoSegno);

        // Split per tabella: se il conto insiste su una sola tabella (caso
        // normale) l'intera quota va lì; altrimenti penny-perfect sui pesi
        // per-tabella del soggetto.
        $tabIds = array_keys($wPerTab);

        foreach ($alloc as $key => $importoSoggetto) {
            if (count($tabIds) === 1) {
                $tid = $tabIds[0];
                $cells[$tid][$key] = ($cells[$tid][$key] ?? 0) + $importoSoggetto;
                continue;
            }

            $pesiSogg = [];
            $sommaPesi = 0.0;
            foreach ($tabIds as $tid) {
                $w = $wPerTab[$tid][$key] ?? 0.0;
                if ($w > 0.0) {
                    $pesiSogg[$tid] = $w;
                    $sommaPesi += $w;
                }
            }
            if ($sommaPesi <= 0.0) {
                $cells[$tabIds[0]][$key] = ($cells[$tabIds[0]][$key] ?? 0) + $importoSoggetto;
                continue;
            }

            $pesiNorm = [];
            foreach ($pesiSogg as $tid => $w) {
                $pesiNorm[$tid] = $w / $sommaPesi;
            }
            foreach ($this->distribuisciImporto($pesiNorm, $importoSoggetto) as $tid => $parte) {
                $cells[$tid][$key] = ($cells[$tid][$key] ?? 0) + $parte;
            }
        }
    }

    /**
     * Distribuisce un importo totale basandosi su un array di pesi normalizzati,
     * garantendo una ripartizione "penny-perfect" senza perdita o creazione di centesimi.
     *
     * COPIA 1:1 di CalcoloQuoteService::distribuisciImporto — DEVE rimanere
     * sincronizzata: la garanzia "riga stampata = rate_quote" dipende dal fatto
     * che i due servizi arrotondino in modo bit-identico.
     *
     * @param array $weights Array di pesi normalizzati (somma = 1)
     * @param int $importoTotale Importo totale da distribuire in centesimi
     * @return array Importi penny-perfect calcolati
     */
    private function distribuisciImporto(array $weights, int $importoTotale): array
    {
        $result = [];
        if ($importoTotale === 0) {
            foreach ($weights as $key => $_) { $result[$key] = 0; }
            return $result;
        }

        $sign    = $importoTotale < 0 ? -1 : 1;
        $totAbs  = abs($importoTotale);
        $bases      = [];
        $remainders = [];
        $sumBase    = 0;

        foreach ($weights as $key => $w) {
            $raw   = round($totAbs * $w, 8);
            $base  = (int) floor($raw);
            $bases[$key]      = $base;
            $remainders[$key] = $raw - $base;
            $sumBase += $base;
        }

        $diff = $totAbs - $sumBase;
        if ($diff > 0) {
            arsort($remainders);
            $keys = array_keys($remainders);
            for ($i = 0; $i < $diff && $i < count($keys); $i++) {
                $bases[$keys[$i]]++;
            }
        }

        foreach ($bases as $key => $b) {
            $result[$key] = $b * $sign;
        }

        return $result;
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
