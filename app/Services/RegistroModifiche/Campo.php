<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

/**
 * Un dato cambiato, nella forma di `registro_modifiche.campi`: `prima`/`dopo` sono il testo come lo dice la pagina («€ 1.852,00», «16/12/2026», «sì»),
 * `valore_prima`/`valore_dopo` il dato grezzo per «Ripristina». Un campo segreto (la password SMTP) dice «cambiata» e non scrive i valori.
 */
final class Campo
{
    public const CAMBIATA = 'cambiata';

    /** @return array{chiave: string, etichetta: string, prima: ?string, dopo: ?string, valore_prima: mixed, valore_dopo: mixed, segreto: bool} */
    public static function nuovo(string $chiave, string $etichetta, ?string $prima, ?string $dopo, mixed $valorePrima = null, mixed $valoreDopo = null,
        bool $segreto = false): array
    {
        if ($segreto) {
            return ['chiave' => $chiave, 'etichetta' => $etichetta, 'prima' => self::CAMBIATA, 'dopo' => self::CAMBIATA, 'valore_prima' => null,
                'valore_dopo' => null, 'segreto' => true];
        }

        return ['chiave' => $chiave, 'etichetta' => $etichetta, 'prima' => $prima, 'dopo' => $dopo, 'valore_prima' => self::grezzo($valorePrima),
            'valore_dopo' => self::grezzo($valoreDopo), 'segreto' => false];
    }

    /** Il dato grezzo in una forma che il JSON tiene uguale andata e ritorno: scalari e liste, le date come testo. */
    public static function grezzo(mixed $valore): mixed
    {
        if ($valore instanceof \DateTimeInterface) {
            return $valore->format('Y-m-d H:i:s');
        }
        if ($valore instanceof \BackedEnum) {
            return $valore->value;
        }
        if ($valore instanceof \UnitEnum) {
            return $valore->name;
        }
        if (is_float($valore)) {
            // Un decimale torna dal JSON di MySQL come numero, e 1.0 diventerebbe 1: il testo lo tiene com'era.
            return (string) $valore;
        }
        if (is_object($valore)) {
            return method_exists($valore, 'toArray') ? $valore->toArray() : (string) json_encode($valore);
        }

        return $valore;
    }
}
