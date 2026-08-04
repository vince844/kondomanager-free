<?php

namespace App\Actions\PianoRate;

use App\Models\Gestionale\PianoRate;
use App\Services\PianoRateCreatorService;
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

        // La partenza non è più l'inizio della gestione, ma quella che il piano dichiara:
        // `data_prima_scadenza` se scelta, altrimenti la gestione — vedi
        // `PianoRate::dataPartenzaCalendario()`. Nessun piano esistente cambia comportamento,
        // perché senza data propria la risposta è la stessa di prima.
        $partenza = $pianoRate->dataPartenzaCalendario() ?? Carbon::parse($gestione->data_inizio);

        $rule = new Rule(
            $ric->rrule,
            new \DateTime($partenza->toDateString(), new \DateTimeZone($timezone))
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
        $start  = Carbon::parse(
            ($pianoRate->dataPartenzaCalendario() ?? Carbon::parse($gestione->data_inizio))->toDateString()
        );

        // ⚠️ Il giorno del mese NON deve sovrascrivere il giorno scelto nella data di
        // partenza: se l'amministratore ha detto «la prima rata il 30 settembre», la prima
        // scadenza è il 30, non il 5. Dalla seconda in poi comanda `giorno_scadenza`, che è
        // ciò che l'amministratore intende quando indica un giorno del mese.
        $giorno = $pianoRate->giorno_scadenza ?? PianoRateCreatorService::GIORNO_SCADENZA_PREDEFINITO;
        $partenzaScelta = $pianoRate->data_prima_scadenza !== null;

        return collect(range(0, $pianoRate->numero_rate - 1))
            ->map(function ($i) use ($start, $giorno, $partenzaScelta) {
                $mese = $start->copy()->addMonthsNoOverflow($i);

                if ($i === 0 && $partenzaScelta) {
                    return $mese;
                }

                return $mese->setDay(min($giorno, $mese->daysInMonth));
            })
            ->toArray();
    }
}
