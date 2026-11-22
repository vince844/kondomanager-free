<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** La maggioranza di un punto (art. 1136 c.c., art. 1117-ter c.c.; legge §3). «Un'altra maggioranza» porta la soglia di una legge speciale con la norma scritta dall'amministratore. */
enum Maggioranza: string
{
    use ConLeParole;

    case ORDINARIA = 'ordinaria';
    case META_DEL_VALORE = 'meta_del_valore';
    case DUE_TERZI = 'due_terzi';
    case QUATTRO_QUINTI = 'quattro_quinti';
    case UNANIMITA = 'unanimita';
    case LIBERA = 'libera';
    case SENZA_VOTO = 'senza_voto';

    public function parola(): string
    {
        return match ($this) {
            self::ORDINARIA => 'Ordinaria',
            self::META_DEL_VALORE => 'Metà del valore',
            self::DUE_TERZI => 'Due terzi del valore',
            self::QUATTRO_QUINTI => 'Quattro quinti',
            self::UNANIMITA => 'Unanimità',
            self::LIBERA => 'Un\'altra maggioranza',
            self::SENZA_VOTO => 'Senza voto',
        };
    }
}
