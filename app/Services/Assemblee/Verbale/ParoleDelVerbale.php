<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Services\Assemblee\Sala\ContiDellaSala;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/** Le parole del verbale: i giorni in lettere («15 dicembre 2026»), le ore di Roma, i millesimi, i condòmini al singolare e al plurale, gli elenchi. */
final class ParoleDelVerbale
{
    private const MESI = [1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

    private const GIORNI = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];

    /** «15 dicembre 2026» da «2026-12-15» (o da un istante: il giorno come si legge). */
    public static function giorno(string|CarbonInterface|null $giorno): string
    {
        if ($giorno === null || $giorno === '') {
            return '';
        }
        $g = $giorno instanceof CarbonInterface ? $giorno : Carbon::parse(substr((string) $giorno, 0, 10));

        return (int) $g->format('j') . ' ' . self::MESI[(int) $g->format('n')] . ' ' . $g->format('Y');
    }

    /** «giovedì 17 dicembre 2026». */
    public static function giornoConLaSettimana(string|CarbonInterface $giorno): string
    {
        $g = $giorno instanceof CarbonInterface ? $giorno : Carbon::parse(substr((string) $giorno, 0, 10));

        return self::GIORNI[(int) $g->format('w')] . ' ' . self::giorno($g);
    }

    /** L'ora di un istante come si legge a Roma: «18:42». */
    public static function ora(string|CarbonInterface|null $istante): string
    {
        if ($istante === null || $istante === '') {
            return '';
        }
        $i = $istante instanceof CarbonInterface ? $istante->copy() : Carbon::parse((string) $istante);

        return $i->setTimezone(self::fuso())->format('H:i');
    }

    /** Il giorno di un istante come si legge a Roma: «2026-12-15». */
    public static function giornoDi(string|CarbonInterface|null $istante): ?string
    {
        if ($istante === null || $istante === '') {
            return null;
        }
        $i = $istante instanceof CarbonInterface ? $istante->copy() : Carbon::parse((string) $istante);

        return $i->setTimezone(self::fuso())->format('Y-m-d');
    }

    /** «494,25». */
    public static function millesimi(int $centomillesimi): string
    {
        return ContiDellaSala::millesimi($centomillesimi);
    }

    /** Il totale come si scrive nella frase: «1.000», o «998,50» quando non è intero. */
    public static function totale(int $centomillesimi): string
    {
        $m = ContiDellaSala::millesimi($centomillesimi);

        return str_ends_with($m, ',00') ? substr($m, 0, -3) : $m;
    }

    /** «1 condòmino», «11 condòmini». */
    public static function condomini(int $quanti): string
    {
        return $quanti === 1 ? '1 condòmino' : $quanti . ' condòmini';
    }

    /** «il punto 2», «i punti 2 e 3». @param list<int|string> $numeri */
    public static function punti(array $numeri): string
    {
        $numeri = array_values(array_map('strval', $numeri));

        return (count($numeri) === 1 ? 'il punto ' : 'i punti ') . self::elenco($numeri);
    }

    /** «a, b e c». @param list<string> $voci */
    public static function elenco(array $voci): string
    {
        return \App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea::elenco(array_values($voci));
    }

    public static function fuso(): string
    {
        return (string) config('app.user_timezone', 'Europe/Rome');
    }
}
