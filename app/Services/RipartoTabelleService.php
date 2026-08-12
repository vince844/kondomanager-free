<?php

namespace App\Services;

use App\Enums\RuoloAnagraficaImmobile;
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
     * Chiave della pseudo-colonna che ospita gli addebiti ad personam.
     *
     * Non è l'id di nessuna tabella — è una stringa proprio per non poter collidere con uno.
     */
    public const COLONNA_DIRETTO = 'diretto';

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

        // ─── Quanto va su ogni capitolo: **lo dice il motore** ─────────────────────────
        //
        // ⚠️ Qui c'erano venti righe che se lo ricalcolavano da sole, leggendo `righe_fattura`.
        // Erano un rimpiazzo ingenuo di `calcolaDaFattureStraordinarie()`, e sbagliavano quattro
        // cose — tutte visibili sul documento che va in assemblea:
        //
        // - prendevano **tutte** le righe con un conto, comprese le ordinarie, che dello
        //   straordinario non fanno parte: il PDF mostrava capitoli che le rate non contenevano;
        // - sommavano `imponibile + iva`, ignorando `importo_collegato`: un piano che finanzia
        //   € 400,00 di una fattura da € 1.000,00 stampava il riparto di tutti e mille;
        // - non conoscevano `fattura_coperture`, e una fattura **pregressa** — che non ha righe —
        //   produceva un **foglio bianco** a fronte di rate emesse correttamente. È lo scenario
        //   della migrazione dello storico;
        // - scartavano le righe **ad personam**, o peggio, se avevano anche un conto, le
        //   spalmavano su tutta la tabella millesimale.
        //
        // Il confine ora è netto: il motore risponde a «quanto», questo servizio solo a «a chi»,
        // che è la parte che deve saper spaccare per colonna — cosa che il motore non fa mai,
        // perché aggrega tutto per anagrafica e immobile.
        //
        // `soloLettura`: la stampa non deve poter essere bloccata da una guardia di
        // *generazione*. Un riparto già emesso si ristampa anche se i dati sono cambiati dopo.
        $motore = app(CalcoloQuoteService::class);

        if ($pianoRate->tipo === 'straordinario' && $pianoRate->fatture()->exists()) {
            $motore->calcolaDaFattureStraordinarie($pianoRate);
        } else {
            $motore->calcolaPerGestione($gestione, $pianoRate, soloLettura: true);
        }

        // Già al netto della decurtazione degli scoperti: è la ragione per cui più sotto questo
        // servizio non la rifà. Mantenerne una seconda copia è il difetto da cui nasce la voce ⑩.
        $pivotOverrides = $motore->getImportiPerConto();
        $capitoliIds    = array_keys($pivotOverrides);

        // Gli addebiti ad personam non appartengono a nessuna tabella: viaggiano a parte.
        $addebitiDiretti = $motore->getAddebitiDiretti();

        if (empty($capitoliIds) && empty($addebitiDiretti)) {
            return $this->empty();
        }

        // ─── 2. Carica tutti i conti foglia con tabelle ───────────────────────
        $queryConti = Conto::with([
            'tabelleMillesimali.tabella.quote.immobile.anagrafiche',
            'tabelleMillesimali.ripartizioni',
            'sottoconti.tabelleMillesimali.tabella.quote.immobile.anagrafiche',
            'sottoconti.tabelleMillesimali.ripartizioni',
        ])->where('piano_conto_id', $pianoConto->id);

        // Si disegnano **esattamente** i conti che il motore ha distribuito, e nessun altro.
        //
        // Prima qui c'erano due rami: uno per il piano con capitoli espliciti e uno «catch-all»
        // che ricaricava tutti i conti radice e riescludeva a mano quelli impegnati da altri
        // piani attivi — cioè una terza copia di una regola che il motore applica già
        // (`CalcoloQuoteService:117-131`). Ora il registro porta solo i conti realmente
        // ripartiti, quindi la selezione è una sola riga e non c'è più niente da tenere
        // allineato. Con il registro vuoto siamo già usciti sopra.
        $contiImpegnatiIds = [];
        $conti = empty($capitoliIds)
            ? new Collection()
            : $queryConti->whereIn('id', $capitoliIds)->get();

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

        // ─── Gli addebiti ad personam, che nessuna tabella può ospitare ────────────────
        //
        // Una spesa con `immobile_id` non passa dai millesimi: la paga chi ha quell'unità. Non ha
        // quindi una colonna «tabella», e prima veniva semplicemente **persa** — o, se la riga
        // aveva anche un conto, spalmata su tutti.
        //
        // Vive in una pseudo-colonna con chiave `'diretto'`, che il resto del codice tratta come
        // una tabella qualunque: così le due invarianti del documento (le celle sommano alla
        // riga, le colonne sommano al gran totale) restano vere senza casi speciali.
        $cellePerSoggetto = [];
        foreach ($addebitiDiretti as $addebito) {
            $chiave = $addebito['anagrafica_id'] . '|' . $addebito['immobile_id'];
            $cellePerSoggetto[$chiave] = ($cellePerSoggetto[$chiave] ?? 0) + $addebito['importo'];
        }

        if (! empty($cellePerSoggetto)) {
            $cells[self::COLONNA_DIRETTO] = $cellePerSoggetto;
            $tabelleInfo[self::COLONNA_DIRETTO] = [
                'nome'        => 'Addebito diretto',
                'quota_label' => '—',
                'quota_tipo'  => null,
                'decimali'    => 0,
                // Non ha una dimensione di riparto: la sotto-colonna delle quote resta vuota
                // invece di mostrare «quote» e un totale «0», che sarebbero due numeri finti su
                // un documento che va in assemblea.
                'senza_quote' => true,
            ];
        }

        if (empty($weights) && empty($cellePerSoggetto)) {
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

        // Mappa ruolo → sigla.
        //
        // `nuda_proprietario` mancava, e il ripiego `strtoupper(substr(..., 0, 1))` di :221 lo
        // rendeva **«N»**: una sigla che nella legenda del documento non esiste. Da quando il
        // ruolo è registrabile (beta.43) può comparire davvero in assemblea.
        $sigleRuolo = [
            'proprietario'      => 'P',
            'nuda_proprietario' => 'NP',
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

        // Ordina soggetti per ruolo (proprietario prima).
        //
        // `nuda_proprietario` cadeva sul `?? 9` e finiva **dopo l'inquilino**. Sta invece
        // accanto al proprietario: è lo stesso soggetto economico con l'usufrutto staccato,
        // ed è la lettura che l'enum già dichiara facendoli terminale l'uno dell'altro.
        $ordineRuoli = ['proprietario' => 0, 'nuda_proprietario' => 1, 'usufruttuario' => 2, 'inquilino' => 3];
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
                    'decimali'    => $tabella->numero_decimali ?? 2,
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

                    // Cascata ruolo — **la stessa catena del motore**, dall'enum.
                    //
                    // ⚠️ Fino alla beta.49 qui c'erano due catene riscritte a mano e la condizione
                    // `&& $rip->soggetto !== 'proprietario'`, che `CalcoloQuoteService` aveva
                    // perso con la beta.43. Il commento sopra dichiarava «identica a
                    // CalcoloQuoteService» ed era falso da undici giorni — la lezione è che un
                    // commento non tiene allineate due copie: le tiene allineate una funzione
                    // sola, e infatti ora la catena la scrive solo `catenaRiparto()`.
                    //
                    // Cosa cambiava a video: su un'unità con nuda proprietà e usufrutto, senza
                    // proprietario attivo e con il coefficiente sul `proprietario`, il motore
                    // risolveva la cascata e **addebitava il nudo proprietario**, mentre la
                    // stampa cadeva nel ramo scoperto e lo mostrava a zero. Il riparto portato in
                    // assemblea non coincideva con quello addebitato.
                    //
                    // Le due catene scritte a mano erano anche incomplete per conto loro: quella
                    // di godimento finiva su `proprietario` e non arrivava mai a
                    // `nuda_proprietario`, e per un `soggetto` fuori catalogo ripiegava
                    // sull'intera catena di godimento invece che sul terminale legale.
                    if ($anagrafiche->isEmpty()) {
                        $candidati = RuoloAnagraficaImmobile::catenaRipiego($rip->soggetto);

                        foreach ($candidati as $ruoloFallback) {
                            $anagrafiche = $immobile->anagrafiche
                                ->where('pivot.attivo', true)
                                ->where('pivot.tipologia', $ruoloFallback->value);
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

        // ⚠️ **Qui c'era la decurtazione degli scoperti, ed è stata cancellata**: l'importo che
        // arriva dal registro del motore è già al netto. Rifarla significherebbe applicarla due
        // volte, e soprattutto significherebbe mantenere allineata una seconda copia
        // dell'aritmetica — che è precisamente il difetto da cui nasce la coda ⑩.
        //
        // Resta invece la raccolta di `$pesoScopertoTotale` qui sopra: serve a normalizzare i
        // pesi sui soli soggetti reali, non a togliere denaro.
        $pesoSoggetti = array_sum($wMerged);
        if ($pesoSoggetti <= 0.0) return;

        $importoContoSegno = $importoConto;

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
