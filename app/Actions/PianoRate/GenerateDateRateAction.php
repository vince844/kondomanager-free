<?php

namespace App\Actions\PianoRate;

use App\Models\Gestionale\PianoRate;
use App\Services\PianoRateCreatorService;
use Carbon\Carbon;
use Recurr\Frequency;
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

        // Le date sono quelle della regola, così come la libreria le emette (salvo la prima, se è stata scelta: vedi sotto). Il giorno del mese sta già nella regola: `creaRicorrenza`
        // scrive `BYMONTHDAY` per la mensile senza giorni della settimana (l'ultimo del mese da 29 in su), e le altre frequenze —
        // settimanale, giornaliera, annuale, mensile «nel primo dei giorni scelti» — non hanno un giorno del mese da imporre.
        //
        // ⚠️ Qui c'era un `$date->day = $giornoTarget` (Coda 419, 1.11.0-beta.59) che non aveva effetto: su un oggetto nato da
        // `Carbon::instance(...)` l'assegnazione non passa dal setter di Carbon, crea una proprietà dinamica (PHP 8.4 la segnala come
        // deprecata) e la data resta quella della regola. Toglierla non cambia nessuna data. Non va rimpiazzata con un `setDay` per
        // ogni frequenza: le rate settimanali e giornaliere cadrebbero tutte nello stesso giorno, le annuali e le «primo lunedì del
        // mese» sul giorno del mese. Lo fermano i test di `Beta59PianoRicorrenzaTest`.
        $date = collect($occurrences)->map(fn ($occ) => Carbon::instance($occ->getStart()));

        // Con una prima scadenza scelta (Coda 419, decisione 153) la prima rata è quella data, come nel ramo mensile, e le successive sono
        // le date della regola dopo di lei: la libreria non emette quelle che precedono la partenza, quindi se la data scelta non è una
        // data della regola la regola non la dà, e va messa in testa; se lo è, la libreria la dà già e non si raddoppia. Senza una data
        // scelta la partenza è l'inizio della gestione e le date sono quelle della regola, come sempre.
        //
        // Con la mensile nel giorno del mese (`BYMONTHDAY`, nessun giorno della settimana) la data scelta prende il posto della rata del suo
        // mese, come nel ramo mensile, che dà una rata per mese (decisione 159, scelta 3): scelta 03/01 con il giorno 5 → 03/01, 05/02, 05/03, non
        // 03/01, 05/01, 05/02. La regola ha `COUNT` uguale a `numero_rate` e un solo giorno per mese, quindi le rate restano `numero_rate`. Con le
        // altre frequenze, e con la mensile nei giorni della settimana scelti, si toglie solo la data uguale alla scelta: altro non è deciso.
        if ($pianoRate->data_prima_scadenza) {
            $scelta = Carbon::instance(new \DateTime($partenza->toDateString(), new \DateTimeZone($timezone)));
            $stesso = ($rule->getFreq() === Frequency::MONTHLY && empty($rule->getByDay())) ? 'Y-m' : 'Y-m-d';

            $date = $date
                ->reject(fn (Carbon $d) => $d->format($stesso) === $scelta->format($stesso))
                ->prepend($scelta)
                ->values();
        }

        return $date->take($pianoRate->numero_rate)->toArray();
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
