<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

// Le parole del compito «da moderare» dell'Action inbox (decisioni 445 e 449): il titolo con i numeri veri, al singolare e al plurale, e la riga sotto.

return [
    'commenti' => '{1} :count commento|[0,*] :count commenti',
    'allegati' => '{1} :count allegato|[0,*] :count allegati',
    // La frase che chiude i numeri: «2 commenti in attesa», «1 commento e 2 allegati in attesa».
    'in_attesa' => 'in attesa',
    'e' => 'e',
    'comunicazione_da_approvare' => 'Comunicazione da approvare',
    'documento_da_approvare' => 'Documento da approvare',
    'evento_da_approvare' => 'Evento da approvare',
    'sotto' => [
        'segnalazione' => 'Segnalazione «:nome»',
        'comunicazione' => 'Comunicazione «:nome»',
        'documento' => 'Documento «:nome»',
        'evento' => 'Evento «:nome»',
    ],
];
