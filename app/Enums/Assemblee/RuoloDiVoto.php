<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Con quale ruolo vota una testa su un punto. */
enum RuoloDiVoto: string
{
    use ConLeParole;

    case PROPRIETARIO = 'proprietario';
    case USUFRUTTUARIO = 'usufruttuario';
    case NUDO_PROPRIETARIO = 'nudo_proprietario';
    case CONDUTTORE = 'conduttore';
    case RAPPRESENTANTE = 'rappresentante';
    case DELEGATO = 'delegato';

    public function parola(): string
    {
        return match ($this) {
            self::PROPRIETARIO => 'proprietario',
            self::USUFRUTTUARIO => 'usufruttuario',
            self::NUDO_PROPRIETARIO => 'nudo proprietario',
            self::CONDUTTORE => 'conduttore',
            self::RAPPRESENTANTE => 'rappresentante',
            self::DELEGATO => 'per delega',
        };
    }
}
