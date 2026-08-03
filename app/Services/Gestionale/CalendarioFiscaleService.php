<?php

namespace App\Services\Gestionale;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Le date delle scadenze fiscali, con lo slittamento ai giorni non lavorativi.
 *
 * La regola, dalla circolare 9/E del 02/05/2024: *«se il termine del 16 cade di sabato o in
 * giorno festivo, il versamento slitta al primo giorno lavorativo successivo»*, e vale su
 * tutte le date del ciclo — il 16 mensile, il 16 giugno, il 16 dicembre e il 16 gennaio.
 *
 * Prima esisteva solo il mezzo controllo dentro `SyncF24WithPagamento`: `isWeekend()` e via.
 * Le festività non c'erano, ed è il difetto §8 punto 5 del design.
 *
 * **Quale festività può davvero colpire il giorno 16.** Nessuna ricorrenza nazionale a data
 * fissa cade il 16 di un mese, né il 17 o il 18 su cui si atterra slittando da un fine
 * settimana. L'unica che ci arriva è il **lunedì dell'Angelo**, che è mobile e cade fra il
 * 23 marzo e il 26 aprile: può quindi essere sia un 16 aprile, sia il lunedì su cui si
 * slitta da un 16 che cadeva di domenica. Il calendario completo è implementato lo stesso —
 * costa poco ed evita di dover tornare qui quando queste date verranno riusate per il
 * plafond, dove i termini non sono solo il giorno 16.
 */
class CalendarioFiscaleService
{
    /**
     * Le ricorrenze nazionali a data fissa: giorno e mese.
     *
     * Il santo patrono è volutamente escluso: è locale, cambia da comune a comune e non
     * sposta un termine di versamento erariale.
     *
     * @var array<int,array{int,int}>
     */
    private const FESTIVITA_FISSE = [
        [1, 1],    // Capodanno
        [6, 1],    // Epifania
        [25, 4],   // Liberazione
        [1, 5],    // Festa del lavoro
        [2, 6],    // Festa della Repubblica
        [15, 8],   // Assunzione — Ferragosto
        [1, 11],   // Ognissanti
        [8, 12],   // Immacolata
        [25, 12],  // Natale
        [26, 12],  // Santo Stefano
    ];

    /**
     * La domenica di Pasqua, con l'algoritmo gregoriano anonimo.
     *
     * Calcolata a mano e non con `easter_date()` perché quella funzione richiede
     * l'estensione `calendar`, che non è fra i requisiti dichiarati dell'installer: una
     * scadenza fiscale non può dipendere da un'estensione facoltativa.
     */
    public function pasqua(int $anno): CarbonImmutable
    {
        $a = $anno % 19;
        $b = intdiv($anno, 100);
        $c = $anno % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);

        $mese = intdiv($h + $l - 7 * $m + 114, 31);
        $giorno = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($anno, $mese, $giorno)->startOfDay();
    }

    /** Il lunedì dell'Angelo: l'unica festività mobile che sposta questi termini. */
    public function lunediDellAngelo(int $anno): CarbonImmutable
    {
        return $this->pasqua($anno)->addDay();
    }

    /**
     * Tutte le festività nazionali di un anno, come stringhe `Y-m-d`.
     *
     * @return array<int,string>
     */
    public function festivita(int $anno): array
    {
        $date = [];

        foreach (self::FESTIVITA_FISSE as [$giorno, $mese]) {
            $date[] = CarbonImmutable::create($anno, $mese, $giorno)->format('Y-m-d');
        }

        $date[] = $this->lunediDellAngelo($anno)->format('Y-m-d');

        return $date;
    }

    public function isFestivo(CarbonInterface $data): bool
    {
        return in_array($data->format('Y-m-d'), $this->festivita((int) $data->year), true);
    }

    public function isGiornoLavorativo(CarbonInterface $data): bool
    {
        return ! $data->isWeekend() && ! $this->isFestivo($data);
    }

    /**
     * Il primo giorno lavorativo a partire da una data — la data stessa, se già lavorativa.
     *
     * Avanza a passi di un giorno invece di saltare al lunedì: dopo lo slittamento da un
     * fine settimana si può atterrare su una festività (il lunedì dell'Angelo), e allora
     * bisogna slittare ancora. Il vecchio `next('Monday')` si fermava al primo tentativo.
     */
    public function primoGiornoLavorativo(CarbonInterface $data): CarbonImmutable
    {
        $giorno = CarbonImmutable::instance($data->toDateTime());

        while (! $this->isGiornoLavorativo($giorno)) {
            $giorno = $giorno->addDay();
        }

        return $giorno;
    }

    /**
     * La scadenza del versamento delle ritenute operate con un pagamento.
     *
     * Il 16 del mese successivo a quello del pagamento, slittato al primo giorno lavorativo.
     * `addMonthNoOverflow()` evita che un 31 gennaio diventi 3 marzo prima ancora di
     * arrivare al `day(16)`.
     */
    public function scadenzaVersamentoRitenute(CarbonInterface $dataPagamento): CarbonImmutable
    {
        $sedici = CarbonImmutable::instance($dataPagamento->toDateTime())
            ->addMonthNoOverflow()
            ->day(16)
            ->startOfDay();

        return $this->primoGiornoLavorativo($sedici);
    }
}
