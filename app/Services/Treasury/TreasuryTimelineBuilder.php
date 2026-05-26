<?php

namespace App\Services\Treasury;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Primitivo di dominio che costruisce una timeline di eventi di cassa.
 * Progetta un array giorno-per-giorno ordinato e calcola il punto di scoperto.
 */
final class TreasuryTimelineBuilder
{
    private Collection $events;
    private int $liquiditaInizialeCents = 0;
    private int $giorni = 30;

    public function __construct()
    {
        $this->events = collect();
    }

    public function setLiquiditaIniziale(int $cents): self
    {
        $this->liquiditaInizialeCents = $cents;
        return $this;
    }

    /**
     * Resetta lo stato del builder per un nuovo calcolo.
     * Fondamentale se il builder viene riusato per più condomini nella stessa request.
     */
    public function reset(): self
    {
        $this->events = collect();
        $this->liquiditaInizialeCents = 0;
        $this->giorni = 30;
        return $this;
    }

    public function setFinestra(int $giorni): self
    {
        $this->giorni = $giorni;
        return $this;
    }

    /**
     * Aggiunge un'uscita (es. fattura da pagare).
     * @param Carbon|string|null $data Se passato/null, viene clampato a "oggi" (Giorno 0)
     */
    public function addUscita(string $label, int $importoCents, $data = null): self
    {
        $this->events->push([
            'tipo'    => 'uscita',
            'label'   => $label,
            'importo' => $importoCents,
            'data'    => $this->normalizeDate($data),
        ]);

        return $this;
    }

    /**
     * Aggiunge un'entrata attesa (es. rate da incassare).
     */
    public function addEntrataAttesa(string $label, int $importoCents, $data): self
    {
        $this->events->push([
            'tipo'    => 'entrata',
            'label'   => $label,
            'importo' => $importoCents,
            'data'    => $this->normalizeDate($data),
        ]);

        return $this;
    }

    /**
     * Costruisce la timeline e calcola lo scenario.
     * @return array{
     *   scenarioPessimisticoCents: int,
     *   scenarioOttimisticoCents: int,
     *   giornoScopertoPrevisto: ?string,
     *   scopertoMaxCents: int,
     *   timeline: array
     * }
     */
    public function build(): array
    {
        $limitDate = Carbon::today()->addDays($this->giorni);
        
        // Filtriamo gli eventi fuori finestra per sicurezza (dovrebbero già essere filtrati a monte)
        $validEvents = $this->events->filter(function ($e) use ($limitDate) {
            return Carbon::parse($e['data'])->lte($limitDate);
        });

        // Raggruppiamo per giorno
        $grouped = $validEvents->groupBy('data')->sortKeys();

        $currentPessimistico = $this->liquiditaInizialeCents;
        $currentOttimistico = $this->liquiditaInizialeCents;
        $scopertoMax = 0;
        $giornoScoperto = null;
        
        $timeline = [];

        foreach ($grouped as $date => $dayEvents) {
            $uscite = $dayEvents->where('tipo', 'uscita')->sum('importo');
            $entrate = $dayEvents->where('tipo', 'entrata')->sum('importo');

            $currentPessimistico -= $uscite;
            $currentOttimistico -= $uscite;
            $currentOttimistico += $entrate; // Le entrate salvano l'ottimistico

            if ($currentPessimistico < 0) {
                if ($currentPessimistico < $scopertoMax) {
                    $scopertoMax = $currentPessimistico;
                }
                if ($giornoScoperto === null) {
                    $giornoScoperto = $date;
                }
            }

            $timeline[$date] = [
                'uscite' => $uscite,
                'entrate' => $entrate,
                'pessimistico' => $currentPessimistico,
                'ottimistico' => $currentOttimistico,
            ];
        }

        return [
            'scenarioPessimisticoCents' => $currentPessimistico,
            'scenarioOttimisticoCents'  => $currentOttimistico,
            'giornoScopertoPrevisto'    => $giornoScoperto,
            'scopertoMaxCents'          => $scopertoMax,
            'timeline'                  => $timeline,
        ];
    }

    private function normalizeDate($data): string
    {
        $today = Carbon::today();
        if (!$data) {
            return $today->toDateString();
        }

        $parsed = Carbon::parse($data)->startOfDay();
        if ($parsed->isPast()) {
            return $today->toDateString(); // Day-0 clamp per il pregresso scaduto o fatture appena scadute
        }

        return $parsed->toDateString();
    }
}
