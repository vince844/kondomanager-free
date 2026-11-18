<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

// Le parole del compito «da moderare» dell'Action inbox (decisioni 445 e 449): il titolo con i numeri veri, al singolare e al plurale, e la riga sotto.

return [
    'commenti' => '{1} :count comment|[0,*] :count comments',
    'allegati' => '{1} :count attachment|[0,*] :count attachments',
    // La frase che chiude i numeri: «2 commenti in attesa», «1 commento e 2 allegati in attesa».
    'in_attesa' => 'awaiting approval',
    'e' => 'and',
    'comunicazione_da_approvare' => 'Notice awaiting approval',
    'documento_da_approvare' => 'Document awaiting approval',
    'evento_da_approvare' => 'Event awaiting approval',
    'sotto' => [
        'segnalazione' => 'Report «:nome»',
        'comunicazione' => 'Notice «:nome»',
        'documento' => 'Document «:nome»',
        'evento' => 'Event «:nome»',
    ],
];
