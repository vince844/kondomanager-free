<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums;

enum OrigineQuota: string
{
    case CALCOLO_AUTOMATICO = 'calcolo_automatico';
    case INSERIMENTO_MANUALE = 'inserimento_manuale';
    case RETTIFICA = 'rettifica';
    case STORNO = 'storno';

    public function label(): string
    {
        return match($this) {
            self::CALCOLO_AUTOMATICO => 'Generato dal sistema',
            self::INSERIMENTO_MANUALE => 'Inserimento manuale',
            self::RETTIFICA => 'Rettifica manuale',
            self::STORNO => 'Storno operazione',
        };
    }
}