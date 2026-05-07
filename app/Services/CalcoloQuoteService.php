<?php

namespace App\Services;

use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servizio per il calcolo delle quote di spesa/entrata per ogni gestione.
 * * VERSION: 1.9.5 (RIPARTIZIONE MISTA & FIX SNAPSHOT SOTTOCONTI)
 * * =========================================================================
 * ARCHITETTURA DEL RULE ENGINE (Motore di Ripartizione)
 * =========================================================================
 * Il calcolo segue una gerarchia di precedenza stretta e deterministica:
 * * Livello 1: OVERRIDE STRUTTURALE (immobile_id NOT NULL su riga_fattura).
 * Blocca le tabelle millesimali. Il 100% dell'importo viene 
 * addebitato all'immobile specificato (Gestito da addebitaDiretto).
 * * Livello 2: REGOLA NATURA SPESA (conto_id NOT NULL su riga_fattura).
 * Se l'immobile_id è NULL, il sistema recupera il conto di budget
 * e applica le tabelle millesimali e le ripartizioni (Es. 50% Prop, 50% Inq)
 * configurate a monte (Gestito da distribuisciSuTabelle).
 * * Livello 3: FALLBACK LEGALE (Soggetto Pagatore).
 * Se la regola di Livello 2 impone il pagamento all'inquilino, ma 
 * l'immobile ne è sprovvisto, il debito rimbalza automaticamente 
 * al proprietario (Solidarietà condominiale).
 * * Livello 4: PENNY-PERFECT ALGORITHM.
 * In caso di comproprietà (es. 2 proprietari al 50%), gli arrotondamenti
 * sui centesimi vengono compensati assegnando l'eventuale centesimo
 * rimanente all'ultimo soggetto della lista, garantendo la quadratura
 * algebrica perfetta (DARE = AVERE).
 */
class CalcoloQuoteService
{
    private ?Gestione $gestioneCorrente = null;
    private array $pivotOverrides = [];
    private ?\Carbon\Carbon $pianoRateCreatedAt = null;

    /**
     * Motore per Piani Ordinari.
     * Legge gli importi previsti dal Piano dei Conti (Budget Preventivo)
     * o le sovrascritture manuali (overrides) impostate in fase di creazione rate.
     *
     * @param Gestione $gestione La gestione corrente di riferimento.
     * @param PianoRate|null $pianoRate Il piano rate da elaborare (opzionale).
     * @return array Array associativo dei totali raggruppati per [anagrafica_id][immobile_id].
     */
    public function calcolaPerGestione(Gestione $gestione, ?PianoRate $pianoRate = null): array
    {
        $this->gestioneCorrente = $gestione;
        $this->pivotOverrides = [];
        $this->pianoRateCreatedAt = $pianoRate?->created_at;
        $totali = [];
        $pianoConto = $gestione->pianoConto;

        if (!$pianoConto) return [];

        $capitoliIds = [];
        if ($pianoRate) {
            $pianoRate->load('capitoli');
            foreach ($pianoRate->capitoli as $capitolo) {
                $capitoliIds[] = $capitolo->id;
                if (!is_null($capitolo->pivot->importo)) {
                    $this->pivotOverrides[$capitolo->id] = (int) $capitolo->pivot->importo;
                }
            }
        }

        // EAGER LOADING PROFONDO: carichiamo le tabelle millesimali anche per i sottoconti
        // per prevenire N+1 queries (o Fatal Error con lazy loading disabilitato in L10/11)
        // quando processaConti() scende ricorsivamente nei figli.
        $query = $pianoConto->conti()
            ->with([
                'tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                'tabelleMillesimali.ripartizioni',
                'sottoconti.tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                'sottoconti.tabelleMillesimali.ripartizioni',
                'sottoconti.sottoconti',
            ]);

        if (!empty($capitoliIds)) {
            $query->whereIn('id', $capitoliIds);
        } else {
            $query->whereNull('parent_id');
        }

        $conti = $query->get();

        Log::info("=== INIZIO CALCOLO QUOTE ORDINARIO V1.9.5 ===", [
            'piano_rate_id' => $pianoRate?->id,
            'overrides' => count($this->pivotOverrides)
        ]);

        $this->processaConti($conti, $totali);

        return $totali;
    }

    /**
     * FEATURE 2 — RIPARTIZIONE MISTA (Motore per Piani Straordinari).
     * Legge direttamente le righe fattura collegate al piano, bypassando il budget.
     * Implementa la regola dell'Override Individuale.
     *
     * @param PianoRate $pianoRate Il piano rate straordinario da calcolare.
     * @return array Array associativo dei totali raggruppati per [anagrafica_id][immobile_id].
     * @throws \RuntimeException Se il piano straordinario non ha fatture collegate.
     */
    public function calcolaDaFattureStraordinarie(PianoRate $pianoRate): array
    {
        $fattureIds = $pianoRate->fatture->pluck('id')->toArray();

        if (empty($fattureIds)) {
            throw new \RuntimeException(
                "Piano straordinario ID {$pianoRate->id} non ha fatture collegate. Impossibile calcolare le quote."
            );
        }

        $righe = DB::table('righe_fattura')
            ->whereIn('fattura_passiva_id', $fattureIds)
            ->get();

        $totali = [];

        foreach ($righe as $riga) {
            $importoCents = abs($riga->importo_imponibile + $riga->importo_iva);

            if ($importoCents === 0) continue;

            // RULE ENGINE: Livello 1 (Override Strutturale Ad Personam)
            if (!is_null($riga->immobile_id)) {
                $this->addebitaDiretto((int) $riga->immobile_id, $importoCents, $totali);
            } else {
                
                // RULE ENGINE: Livello 2 (Regola Natura Spesa / Millesimi)
                if (is_null($riga->conto_id)) {
                    Log::warning("calcolaDaFattureStraordinarie: riga senza conto_id e senza immobile_id, saltata.", [
                        'riga_id'            => $riga->id,
                        'fattura_passiva_id' => $riga->fattura_passiva_id,
                    ]);
                    continue;
                }

                $conto = Conto::with([
                    'tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                    'tabelleMillesimali.ripartizioni',
                ])->find($riga->conto_id);

                if (!$conto) {
                    Log::warning("calcolaDaFattureStraordinarie: conto_id={$riga->conto_id} non trovato, riga saltata.");
                    continue;
                }

                // Il segno segue il tipo del conto (spesa = positivo)
                $importoConto = in_array($conto->tipo, ['spesa', 'uscita'])
                    ? $importoCents
                    : -$importoCents;

                $this->distribuisciSuTabelle($conto, $importoConto, $totali);
            }
        }

        Log::info("=== CALCOLO STRAORDINARIO COMPLETATO ===", [
            'piano_rate_id'    => $pianoRate->id,
            'fatture_ids'      => $fattureIds,
            'righe_elaborate'  => $righe->count(),
            'soggetti_trovati' => count($totali),
        ]);

        return $totali;
    }

    /**
     * Applica l'addebito diretto al 100% sull'immobile (Override),
     * dividendo la quota solo tra gli eventuali comproprietari, applicando
     * l'algoritmo Penny-Perfect per evitare scompensi centesimali.
     *
     * @param int $immobileId L'ID dell'immobile a cui addebitare direttamente la spesa.
     * @param int $importoCents L'importo totale da addebitare (in centesimi).
     * @param array &$totali Array associativo passato per riferimento per accumulare le quote.
     * @return void
     */
    private function addebitaDiretto(int $immobileId, int $importoCents, array &$totali): void
    {
        // 1. Cerchiamo i proprietari attivi
        $proprietari = DB::table('anagrafica_immobile')
            ->where('immobile_id', $immobileId)
            ->where('attivo', true)
            ->where('tipologia', 'proprietario')
            ->get();

        // 2. Fallback: Qualsiasi occupante attivo se non ci sono proprietari
        if ($proprietari->isEmpty()) {
            $proprietari = DB::table('anagrafica_immobile')
                ->where('immobile_id', $immobileId)
                ->where('attivo', true)
                ->get();
        }

        // Se l'immobile è davvero un fantasma, saltiamo e logghiamo l'errore grave
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

            // Penny-perfect algorithm: L'ultimo comproprietario assorbe il residuo esatto
            if ($i === $count) {
                $quotaDaPagare = $importoCents - $assegnato;
            } else {
                $quotaDaPagare = (int) round($importoCents * ($prop->quota / $totaleQuoteMillesimali));
                $assegnato += $quotaDaPagare;
            }

            if ($quotaDaPagare === 0) continue;

            // Inizializza l'array se non esiste per evitare warning
            if (!isset($totali[$prop->anagrafica_id])) {
                $totali[$prop->anagrafica_id] = [];
            }
            if (!isset($totali[$prop->anagrafica_id][$immobileId])) {
                $totali[$prop->anagrafica_id][$immobileId] = 0;
            }

            $totali[$prop->anagrafica_id][$immobileId] += $quotaDaPagare;
        }
    }

    // =========================================================================
    // METODI PRIVATI CONDIVISI (usati da entrambi i motori per il calcolo standard)
    // =========================================================================

    /**
     * Processa ricorsivamente una collezione di conti e sottoconti,
     * applicando eventuali override (valori pivot congelati) o usando il preventivo live,
     * e distribuendo gli importi calcolati sulle tabelle millesimali.
     *
     * @param Collection $conti Collezione di oggetti Conto da elaborare.
     * @param array &$totali Array associativo passato per riferimento per accumulare le quote.
     * @return void
     */
    private function processaConti(Collection $conti, array &$totali): void
    {
        foreach ($conti as $conto) {
            
            $hasOverride = isset($this->pivotOverrides[$conto->id]);
            
            if ($hasOverride) {
                $importoOverride = $this->pivotOverrides[$conto->id];

                if ($conto->tabelleMillesimali->isNotEmpty()) {
                    $importoConto = in_array($conto->tipo, ['spesa', 'uscita']) 
                        ? abs($importoOverride) : -abs($importoOverride);
                    
                    $this->distribuisciSuTabelle($conto, $importoConto, $totali);
                    continue; 
                }

                elseif ($conto->sottoconti->isNotEmpty()) {

                    // FIX: snapshot — solo sottoconti esistenti alla creazione del piano.
                    // Se TUTTI i sottoconti risultano successivi alla creazione del piano
                    // (es. aggiunti dalla migration dopo la pivot), usiamo i figli correnti
                    // come destinatari: il valore pivot è già il totale "congelato" corretto.
                    $sottocontiFiltrati = $this->pianoRateCreatedAt
                        ? $conto->sottoconti->filter(
                            fn($s) => $s->created_at->lte($this->pianoRateCreatedAt)
                        )
                        : $conto->sottoconti;

                    // FALLBACK: se il filtro snapshot svuota la lista ma abbiamo
                    // un override esplicito dalla pivot, usiamo TUTTI i figli correnti.
                    // L'importo pivot è già il valore snapshot congelato — non si gonfia.
                    if ($sottocontiFiltrati->isEmpty() && $importoOverride > 0) {
                        Log::warning("processaConti: snapshot vuoto per conto ID={$conto->id} ('{$conto->nome}'), fallback su tutti i sottoconti correnti. importoOverride={$importoOverride}");
                        $sottocontiFiltrati = $conto->sottoconti;
                    }

                    if ($sottocontiFiltrati->isEmpty()) {
                        continue;
                    }

                    $totaleOriginaleFigli = (int) $sottocontiFiltrati->sum('importo');
                    $totaleFigli = $sottocontiFiltrati->count();
                    $sommaAssegnata = 0;
                    $counter = 0;

                    // Distribuzione proporzionale al budget dei figli.
                    // FALLBACK: se tutti i figli hanno importo=0 (es. mastro vuoto creato
                    // manualmente), ripartiamo l'override in parti uguali (Penny-Perfect equo)
                    // per non perdere silenziosamente l'importo della pivot.
                    foreach ($sottocontiFiltrati as $figlio) {
                        $counter++;
                        if ($counter === $totaleFigli) {
                            // Ultimo figlio: assorbe il residuo per quadratura perfetta
                            $quotaFiglio = $importoOverride - $sommaAssegnata;
                        } elseif ($totaleOriginaleFigli > 0) {
                            $quotaFiglio = (int) round($importoOverride * ($figlio->importo / $totaleOriginaleFigli));
                        } else {
                            // Budget figli tutti zero: divisione equa di emergenza
                            $quotaFiglio = (int) round($importoOverride / $totaleFigli);
                        }
                        $sommaAssegnata += $quotaFiglio;
                        $this->pivotOverrides[$figlio->id] = $quotaFiglio;
                    }

                    $this->processaConti($sottocontiFiltrati, $totali);
                    continue;
                }
                
                continue; 
            }

            $importoLordo = (int) $conto->importo;
            
            if ($importoLordo !== 0) {
                $tipo = $conto->tipo ?? 'spesa';
                $importoConto = in_array($tipo, ['spesa', 'uscita'])
                    ? abs($importoLordo)
                    : -abs($importoLordo);

                $this->distribuisciSuTabelle($conto, $importoConto, $totali);
            }

            if ($conto->sottoconti && $conto->sottoconti->count() > 0) {
                $this->processaConti($conto->sottoconti, $totali);
            }
        }
    }

    /**
     * Distribuisce l'importo di un conto di spesa/uscita (o entrata) sulle anagrafiche (condomini).
     * Il calcolo viene effettuato distribuendo l'importo proporzionalmente in base ai
     * millesimi (tabelle millesimali) e alle percentuali di ripartizione (proprietario/inquilino).
     *
     * @param Conto $conto Il conto (capitolo di spesa) da elaborare.
     * @param int $importoConto L'importo totale da distribuire (in centesimi). Positivo per spese, negativo per entrate.
     * @param array &$totali Array associativo passato per riferimento per accumulare le quote per [anagrafica_id][immobile_id].
     * @return void
     */
    private function distribuisciSuTabelle(Conto $conto, int $importoConto, array &$totali): void
    {
        $weights = [];

        foreach ($conto->tabelleMillesimali as $ctm) {
            $tabella = $ctm->tabella ?? null;
            if (!$tabella) continue;

            $coeff = (float) $ctm->coefficiente;
            if ($coeff <= 0) continue;

            $weightCoeff = $coeff / 100.0;
            $quote = $tabella->quote;
            if ($quote->isEmpty()) continue;

            $sommaValori = (float) $quote->sum('valore');
            if ($sommaValori <= 0.0) continue;

            foreach ($quote as $quota) {
                $immobile = $quota->immobile ?? null;
                if (!$immobile) continue;

                $valore = (float) $quota->valore;
                if ($valore <= 0.0) continue;

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

                    // RULE ENGINE: Livello 3 (Fallback Legale al Proprietario)
                    if ($anagrafiche->isEmpty() && in_array($rip->soggetto, ['inquilino', 'usufruttuario'])) {
                        $anagrafiche = $immobile->anagrafiche
                            ->where('pivot.attivo', true)
                            ->where('pivot.tipologia', 'proprietario');
                    }

                    if ($anagrafiche->isEmpty()) continue;

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

        if (empty($weights)) return;

        $pesoTotale = array_sum($weights);
        if ($pesoTotale <= 0.0) return;

        foreach ($weights as $key => $w) {
            $weights[$key] = $w / $pesoTotale;
        }

        $importiDistributi = $this->distribuisciImporto($weights, $importoConto);

        foreach ($importiDistributi as $key => $importoCentesimi) {
            [$aid, $iid] = array_map('intval', explode('|', $key));
            
            if (!isset($totali[$aid])) {
                $totali[$aid] = [];
            }
            if (!isset($totali[$aid][$iid])) {
                $totali[$aid][$iid] = 0;
            }

            $totali[$aid][$iid] += $importoCentesimi;
        }
    }

    /**
     * Distribuisce un importo totale su un array di pesi proporzionali (Penny-Perfect).
     * Garantisce che la somma delle quote distribuite sia esattamente uguale all'importo totale,
     * risolvendo i problemi di arrotondamento e allocando eventuali centesimi rimanenti ai soggetti con resti maggiori.
     *
     * @param array $weights Array associativo di pesi normalizzati (chiave stringa => float peso). La somma dei pesi deve essere 1.
     * @param int $importoTotale L'importo totale da distribuire (in centesimi).
     * @return array Array associativo con le stesse chiavi di $weights contenente le quote esatte (in centesimi).
     */
    private function distribuisciImporto(array $weights, int $importoTotale): array
    {
        $result = [];
        if ($importoTotale === 0) {
            foreach ($weights as $key => $_) { $result[$key] = 0; }
            return $result;
        }

        $sign = $importoTotale < 0 ? -1 : 1;
        $totAbs = abs($importoTotale);
        $bases = [];
        $remainders = [];
        $sumBase = 0;

        foreach ($weights as $key => $w) {
            $raw = $totAbs * $w;
            $base = (int) floor($raw);
            $bases[$key] = $base;
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