<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** L'esito di una seduta (la sala): deserta, tenuta, sospesa in una data dell'avviso (art. 66 c. 5 disp. att.), rinviata con una riconvocazione. */
enum EsitoSeduta: string
{
    use ConLeParole;

    case DESERTA = 'deserta';
    case TENUTA = 'tenuta';
    case SOSPESA = 'sospesa';
    case RINVIATA = 'rinviata';

    public function parola(): string
    {
        return match ($this) {
            self::DESERTA => 'Deserta',
            self::TENUTA => 'Tenuta',
            self::SOSPESA => 'Sospesa',
            self::RINVIATA => 'Rinviata',
        };
    }
}
