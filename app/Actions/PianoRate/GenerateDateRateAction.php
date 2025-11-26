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
                $giorno = $pianoRate->giorno_scadenza ?? 5;

                // ✅ Post-processing applicato SOLO se BYMONTHDAY=-1
                if ($ric->by_month_day === -1 && $giorno <= $date->daysInMonth) {
                    return $date->setDay($giorno);
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
