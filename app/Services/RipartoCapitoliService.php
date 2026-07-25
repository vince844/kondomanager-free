<?php

namespace App\Services;

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service responsabile della generazione della matrice di riparto per i Capitoli di un Piano Rate.
 * 
 * Questo servizio calcola in modo esatto le quote da assegnare ad ogni soggetto (Proprietario, Inquilino, ecc.)
 * in base agli importi dei conti (o gli override definiti nel piano rate), alle tabelle millesimali 
 * collegate, e alle regole di ripartizione specifiche per ogni conto.
 * Gestisce l'arrotondamento per difetto e il recupero dei resti (Metodo di Hare / Largest Remainder Method)
 * in modo che la somma esatta corrisponda sempre all'importo del capitolo.
 */
class RipartoCapitoliService
{
    /**
     * Costruisce la matrice di ripartizione completa per un dato Piano Rate.
     *
     * @param PianoRate $pianoRate Il piano rate per il quale generare la matrice.
     * @return array Array strutturato contenente:
     *               - 'capitoli': Info sui capitoli coinvolti (nome, quota_label, tot_importo).
     *               - 'righe': Dati di riparto raggruppati per Immobile e Soggetto.
     *               - 'gran_totale': Il totale generale calcolato su tutti i capitoli.
     *               - 'tot_per_capitolo': I totali ripartiti suddivisi per ogni capitolo.
     */
    public function buildMatrice(PianoRate $pianoRate): array
    {
        $pianoRate->load(['gestione.pianoConto', 'capitoli']);
        $gestione  = $pianoRate->gestione;
        $pianoConto = $gestione?->pianoConto;

        if (!$pianoConto) {
            return $this->empty();
        }

        // Override importi: se straordinario su fatture, aggrega righe_fattura; altrimenti usa pivot capitoli
        $pivotOverrides = [];
        $hasFatture = false;
        
        $capitoliIds = [];
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
            $capitoliIds[] = $cap->id;
            if (!is_null($cap->pivot->importo)) {
                if (!isset($pivotOverrides[$cap->id])) {
                    $pivotOverrides[$cap->id] = 0;
                }
                $pivotOverrides[$cap->id] += (int) $cap->pivot->importo;
            }
        }
        $capitoliIds = array_unique($capitoliIds);

        $queryConti = Conto::with([
            'tabelleMillesimali.tabella.quote.immobile.anagrafiche',
            'tabelleMillesimali.ripartizioni',
            'sottoconti.tabelleMillesimali.tabella.quote.immobile.anagrafiche',
            'sottoconti.tabelleMillesimali.ripartizioni',
        ])->where('piano_conto_id', $pianoConto->id);

        $contiImpegnatiIds = [];
        if (!empty($capitoliIds)) {
            $queryConti->whereIn('id', $capitoliIds);
        } else {
            $queryConti->whereNull('parent_id');
            if (!$hasFatture) {
                // È un piano rate generale: escludiamo i capitoli già assegnati ad ALTRI piani rate attivi
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

        $weightsPerCapitolo = [];
        $capitoliInfo = [];
        $quoteMill = [];
        $processatiIds = [];

        $this->processaCapitoliPerPesi($conti, $pivotOverrides, $weightsPerCapitolo, $capitoliInfo, $quoteMill, $contiImpegnatiIds, $processatiIds, null);

        if (empty($weightsPerCapitolo)) {
            return $this->empty();
        }

        $righe = [];
        $totPerCapitolo = array_fill_keys(array_keys($capitoliInfo), 0);
        $granTotale = 0;

        $sigleRuolo = [
            'proprietario'  => 'P',
            'inquilino'     => 'I',
            'usufruttuario' => 'U',
        ];

        $importiAssegnati = [];

        // Ripartizione degli importi applicando il Metodo dei Resti Più Alti (Hare-Niemeyer)
        foreach ($weightsPerCapitolo as $contoId => $pesi) {
            $importoCapitolo = $capitoliInfo[$contoId]['tot_importo'];
            
            $sumWeights = array_sum($pesi);
            if ($sumWeights <= 0) continue;

            $normalizedWeights = [];
            foreach ($pesi as $key => $w) {
                $normalizedWeights[$key] = $w / $sumWeights;
            }

            $bases = [];
            $remainders = [];
            $sumBase = 0;

            foreach ($normalizedWeights as $key => $w) {
                $raw = $importoCapitolo * $w;
                $base = (int) floor($raw);
                $bases[$key] = $base;
                $remainders[$key] = $raw - $base;
                $sumBase += $base;
            }

            $diff = $importoCapitolo - $sumBase;
            if ($diff > 0) {
                arsort($remainders);
                $keys = array_keys($remainders);
                for ($i = 0; $i < $diff && $i < count($keys); $i++) {
                    $bases[$keys[$i]]++;
                }
            }

            foreach ($bases as $key => $imp) {
                if ($imp === 0) continue;
                if (!isset($importiAssegnati[$key])) $importiAssegnati[$key] = [];
                $importiAssegnati[$key][$contoId] = $imp;
            }
        }

        $immobiliDict = [];
        $anagraficheDict = [];
        $processatiEntitiesIds = [];
        foreach ($conti as $c) {
            $this->extractEntities($c, $immobiliDict, $anagraficheDict, $contiImpegnatiIds, $processatiEntitiesIds);
        }

        // Totali reali da rate_quote: la matrice fin qui è calcolata sui soli
        // importi LORDI dei capitoli (conto->importo o override), che NON
        // conoscono il netting del "già versato" (beta.26) né qualunque altro
        // aggiustamento applicato dal motore prima di generare le rate. Senza
        // questo riallineamento, un condominio con un accantonamento registrato
        // vedrebbe stampato un importo diverso da quello davvero addebitato —
        // esattamente la garanzia "riga stampata = rate_quote" che
        // RipartoTabelleService applica già per la stampa gemella.
        $pianoRate->loadMissing(['rate.rateQuote.anagrafica', 'rate.rateQuote.immobile']);
        $totaliReali = [];
        foreach ($pianoRate->rate as $rata) {
            foreach ($rata->rateQuote as $rq) {
                if (!$rq->anagrafica_id || !$rq->immobile_id) continue;
                $totaliReali[$rq->anagrafica_id][$rq->immobile_id] =
                    ($totaliReali[$rq->anagrafica_id][$rq->immobile_id] ?? 0) + (int) round($rq->importo);
            }
        }

        // Assemblaggio della struttura finale delle righe da passare alla view/pdf
        foreach ($importiAssegnati as $key => $importiPerCapitolo) {
            [$aid, $iid] = explode('|', $key);
            $immobile = $immobiliDict[$iid] ?? null;
            $anagrafica = $anagraficheDict[$aid] ?? null;

            if (!$immobile || !$anagrafica) continue;

            $pivot = collect($immobile->anagrafiche)->where('id', $aid)->first()?->pivot;
            $ruoloRaw = $pivot?->tipologia ?? 'proprietario';
            $quotaSogg = $pivot?->quota ?? 100;
            $siglRuolo = $sigleRuolo[$ruoloRaw] ?? strtoupper(substr($ruoloRaw, 0, 1));

            // Riallineamento di sicurezza: se il totale reale (rate_quote) per
            // questo soggetto diverge dal lordo appena calcolato — tipicamente
            // perché il netting ha ridotto una o più quote — il residuo va sul
            // capitolo a peso maggiore per quel soggetto, la stessa strategia già
            // in produzione in RipartoTabelleService. Se il piano non è ancora
            // stato generato ($totaliReali vuoto), non c'è nulla da riallineare:
            // resta il lordo, come sempre stato.
            $importoReale = $totaliReali[$aid][$iid] ?? null;
            if ($importoReale !== null) {
                $lordoRiga = array_sum($importiPerCapitolo);
                $residuo = $importoReale - $lordoRiga;
                if ($residuo !== 0) {
                    $pesiSogg = [];
                    foreach ($capitoliInfo as $contoId => $_) {
                        $w = $weightsPerCapitolo[$contoId][$key] ?? 0.0;
                        if ($w > 0.0) $pesiSogg[$contoId] = $w;
                    }
                    if (!empty($pesiSogg)) {
                        arsort($pesiSogg);
                        $contoMax = array_key_first($pesiSogg);
                        $importiPerCapitolo[$contoMax] = ($importiPerCapitolo[$contoMax] ?? 0) + $residuo;
                        Log::debug("RipartoCapitoliService: residuo di {$residuo} cent riallineato a rate_quote.", [
                            'piano_rate_id' => $pianoRate->id,
                            'anagrafica_id' => $aid,
                            'immobile_id'   => $iid,
                        ]);
                    }
                }
            }

            $totSogg = 0;
            $quotaPerCapitolo = [];
            foreach ($capitoliInfo as $contoId => $_) {
                $imp = $importiPerCapitolo[$contoId] ?? 0;
                $totSogg += $imp;
                $totPerCapitolo[$contoId] += $imp;

                $quotaPerCapitolo[$contoId] = [
                    'quota' => $quoteMill[$contoId][$iid] ?? null,
                    'importo' => $imp
                ];
            }

            if (!isset($righe[$iid])) {
                $righe[$iid] = [
                    'interno'          => $immobile->interno ?? '',
                    'piano'            => $immobile->piano   ?? '',
                    'soggetti'         => [],
                    'totale_immobile'  => 0,
                ];
            }

            $righe[$iid]['soggetti'][$aid] = [
                'nome'        => $anagrafica->nome ?? '—',
                'ruolo'       => $siglRuolo,
                'ruolo_raw'   => $ruoloRaw,
                'quota_sogg'  => $quotaSogg,
                'per_capitolo'=> $quotaPerCapitolo,
                'totale'      => $totSogg,
            ];

            $righe[$iid]['totale_immobile'] += $totSogg;
            $granTotale += $totSogg;
        }

        // Ordinamento finale per interno immobile e ruoli dei soggetti
        uasort($righe, fn($a, $b) => ($a['interno'] ?? '') <=> ($b['interno'] ?? ''));

        $ordineRuoli = ['proprietario' => 0, 'usufruttuario' => 1, 'inquilino' => 2];
        foreach ($righe as &$riga) {
            uasort($riga['soggetti'], fn($a, $b) =>
                ($ordineRuoli[$a['ruolo_raw']] ?? 9) <=> ($ordineRuoli[$b['ruolo_raw']] ?? 9)
            );
        }
        unset($riga);

        return [
            'capitoli'        => $capitoliInfo,
            'righe'           => $righe,
            'gran_totale'     => $granTotale,
            'tot_per_capitolo'=> $totPerCapitolo,
        ];
    }

    /**
     * Elabora l'albero dei conti (padri e figli) e calcola i pesi provvisori per la ripartizione.
     *
     * Se un conto padre ha un override di importo (fissato dal piano rate), quell'importo
     * viene processato. Se non ha tabelle ma ha dei sottoconti, l'importo override viene
     * distribuito proporzionalmente ai sottoconti in base ai loro importi nativi, o in parti uguali.
     *
     * Segnalazione amministratore: la stampa mostrava una colonna per ogni
     * SOTTOCONTO foglia (es. "AM.BK", "AM.CF"...) invece che una per il
     * CAPITOLO padre ("Amministrative") — su un condominio con molti
     * sottoconti la tabella HTML diventava enorme (crash mPDF,
     * pcre.backtrack_limit) e sul chunking a pagine la somma visibile non
     * coincideva col totale riga. Ogni chiamata a distribuisciConto() ora
     * riceve anche il capitolo RADICE (l'antenato di primo livello, calcolato
     * una sola volta per ramo e propagato ai figli) su cui aggregare pesi e
     * importo, mentre la matematica di ripartizione resta sulla foglia (dove
     * vivono davvero tabella millesimale e ripartizioni).
     *
     * @param Collection $conti Collezione dei conti da processare.
     * @param array $pivotOverrides Array con eventuali override [conto_id => importo].
     * @param array &$weightsPerCapitolo Referenza all'array in cui accumulare i pesi calcolati.
     * @param array &$capitoliInfo Referenza all'array in cui salvare info di contesto sui capitoli.
     * @param array &$quoteMill Referenza all'array per salvare le quote millesimali per le intestazioni.
     * @param Conto|null $radice Capitolo radice del ramo corrente (null al primo livello: si calcola per ogni conto).
     * @return void
     */
    private function processaCapitoliPerPesi(
        Collection $conti,
        array $pivotOverrides,
        array &$weightsPerCapitolo,
        array &$capitoliInfo,
        array &$quoteMill,
        array $contiImpegnatiIds = [],
        array &$processatiIds = [],
        ?Conto $radice = null
    ): void {
        foreach ($conti as $conto) {
            if (in_array($conto->id, $contiImpegnatiIds)) continue;
            if (in_array($conto->id, $processatiIds)) continue;
            $processatiIds[] = $conto->id;

            $radiceEffettiva = $radice ?? $this->radiceDi($conto);

            $hasOverride = isset($pivotOverrides[$conto->id]);

            if ($hasOverride) {
                $importo = $pivotOverrides[$conto->id];

                if ($conto->tabelleMillesimali->isNotEmpty()) {
                    $this->distribuisciConto($conto, $importo, $weightsPerCapitolo, $capitoliInfo, $quoteMill, $radiceEffettiva);
                    continue;
                }

                if ($conto->sottoconti->isNotEmpty()) {
                    $figli = $conto->sottoconti;
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

                    $this->processaCapitoliPerPesi($figli, $pivotOverrides, $weightsPerCapitolo, $capitoliInfo, $quoteMill, $contiImpegnatiIds, $processatiIds, $radiceEffettiva);
                }
                continue;
            }

            $importo = (int) ($conto->importo ?? 0);
            if ($importo !== 0 && $conto->tabelleMillesimali->isNotEmpty()) {
                $this->distribuisciConto($conto, abs($importo), $weightsPerCapitolo, $capitoliInfo, $quoteMill, $radiceEffettiva);
            }

            if ($conto->sottoconti && $conto->sottoconti->isNotEmpty()) {
                $this->processaCapitoliPerPesi($conto->sottoconti, $pivotOverrides, $weightsPerCapitolo, $capitoliInfo, $quoteMill, $contiImpegnatiIds, $processatiIds, $radiceEffettiva);
            }
        }
    }

    /**
     * Risale la catena parent_id fino all'antenato di primo livello (il
     * capitolo "vero" nel senso del piano dei conti). Si ferma anche se
     * incontra un parent_id orfano (record mancante), come guardia difensiva.
     */
    private function radiceDi(Conto $conto): Conto
    {
        $corrente = $conto;
        while ($corrente->parent_id !== null && $corrente->parent) {
            $corrente = $corrente->parent;
        }
        return $corrente;
    }

    /**
     * Distribuisce l'importo di un singolo conto (foglia) sugli immobili e le
     * relative anagrafiche, tenendo conto delle tabelle millesimali e delle
     * quote di ripartizione — ma accumula pesi e importo sul capitolo
     * RADICE, non sulla foglia: più sottoconti sotto lo stesso capitolo
     * (es. "Amministrative" → AM.BK, AM.CF, AM.DF...) finiscono in un'unica
     * colonna aggregata invece che una per ciascuno.
     *
     * Se il ruolo previsto (es. inquilino) manca nell'immobile, la quota ricade a cascata
     * sul ruolo di rango superiore (es. proprietario) in base alla catena di godimento.
     *
     * @param Conto $conto Il conto FOGLIA da cui prelevare tabelle millesimali e ripartizioni.
     * @param int $importo Importo base (da distribuire) per questo conto.
     * @param array &$weightsPerCapitolo Referenza all'accumulatore dei pesi, chiave = id capitolo radice.
     * @param array &$capitoliInfo Referenza all'accumulatore meta-dati capitoli, chiave = id capitolo radice.
     * @param array &$quoteMill Referenza per salvare le quote di prima tabella, chiave = id capitolo radice.
     * @param Conto $radice Il capitolo radice (antenato di primo livello) su cui aggregare.
     * @return void
     */
    private function distribuisciConto(
        Conto $conto,
        int $importo,
        array &$weightsPerCapitolo,
        array &$capitoliInfo,
        array &$quoteMill,
        Conto $radice
    ): void {
        $radiceId = $radice->id;
        $primoTabId = null;
        $primoTabQuota = 'mill.';

        foreach ($conto->tabelleMillesimali as $ctm) {
            $tabella = $ctm->tabella ?? null;
            if (!$tabella) continue;

            if (!$primoTabId) {
                $primoTabId = $tabella->id;
                $primoTabQuota = $tabella->quota_label ?? ucfirst($tabella->quota ?? 'mill.');
            }

            $coeff = (float) $ctm->coefficiente;
            if ($coeff <= 0) continue;

            $quote = $tabella->quote;
            if ($quote->isEmpty()) continue;

            $sommaValori = (float) $quote->sum('valore');
            if ($sommaValori <= 0.0) continue;

            $parteSuTabella = $importo * ($coeff / 100.0);

            $ripartizioni = $ctm->ripartizioni->isNotEmpty()
                ? $ctm->ripartizioni
                : collect([(object) ['soggetto' => 'proprietario', 'percentuale' => 100.0]]);

            foreach ($quote as $quota) {
                $immobile = $quota->immobile ?? null;
                if (!$immobile) continue;

                $valore = (float) $quota->valore;
                if ($valore <= 0.0) continue;

                // Salva il valore millesimale della prima tabella collegata, utile per
                // l'intestazione PDF — ma solo finché i sottoconti aggregati sotto
                // questo capitolo usano tutti la STESSA tabella: se differiscono
                // (vedi sotto, 'quota_mista'), un singolo valore "mill." per il
                // capitolo sarebbe fuorviante, quindi non se ne mostra nessuno.
                if ($tabella->id === $primoTabId && empty($capitoliInfo[$radiceId]['quota_mista'])) {
                    $quoteMill[$radiceId][$immobile->id] = $valore;
                }

                $pesoImmobile = $valore / $sommaValori;

                foreach ($ripartizioni as $rip) {
                    $percent = (float) $rip->percentuale;
                    if ($percent <= 0.0) continue;

                    $anagrafiche = $immobile->anagrafiche
                        ->where('pivot.attivo', true)
                        ->where('pivot.tipologia', $rip->soggetto);

                    // Gestione "Cascata di Ruoli": se manca l'inquilino, ricade su usufruttuario/proprietario
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

                        if (!isset($weightsPerCapitolo[$radiceId])) {
                            $weightsPerCapitolo[$radiceId] = [];
                        }
                        $weightsPerCapitolo[$radiceId][$key] = ($weightsPerCapitolo[$radiceId][$key] ?? 0.0) + $parteSuTabella * $pesoAnag;
                    }
                }
            }
        }

        // Salva/aggiorna le info del capitolo RADICE: più sottoconti-foglia
        // contribuiscono allo stesso capitolo, quindi l'importo si SOMMA
        // invece di sovrascriversi (a differenza del vecchio comportamento
        // per-foglia, dove ogni sottoconto era il proprio capitolo).
        if ($primoTabId) {
            if (!isset($capitoliInfo[$radiceId])) {
                $capitoliInfo[$radiceId] = [
                    'nome'               => $radice->nome,
                    'quota_label'        => $primoTabQuota,
                    'tot_importo'        => 0,
                    'quota_mista'        => false,
                    '_prima_tabella_id'  => $primoTabId,
                ];
            } elseif (!$capitoliInfo[$radiceId]['quota_mista'] && $capitoliInfo[$radiceId]['_prima_tabella_id'] !== $primoTabId) {
                $capitoliInfo[$radiceId]['quota_mista'] = true;
                $capitoliInfo[$radiceId]['quota_label'] = '—';
                unset($quoteMill[$radiceId]);
            }
            $capitoliInfo[$radiceId]['tot_importo'] += $importo;
        }
    }

    /**
     * Esegue una scansione completa di un albero di conti ed estrae in modo univoco
     * tutti gli immobili e le rispettive anagrafiche associate tramite tabelle millesimali.
     * Serve come dizionario rapido (cache in-memory) durante l'assemblaggio delle righe.
     *
     * @param Conto $conto Il conto di partenza da ispezionare.
     * @param array &$immobili Referenza al dizionario degli Immobili da popolare.
     * @param array &$anagrafiche Referenza al dizionario delle Anagrafiche da popolare.
     * @return void
     */
    private function extractEntities(Conto $conto, array &$immobili, array &$anagrafiche, array $contiImpegnatiIds = [], array &$processatiIds = []): void
    {
        if (in_array($conto->id, $contiImpegnatiIds)) return;
        if (in_array($conto->id, $processatiIds)) return;
        $processatiIds[] = $conto->id;

        foreach ($conto->tabelleMillesimali as $ctm) {
            $tabella = $ctm->tabella ?? null;
            if (!$tabella) continue;
            foreach ($tabella->quote as $quota) {
                if ($quota->immobile) {
                    $immobili[$quota->immobile->id] = $quota->immobile;
                    foreach ($quota->immobile->anagrafiche as $anag) {
                        $anagrafiche[$anag->id] = $anag;
                    }
                }
            }
        }
        foreach ($conto->sottoconti as $sub) {
            $this->extractEntities($sub, $immobili, $anagrafiche, $contiImpegnatiIds, $processatiIds);
        }
    }

    /**
     * Restituisce la struttura vuota di default da ritornare in caso di assenza
     * del Piano dei Conti o altre condizioni di fallback in cui non c'è nulla da ripartire.
     *
     * @return array
     */
    private function empty(): array
    {
        return [
            'capitoli'        => [],
            'righe'           => [],
            'gran_totale'     => 0,
            'tot_per_capitolo'=> [],
        ];
    }
}
