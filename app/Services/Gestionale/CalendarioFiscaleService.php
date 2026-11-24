<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
 * Le festività non c'erano, ed è il difetto §8 punto 5 del design. Dalla 1.12.0-beta.14 le scadenze fiscali passano da
 * `scadenzaFiscale()`, che aggiunge la regola del 20 agosto (Coda 717).
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
     * Il giorno vero di una scadenza fiscale: la regola di agosto, poi lo slittamento di sabato e festivi (Coda 717).
     *
     * Gli adempimenti e i versamenti che scadono **dal 1° al 20 agosto** si possono fare entro il **20 agosto**, senza maggiorazione
     * (art. 37, c. 11-bis, D.L. 223/2006; dal 1° gennaio 2027 art. 11 D.Lgs. 33/2025). Il 16/08/2026 è domenica: fino alla
     * 1.12.0-beta.13 il programma diceva lunedì 17/08/2026, e dal 18 la delega era «Scaduta da 1 giorno»; con la regola è giovedì
     * 20/08/2026. Se il 20 agosto cade di sabato o domenica slitta anche lui (il 20/08/2028 è domenica: lunedì 21/08/2028).
     *
     * La regola si guarda anche dopo lo slittamento: un termine che slittando entra fra il 1° e il 20 agosto vale fino al 20.
     *
     * ⚠️ **Solo per le scadenze fiscali.** `primoGiornoLavorativo()` resta il calendario e basta: lo usano anche i termini delle
     * assemblee (`TerminiDellImpugnazione`, `TerminiDellaConvocazione`, `PromemoriaDellaConvocazione`), dove agosto non sposta niente.
     */
    public function scadenzaFiscale(CarbonInterface $giorno): CarbonImmutable
    {
        $slittato = $this->primoGiornoLavorativo(CarbonImmutable::instance($giorno->toDateTime())->startOfDay());

        if ($slittato->month === 8 && $slittato->day <= 20) {
            return $this->primoGiornoLavorativo($slittato->day(20));
        }

        return $slittato;
    }

    /**
     * La scadenza del versamento delle ritenute operate con un pagamento, senza cumulo.
     *
     * Il 16 del mese successivo a quello del pagamento, con la regola di agosto e lo slittamento ({@see scadenzaFiscale()}).
     * `addMonthNoOverflow()` evita che un 31 gennaio diventi 3 marzo prima ancora di arrivare al `day(16)`.
     *
     * Non è la data della delega, che applica il cumulo e le soglie di ogni regime (`PlafondRitenuteService`): fino alla
     * 1.12.0-beta.13 la usava il promemoria di ogni pagamento, e la stessa ritenuta aveva due date (Coda 718).
     */
    public function scadenzaVersamentoRitenute(CarbonInterface $dataPagamento): CarbonImmutable
    {
        $sedici = CarbonImmutable::instance($dataPagamento->toDateTime())
            ->addMonthNoOverflow()
            ->day(16)
            ->startOfDay();

        return $this->scadenzaFiscale($sedici);
    }
}
