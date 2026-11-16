<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Accessori;

/**
 * Che cos'è un accessorio e su che cosa si fonda: il tipo (gli interessi di mora, le spese di sollecito, le spese legali prima della causa e della causa,
 * altro), la base che l'amministratore dichiara (la legge, il regolamento, una delibera, un provvedimento del giudice, altro, nessuna) con il suo
 * riferimento, e gli avvisi.
 *
 * La base si mostra e non blocca: gli avvisi dicono dove una sentenza ha visto le cose in un altro modo, senza parole di invalidità, e la scelta resta
 * dell'amministratore. Il bollo della ricevuta, se lo vuole chiedere, è «altro».
 */
final class BaseDegliAccessori
{
    public const TIPI = [
        'interessi' => 'Interessi di mora',
        'sollecito' => 'Spese di sollecito',
        'legali_stragiudiziali' => 'Spese legali prima della causa',
        'legali_giudiziali' => 'Spese legali della causa',
        'altro' => 'Altra spesa',
    ];

    public const BASI = [
        'legge' => 'la legge',
        'regolamento' => 'il regolamento',
        'delibera' => 'una delibera dell\'assemblea',
        'provvedimento' => 'un provvedimento del giudice',
        'altro' => 'altro',
        'nessuna' => 'nessuna',
    ];

    public const AVVISO_STRAGIUDIZIALI_DELIBERA = 'Alcune sentenze non ammettono che una delibera a maggioranza addebiti al solo condòmino le spese dell\'avvocato '
        . 'prima della causa: controlla la base.';

    public const AVVISO_GIUDIZIALI = 'Le spese della causa si addebitano con la condanna alle spese: controlla la base.';

    public const AVVISO_SOLLECITO = 'Senza una base scritta, l\'addebito di una spesa di sollecito al solo condòmino si valuta caso per caso: controlla la base.';

    /**
     * Gli avvisi di un accessorio, per la sua base: mai un blocco.
     *
     * @return list<string>
     */
    public static function avvisi(?string $tipo, ?string $base, ?string $fonteDelTasso = null): array
    {
        $avvisi = [];
        if ($tipo === 'legali_stragiudiziali' && $base === 'delibera') {
            $avvisi[] = self::AVVISO_STRAGIUDIZIALI_DELIBERA;
        }
        if ($tipo === 'legali_giudiziali' && $base !== 'provvedimento') {
            $avvisi[] = self::AVVISO_GIUDIZIALI;
        }
        if ($tipo === 'sollecito' && ($base === null || $base === 'nessuna')) {
            $avvisi[] = self::AVVISO_SOLLECITO;
        }
        if ($tipo === 'interessi' && ($avviso = RegoleDelRecupero::avvisoDellaFonte($fonteDelTasso ?? $base)) !== null) {
            $avvisi[] = $avviso;
        }

        return $avvisi;
    }

    /** La base detta per chi legge: «il regolamento, art. 12», «una delibera dell'assemblea, del 14/03/2026, verbale n. 1», «nessuna». */
    public static function testo(?string $base, ?string $riferimento): ?string
    {
        if ($base === null) {
            return null;
        }
        $riferimento = trim((string) $riferimento);

        return (self::BASI[$base] ?? $base) . ($riferimento !== '' ? ', ' . $riferimento : '');
    }
}
