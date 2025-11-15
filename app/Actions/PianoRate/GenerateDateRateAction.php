<?php

namespace App\Actions\PianoRate;

use App\Models\Gestionale\PianoRate;
use Carbon\Carbon;

class GenerateDateRateAction
{
    /**
     * Generate monthly rate dates based on gestione start date.
     *
     * @return array<int, Carbon>
     */
    public function execute(PianoRate $pianoRate, $gestione): array
    {
        $start  = Carbon::parse($gestione->data_inizio);
        $giorno = $pianoRate->giorno_scadenza ?? 5;

        return collect(range(0, $pianoRate->numero_rate - 1))
            ->map(fn ($i) =>
                $start->copy()
                    ->addMonths($i)
                    ->setDay(min($giorno, $start->copy()->addMonths($i)->daysInMonth))
            )
            ->toArray();
    }
}
