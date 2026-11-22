<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

/**
 * Due versioni (decisione 519 (4)): il gesto è partito da una versione che nel frattempo è cambiata in un altro modo. Niente si sovrascrive: il gesto resta
 * «in conflitto» con le due frasi, la sala le mostra, e sceglie l'amministratore. `su` dice che cosa riguarda (`votazione:12`), così una votazione con una
 * scelta aperta non si conferma. `diAdesso` è il numero del gesto che ha fatto la versione di adesso: la sala dice da quale dispositivo e a che ora.
 */
final class DueVersioni extends \RuntimeException
{
    public function __construct(public readonly string $questa, public readonly string $quella, public readonly ?string $su = null,
        public readonly ?string $diAdesso = null)
    {
        parent::__construct('Due versioni: «' . $questa . '» e «' . $quella . '».');
    }
}
