<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Treasury;

use App\Helpers\DateHelper;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Primitivo di dominio che costruisce una timeline di eventi di cassa.
 * Progetta un array giorno-per-giorno ordinato e calcola il punto di scoperto.
 *
 * Tre tipi di eventi: le uscite previste (le fatture da pagare), le entrate attese (le rate dei condòmini, «se arrivano») e i movimenti già registrati con
 * una data dopo oggi (un incasso o un pagamento scritto con il suo giorno). Lo scenario pessimistico conta le uscite e i registrati, quello ottimistico anche
 * le entrate attese. Gli eventi si tengono già sommati per giorno: le rate di migliaia di persone non diventano migliaia di righe in memoria.
 *
 * Per i tre scenari del grafico le entrate attese si tengono anche divise: le rate che scadono nella finestra (`rate_in_scadenza`, al loro
 * giorno) e le già scadute (tutte al giorno di oggi). «Con le rate in scadenza» è il saldo senza le entrate più le rate in scadenza fino a quel giorno; il suo
 * giorno più basso dice quanto basta perché non vada sotto zero. Ogni giorno porta anche le sue fatture, fino a {@see self::FATTURE_PER_GIORNO}, per le azioni
 * consigliate.
 */
final class TreasuryTimelineBuilder
{
    /** Le voci di un giorno che il riquadro del grafico nomina una per una; le altre si contano. */
    public const VOCI_PER_GIORNO = 3;

    /** Le fatture di un giorno che le azioni consigliate elencano, con l'`id`; le altre si contano con `quante_uscite`. */
    public const FATTURE_PER_GIORNO = 8;

    /** @var array<string, array{importo:int, quante:int, voci:list<array{voce:string, importo:int}>, fatture:list<array{id:int, fornitore:string, numero:string, importo:int}>}> */
    private array $uscite = [];

    /** @var array<string, array{importo:int, quante:int}> */
    private array $entrate = [];

    /** Le sole rate che scadono nella finestra, non ancora scadute: al loro giorno. @var array<string, array{importo:int, quante:int}> */
    private array $inScadenza = [];

    /** @var array<string, array{importo:int, quante:int, voci:list<array{voce:string, importo:int}>}> */
    private array $registrati = [];

    private int $liquiditaInizialeCents = 0;
    private int $giorni = 30;

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
        $this->uscite = [];
        $this->entrate = [];
        $this->inScadenza = [];
        $this->registrati = [];
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
     * @param array{id:int, fornitore:string, numero:string}|null $fattura la fattura, per l'elenco del giorno
     */
    public function addUscita(string $label, int $importoCents, $data = null, ?array $fattura = null): self
    {
        $giorno = $this->normalizeDate($data);
        $this->uscite[$giorno] ??= ['importo' => 0, 'quante' => 0, 'voci' => [], 'fatture' => []];
        $this->uscite[$giorno]['importo'] += $importoCents;
        $this->uscite[$giorno]['quante']++;
        if (count($this->uscite[$giorno]['voci']) < self::VOCI_PER_GIORNO) {
            $this->uscite[$giorno]['voci'][] = ['voce' => $label, 'importo' => $importoCents];
        }
        if ($fattura !== null && count($this->uscite[$giorno]['fatture']) < self::FATTURE_PER_GIORNO) {
            $this->uscite[$giorno]['fatture'][] = ['id' => (int) $fattura['id'], 'fornitore' => (string) $fattura['fornitore'], 'numero' => (string) $fattura['numero'],
                'importo' => $importoCents];
        }

        return $this;
    }

    /**
     * Aggiunge un'entrata attesa (es. rate da incassare). `$scaduta`: la rata è già scaduta (entra oggi, e non è fra le rate in scadenza dello scenario
     * «Con le rate in scadenza»).
     */
    public function addEntrataAttesa(string $label, int $importoCents, $data, bool $scaduta = false): self
    {
        if ($importoCents === 0) {
            return $this;
        }
        $giorno = $this->normalizeDate($data);
        $this->entrate[$giorno] ??= ['importo' => 0, 'quante' => 0];
        $this->entrate[$giorno]['importo'] += $importoCents;
        $this->entrate[$giorno]['quante']++;
        if (! $scaduta) {
            $this->inScadenza[$giorno] ??= ['importo' => 0, 'quante' => 0];
            $this->inScadenza[$giorno]['importo'] += $importoCents;
            $this->inScadenza[$giorno]['quante']++;
        }

        return $this;
    }

    /**
     * Aggiunge un movimento già registrato con un giorno dopo oggi: con il segno (positivo entra, negativo esce), in tutti e due gli scenari.
     */
    public function addRegistrato(string $label, int $importoCents, $data): self
    {
        if ($importoCents === 0) {
            return $this;
        }
        $giorno = $this->normalizeDate($data);
        $this->registrati[$giorno] ??= ['importo' => 0, 'quante' => 0, 'voci' => []];
        $this->registrati[$giorno]['importo'] += $importoCents;
        $this->registrati[$giorno]['quante']++;
        if (count($this->registrati[$giorno]['voci']) < self::VOCI_PER_GIORNO) {
            $this->registrati[$giorno]['voci'][] = ['voce' => $label, 'importo' => $importoCents];
        }

        return $this;
    }

    /**
     * Costruisce la timeline e calcola lo scenario.
     *
     * `giorni`: un giorno per riga da oggi alla fine della finestra, con il saldo a fine giornata senza le entrate attese (`saldo`) e con (`con_entrate`), e
     * che cosa c'è quel giorno (le uscite con le prime voci e le fatture, le entrate attese contate, le sole rate in scadenza, i registrati).
     *
     * `scopertoMaxConRateCents`: il giorno più basso dello scenario «Con le rate in scadenza» (zero se non va sotto zero): la cifra che basta, entrata prima,
     * perché quello scenario resti a zero o sopra.
     *
     * @return array{
     *   scenarioPessimisticoCents: int,
     *   scenarioOttimisticoCents: int,
     *   giornoScopertoPrevisto: ?string,
     *   scopertoMaxCents: int,
     *   scopertoMaxConEntrateCents: int,
     *   scopertoMaxConRateCents: int,
     *   timeline: array,
     *   giorni: list<array<string, mixed>>
     * }
     */
    public function build(): array
    {
        // «Oggi» è il giorno dell'amministratore: fra le 00:00 e le 02:00 di Roma il giorno UTC è ancora ieri.
        $oggi = CarbonImmutable::parse(DateHelper::oggiUtente());
        $fine = $oggi->addDays($this->giorni)->format('Y-m-d');

        $currentPessimistico = $this->liquiditaInizialeCents;
        $currentOttimistico = $this->liquiditaInizialeCents;
        $scopertoMax = 0;
        $scopertoMaxConEntrate = 0;
        $scopertoMaxConRate = 0;
        $rateInScadenza = 0;
        $giornoScoperto = null;

        $timeline = [];
        $giorni = [];

        for ($g = $oggi; $g->format('Y-m-d') <= $fine; $g = $g->addDay()) {
            $date = $g->format('Y-m-d');
            $uscite = (int) ($this->uscite[$date]['importo'] ?? 0);
            $entrate = (int) ($this->entrate[$date]['importo'] ?? 0);
            $registrati = (int) ($this->registrati[$date]['importo'] ?? 0);

            $currentPessimistico += $registrati - $uscite;
            $currentOttimistico += $registrati - $uscite + $entrate; // Le entrate salvano l'ottimistico
            $rateInScadenza += (int) ($this->inScadenza[$date]['importo'] ?? 0);

            $scopertoMaxConEntrate = min($scopertoMaxConEntrate, $currentOttimistico);
            $scopertoMaxConRate = min($scopertoMaxConRate, $currentPessimistico + $rateInScadenza);
            if ($currentPessimistico < 0) {
                if ($currentPessimistico < $scopertoMax) {
                    $scopertoMax = $currentPessimistico;
                }
                if ($giornoScoperto === null) {
                    $giornoScoperto = $date;
                }
            }

            if (isset($this->uscite[$date]) || isset($this->entrate[$date]) || isset($this->registrati[$date])) {
                $timeline[$date] = [
                    'uscite' => $uscite,
                    'entrate' => $entrate,
                    'registrati' => $registrati,
                    'pessimistico' => $currentPessimistico,
                    'ottimistico' => $currentOttimistico,
                ];
            }

            $giorni[] = [
                'giorno' => $date,
                'saldo' => $currentPessimistico,
                'con_entrate' => $currentOttimistico,
                'uscite' => $uscite,
                'quante_uscite' => (int) ($this->uscite[$date]['quante'] ?? 0),
                'voci_uscite' => $this->uscite[$date]['voci'] ?? [],
                'fatture' => $this->uscite[$date]['fatture'] ?? [],
                'entrate' => $entrate,
                'quante_entrate' => (int) ($this->entrate[$date]['quante'] ?? 0),
                'rate_in_scadenza' => (int) ($this->inScadenza[$date]['importo'] ?? 0),
                'quante_rate_in_scadenza' => (int) ($this->inScadenza[$date]['quante'] ?? 0),
                'registrati' => $registrati,
                'quanti_registrati' => (int) ($this->registrati[$date]['quante'] ?? 0),
                'voci_registrati' => $this->registrati[$date]['voci'] ?? [],
            ];
        }

        return [
            'scenarioPessimisticoCents' => $currentPessimistico,
            'scenarioOttimisticoCents'  => $currentOttimistico,
            'giornoScopertoPrevisto'    => $giornoScoperto,
            'scopertoMaxCents'          => $scopertoMax,
            'scopertoMaxConEntrateCents' => $scopertoMaxConEntrate,
            'scopertoMaxConRateCents'   => $scopertoMaxConRate,
            'timeline'                  => $timeline,
            'giorni'                    => $giorni,
        ];
    }

    private function normalizeDate($data): string
    {
        $oggi = DateHelper::oggiUtente();
        if (!$data) {
            return $oggi;
        }

        $giorno = $data instanceof \DateTimeInterface ? $data->format('Y-m-d') : Carbon::parse($data)->toDateString();

        // Day-0 clamp per il pregresso scaduto o fatture appena scadute
        return $giorno < $oggi ? $oggi : $giorno;
    }
}
