<?php

namespace App\Services;

use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\PianoRate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servizio per il calcolo delle quote di spesa/entrata per ogni gestione.
 *
 * VERSION: 1.9.6 (CASCADE RESOLUTION + SCOPERTO BUCKET)
 *
 * =========================================================================
 * ARCHITETTURA DEL RULE ENGINE (Motore di Ripartizione)
 * =========================================================================
 * Livello 1: OVERRIDE STRUTTURALE (immobile_id NOT NULL su riga_fattura).
 * Livello 2: REGOLA NATURA SPESA (conto_id NOT NULL su riga_fattura).
 * Livello 3: FALLBACK LEGALE (Soggetto Pagatore).
 * Livello 4: PENNY-PERFECT ALGORITHM.
 */
class CalcoloQuoteService
{
    private ?Gestione $gestioneCorrente = null;
    private array $pivotOverrides = [];
    private ?\Carbon\Carbon $pianoRateCreatedAt = null;
    
    /** @var array Accumulatore per le quote non assegnabili per mancanza di anagrafiche attive */
    private array $scopertiAccumulati = [];

    /** Unità che hanno versato più di quanto la spesa richiedeva loro. */
    private array $eccedenzeCopertura = [];

    // =========================================================================
    // MOTORE ORDINARIO
    // =========================================================================

    /**
     * Calcola le quote per un'intera gestione (preventivo o rateizzazione).
     *
     * @param Gestione $gestione La gestione di riferimento
     * @param PianoRate|null $pianoRate Opzionale piano rate per determinare il momento di validità degli overrides
     * @return array Quote calcolate, raggruppate per anagrafica_id e immobile_id
     */
    public function calcolaPerGestione(Gestione $gestione, ?PianoRate $pianoRate = null): array
    {
        $this->gestioneCorrente = $gestione;
        $this->pivotOverrides   = [];
        $this->pianoRateCreatedAt = $pianoRate?->created_at;
        $this->scopertiAccumulati = [];
        $this->eccedenzeCopertura = [];
        $totali = [];
        $pianoConto = $gestione->pianoConto;

        if (!$pianoConto) {
            Log::error("calcolaPerGestione: gestione ID={$gestione->id} non ha un piano dei conti associato.");
            return [];
        }

        $capitoliIds = [];
        if ($pianoRate) {
            $pianoRate->load('capitoli');
            foreach ($pianoRate->capitoli as $capitolo) {
                $capitoliIds[] = $capitolo->id;
                if (!is_null($capitolo->pivot->importo)) {
                    $this->pivotOverrides[$capitolo->id] = (int) $capitolo->pivot->importo;
                }
            }

            $this->guardiaSovraFinanziamentoGiaVersato($pianoRate);

            // [DIAG] Log capitoli senza override (importo pivot NULL)
            $senzaOverride = $pianoRate->capitoli->filter(fn($c) => is_null($c->pivot->importo));
            if ($senzaOverride->isNotEmpty()) {
                Log::debug("calcolaPerGestione: capitoli senza override (pivot.importo NULL)", [
                    'piano_rate_id' => $pianoRate->id,
                    'capitoli_senza_override' => $senzaOverride->pluck('nome', 'id')->toArray(),
                ]);
            }
        }

        $query = $pianoConto->conti()
            ->with([
                'tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                'tabelleMillesimali.ripartizioni',
                'sottoconti.tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                'sottoconti.tabelleMillesimali.ripartizioni',
                'sottoconti.sottoconti',
            ]);

        $contiImpegnatiIds = [];
        if (!empty($capitoliIds)) {
            $query->whereIn('id', $capitoliIds);
        } else {
            $query->whereNull('parent_id');
            if ($pianoRate) {
                $contiImpegnatiIds = \Illuminate\Support\Facades\DB::table('piano_rate_capitoli')
                    ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
                    ->where('piani_rate.gestione_id', $gestione->id)
                    ->where('piani_rate.attivo', true)
                    ->where('piani_rate.id', '!=', $pianoRate->id)
                    ->pluck('conto_id')
                    ->toArray();
            }
        }

        $conti = $query->get();

        // [DIAG] Log se non vengono trovati conti
        if ($conti->isEmpty()) {
            Log::warning("calcolaPerGestione: nessun conto trovato per il piano dei conti ID={$pianoConto->id}", [
                'piano_rate_id' => $pianoRate?->id,
                'capitoli_cercati' => $capitoliIds,
            ]);
            return [];
        }

        Log::info("=== INIZIO CALCOLO QUOTE ORDINARIO V1.9.6 ===", [
            'piano_rate_id' => $pianoRate?->id,
            'overrides'     => count($this->pivotOverrides),
            'conti_caricati' => $conti->count(),
        ]);

        $processatiIds = [];
        $this->processaConti($conti, $totali, $contiImpegnatiIds, $processatiIds);

        // [DIAG] Riepilogo delta: importo pianificato vs importo effettivamente distribuito
        $totaleOverrides   = array_sum($this->pivotOverrides);
        $totaleDistribuito = 0;
        foreach ($totali as $immobili) {
            foreach ($immobili as $importo) {
                $totaleDistribuito += $importo;
            }
        }
        $delta = $totaleOverrides - $totaleDistribuito;

        Log::info("Riepilogo Ripartizione", [
            'piano_rate_id'              => $pianoRate?->id,
            'importo_override_pianificato' => $totaleOverrides,
            'importo_effettivamente_distribuito' => $totaleDistribuito,
            'delta_non_ripartito'        => $delta,
            'soggetti_trovati'           => count($totali),
        ]);

        if ($delta !== 0) {
            Log::warning("calcolaPerGestione: delta non ripartito = {$delta} centesimi. Verificare tabelle millesimali.", [
                'piano_rate_id' => $pianoRate?->id,
            ]);
        }

        return $totali;
    }

    /**
     * Guardia di sicurezza: un conto con già-versato registrato non deve mai
     * essere finanziato in modo incoerente con `conto->importo`.
     *
     * Il netting proporzionale (`nettingGiaVersato`) presuppone che
     * `conto->importo` sia il vero fabbisogno totale, e che la somma delle
     * chiamate indipendenti sullo stesso conto non lo ecceda — è il caso
     * testato e corretto di acconto+saldo, dove i due pivot sommano
     * esattamente al budget. Due modi distinti in cui questa premessa può
     * saltare, entrambi verificati con un test reale prima di scrivere questa
     * guardia:
     *
     *   1. SOMMA CHE ECCEDE — es. un piano "base" da 100.000 più uno
     *      "integrativo" da 10.000 sullo stesso conto da 100.000: ogni
     *      chiamata calcola la propria quota contro lo STESSO denominatore
     *      statico, e la stessa copertura viene accreditata due volte —
     *      entrambi i piani chiedono zero invece di sommare ai 10.000 dovuti.
     *   2. BUDGET STANTIO — un conto rimasto a 100.000 mentre una fattura REALE
     *      da 110.000 è già stata registrata (lo sforo): un piano "solo per lo
     *      sforo" (pivot 10.000, il 10% del budget VECCHIO) fa calcolare al
     *      netting una quota del 10% sulla copertura, azzerando quei 10.000
     *      invece di chiederli — il primo caso cattura il pivot troppo
     *      GRANDE, questo cattura il budget troppo PICCOLO rispetto al vero
     *      speso, indipendentemente da quanto vale il pivot in sé.
     *
     * Si blocca PRIMA di generare, con un messaggio che dice cosa correggere,
     * sullo stesso principio di `ScopertiNonAccettatiException`: meglio un
     * errore esplicito che un buco silenzioso nello Stato Patrimoniale.
     *
     * @throws \RuntimeException
     */
    private function guardiaSovraFinanziamentoGiaVersato(PianoRate $pianoRate): void
    {
        foreach ($this->pivotOverrides as $contoId => $pivotImporto) {
            $haCopertura = ContributoVersato::where('target_type', Conto::class)
                ->where('target_id', $contoId)
                ->exists();

            if (!$haCopertura) {
                continue;
            }

            $conto = Conto::find($contoId);
            if (!$conto) {
                continue;
            }

            // Caso 2 — budget stantio: una fattura REALE già registrata (non
            // pregressa: stesso filtro già usato da FatturaPassivaController
            // per rilevare lo sforamento) supera conto->importo. Blocca a
            // prescindere dal pivot: qualunque frazione calcolata contro un
            // denominatore troppo piccolo è sbagliata, sia per eccesso che
            // per difetto.
            $spesoReale = (int) DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->where('righe_fattura.conto_id', $contoId)
                ->where('fatture_passive.is_pregresso', false)
                ->selectRaw('COALESCE(SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva), 0) as tot')
                ->value('tot');

            $budgetConto = max(abs((int) $conto->importo), $spesoReale);

            if ($spesoReale > abs((int) $conto->importo)) {
                throw new \RuntimeException(
                    "Impossibile generare: la voce di spesa \"{$conto->nome}\" ha un già-versato registrato, "
                    ."ma risulta una fattura reale (".number_format($spesoReale / 100, 2, ',', '.')." €) "
                    ."superiore al suo budget (".number_format(abs((int) $conto->importo) / 100, 2, ',', '.')." €). "
                    ."Aggiorna prima l'importo della voce al costo reale della spesa: altrimenti il già "
                    ."versato viene applicato contro un budget sbagliato, e la quota calcolata — qualunque "
                    ."sia il piano rate — non corrisponde a quanto realmente dovuto."
                );
            }

            // Caso 1 — somma che eccede: come prima di questa correzione.
            $altriPivot = (int) DB::table('piano_rate_capitoli')
                ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
                ->where('piano_rate_capitoli.conto_id', $contoId)
                ->where('piani_rate.attivo', true)
                ->where('piani_rate.id', '!=', $pianoRate->id)
                ->whereNotNull('piano_rate_capitoli.importo')
                ->sum('piano_rate_capitoli.importo');

            $totaleImpegnato = $altriPivot + $pivotImporto;

            if ($totaleImpegnato > $budgetConto) {
                throw new \RuntimeException(
                    "Impossibile generare: la voce di spesa \"{$conto->nome}\" ha un già-versato registrato, "
                    ."ma la somma degli importi richiesti dai piani rate attivi su questa voce ("
                    .number_format($totaleImpegnato / 100, 2, ',', '.')." €) supera il suo budget ("
                    .number_format($budgetConto / 100, 2, ',', '.')." €). "
                    ."Aggiorna prima l'importo della voce al fabbisogno reale, oppure disattiva uno dei piani "
                    ."rate in conflitto: altrimenti il già versato verrebbe conteggiato più volte e parte "
                    ."della spesa non verrebbe mai richiesta ai condòmini."
                );
            }
        }
    }

    // =========================================================================
    // MOTORE STRAORDINARIO
    // =========================================================================

    /**
     * Calcola le quote per una rateizzazione di fatture straordinarie.
     *
     * @param PianoRate $pianoRate Il piano rate contenente le fatture straordinarie
     * @return array Quote calcolate, raggruppate per anagrafica_id e immobile_id
     */
    public function calcolaDaFattureStraordinarie(PianoRate $pianoRate): array
    {
        $this->scopertiAccumulati = [];
        $this->eccedenzeCopertura = [];

        // Carichiamo le fatture col pivot: importo_collegato è la quota della
        // fattura effettivamente finanziata da QUESTO piano (residuo/split).
        $fatture = $pianoRate->fattureStraordinarie()->get();

        if ($fatture->isEmpty()) {
            throw new \RuntimeException(
                "Piano straordinario ID {$pianoRate->id} non ha fatture collegate. Impossibile calcolare le quote."
            );
        }

        $totali             = [];
        $righeElaborate     = 0;
        $copertureElaborate = 0;

        // Accumulatore per conto: più fatture (o più componenti della stessa
        // fattura) possono puntare allo STESSO conto imprevisto — due sopravvenienze
        // pregresse sul medesimo capitolo, ad esempio. Il netting del già-versato
        // (dentro distribuisciSuTabelle) va applicato UNA sola volta per conto per
        // chiamata: chiamarlo una volta per componente lo sottrarrebbe più volte
        // nella stessa esecuzione, anche a monte di qualunque split fra piani.
        $importiPerConto = [];

        foreach ($fatture as $fattura) {

            // -----------------------------------------------------------------
            // 1. COMPONENTI STRAORDINARI DELLA FATTURA
            //    - Fattura corrente: SOLO righe imprevisto/ad personam
            //      (is_sopravvenienza OR immobile_id). Le righe ordinarie
            //      (capitolo a preventivo) NON fanno parte dello straordinario.
            //      Stesso filtro usato dal carrello (FetchFattureStraordinarie 1a)
            //      e dalla marcatura is_rateizzata nel PianoRateController.
            //    - Fattura pregressa: nessuna riga_fattura → si usa la copertura
            //      'sopravvenienza' (fattura_coperture), agganciata a un Conto
            //      con tabelle millesimali (FetchFattureStraordinarie 1b).
            // -----------------------------------------------------------------
            $componenti = [];

            if ($fattura->is_pregresso) {
                $coperture = DB::table('fattura_coperture')
                    ->where('fattura_passiva_id', $fattura->id)
                    ->where('tipo_copertura', 'sopravvenienza')
                    ->whereNotNull('conto_id')
                    ->get();

                foreach ($coperture as $cop) {
                    $imp = abs((int) $cop->importo);
                    if ($imp === 0) continue;

                    $componenti[] = ['immobile_id' => null, 'conto_id' => (int) $cop->conto_id, 'importo' => $imp];
                    $copertureElaborate++;
                }
            } else {
                $righe = DB::table('righe_fattura')
                    ->where('fattura_passiva_id', $fattura->id)
                    ->where(function ($q) {
                        $q->where('is_sopravvenienza', true)
                          ->orWhereNotNull('immobile_id');
                    })
                    ->get();

                foreach ($righe as $riga) {
                    $imp = abs((int) ($riga->importo_imponibile + $riga->importo_iva));
                    if ($imp === 0) continue;

                    if (is_null($riga->immobile_id) && is_null($riga->conto_id)) {
                        Log::warning("calcolaDaFattureStraordinarie: riga senza conto_id e senza immobile_id, saltata.", [
                            'riga_id'            => $riga->id,
                            'fattura_passiva_id' => $riga->fattura_passiva_id,
                        ]);
                        continue;
                    }

                    $componenti[] = [
                        'immobile_id' => is_null($riga->immobile_id) ? null : (int) $riga->immobile_id,
                        'conto_id'    => is_null($riga->conto_id) ? null : (int) $riga->conto_id,
                        'importo'     => $imp,
                    ];
                    $righeElaborate++;
                }
            }

            if (empty($componenti)) continue;

            // -----------------------------------------------------------------
            // 2. IMPORTO EFFETTIVO DA RIPARTIRE (rispetta importo_collegato)
            //    Un piano può finanziare solo una parte della fattura (residuo,
            //    split su più piani): importo_collegato è la quota reale a carico
            //    di QUESTO piano. Fallback difensivo al totale naturale se il
            //    pivot è mancante/0 (dati storici o piani ante-colonna) → in quel
            //    caso si distribuisce l'intero, esattamente come prima.
            // -----------------------------------------------------------------
            $naturale = array_sum(array_column($componenti, 'importo'));
            if ($naturale <= 0) continue;

            $collegato = (int) ($fattura->pivot->importo_collegato ?? 0);
            $target    = $collegato > 0 ? $collegato : $naturale;

            // Finanziamento intero (target == naturale): ogni componente mantiene
            // il suo importo esatto → identico alla distribuzione riga-per-riga.
            // Solo il finanziamento parziale attiva lo scaling penny-perfect.
            if ($target === $naturale) {
                $importiComponenti = array_column($componenti, 'importo');
            } else {
                $pesi = [];
                foreach ($componenti as $i => $c) {
                    $pesi[$i] = $c['importo'] / $naturale;
                }
                $importiComponenti = $this->distribuisciImporto($pesi, $target);
            }

            // -----------------------------------------------------------------
            // 3. ACCUMULO PER CONTO (l'addebito diretto invece resta immediato:
            //    non passa dal netting, ogni immobile ha la sua riga_fattura)
            // -----------------------------------------------------------------
            foreach ($componenti as $i => $c) {
                $importoComp = (int) ($importiComponenti[$i] ?? 0);
                if ($importoComp === 0) continue;

                if (!is_null($c['immobile_id'])) {
                    $this->addebitaDiretto($c['immobile_id'], $importoComp, $totali);
                    continue;
                }

                $importiPerConto[$c['conto_id']] = ($importiPerConto[$c['conto_id']] ?? 0) + $importoComp;
            }
        }

        // Distribuzione: UNA sola chiamata per conto su tutto il piano, sul totale
        // accumulato da tutte le fatture/componenti che lo riguardano.
        foreach ($importiPerConto as $contoId => $importoComp) {
            if ($importoComp === 0) continue;

            $conto = Conto::with([
                'tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                'tabelleMillesimali.ripartizioni',
            ])->find($contoId);

            if (!$conto) {
                Log::warning("calcolaDaFattureStraordinarie: conto_id={$contoId} non trovato, componente saltato.", [
                    'piano_rate_id' => $pianoRate->id,
                ]);
                continue;
            }

            $importoConto = in_array($conto->tipo, ['spesa', 'uscita'])
                ? $importoComp
                : -$importoComp;

            $this->distribuisciSuTabelle($conto, $importoConto, $totali);
        }

        Log::info("=== CALCOLO STRAORDINARIO COMPLETATO ===", [
            'piano_rate_id'       => $pianoRate->id,
            'fatture'             => $fatture->count(),
            'righe_elaborate'     => $righeElaborate,
            'coperture_pregresse' => $copertureElaborate,
            'soggetti_trovati'    => count($totali),
        ]);

        return $totali;
    }

    /**
     * @return array Dati relativi agli importi scoperti durante il calcolo.
     */
    public function getScoperti(): array
    {
        return $this->scopertiAccumulati;
    }

    // =========================================================================
    // METODI PRIVATI
    // =========================================================================

    /**
     * Esegue un addebito diretto forzato su un singolo immobile (es. addebito personale).
     *
     * @param int $immobileId L'ID dell'immobile
     * @param int $importoCents L'importo in centesimi
     * @param array &$totali Array in cui accumulare la quota
     */
    private function addebitaDiretto(int $immobileId, int $importoCents, array &$totali): void
    {
        $proprietari = DB::table('anagrafica_immobile')
            ->where('immobile_id', $immobileId)
            ->where('attivo', true)
            ->where('tipologia', 'proprietario')
            ->get();

        if ($proprietari->isEmpty()) {
            $proprietari = DB::table('anagrafica_immobile')
                ->where('immobile_id', $immobileId)
                ->where('attivo', true)
                ->get();
        }

        if ($proprietari->isEmpty()) {
            Log::warning("addebitaDiretto: nessun occupante attivo per immobile_id={$immobileId}. Importo {$importoCents} centesimi non assegnato.");
            return;
        }

        $totaleQuoteMillesimali = (float) $proprietari->sum('quota');
        if ($totaleQuoteMillesimali <= 0) $totaleQuoteMillesimali = 1.0;

        $assegnato = 0;
        $count     = $proprietari->count();
        $i         = 0;

        foreach ($proprietari as $prop) {
            $i++;
            if ($i === $count) {
                $quotaDaPagare = $importoCents - $assegnato;
            } else {
                $quotaDaPagare = (int) round($importoCents * ($prop->quota / $totaleQuoteMillesimali));
                $assegnato += $quotaDaPagare;
            }

            if ($quotaDaPagare === 0) continue;

            if (!isset($totali[$prop->anagrafica_id])) $totali[$prop->anagrafica_id] = [];
            if (!isset($totali[$prop->anagrafica_id][$immobileId])) $totali[$prop->anagrafica_id][$immobileId] = 0;

            $totali[$prop->anagrafica_id][$immobileId] += $quotaDaPagare;
        }
    }

    /**
     * Processa iterativamente una collezione di conti e calcola la ripartizione su ognuno.
     *
     * @param Collection $conti La collezione di conti da elaborare
     * @param array &$totali Array in cui accumulare i risultati
     */
    private function processaConti(Collection $conti, array &$totali, array $contiImpegnatiIds = [], array &$processatiIds = []): void
    {
        foreach ($conti as $conto) {
            if (in_array($conto->id, $contiImpegnatiIds)) continue;
            if (in_array($conto->id, $processatiIds)) continue;
            $processatiIds[] = $conto->id;

            $hasOverride = isset($this->pivotOverrides[$conto->id]);

            if ($hasOverride) {
                $importoOverride = $this->pivotOverrides[$conto->id];

                if ($conto->tabelleMillesimali->isNotEmpty()) {
                    $importoConto = in_array($conto->tipo, ['spesa', 'uscita'])
                        ? abs($importoOverride)
                        : -abs($importoOverride);

                    $this->distribuisciSuTabelle($conto, $importoConto, $totali);
                    continue;
                }

                elseif ($conto->sottoconti->isNotEmpty()) {

                    $sottocontiFiltrati = $this->pianoRateCreatedAt
                        ? $conto->sottoconti->filter(
                            fn($s) => $s->created_at->lte($this->pianoRateCreatedAt)
                        )
                        : $conto->sottoconti;

                    if ($sottocontiFiltrati->isEmpty() && $importoOverride > 0) {
                        Log::warning("processaConti: snapshot vuoto per conto ID={$conto->id} ('{$conto->nome}'), fallback su tutti i sottoconti correnti.", [
                            'importo_override' => $importoOverride,
                        ]);
                        $sottocontiFiltrati = $conto->sottoconti;
                    }

                    if ($sottocontiFiltrati->isEmpty()) continue;

                    $totaleOriginaleFigli = (int) $sottocontiFiltrati->sum('importo');
                    $totaleFigli  = $sottocontiFiltrati->count();
                    $sommaAssegnata = 0;
                    $counter = 0;

                    foreach ($sottocontiFiltrati as $figlio) {
                        $counter++;
                        if ($counter === $totaleFigli) {
                            $quotaFiglio = $importoOverride - $sommaAssegnata;
                        } elseif ($totaleOriginaleFigli > 0) {
                            $quotaFiglio = (int) round($importoOverride * ($figlio->importo / $totaleOriginaleFigli));
                        } else {
                            $quotaFiglio = (int) round($importoOverride / $totaleFigli);
                        }
                        $sommaAssegnata += $quotaFiglio;
                        $this->pivotOverrides[$figlio->id] = $quotaFiglio;
                    }

                    $this->processaConti($sottocontiFiltrati, $totali, $contiImpegnatiIds, $processatiIds);
                    continue;
                }

                // [DIAG] Silent discard intercettato — questo è il bug che causa quote_create:0
                Log::warning("processaConti: SILENT DISCARD — conto ID={$conto->id} ('{$conto->nome}') ha override ma nessuna tabella millesimale e nessun sottoconto.", [
                    'importo_override_ignorato_cents' => $importoOverride,
                    'conto_tipo'  => $conto->tipo,
                    'conto_nome'  => $conto->nome,
                ]);
                continue;
            }

            // Branch senza override: usa importo live del conto
            $importoLordo = (int) $conto->importo;

            if ($importoLordo !== 0) {
                $tipo = $conto->tipo ?? 'spesa';
                $importoConto = in_array($tipo, ['spesa', 'uscita'])
                    ? abs($importoLordo)
                    : -abs($importoLordo);

                $this->distribuisciSuTabelle($conto, $importoConto, $totali);
            }

            if ($conto->sottoconti && $conto->sottoconti->count() > 0) {
                $this->processaConti($conto->sottoconti, $totali, $contiImpegnatiIds, $processatiIds);
            }
        }
    }

    /**
     * Algoritmo core: Ripartisce l'importo di un conto sugli immobili collegati alle tabelle,
     * risolvendo la cascata del ruolo e mantenendo traccia degli scoperti.
     *
     * @param Conto $conto Conto di spesa da ripartire
     * @param int $importoConto Importo in centesimi
     * @param array &$totali Array in cui accumulare i risultati
     */
    private function distribuisciSuTabelle(Conto $conto, int $importoConto, array &$totali): void
    {
        $weights      = [];
        $pesiScoperti = [];

        // Nessuna tabella millesimale collegata al conto.
        //
        // Fino alla beta.32 qui c'era un `return` con un Log::warning: l'importo
        // spariva dal piano rate in silenzio — nessun errore, nessuno scoperto,
        // solo una riga nel file di log. I condòmini venivano addebitati di meno
        // e nessuno se ne accorgeva.
        //
        // Quell'importo è per definizione SCOPERTO: non è assegnabile a nessuno.
        // Va quindi nello stesso bucket delle quote senza destinatario, che già
        // blocca la generazione e chiede all'amministratore una motivazione
        // scritta per procedere. Nessun meccanismo nuovo: quello giusto esisteva
        // già venti righe più in basso.
        if ($conto->tabelleMillesimali->isEmpty()) {
            Log::warning("distribuisciSuTabelle: conto ID={$conto->id} ('{$conto->nome}') non ha tabelle millesimali collegate. Importo {$importoConto} registrato come scoperto.");

            if ($importoConto != 0) {
                $this->scopertiAccumulati[] = [
                    'immobile_id'     => null,   // l'intero capitolo, non una singola unità
                    'conto_id'        => $conto->id,
                    'tabella_id'      => null,
                    'ruolo_richiesto' => null,
                    'importo'         => abs($importoConto),
                    'motivo'          => 'conto_senza_tabella',
                ];
            }

            return;
        }

        foreach ($conto->tabelleMillesimali as $ctm) {
            $tabella = $ctm->tabella ?? null;

            if (!$tabella) {
                Log::warning("distribuisciSuTabelle: conto_tabella_millesimale ID={$ctm->id} non ha una tabella collegata.", [
                    'conto_id' => $conto->id,
                ]);
                continue;
            }

            $coeff = (float) $ctm->coefficiente;
            if ($coeff <= 0) {
                Log::debug("distribuisciSuTabelle: coefficiente <= 0 per tabella ID={$tabella->id}, conto ID={$conto->id}. Saltata.");
                continue;
            }

            $weightCoeff = $coeff / 100.0;
            $quote = $tabella->quote;

            // [DIAG] Tabella senza quote millesimali
            if ($quote->isEmpty()) {
                Log::warning("distribuisciSuTabelle: tabella millesimale ID={$tabella->id} ('{$tabella->nome}') non ha quote inserite. Importo non distribuito per conto ID={$conto->id}.", [
                    'conto_nome'   => $conto->nome,
                    'tabella_nome' => $tabella->nome,
                ]);
                continue;
            }

            $sommaValori = (float) $quote->sum('valore');

            // [DIAG] Somma millesimi = 0
            if ($sommaValori <= 0.0) {
                Log::warning("distribuisciSuTabelle: tabella ID={$tabella->id} ('{$tabella->nome}') ha quote ma la somma dei valori è zero. Verificare i millesimi inseriti.", [
                    'conto_id'    => $conto->id,
                    'conto_nome'  => $conto->nome,
                    'num_quote'   => $quote->count(),
                ]);
                continue;
            }

            foreach ($quote as $quota) {
                $immobile = $quota->immobile ?? null;

                if (!$immobile) {
                    Log::debug("distribuisciSuTabelle: quota ID={$quota->id} in tabella ID={$tabella->id} non ha immobile associato. Saltata.");
                    continue;
                }

                $valore = (float) $quota->valore;

                // [DIAG] Valore millesimale zero per immobile specifico
                if ($valore <= 0.0) {
                    Log::debug("distribuisciSuTabelle: immobile ID={$immobile->id} ha valore millesimale zero nella tabella ID={$tabella->id}. Saltato.");
                    continue;
                }

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

                    // Rule Engine Livello 3: Risoluzione a cascata del ruolo (catena per natura)
                    if ($anagrafiche->isEmpty() && $rip->soggetto !== 'proprietario') {
                        $catenaGodimento = ['inquilino', 'usufruttuario', 'proprietario'];
                        $catenaCapitale  = ['nuda_proprietario', 'proprietario'];

                        $catena = in_array($rip->soggetto, $catenaCapitale, true)
                            ? $catenaCapitale
                            : $catenaGodimento;

                        $start     = array_search($rip->soggetto, $catena, true);
                        $candidati = $start === false ? $catena : array_slice($catena, $start + 1);

                        foreach ($candidati as $ruoloFallback) {
                            $anagrafiche = $immobile->anagrafiche
                                ->where('pivot.attivo', true)
                                ->where('pivot.tipologia', $ruoloFallback);

                            if ($anagrafiche->isNotEmpty()) {
                                Log::debug("distribuisciSuTabelle: ruolo '{$rip->soggetto}' assente su immobile "
                                    . "ID={$immobile->id}, risolto a cascata su '{$ruoloFallback}'.");
                                break;
                            }
                        }
                    }

                    // Tracciamento e bucket dello scoperto se cascata esaurita
                    if ($anagrafiche->isEmpty()) {
                        $pesiScoperti[] = [
                            'immobile_id'     => $immobile->id,
                            'tabella_id'      => $tabella->id,
                            'ruolo_richiesto' => $rip->soggetto,
                            'peso'            => $weightRip,
                        ];
                        Log::warning("distribuisciSuTabelle: cascata esaurita — nessun soggetto "
                            . "per ruolo '{$rip->soggetto}' su immobile ID={$immobile->id}. "
                            . "Peso {$weightRip} tracciato come scoperto.", [
                            'conto_id'    => $conto->id,
                            'tabella_id'  => $tabella->id,
                            'immobile_id' => $immobile->id,
                        ]);
                        continue;
                    }

                    $sommaQuote = (float) $anagrafiche->sum('pivot.quota');
                    if ($sommaQuote <= 0.0) $sommaQuote = 1.0;

                    foreach ($anagrafiche as $anag) {
                        $quotaAnag = (float) $anag->pivot->quota;
                        if ($quotaAnag <= 0.0) continue;

                        $weightAnagrafica = $weightRip * ($quotaAnag / $sommaQuote);
                        $key = $anag->id . '|' . $immobile->id;
                        $weights[$key] = ($weights[$key] ?? 0.0) + $weightAnagrafica;
                    }
                }
            }
        }

        // Pesi finali vuoti: nessuna quota sarà generata per questo conto (se neanche pesiScoperti è popolato)
        if (empty($weights) && empty($pesiScoperti)) {
            Log::warning("distribuisciSuTabelle: nessun peso calcolato per conto ID={$conto->id} ('{$conto->nome}'). Importo {$importoConto} centesimi NON distribuito. Causa probabile: tabelle millesimali vuote o anagrafiche mancanti.", [
                'conto_id'    => $conto->id,
                'conto_nome'  => $conto->nome,
                'importo'     => $importoConto,
            ]);
            return;
        }

        $pesoSoggetti = array_sum($weights);
        $pesoScopertoTotale = array_sum(array_column($pesiScoperti, 'peso'));
        
        $pesoTotaleInclScoperto = $pesoSoggetti + $pesoScopertoTotale;
        
        if ($pesoTotaleInclScoperto <= 0.0) return;

        // Tracciatura degli importi scoperti
        if (!empty($pesiScoperti)) {
            foreach ($pesiScoperti as $ps) {
                $importoScoperto = (int) round(abs($importoConto) * ($ps['peso'] / $pesoTotaleInclScoperto));
                if ($importoScoperto > 0) {
                    $this->scopertiAccumulati[] = [
                        'immobile_id'     => $ps['immobile_id'],
                        'conto_id'        => $conto->id,
                        'tabella_id'      => $ps['tabella_id'],
                        'ruolo_richiesto' => $ps['ruolo_richiesto'],
                        'importo'         => $importoScoperto,
                    ];
                }
            }
        }

        if (empty($weights)) return; // Se tutto è scoperto, non procediamo con la distribuzione penny-perfect

        // Peso normalizzato solo sui soggetti reali; l'importo da distribuire è già al netto dello scoperto,
        // garantendo che la somma dei pesi dia un risultato congruo con l'importo decurtato.
        
        $importoDaDistribuirePennyPerfect = abs($importoConto);
        if ($pesoScopertoTotale > 0.0) {
            $totaleScopertoInt = (int) round(abs($importoConto) * ($pesoScopertoTotale / $pesoTotaleInclScoperto));
            $importoDaDistribuirePennyPerfect = abs($importoConto) - $totaleScopertoInt;
        }

        $importoContoSegno = $importoConto < 0 ? -$importoDaDistribuirePennyPerfect : $importoDaDistribuirePennyPerfect;

        foreach ($weights as $key => $w) {
            $weights[$key] = $w / $pesoSoggetti; // Qui normalizziamo a 1 per il penny-perfect sull'importo decurtato
        }

        $importiDistributi = $this->distribuisciImporto($weights, $importoContoSegno);

        // Sottrae quanto ciascuna unità ha GIÀ versato per questa voce: senza questo
        // passaggio una spesa già coperta in tutto o in parte verrebbe richiesta una
        // seconda volta (vedi docs/fondo_accantonato_e_quadratura_sp.md §4).
        $importiDistributi = $this->nettingGiaVersato($conto, $importiDistributi);

        foreach ($importiDistributi as $key => $importoCentesimi) {
            [$aid, $iid] = array_map('intval', explode('|', $key));

            if (!isset($totali[$aid])) $totali[$aid] = [];
            if (!isset($totali[$aid][$iid])) $totali[$aid][$iid] = 0;

            $totali[$aid][$iid] += $importoCentesimi;
        }
    }

    /**
     * Netting del già-versato: da ogni quota lorda sottrae la copertura che quella
     * UNITÀ ha già versato verso questa voce di spesa.
     *
     * La copertura è per immobile (segue l'unità, non la persona: art. 63 disp. att.
     * c.c.), quindi se l'unità ha più comproprietari va ripartita tra loro in
     * proporzione alle rispettive quote lorde, penny-perfect.
     *
     * La quota netta non scende mai sotto zero: l'eventuale eccedenza — l'unità ha
     * versato più di quanto le spetta — non viene inghiottita in silenzio ma
     * accumulata e resa leggibile da `getEccedenzeCopertura()`, perché è denaro dei
     * condòmini che va restituito o conguagliato.
     *
     * @param  array<string,int>  $importiDistributi  mappa "anagraficaId|immobileId" => centesimi lordi
     * @return array<string,int>  la stessa mappa, al netto delle coperture
     */
    private function nettingGiaVersato(Conto $conto, array $importiDistributi): array
    {
        $coperture = ContributoVersato::perImmobile(Conto::class, $conto->id);

        if ($coperture->isEmpty()) {
            return $importiDistributi;
        }

        // D8 (docs/fondo_accantonato_e_quadratura_sp.md): la copertura è per
        // IMMOBILE, non per soggetto. Su un conto con ripartizione mista
        // (proprietario/inquilino) viene sottratta dal lordo aggregato
        // dell'unità PRIMA che questo venga spaccato fra i soggetti — un
        // versamento del solo proprietario finisce per scontare anche
        // l'inquilino. Non bloccante (deciso di segnalare, non correggere): la
        // UI (ContributiEdit.vue) lo mostra già in fase di inserimento, qui
        // resta traccia anche lato motore, al momento in cui conta davvero.
        $haRipartizioneMista = $conto->tabelleMillesimali->contains(function ($ctm) {
            $rip = $ctm->ripartizioni;
            return $rip->isNotEmpty() && !($rip->count() === 1
                && $rip->first()->soggetto === 'proprietario'
                && (float) $rip->first()->percentuale === 100.0);
        });
        if ($haRipartizioneMista) {
            Log::warning("nettingGiaVersato: conto con ripartizione per soggetto (proprietario/inquilino) e copertura già-versato registrata — la copertura è per immobile e viene sottratta dal lordo aggregato prima della spaccatura per soggetto (D8).", [
                'conto_id' => $conto->id,
            ]);
        }

        // QUOTA di questa chiamata sul budget nominale del conto.
        //
        // Un capitolo può essere finanziato da PIÙ chiamate indipendenti: due
        // piani rate sullo stesso conto (acconto + saldo), o più fatture
        // straordinarie collegate allo stesso conto imprevisto. La copertura
        // storica in `contributi_versati` è il totale versato per l'INTERA voce,
        // non per questa singola chiamata: applicarla per intero ad ogni chiamata
        // la sottrarrebbe più volte, lasciando un residuo mai richiesto a
        // nessuno — vedi BucoBGiaVersatoDoppioPianoRateTest. Qui se ne applica
        // solo la quota proporzionale a quanto QUESTA chiamata rappresenta del
        // budget totale (conto->importo). Con una sola chiamata (il caso comune,
        // nessuna rateizzazione in più tranche) la quota è 1 e il comportamento
        // resta identico a prima di questa correzione.
        //
        // floor(), mai round(): un pareggio fra chiamate indipendenti nel tempo
        // (l'acconto oggi, il saldo fra un mese) non può ridistribuire un resto
        // come fa distribuisciImporto() dentro la STESSA chiamata. floor() sbaglia
        // sempre per difetto sulla copertura applicata: nel peggiore dei casi si
        // richiede qualche centesimo IN PIÙ del dovuto, mai in meno.
        $totaleNominale = abs((int) $conto->importo);
        $totaleQuestaChiamata = abs(array_sum($importiDistributi));
        $quota = ($totaleNominale > 0 && $totaleQuestaChiamata < $totaleNominale)
            ? $totaleQuestaChiamata / $totaleNominale
            : 1.0;

        // Raggruppa le righe per immobile: la copertura è dell'unità, non del soggetto.
        $righePerImmobile = [];
        foreach ($importiDistributi as $key => $importo) {
            [, $iid] = array_map('intval', explode('|', $key));
            $righePerImmobile[$iid][$key] = $importo;
        }

        // Copertura orfana: un'unità con un già-versato registrato ma che non
        // compare fra le righe distribuite (tipicamente perché la cascata di
        // risoluzione soggetto/ruolo non trova nessuna anagrafica attiva e
        // l'unità finisce nel bucket "scoperto"). La copertura non viene persa —
        // resta nel ledger per la prossima volta — ma qui non ha nulla da
        // scontare: se ne resta traccia solo nei log, senza bloccare nulla.
        foreach ($coperture as $immobileId => $importo) {
            if ($importo > 0 && !isset($righePerImmobile[$immobileId])) {
                Log::warning("nettingGiaVersato: copertura registrata su un'unità che non compare fra le righe distribuite (probabile unità scoperta, senza anagrafiche attive).", [
                    'conto_id'      => $conto->id,
                    'immobile_id'   => $immobileId,
                    'importo_cents' => $importo,
                ]);
            }
        }

        foreach ($righePerImmobile as $immobileId => $righe) {
            $coperturaStorica = (int) ($coperture[$immobileId] ?? 0);

            if ($coperturaStorica <= 0) {
                continue;
            }

            $lordoImmobile = array_sum($righe);

            // Su una quota negativa (nota di credito) il netting non si applica.
            if ($lordoImmobile <= 0) {
                continue;
            }

            $copertura = $quota >= 1.0 ? $coperturaStorica : (int) floor($coperturaStorica * $quota);
            $applicata = min($copertura, $lordoImmobile);

            if ($copertura > $lordoImmobile) {
                $this->eccedenzeCopertura[] = [
                    'immobile_id' => $immobileId,
                    'conto_id'    => $conto->id,
                    'versato'     => $copertura,
                    'dovuto'      => $lordoImmobile,
                    'eccedenza'   => $copertura - $lordoImmobile,
                ];
            }

            // Ripartisce la copertura tra i comproprietari in proporzione al lordo.
            //
            // Scaricare il resto per intero sull'ULTIMA riga (come prima di questa
            // correzione) può assegnarle più copertura di quanta ne possa assorbire
            // il suo stesso lordo, se le quote sono molto sbilanciate: es. lordo
            // immobile 10.000, comproprietari 1%/1%/98% (100/100/9.800), copertura
            // applicata 9.999 — al comproprietario che finisce per ultimo in
            // iterazione, col vecchio schema, tocca 9.999 − 9.898 = 101, contro un
            // suo lordo di soli 100: 1 centesimo di copertura "evapora" nel clamp
            // max(0, ...) invece di finire su un altro comproprietario che ha
            // ancora capienza. Qui ogni riga riceve al più il proprio lordo, e il
            // resto (mai negativo: $applicata ≤ $lordoImmobile per costruzione) va
            // — un centesimo alla volta — a chi ha ancora capienza, iniziando da
            // chi ha il resto frazionario più alto (stesso principio di
            // distribuisciImporto(), qui vincolato dalla capienza di ogni riga).
            $quote = [];
            $assegnato = 0;
            foreach ($righe as $key => $lordoRiga) {
                $base = (int) floor($applicata * $lordoRiga / $lordoImmobile);
                $quote[$key] = min($base, $lordoRiga);
                $assegnato += $quote[$key];
            }

            $resto = $applicata - $assegnato;
            if ($resto > 0) {
                $chiaviOrdinate = array_keys($righe);
                usort($chiaviOrdinate, function ($a, $b) use ($righe, $applicata, $lordoImmobile) {
                    $fracA = fmod($applicata * $righe[$a] / $lordoImmobile, 1);
                    $fracB = fmod($applicata * $righe[$b] / $lordoImmobile, 1);
                    return $fracB <=> $fracA;
                });

                foreach ($chiaviOrdinate as $key) {
                    if ($resto <= 0) break;
                    $capienza = $righe[$key] - $quote[$key];
                    if ($capienza <= 0) continue;
                    $incremento = min($capienza, $resto);
                    $quote[$key] += $incremento;
                    $resto -= $incremento;
                }
            }

            foreach ($righe as $key => $lordoRiga) {
                $importiDistributi[$key] = max(0, $lordoRiga - $quote[$key]);
            }
        }

        return $importiDistributi;
    }

    /**
     * Unità che hanno versato PIÙ di quanto la spesa richiedeva loro.
     *
     * @return array<int,array{immobile_id:int,conto_id:int,versato:int,dovuto:int,eccedenza:int}>
     */
    public function getEccedenzeCopertura(): array
    {
        return $this->eccedenzeCopertura;
    }

    /**
     * Distribuisce un importo totale basandosi su un array di pesi normalizzati,
     * garantendo una ripartizione "penny-perfect" senza perdita o creazione di centesimi.
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
}
