<?php

namespace App\Actions\PianoRate;

use App\Models\Gestionale\PianoRate;
use Carbon\Carbon;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;

class GenerateDateRateAction
{
    public function execute(PianoRate $pianoRate, $gestione): array
    {
        $ric = $pianoRate->ricorrenza;

        if (!$ric) {
            return $this->defaultMonthly($pianoRate, $gestione);
        }

        $timezone = $ric->timezone ?: config('app.timezone');

        $rule = new Rule(
            $ric->rrule,
            new \DateTime($gestione->data_inizio, new \DateTimeZone($timezone))
        );

        $config = new ArrayTransformerConfig();
        $config->enableLastDayOfMonthFix();
        $transformer = new ArrayTransformer($config);

        $occurrences = $transformer->transform($rule);

        return collect($occurrences)
        ->take($pianoRate->numero_rate)
        ->map(function ($occ) use ($pianoRate, $ric) {
            $date = Carbon::instance($occ->getStart());
            
            // Se non c'è ricorrenza salvata o vogliamo forzare il giorno
            $giornoTarget = $pianoRate->giorno_scadenza ?? 5;

            // Se la regola era "ultimo del mese" (-1) o se stiamo correggendo la data
            // controlliamo che il giorno target non superi i giorni del mese
            if ($giornoTarget > $date->daysInMonth) {
                $date->day = $date->daysInMonth; // Imposta all'ultimo giorno utile (es. 28 Feb)
            } else {
                $date->day = $giornoTarget;
            }

            return $date;
        })
        ->toArray();
    }

    private function defaultMonthly(PianoRate $pianoRate, $gestione): array
    {
        $start  = Carbon::parse($gestione->data_inizio);
        $giorno = $pianoRate->giorno_scadenza ?? 5;

        return collect(range(0, $pianoRate->numero_rate - 1))
            ->map(fn ($i) =>
                $start->copy()
                    ->addMonthsNoOverflow($i)
                    ->setDay(min($giorno, $start->copy()->addMonthsNoOverflow($i)->daysInMonth))
            )
            ->toArray();
    }
}
