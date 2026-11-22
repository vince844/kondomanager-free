<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Helpers\MoneyHelper;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Il testo di un valore come lo dice la pagina: date «16/12/2026», importi «€ 1.852,00» (dai centesimi, mai un `* 100`), percentuali «1,60 %», «sì»/«no»,
 * una scelta con la sua parola, una chiave esterna con il nome. Il valore vuoto è `null` (la pagina scrive «vuota»).
 *
 * Un valore che non si sa scrivere **non ferma mai la modifica** (decisione 518): si ripiega sul testo grezzo.
 *
 * Formati: `testo`, `data`, `data_ora`, `euro` (centesimi), `si_no`, `percentuale`, `quota` (percentuale senza decimali inutili), `numero`, `millesimi`,
 * `ora_letta` (un'ora scritta come l'agenda la scrive, «come si legge» a Roma: «15/12/2026 alle 18:30», senza conversione del fuso),
 * `['scelta', [valore => parola]]`, `['chiave', Modello::class, colonna del nome]`; con `@nome` al posto della colonna il nome lo dà il metodo `nome()` del
 * modello (l'assemblea, che non ha una colonna del nome).
 */
final class FormatoDelValore
{
    public static function testo(mixed $grezzo, string|array $formato = 'testo'): ?string
    {
        if ($grezzo === null || $grezzo === '') {
            return null;
        }

        try {
            return self::scrivi($grezzo, $formato);
        } catch (Throwable) {
            return is_scalar($grezzo) ? (string) $grezzo : (string) json_encode($grezzo, JSON_UNESCAPED_UNICODE);
        }
    }

    private static function scrivi(mixed $grezzo, string|array $formato): ?string
    {
        if (is_array($formato)) {
            return match ($formato[0]) {
                'scelta' => self::scelta($grezzo, $formato[1]),
                'chiave' => self::chiave($grezzo, $formato[1], $formato[2] ?? 'nome'),
                default => (string) $grezzo,
            };
        }

        return match ($formato) {
            'data' => self::data($grezzo)->format('d/m/Y'),
            'data_ora' => self::data($grezzo)->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y H:i'),
            'ora_letta' => self::data($grezzo)->format('d/m/Y') . ' alle ' . self::data($grezzo)->format('H:i'),
            'euro' => MoneyHelper::format((int) $grezzo),
            'si_no' => self::vero($grezzo) ? 'sì' : 'no',
            'percentuale' => number_format((float) $grezzo, 2, ',', '.') . ' %',
            'quota' => self::numero($grezzo) . ' %',
            'numero' => self::numero($grezzo),
            'millesimi' => number_format((float) $grezzo, 2, ',', '.'),
            default => is_scalar($grezzo) ? (string) $grezzo : (string) json_encode($grezzo, JSON_UNESCAPED_UNICODE),
        };
    }

    public static function data(mixed $grezzo): Carbon
    {
        if ($grezzo instanceof \DateTimeInterface) {
            return Carbon::instance($grezzo);
        }

        return Carbon::parse((string) $grezzo, 'UTC');
    }

    private static function vero(mixed $grezzo): bool
    {
        return in_array($grezzo, [true, 1, '1', 'true', 'on', 'sì', 'si'], true);
    }

    /** Un numero all'italiana, senza gli zeri finali dei decimali: 55.600 → «55,6», 100.00 → «100». */
    private static function numero(mixed $grezzo): string
    {
        if (! is_numeric($grezzo)) {
            return (string) $grezzo;
        }
        $testo = number_format((float) $grezzo, 6, ',', '.');

        return rtrim(rtrim($testo, '0'), ',');
    }

    /** @param array<string|int, string> $parole */
    private static function scelta(mixed $grezzo, array $parole): string
    {
        $chiave = is_bool($grezzo) ? (int) $grezzo : $grezzo;
        if (array_key_exists((string) $chiave, $parole)) {
            return $parole[(string) $chiave];
        }

        return (string) $grezzo;
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $modello */
    private static function chiave(mixed $grezzo, string $modello, string $colonna): string
    {
        $istanza = $modello::query()->find($grezzo);
        if ($istanza === null) {
            return 'n. ' . $grezzo . ' (non c\'è più)';
        }
        $nome = $colonna === '@nome' && method_exists($istanza, 'nome') ? $istanza->nome() : $istanza->getAttribute($colonna);

        return $nome === null || $nome === '' ? 'n. ' . $grezzo : (string) $nome;
    }
}
