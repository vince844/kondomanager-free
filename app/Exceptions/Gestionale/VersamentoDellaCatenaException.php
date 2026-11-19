<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Gestionale;

/**
 * Decisione 530, Q5 della quarta prova della 1.12.0-beta.12: «Versato da» l'erede di un erede morto a sua volta (Luca, erede di Marco, che l'aveva ereditato
 * da Ettore) sulla rata del primo defunto. Il suo debito ereditato è nella sua riga, con la seconda successione: dividerlo fra gli eredi della prima chiudeva il
 * debito di un altro erede con i suoi soldi. Il programma non scrive niente e dice dove si registra: lui pagante, la cifra nella sua riga «Debito ereditato
 * da …». Se il versamento resta comunque al defunto, l'amministratore sceglie «Resta a …».
 */
class VersamentoDellaCatenaException extends IncassoNonRegistrabileException
{
    public function __construct(string $erede, string $da, string $defunto)
    {
        parent::__construct(sprintf('%s ha ereditato questo debito da %s: il suo versamento si registra a nome suo. Scegli %s come pagante e scrivi la cifra '
            . 'nella riga «Debito ereditato da %s»: copre la sua parte, e il prossimo piano non gliela chiede di nuovo. Se questo versamento resta comunque a %s, '
            . 'scegli «Resta a %s».', $erede, $da, $erede, $da, $defunto, $defunto));
    }

    public function campo(): string
    {
        return 'versato_da_id';
    }
}
