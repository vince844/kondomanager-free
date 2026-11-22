<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Traits;

use App\Services\RegistroModifiche\RigheDelTratto;

/**
 * Il tratto leggero dei modelli «solo gesto» del registro delle modifiche (P5): le rate, le quote, i passaggi, le scritture. Dentro un gesto ogni scrittura
 * si conta nel lotto; fuori si annota in un raccoglitore (non solleva: i test creano rate e quote a mano nella preparazione), e i test per area, che fanno
 * il gesto vero dalla rotta, pretendono il raccoglitore vuoto. Una scrittura contabile fuori da un gesto scrive invece la sua riga (§5).
 */
trait ContaNelGesto
{
    public static function bootContaNelGesto(): void
    {
        static::created(fn ($modello) => RigheDelTratto::scritturaDiUnGesto($modello, 'creata'));
        static::updated(fn ($modello) => RigheDelTratto::scritturaDiUnGesto($modello, 'modificata'));
        static::deleted(fn ($modello) => RigheDelTratto::scritturaDiUnGesto($modello, 'eliminata'));
    }
}
