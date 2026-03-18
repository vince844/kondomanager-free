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

    /**
     * @param PianoRate $pianoRate
     * @param bool|null $forzaApplicazioneSaldi 
     */
    public function execute(PianoRate $pianoRate, ?bool $forzaApplicazioneSaldi = null, array $saldiConfig = []): array
    {
        Log::info("=== GENERAZIONE PIANO RATE ===");

        $pianoRate->load(['ricorrenza']);

        if (!$pianoRate->relationLoaded('gestione')) {
            $pianoRate->load('gestione');
        }
        $gestione = $pianoRate->gestione;

        // 2. Calcolo Spese (Quote pure ordinarie)
        $totaliPerImmobile = $this->calcolatore->calcolaPerGestione($gestione, $pianoRate);

        // 3. GESTIONE SALDI
        $flagDb = $gestione->fresh()->saldo_applicato; 
        
        $applicare = false;

        if ($forzaApplicazioneSaldi !== null) {
            $applicare = $forzaApplicazioneSaldi;
        } else {
            $applicare = $flagDb;
        }

        if ($applicare) {
            // Passiamo $saldiConfig alla action dei saldi!
            $saldi = $this->saldiAction->execute($pianoRate, $gestione, $saldiConfig);
            Log::info("Generazione: Saldi INCLUSI (" . count($saldi) . " anagrafiche)");
        } else {
            $saldi = [];
            Log::info("Generazione: Saldi ESCLUSI (Array vuoto)");
        }

        // 4. Generazione Date (NON TOCCHIAMO, ma diamo l'informazione alla action successiva)
        // Se è 'rata_zero', la logica delle date standard viene mantenuta, 
        // e la GenerateRateQuotesAction inietterà una scadenza immediata per la Rata 0.
        $dateRate = $this->dateRateAction->execute($pianoRate, $gestione);

        // 5. Creazione Rate Fisiche
        $stats = $this->rateQuotesAction->execute(
            $pianoRate,
            $totaliPerImmobile,
            $dateRate,
            $saldi
        );

        // =========================================================================
        // NUOVO BLOCCO: AUTO-CHIUSURA TASK "EMISSIONE RATA SOPRAVVENIENZA"
        // =========================================================================
        try {
            Log::info("▶ START AUTO-CHIUSURA INBOX per Piano Rate ID: {$pianoRate->id}");

            if (!$pianoRate->relationLoaded('capitoli')) {
                $pianoRate->load('capitoli');
            }

            $capitoliIds = $pianoRate->capitoli->pluck('id')->toArray();
            Log::info("▶ Capitoli inclusi nel piano rate: ", $capitoliIds);

            if (!empty($capitoliIds)) {
                
                // LA QUERY PERFETTA: Grazie alla tua migrazione sappiamo esattamente dove guardare!
                // Cerchiamo le coperture di tipo 'sopravvenienza' che puntano ai conti usati nel piano rate.
                $fattureIds = \Illuminate\Support\Facades\DB::table('fattura_coperture')
                    ->where('tipo_copertura', 'sopravvenienza')
                    ->whereIn('conto_id', $capitoliIds)
                    ->pluck('fattura_passiva_id')
                    ->unique()
                    ->toArray();

                Log::info("▶ Fatture (Sopravvenienze) collegate trovate: ", $fattureIds);

                if (!empty($fattureIds)) {
                    $eventiChiusi = 0;
                    
                    foreach ($fattureIds as $fattId) {
                        $evento = \App\Models\Evento::where('meta->type', 'emissione_rata_sopravvenienza')
                            ->where('meta->context->fattura_id', $fattId)
                            ->where('meta->requires_action', true)
                            ->first();

                        if ($evento) {
                            // Spegniamo il task!
                            $meta = $evento->meta;
                            $meta['requires_action'] = false;
                            $meta['is_completed']    = true;
                            
                            $evento->meta = $meta;
                            $evento->save();
                            
                            $eventiChiusi++;
                        }
                    }
                    Log::info("✅ AUTO-CHIUSURA INBOX completata. Task risolti in automatico: {$eventiChiusi}");
                } else {
                    Log::info("▶ Nessuna Sopravvenienza pendente trovata per questi capitoli.");
                }
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