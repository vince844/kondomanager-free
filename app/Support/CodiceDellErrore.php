<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support;

use Throwable;
use WeakMap;

/**
 * Il codice di un errore (Coda 755 (1), decisione 690 (2)): sei caratteri senza quelli che si confondono (niente 0 e O, 1, I e L), che si dettano al
 * telefono. È lo stesso nella pagina d'errore, nella riga del registro degli errori (`codice_errore`, dal contesto di `bootstrap/app.php`) e nella riga della
 * tabella `errori_volte`.
 *
 * Lo stesso per la stessa eccezione: Laravel, per disegnare la pagina, avvolge un errore che non è HTTP in un `HttpException(500)` con l'originale come
 * `getPrevious()`; {@see di()} risale la catena e trova il codice dato all'originale quando il registro l'ha scritto.
 */
final class CodiceDellErrore
{
    /** 31 caratteri: le cifre da 2 a 9 e le lettere senza I, L e O. */
    public const ALFABETO = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const LUNGHEZZA = 6;

    /** @var WeakMap<Throwable, string>|null */
    private static ?WeakMap $codici = null;

    public static function di(Throwable $e): string
    {
        $codici = self::$codici ??= new WeakMap();
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if (isset($codici[$x])) {
                return $codici[$x];
            }
        }

        return $codici[$e] = self::nuovo();
    }

    public static function nuovo(): string
    {
        $codice = '';
        $ultimo = strlen(self::ALFABETO) - 1;
        for ($i = 0; $i < self::LUNGHEZZA; $i++) {
            $codice .= self::ALFABETO[random_int(0, $ultimo)];
        }

        return $codice;
    }

    /** Il codice com'è scritto da chi lo cerca (minuscole, spazi, un trattino in mezzo), o null se non è un codice. */
    public static function daTesto(?string $testo): ?string
    {
        $pulito = strtoupper(preg_replace('/[\s\-]+/', '', (string) $testo) ?? '');

        return preg_match('/^['.self::ALFABETO.']{'.self::LUNGHEZZA.'}$/', $pulito) === 1 ? $pulito : null;
    }
}
