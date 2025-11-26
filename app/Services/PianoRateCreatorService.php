<?php

namespace App\Services;

use App\Models\Condominio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use Recurr\Rule;
use RuntimeException;

class PianoRateCreatorService
{
    public function verificaGestione(int $gestioneId): Gestione
    {
        $gestione = Gestione::with(['pianoConto.conti', 'esercizi'])->findOrFail($gestioneId);

        if (!$gestione->pianoConto) {
            throw new RuntimeException("The selected management (gestione) has no linked chart of accounts (piano conti).");
        }
        if (!$gestione->data_inizio) {
            throw new RuntimeException("The selected management (gestione) has no defined start date.");
        }

        return $gestione;
    }

    public function creaPianoRate(array $data, Condominio $condominio): PianoRate
    {
        return PianoRate::create([
            'gestione_id'          => $data['gestione_id'],
            'condominio_id'        => $condominio->id,
            'nome'                 => $data['nome'],
            'descrizione'          => $data['descrizione'] ?? null,
            'metodo_distribuzione' => $data['metodo_distribuzione'] ?? 'prima_rata',
            'numero_rate'          => $data['numero_rate'],
            'giorno_scadenza'      => $data['giorno_scadenza'] ?? 1,
            'note'                 => $data['note'] ?? null,
            'attivo'               => true,
        ]);
    }

    public function creaRicorrenza(PianoRate $pianoRate, array $data): void
    {
        $gestione = $pianoRate->gestione;
        $start    = new \DateTime($gestione->data_inizio, new \DateTimeZone('Europe/Rome'));

        $frequency = strtoupper($data['recurrence_frequency']);
        $interval  = max(1, (int)($data['recurrence_interval'] ?? 1));
        $byDay     = $data['recurrence_by_day'] ?? [];
        $giorno    = $data['giorno_scadenza'] ?? $pianoRate->giorno_scadenza;

        $rule = (new Rule())
            ->setStartDate($start)
            ->setFreq($frequency)
            ->setInterval($interval)
            ->setCount($pianoRate->numero_rate);

        $bySetPos      = null;
        $byMonthDayVal = null;

        if ($frequency === 'WEEKLY' && !empty($byDay)) {
            $rule->setByDay($byDay);
        }

        elseif ($frequency === 'MONTHLY') {

            if (!empty($byDay)) {
                $rule->setByDay($byDay);
                $rule->setBySetPosition([1]); // primo giorno utile nel mese
                $bySetPos = 1;

            } else {
                if ($giorno >= 29) {
                    $rule->setByMonthDay([-1]); // ultimo giorno del mese
                    $byMonthDayVal = -1;
                } else {
                    $rule->setByMonthDay([$giorno]);
                    $byMonthDayVal = $giorno;
                }
            }
        }

        if (!empty($data['recurrence_until'])) {
            $until = new \DateTime($data['recurrence_until'], new \DateTimeZone('Europe/Rome'));
            $rule->setUntil($until);
        }

        $pianoRate->ricorrenza()->create([
            'frequency'     => strtolower($frequency),
            'interval'      => $interval,
            'by_day'        => !empty($byDay) ? $byDay : null,
            'by_month_day'  => $byMonthDayVal,
            'by_set_pos'    => $bySetPos,
            'until'         => $data['recurrence_until'] ?? null,
            'rrule'         => $rule->getString(),
            'timezone'      => 'Europe/Rome',
        ]);
    }
}
