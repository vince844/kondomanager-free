<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Settings;


/**
 * Il tasso legale degli anni che `config/fiscale.php` non ha ancora, scritto a mano dallo studio: uno per anno, per tutti i condomini. Lo legge e lo
 * scrive {@see \App\Services\Gestionale\Accessori\TassoLegaleAMano}; la forma di ogni riga la dice quella classe. (Senza il tipo degli elementi nel
 * commento: la libreria delle impostazioni lo leggerebbe come un tipo da convertire.)
 */
class TassoLegaleSettings extends ImpostazioniDelRegistro
{
    public array $scritti_a_mano = [];

    public static function group(): string
    {
        return 'tasso_legale';
    }
}
