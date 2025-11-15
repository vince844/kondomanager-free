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
     * Full pipeline to generate a PianoRate.
     *
     * @return array Statistics about generation
     */
    public function execute(PianoRate $pianoRate): array
    {
        Log::info("=== GENERAZIONE PIANO RATE INIZIATA ===");

        $gestione = $pianoRate->gestione;

        $esercizio = $gestione->esercizi()->wherePivot('attiva', true)->first()
            ?? $gestione->esercizi()->first();

        $totaliPerImmobile = $this->calcolatore->calcolaPerGestione($gestione);

        $saldi = $this->saldiAction->execute($pianoRate, $gestione, $esercizio);

        $dateRate = $this->dateRateAction->execute($pianoRate, $gestione);

        $stats = $this->rateQuotesAction->execute(
            $pianoRate,
            $totaliPerImmobile,
            $dateRate,
            $saldi
        );

        return array_merge([
            'piano_rate_id' => $pianoRate->id,
            'rate_create'   => count($dateRate),
        ], $stats);
    }
}
