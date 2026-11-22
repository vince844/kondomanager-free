<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/** Che cosa è per un punto una scheda fra i suoi oggetti: la scheda da cui nasce, una di cui parla, un documento che porta. */
enum RuoloOggettoPunto: string
{
    use ConLeParole;

    case ORIGINE = 'origine';
    case PARLA_DI = 'parla_di';
    case DOCUMENTO = 'documento';

    public function parola(): string
    {
        return match ($this) {
            self::ORIGINE => 'Da dove nasce',
            self::PARLA_DI => 'Parla di',
            self::DOCUMENTO => 'Documento',
        };
    }
}
