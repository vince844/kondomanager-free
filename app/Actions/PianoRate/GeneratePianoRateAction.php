<?php

namespace App\Actions\PianoRate;

use App\Models\Evento;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Services\CalcoloQuoteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GeneratePianoRateAction
{
    public function __construct(
        private CalcoloQuoteService $calcolatore,
        private GenerateSaldiAction $saldiAction,
        private GenerateDateRateAction $dateRateAction,
        private GenerateRateQuotesAction $rateQuotesAction,
    ) {}

    public function execute(PianoRate $pianoRate, ?bool $forzaApplicazioneSaldi = null, array $saldiConfig = []): array
    {
        $pianoRate->refresh();

        Log::info("=== GENERAZIONE PIANO RATE ===");
        Log::info("▶ Tipo Piano: " . strtoupper($pianoRate->tipo));

        $pianoRate->load(['ricorrenza', 'fatture']);

        if (!$pianoRate->relationLoaded('gestione')) {
            $pianoRate->load('gestione');
        }
        $gestione = $pianoRate->gestione;

        // =========================================================================
        // BIVIO ARCHITETTURALE
        // =========================================================================
        if ($pianoRate->tipo === 'straordinario') {
            $totaliPerImmobile = $this->calcolatore->calcolaDaFattureStraordinarie($pianoRate);
        } else {
            $totaliPerImmobile = $this->calcolatore->calcolaPerGestione($gestione, $pianoRate);
        }
        // =========================================================================

        // =========================================================================
        // GUARD PRE-GENERAZIONE (fail-fast)
        // Blocca la generazione se il calcolo ha prodotto zero soggetti/importi.
        // Evita che il piano venga salvato come "completato" con quote_create=0
        // mascherando un errore di configurazione (millesimi mancanti, anagrafiche
        // non configurate, tabelle millesimali vuote).
        // =========================================================================
        if (empty($totaliPerImmobile)) {
            Log::error("GeneratePianoRate: GUARD — totaliPerImmobile vuoto per piano_rate_id={$pianoRate->id}. Generazione bloccata.", [
                'piano_rate_id' => $pianoRate->id,
                'tipo'          => $pianoRate->tipo,
                'gestione_id'   => $gestione->id,
            ]);

            throw new \RuntimeException(
                "Impossibile generare il piano rate: nessuna quota calcolata. " .
                "Verificare che (1) le tabelle millesimali abbiano i millesimi inseriti per ogni immobile, " .
                "(2) ogni immobile abbia almeno un condòmino attivo associato, " .
                "(3) ogni voce di spesa sia collegata a una tabella millesimale."
            );
        }
        // =========================================================================

        // 3. GESTIONE SALDI
        $flagDb   = $gestione->fresh()->saldo_applicato;
        $applicare = $forzaApplicazioneSaldi !== null ? $forzaApplicazioneSaldi : $flagDb;

        if ($pianoRate->tipo === 'straordinario') {
            Log::info("▶ Piano Straordinario: Saldi ignorati di default (salvo override esplicito).");
        }

        if ($applicare) {
            $saldi = $this->saldiAction->execute($pianoRate, $gestione, $saldiConfig);
            Log::info("Generazione: Saldi INCLUSI (" . count($saldi) . " anagrafiche)");
        } else {
            $saldi = [];
            Log::info("Generazione: Saldi ESCLUSI (Array vuoto)");
        }

        // 4. Generazione Date
        $dateRate = $this->dateRateAction->execute($pianoRate, $gestione);

        // 5. Creazione Rate Fisiche
        $stats = $this->rateQuotesAction->execute(
            $pianoRate,
            $totaliPerImmobile,
            $dateRate,
            $saldi
        );

        // =========================================================================
        // AUTO-CHIUSURA TASK "EMISSIONE RATA SOPRAVVENIENZA"
        // =========================================================================
        try {
            Log::info("▶ START AUTO-CHIUSURA INBOX per Piano Rate ID: {$pianoRate->id}");

            if ($pianoRate->tipo === 'straordinario' && $pianoRate->fatture->isNotEmpty()) {
                $fattureIds = $pianoRate->fatture->pluck('id')->toArray();
            } else {
                if (!$pianoRate->relationLoaded('capitoli')) {
                    $pianoRate->load('capitoli');
                }
                $capitoliIds = $pianoRate->capitoli->pluck('id')->toArray();

                $fattureIds = DB::table('fattura_coperture')
                    ->where('tipo_copertura', 'sopravvenienza')
                    ->whereIn('conto_id', $capitoliIds)
                    ->pluck('fattura_passiva_id')
                    ->unique()
                    ->toArray();
            }

            if (!empty($fattureIds)) {
                $eventiChiusi = 0;

                foreach ($fattureIds as $fattId) {
                    $evento = Evento::where('meta->type', 'emissione_rata_sopravvenienza')
                        ->where('meta->context->fattura_id', $fattId)
                        ->where('meta->requires_action', true)
                        ->first();

                    if ($evento) {
                        $meta = $evento->meta;
                        $meta['requires_action'] = false;
                        $meta['is_completed']    = true;
                        $evento->meta = $meta;
                        $evento->save();
                        $eventiChiusi++;
                    }
                }

                Log::info("AUTO-CHIUSURA INBOX completata. Task risolti: {$eventiChiusi}");
            }
        } catch (\Exception $e) {
            Log::error("❌ Errore Auto-chiusura Inbox: " . $e->getMessage());
        }
        // =========================================================================

        return array_merge([
            'piano_rate_id' => $pianoRate->id,
            'rate_create'   => $stats['rate_create'] ?? count($dateRate),
        ], $stats);
    }
}
