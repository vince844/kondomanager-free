<?php

namespace App\Actions\PianoRate;

use App\Models\Gestionale\PianoRate;
use App\Services\CalcoloQuoteService;
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

        return array_merge([
            'piano_rate_id' => $pianoRate->id,
            'rate_create'   => $stats['rate_create'] ?? count($dateRate),
        ], $stats);
    }
}