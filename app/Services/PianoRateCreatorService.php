<?php

namespace App\Services;

use App\Models\Condominio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use Recurr\Rule;
use RuntimeException;

class PianoRateCreatorService
{
    /**
     * Validates that the Gestione exists and is correctly configured.
     *
     * Requirements:
     * - Gestione must exist.
     * - A PianoConto must be associated.
     * - A valid start date must be defined.
     *
     * @param  int  $gestioneId
     * @return Gestione
     *
     * @throws RuntimeException If the Gestione is missing required data.
     */
    public function verificaGestione(int $gestioneId): Gestione
    {
        /** @var Gestione $gestione */
        $gestione = Gestione::with(['pianoConto.conti', 'esercizi'])
            ->findOrFail($gestioneId);

        if (!$gestione->pianoConto) {
            throw new RuntimeException("The selected management (gestione) has no linked chart of accounts (piano conti).");
        }

        if (!$gestione->data_inizio) {
            throw new RuntimeException("The selected management (gestione) has no defined start date.");
        }

        return $gestione;
    }

    /**
     * Creates a new PianoRate using validated input data.
     *
     * This method only persists the record; it does not generate rates or apply
     * recurrence rules. It is intentionally simple and single-responsibility.
     *
     * @param  array        $data        Validated request data.
     * @param  Condominio   $condominio  The condominium the plan belongs to.
     * @return PianoRate
     */
    public function creaPianoRate(array $data, Condominio $condominio): PianoRate
    {
        return PianoRate::create([
            'gestione_id'          => $data['gestione_id'],
            'condominio_id'        => $condominio->id,
            'nome'                 => $data['nome'],
            'descrizione'          => $data['descrizione'] ?? null,
            'metodo_calcolo'       => $data['metodo_calcolo'],
            'metodo_distribuzione' => $data['metodo_distribuzione'] ?? 'prima_rata',
            'numero_rate'          => $data['numero_rate'],
            'giorno_scadenza'      => $data['giorno_scadenza'] ?? 1,
            'note'                 => $data['note'] ?? null,
            'attivo'               => true,
        ]);
    }

    /**
     * Creates the recurrence rule (RRULE) for a PianoRate.
     *
     * The recurrence rule defines how future rates should automatically repeat.
     * It corresponds to the same structure used in recurring calendar events
     * and is fully compatible with the "recurr" PHP library.
     *
     * Supported frequencies: DAILY, WEEKLY, MONTHLY, YEARLY.
     *
     * - The start date is taken from the linked Gestione.
     * - Monthly recurrence may specify a specific day of month.
     *
     * @param  PianoRate  $pianoRate
     * @param  array      $data  Validated request data.
     * @return void
     */
    public function creaRicorrenza(PianoRate $pianoRate, array $data): void
    {
        $gestione = $pianoRate->gestione;

        $start = new \DateTime(
            $gestione->data_inizio,
            new \DateTimeZone('Europe/Rome')
        );

        $rule = (new Rule())
            ->setStartDate($start)
            ->setFreq($data['recurrence_frequency'])
            ->setInterval($data['recurrence_interval'] ?? 1);

        // If frequency is monthly, specify the day of the month
        if ($data['recurrence_frequency'] === 'MONTHLY') {
            $rule->setByMonthDay([
                $data['giorno_scadenza'] ?? $pianoRate->giorno_scadenza
            ]);
        }

        // Persist recurrence in the database
        $pianoRate->ricorrenza()->create([
            'frequency'     => strtolower($data['recurrence_frequency']),
            'interval'      => $data['recurrence_interval'] ?? 1,
            'by_month_day'  => $data['giorno_scadenza'] ?? $pianoRate->giorno_scadenza,
            'rrule'         => $rule->getString(),
        ]);
    }
}
