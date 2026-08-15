<?php

namespace App\Services;

use App\Enums\RuoloAnagraficaImmobile;
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
     * Chiave della pseudo-colonna che ospita gli importi di un soggetto **non più associato
     * all'unità**.
     *
     * Non è l'id di nessun conto — è una stringa proprio per non poter collidere con uno, come
     * `RipartoTabelleService::COLONNA_DIRETTO` per la stampa gemella.
     *
     * ## Perché esiste, e perché non poteva essere fatta diversamente
     *
     * Questa stampa costruiva le righe dai **pesi ricalcolati dal vivo sulla pivot**, mentre la
     * gemella le costruisce dalle quote **realmente emesse** in `rate_quote`. Un soggetto
     * dissociato dopo la generazione ha le sue quote in `rate_quote` e non ha più pesi: compariva
     * in un documento e spariva dall'altro. Non a zero — proprio assente, quindi le colonne
     * quadravano fra loro e nessuna invariante interna se ne accorgeva.
     *
     * ⚠️ **Non è un caso di laboratorio:** è il rimedio che gli amministratori usano oggi per il
     * subentro, perché il motore non legge le date di competenza — genero le rate, stacco il
     * vecchio proprietario, ristampo. Misurato sulla fixture `scenarioNudaProprieta()`: gran
     * totale 100000 centesimi per tabelle contro **40000** per capitoli.
     *
     * Le altre due strade erano chiuse, e per un motivo di dati, non di gusto. **Ripartire
     * l'importo orfano sui capitoli reali è impossibile:** `rate_quote.regole_calcolo` conserva
     * `audit`, `importi`, `origine`, `parametri` e `dettagli_saldo` — e in `importi` solo
     * `saldo_usato`, `totale_calcolato` e `quota_pura_gestione`. Il dettaglio per capitolo non è
     * persistito e non è ricostruibile a posteriori. **Lasciare la riga con le celle vuote**
     * romperebbe l'invariante «le celle sommano alla riga», che un altro test presidia.
     *
     * Resta questa: una colonna che dichiara ciò che quell'importo è davvero — denaro addebitato
     * a un soggetto che l'unità non ha più, che nessun capitolo può spiegare.
     */
    public const COLONNA_FUORI_RIPARTO = 'fuori_riparto';

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

        // Mappa ruolo → sigla.
        //
        // ⚠️ `nuda_proprietario` mancava, e il ripiego `strtoupper(substr(..., 0, 1))` più sotto
        // lo rendeva **«N»**: una sigla che la legenda del documento non contiene. La correzione
        // era già stata fatta nella gemella `RipartoTabelleService`, con il suo commento — qui no,
        // e una regola applicata a un servizio solo è il difetto che questa beta sta chiudendo in
        // tre punti diversi. Da quando il ruolo è registrabile (beta.43) può comparire davvero.
        $sigleRuolo = [
            'proprietario'      => 'P',
            'nuda_proprietario' => 'NP',
            'inquilino'         => 'I',
            'usufruttuario'     => 'U',
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

                // I modelli servono più avanti per i soggetti che **non** hanno pesi: senza,
                // l'assemblaggio li salterebbe con il suo `continue` su dizionario mancante,
                // perché `extractEntities()` raccoglie solo chi passa dai conti.
                if ($rq->immobile   && !isset($immobiliDict[$rq->immobile_id]))    $immobiliDict[$rq->immobile_id]      = $rq->immobile;
                if ($rq->anagrafica && !isset($anagraficheDict[$rq->anagrafica_id])) $anagraficheDict[$rq->anagrafica_id] = $rq->anagrafica;
            }
        }

        // ─── I soggetti addebitati che non hanno più peso ─────────────────────────────────
        //
        // Fin qui `$importiAssegnati` contiene **solo** chi ha un peso vivo sulla pivot. Chi è
        // stato dissociato dopo la generazione ha le sue quote in `rate_quote` e nessun peso:
        // senza questo blocco non riceve alcuna riga, e il suo importo sparisce dal documento
        // senza che nulla lo segnali. Vedi la nota su `COLONNA_FUORI_RIPARTO`.
        //
        // La riga si apre **vuota**: il riallineamento più sotto ci porta l'intero importo reale,
        // perché il residuo rispetto a un lordo di zero è l'importo stesso.
        //
        // La colonna si dichiara **qui e non nel ciclo**, benché serva solo a queste righe: le
        // celle si scrivono percorrendo `$capitoliInfo`, quindi una colonna dichiarata a metà
        // assemblaggio mancherebbe dalle righe già scritte. E il caso è noto in anticipo — una
        // chiave di `$importiAssegnati` ha per costruzione almeno un peso, quindi il bisogno
        // della pseudo-colonna coincide esattamente con l'esistenza di un orfano.
        foreach ($totaliReali as $aidReale => $perImmobile) {
            foreach ($perImmobile as $iidReale => $importoReale) {
                $chiave = $aidReale . '|' . $iidReale;

                if (isset($importiAssegnati[$chiave]) || $importoReale === 0) {
                    continue;
                }

                $importiAssegnati[$chiave] = [];
                $this->dichiaraColonnaFuoriRiparto($capitoliInfo, $totPerCapitolo);

                Log::warning('RipartoCapitoliService: soggetto addebitato senza peso sulla pivot, '
                    . 'riga ricostruita da rate_quote e importo portato in colonna «Fuori riparto». '
                    . 'Probabile titolare staccato dopo la generazione delle rate.', [
                        'piano_rate_id' => $pianoRate->id,
                        'anagrafica_id' => $aidReale,
                        'immobile_id'   => $iidReale,
                        'importo_cents' => $importoReale,
                    ]);
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
            // in produzione in RipartoTabelleService.
            //
            // ⚠️ **La guardia è sulla MATRICE, non sulla singola chiave, e la differenza vale
            // € 600,00 su € 1.000,00.** Scritta come `?? null` diceva «questo soggetto non ha
            // quote emesse, quindi lasciagli il lordo» — che è giusto solo quando il piano non è
            // stato generato affatto. A piano generato significa l'opposto: **quel soggetto non è
            // mai stato addebitato**, e il suo lordo ricalcolato dal vivo è un importo che nessuno
            // deve.
            //
            // Il caso concreto è il subentro fatto per intero: genero le rate, stacco il vecchio
            // proprietario, **attacco il subentrante**, ristampo. Il subentrante ha pesi vivi sulla
            // pivot e zero quote in `rate_quote`. Misurato sulla fixture `scenarioNudaProprieta()`:
            // rate emesse 100000 cent, stampa per capitoli **160000**.
            //
            // ⚠️ **E prima della beta.52 il difetto c'era già, nascosto da un secondo difetto.**
            // Il lordo fantasma del subentrante esisteva, ma era compensato dall'assenza della riga
            // dell'orfano — i due errori si annullavano e il gran totale tornava *per caso*.
            // Aggiungendo la riga dell'orfano senza toccare la metà speculare, questa beta li ha
            // fatti sommare. È il motivo per cui la revisione avversariale sta prima del racconto.
            //
            // La gemella non ha questo problema per costruzione: costruisce le righe **da**
            // `rate_quote`, quindi una riga senza quote emesse non può proprio esistere.
            $importoReale = $totaliReali[$aid][$iid] ?? (empty($totaliReali) ? null : 0);
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
                    } else {
                        // ⚠️ **Il soggetto non ha peso in nessun capitolo**, quindi non esiste un
                        // «capitolo a peso maggiore» su cui appoggiare il residuo: `arsort` su un
                        // vettore vuoto non ha una risposta, e prima di questa riga il residuo
                        // veniva semplicemente perso.
                        //
                        // È il soggetto dissociato del blocco qui sopra, dove il residuo coincide
                        // con l'intero importo addebitato. Va nella pseudo-colonna, che è già
                        // stata dichiarata in `$capitoliInfo` prima dell'assemblaggio.
                        $importiPerCapitolo[self::COLONNA_FUORI_RIPARTO] =
                            ($importiPerCapitolo[self::COLONNA_FUORI_RIPARTO] ?? 0) + $residuo;

                        $this->dichiaraColonnaFuoriRiparto($capitoliInfo, $totPerCapitolo);
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

        // Peso che nessun soggetto può ricevere perché la cascata dei ruoli si è esaurita.
        // Si raccoglie per poterlo dire nei log, non per toglierlo dall'importo: vedi la nota
        // estesa al punto in cui viene accumulato.
        $pesoScopertoTotale = 0.0;

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

                    // Cascata dei ruoli, allineata al motore e all'altra stampa (beta.51).
                    //
                    // La beta.49 aveva corretto questo stesso difetto in RipartoTabelleService e
                    // aveva lasciato indietro il gemello: delle due stampe che vanno in assemblea
                    // ne è stata sistemata una sola. Qui la condizione era
                    // `if ($anagrafiche->isEmpty() && $rip->soggetto !== 'proprietario')`, cioè la
                    // cascata veniva saltata **proprio per il proprietario** — che è il caso della
                    // nuda proprietà. Su un'unità con nudo proprietario e usufruttuario, e senza
                    // `proprietario` attivo, il motore risolveva la cascata e addebitava il nudo
                    // proprietario, mentre questa stampa cadeva sul `continue` qui sotto e faceva
                    // sparire l'unità dal documento: il riparto portato in assemblea non
                    // coincideva con quello addebitato.
                    //
                    // Le due catene scritte a mano erano anche incomplete per conto loro: quella
                    // di godimento finiva su `proprietario` e non arrivava mai a
                    // `nuda_proprietario`. `catenaRipiego` è la stessa sorgente che usano
                    // CalcoloQuoteService:835 e RipartoTabelleService:562 — tre copie della regola
                    // erano già due di troppo.
                    if ($anagrafiche->isEmpty()) {
                        $candidati = RuoloAnagraficaImmobile::catenaRipiego($rip->soggetto);

                        foreach ($candidati as $ruoloFallback) {
                            $anagrafiche = $immobile->anagrafiche
                                ->where('pivot.attivo', true)
                                ->where('pivot.tipologia', $ruoloFallback->value);
                            if ($anagrafiche->isNotEmpty()) break;
                        }
                    }

                    // ⚠️ **Cascata esaurita: qui c'era un `continue` nudo.** È la metà del blocco
                    // A2 che la beta.51 non ha chiuso — le liste scritte a mano erano già state
                    // sostituite da `catenaRipiego()`, il tracciamento no. Le altre due copie lo
                    // fanno da tempo: `CalcoloQuoteService:851-866` con il suo bucket, e
                    // `RipartoTabelleService:573-576` con l'accumulo.
                    //
                    // Il peso non si può addebitare a nessuno, e questo è corretto: non esiste il
                    // soggetto a cui il coefficiente punta, e nemmeno un ripiego. Ciò che non era
                    // corretto è che **sparisse in silenzio**, senza lasciare traccia né nei log
                    // né altrove — su un documento che va in assemblea, un importo che evapora e
                    // uno che non è mai esistito hanno lo stesso aspetto.
                    //
                    // ⚠️ **Si traccia e non si decurta**, di proposito: l'importo che arriva qui
                    // dal registro del motore è **già al netto** degli scoperti. Rifare la
                    // decurtazione la applicherebbe due volte, ed è l'avvertimento scritto in
                    // `RipartoTabelleService:601-607` — mantenere una seconda copia
                    // dell'aritmetica è precisamente il difetto da cui nasce la coda ⑩.
                    if ($anagrafiche->isEmpty()) {
                        $pesoScopertoTotale += $pesoImmobile * ($percent / 100.0);

                        Log::warning("RipartoCapitoliService: cascata esaurita — nessun soggetto "
                            . "per ruolo '{$rip->soggetto}' su immobile ID={$immobile->id}. "
                            . "Peso tracciato come scoperto, non addebitato.", [
                                'conto_id'    => $conto->id,
                                'tabella_id'  => $tabella->id,
                                'immobile_id' => $immobile->id,
                                'ruolo_richiesto' => $rip->soggetto,
                            ]);

                        continue;
                    }

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

        // Il riepilogo dello scoperto sul capitolo, che è il numero azionabile: i log riga per
        // riga dicono *dove*, questo dice *quanto*. Senza, l'accumulatore sarebbe una variabile
        // scritta e mai letta — cioè lo stesso difetto che questa beta sta togliendo altrove.
        if ($pesoScopertoTotale > 0.0) {
            Log::warning(sprintf(
                "RipartoCapitoliService: capitolo '%s' (ID=%d) ha un peso complessivo scoperto di "
                . "%.6f — quota di riparto che nessun soggetto può ricevere. L'importo stampato è "
                . "già al netto (lo decurta il motore): questa riga serve a sapere che esiste.",
                $radice->nome,
                $radiceId,
                $pesoScopertoTotale
            ), [
                'conto_id'   => $conto->id,
                'radice_id'  => $radiceId,
                'importo'    => $importo,
            ]);
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
     * Dichiara la pseudo-colonna «Fuori riparto», una volta sola.
     *
     * Tiene insieme `$capitoliInfo` e `$totPerCapitolo`, che l'assemblaggio percorre in parallelo:
     * `$totPerCapitolo` nasce da `array_fill_keys(array_keys($capitoliInfo), 0)` **prima** del
     * ciclo, quindi una colonna aggiunta al primo senza il secondo produrrebbe un indice non
     * definito al primo importo che ci finisce dentro.
     *
     * `senza_quote` come nella gemella: la colonna non ha una dimensione di riparto, e la
     * sotto-colonna delle quote resta vuota invece di mostrare un totale «0» che sarebbe un
     * numero finto su un documento che va in assemblea.
     */
    private function dichiaraColonnaFuoriRiparto(array &$capitoliInfo, array &$totPerCapitolo): void
    {
        if (isset($capitoliInfo[self::COLONNA_FUORI_RIPARTO])) {
            return;
        }

        $capitoliInfo[self::COLONNA_FUORI_RIPARTO] = [
            'nome'        => 'Fuori riparto',
            'quota_label' => '—',
            'quota_tipo'  => null,
            'decimali'    => 0,
            'tot_importo' => 0,
            'senza_quote' => true,
        ];

        $totPerCapitolo[self::COLONNA_FUORI_RIPARTO] = 0;
    }

    /**
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
